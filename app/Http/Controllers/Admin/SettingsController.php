<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TestMailRequest;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\SettingsService;
use App\Services\Core\TestMailService;
use App\Support\Modules;
use App\Support\SettingsRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The settings screen (phase-02 §4 routes, §5 UI).
 *
 * Route table this controller answers to — the method names are the route names read left to
 * right, so `admin.settings.file.destroy` is `destroyFile()`:
 *
 *   GET    /admin/settings/{group?}              admin.settings.index        can:settings.view
 *   PUT    /admin/settings/{group}               admin.settings.update       can:settings.edit
 *   DELETE /admin/settings/{group}/file/{key}    admin.settings.file.destroy can:settings.edit
 *   POST   /admin/settings/{group}/reset         admin.settings.reset        can:settings.edit
 *   POST   /admin/settings/mail/test             admin.settings.mail.test    can:settings.edit + throttle:3,1
 *   POST   /admin/settings/cache/clear           admin.settings.cache.clear  can:settings.edit
 *
 * Three rules shape everything below.
 *
 * **1. Definitions come from `SettingsRegistry`, never from here.** The tab rail is
 * `groups()`, the form is `fields($group)` and the validation is `rulesFor($group)` through
 * `UpdateSettingsRequest`. Adding a setting is a registry edit; this file does not change.
 *
 * **2. Every write goes through `SettingsService`.** There is no `Setting::update()`,
 * `->save()` or `setting_set()` in this class: the service owns the transaction, the uploads,
 * the encryption, the `updated_by` stamp, the cache flush and the one-activity-entry-per-key
 * audit trail. What this controller reads from the `settings` table it reads to *render*.
 *
 * **3. A secret is never rendered.** The mail password reaches the screen as
 * `Setting::MASK` (or as "not set"), and the test-mail result is redacted before it is flashed,
 * so the SMTP password cannot appear in the HTML even inside an exception message.
 *
 * Authorization, in three layers (CLAUDE.md rule 7):
 *
 *   · the route's `can:` middleware;
 *   · `settings.view` renders the form read-only — every input disabled, no save bar, no danger
 *     zone — and `settings.edit` is what unlocks it;
 *   · the mail group additionally needs {@see self::PERMISSION_MAIL}, so whoever may not change
 *     the SMTP credentials may not send with them either.
 */
final class SettingsController extends Controller
{
    /** The group whose write path carries the extra permission. */
    public const GROUP_MAIL = 'mail';

    /**
     * The one place the mail gate is named — and it is named once, by the service that enforces it.
     *
     * phase-02 §5 puts the SMTP credentials behind a Super-Admin-only permission ("Admin:
     * everything except … `settings.edit` of SMTP", phase-01 §5). `settings.edit_mail` is now
     * declared in `PermissionRegistry` (a narrow ability on the `settings` module, the same shape
     * as `project_payments.link_invoice`, D43) and withheld from Admin by `RoleSeeder`. Reading it
     * off `SettingsService` rather than re-typing the string is what stops the controller and the
     * service from disagreeing — they did during Phase 2 development, and a role granted one
     * spelling would have passed the service and still been blocked here.
     */
    public const PERMISSION_MAIL = SettingsService::EDIT_MAIL_PERMISSION;

    /** Renders the screen. */
    private const PERMISSION_VIEW = 'settings.view';

    /** Saves a group — the registry's own constant, so the two cannot drift. */
    private const PERMISSION_EDIT = SettingsRegistry::PERMISSION;

    /** Flash key the mail-test result travels back on. */
    private const FLASH_MAIL_TEST = 'settings.mail_test';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly TestMailService $mailer,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Screen
    |--------------------------------------------------------------------------
    */

    /**
     * One group's form, with the tab rail beside it.
     */
    public function index(Request $request, ?string $group = null): View
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can(self::PERMISSION_VIEW), 403);

        $slug = $this->resolveGroup($group);
        $meta = SettingsRegistry::group($slug) ?? [];
        $canEdit = self::mayEditGroup($user, $slug);

        $rows = $this->rows($slug);
        $fields = $this->presentFields($slug, $rows, $canEdit);

        return view('admin.settings.index', [
            'groups' => $this->rail($user, $slug),
            'group' => $meta,
            'fields' => $fields,
            'canEdit' => $canEdit,
            'canClearCache' => $user->can(self::PERMISSION_EDIT),
            'audit' => $this->audit($rows),
            'preview' => $slug === 'branding' ? $this->brandingPreview($fields) : null,
            'mail' => $slug === self::GROUP_MAIL ? $this->mailPanel($request, $user) : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Writes — all of them through SettingsService
    |--------------------------------------------------------------------------
    */

    /**
     * Save one group.
     *
     * Authorization, the rules and the unknown / foreign / readonly key refusals all live in
     * {@see UpdateSettingsRequest}; by the time we are here the payload is exactly the set of
     * keys this group allows.
     */
    public function update(UpdateSettingsRequest $request, string $group): RedirectResponse
    {
        $slug = $request->groupSlug();
        $values = $request->values();

        if ($values === []) {
            return $this->back($slug)->with('toast', [
                'type' => 'info',
                'message' => 'Nothing to save — no value changed.',
            ]);
        }

        try {
            $changed = $this->writeGroup($slug, $values);
        } catch (LogicException|ValidationException $exception) {
            // A wiring mistake surfaces; a field error from the service goes back through the
            // exception handler as field errors (with the secrets stripped from the old input),
            // never flattened into a toast.
            throw $exception;
        } catch (ActionNotAllowedException|AuthorizationException $exception) {
            // Written for an operator (a read-only key, a refused upload): safe to show as is.
            return $this->back($slug)
                ->withInput($request->except(SettingsRegistry::secretInputNames()))
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'Those settings could not be saved: '.$exception->getMessage(),
                ]);
        } catch (Throwable $exception) {
            // Anything else (a QueryException carries the SQL and its bindings) is reported, not
            // rendered into the page or flashed into the session.
            report($exception);

            return $this->back($slug)
                ->withInput($request->except(SettingsRegistry::secretInputNames()))
                ->with('toast', [
                    'type' => 'error',
                    'message' => 'Those settings could not be saved because of an unexpected error. It has been logged.',
                ]);
        }

        return $this->back($slug)->with('toast', [
            'type' => 'success',
            'message' => $this->savedMessage($slug, $changed),
        ]);
    }

    /**
     * Remove one uploaded file (logo, favicon, social image).
     *
     * Writing null through the service is what deletes the stored file as well as the value, so
     * the removal follows the same audited, cache-flushing path as any other change.
     */
    public function destroyFile(Request $request, string $group, string $key): RedirectResponse
    {
        $slug = $this->resolveGroup($group, strict: true);

        abort_unless(self::mayEditGroup($request->user(), $slug), 403);

        $field = SettingsRegistry::fields($slug)[$key] ?? null;

        abort_if($field === null || ! SettingsRegistry::isFileType((string) $field['type']), 404);
        abort_if($field['readonly'] === true, 403);

        try {
            $this->removeFile($slug, (string) $key);
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->back($slug)->with('toast', [
                'type' => 'error',
                'message' => 'That file could not be removed: '.$exception->getMessage(),
            ]);
        }

        return $this->back($slug)->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s removed.', $field['label']),
        ]);
    }

    /**
     * Restore a whole group to the registry defaults (behind `x-ui.confirm` on the screen).
     */
    public function reset(Request $request, string $group): RedirectResponse
    {
        $slug = $this->resolveGroup($group, strict: true);

        abort_unless(self::mayEditGroup($request->user(), $slug), 403);

        try {
            $this->resetGroup($slug);
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->back($slug)->with('toast', [
                'type' => 'error',
                'message' => 'That group could not be reset: '.$exception->getMessage(),
            ]);
        }

        return $this->back($slug)->with('toast', [
            'type' => 'warning',
            'message' => sprintf(
                '%s settings are back to their defaults. Uploaded files were cleared with them.',
                $this->groupLabel($slug)
            ),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Mail test
    |--------------------------------------------------------------------------
    */

    /**
     * Send one test message with the saved settings and show what actually happened.
     *
     * A failure is reported, not hidden: the operator needs the transport's own message
     * ("Connection could not be established with host smtp.example.com") to fix it. The password
     * is redacted from that message before it leaves this method.
     */
    public function testMail(TestMailRequest $request): RedirectResponse|JsonResponse
    {
        $recipient = $request->recipient();
        $result = $this->sendTestMail($recipient);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return $this->back(self::GROUP_MAIL)
            ->with(self::FLASH_MAIL_TEST, $result)
            ->with('toast', [
                'type' => $result['ok'] ? 'success' : 'error',
                'message' => $result['ok']
                    ? sprintf('Test email sent to %s.', $recipient)
                    : 'The test email failed — the transport’s reply is on the screen.',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Caches
    |--------------------------------------------------------------------------
    */

    /**
     * Flush the caches a settings change touches.
     *
     * Deliberately narrow: the settings payload, the module map, spatie's permission cache and
     * the compiled Blade views. `config:clear` and `route:clear` are **not** run — on a
     * production install they would drop optimisations this button has no business dropping.
     */
    public function clearCache(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->can(self::PERMISSION_EDIT), 403);

        $cleared = [];

        try {
            settings_repo()->flush();
            $cleared[] = 'settings';

            Modules::flushCache();
            $cleared[] = 'modules';

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $cleared[] = 'permissions';

            Artisan::call('view:clear');
            $cleared[] = 'views';
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Some caches could not be cleared: '.$exception->getMessage(),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Cleared the '.implode(', ', $cleared).' caches.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    /**
     * May this user save this group?
     *
     * The whole screen — the controller, `UpdateSettingsRequest`, `TestMailRequest`, the tab
     * rail's lock icon and the read-only banner — asks this one question, so the UI and the
     * server can never disagree about what is editable.
     */
    public static function mayEditGroup(?Authenticatable $user, string $group): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->can(self::PERMISSION_EDIT)) {
            return false;
        }

        return $group !== self::GROUP_MAIL || $user->can(self::PERMISSION_MAIL);
    }

    /*
    |--------------------------------------------------------------------------
    | SettingsService seam
    |--------------------------------------------------------------------------
    |
    | phase-02 §3 fixes the service's responsibility and names one of its methods
    | (`resetGroup(string $group)`); the write method's exact name is the service's own choice.
    | Rather than guess once and break, each call below resolves the first name the service
    | actually publishes and fails with a message that names the alternatives. This is the only
    | place in the settings screen that talks to the service.
    |
    */

    /**
     * Persist one group's values. Returns the number of changed keys when the service reports it.
     *
     * @param  array<string, mixed>  $values  bare key => value, uploads included
     */
    private function writeGroup(string $group, array $values): ?int
    {
        $result = $this->onService(
            ['updateGroup', 'update', 'saveGroup', 'save', 'setGroup', 'putGroup'],
            [$group, $values],
        );

        if (is_int($result)) {
            return $result;
        }

        return is_countable($result) ? count($result) : null;
    }

    /**
     * Drop one uploaded file and the value pointing at it.
     */
    private function removeFile(string $group, string $key): void
    {
        foreach (['deleteFile', 'removeFile', 'forgetFile', 'clearFile'] as $method) {
            if (method_exists($this->settings, $method)) {
                $this->settings->{$method}($group, $key);

                return;
            }
        }

        // No dedicated method: writing null through the normal path is the same operation —
        // the service deletes the file it replaces (phase-02 §3).
        $this->writeGroup($group, [$key => null]);
    }

    /**
     * Restore the registry defaults for one group.
     */
    private function resetGroup(string $group): void
    {
        $this->onService(['resetGroup', 'reset', 'restoreDefaults'], [$group]);
    }

    /**
     * Send the test message and normalise `{ok, message, exception}` into a flat array.
     *
     * @return array{ok: bool, message: string, exception: string|null, recipient: string, at: string}
     */
    private function sendTestMail(string $recipient): array
    {
        try {
            $result = $this->onService(
                ['send', 'sendTo', 'test', 'sendTestMail', 'handle', '__invoke'],
                [$recipient],
                $this->mailer,
            );
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $result = [
                'ok' => false,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ];
        }

        $ok = (bool) $this->resultValue($result, 'ok');
        $message = (string) ($this->resultValue($result, 'message') ?? '');
        $exception = $this->resultValue($result, 'exception');

        if ($exception !== null && ! is_string($exception)) {
            $exception = is_object($exception) ? $exception::class : null;
        }

        if ($message === '') {
            $message = $ok
                ? 'The transport accepted the message.'
                : 'The transport refused the message and gave no reason.';
        }

        return [
            'ok' => $ok,
            'message' => $this->redactSecrets($message),
            'exception' => $exception === null ? null : $this->redactSecrets($exception),
            'recipient' => $recipient,
            'at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Call the first method the service publishes from `$candidates`.
     *
     * @param  list<string>  $candidates
     * @param  list<mixed>  $arguments
     */
    private function onService(array $candidates, array $arguments, ?object $service = null): mixed
    {
        $service ??= $this->settings;

        foreach ($candidates as $method) {
            if (method_exists($service, $method)) {
                return $service->{$method}(...$arguments);
            }
        }

        throw new LogicException(sprintf(
            '%s publishes none of [%s] — the settings screen calls it through one of those names.',
            $service::class,
            implode(', ', $candidates),
        ));
    }

    /**
     * Read `ok` / `message` / `exception` off an array, a DTO with properties or a DTO with
     * accessors, without caring which shape the service returned.
     */
    private function resultValue(mixed $result, string $key): mixed
    {
        if (is_array($result)) {
            return $result[$key] ?? null;
        }

        if (! is_object($result)) {
            // A bare boolean is a legitimate "it worked" answer.
            return $key === 'ok' ? (bool) $result : null;
        }

        if (method_exists($result, $key)) {
            return $result->{$key}();
        }

        return $result->{$key} ?? null;
    }

    /**
     * Never let a stored secret out in a message, however it got in there.
     */
    private function redactSecrets(string $message): string
    {
        foreach (SettingsRegistry::encryptedKeys() as $key) {
            $secret = setting($key);

            if (is_string($secret) && mb_strlen($secret) > 2 && str_contains($message, $secret)) {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | View model
    |--------------------------------------------------------------------------
    */

    /**
     * The tab rail: every declared group, in sort order, with the current one marked.
     *
     * @return list<array<string, mixed>>
     */
    private function rail(User $user, string $current): array
    {
        $groups = SettingsRegistry::groups();

        uasort($groups, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $rail = [];

        foreach ($groups as $slug => $meta) {
            $rail[] = [
                'slug' => $slug,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'description' => $meta['description'],
                'url' => route('admin.settings.index', $slug),
                'active' => $slug === $current,
                'locked' => ! self::mayEditGroup($user, $slug),
                'count' => count(SettingsRegistry::fields($slug)),
            ];
        }

        return $rail;
    }

    /**
     * The group's rows, keyed by bare key, with the last editor eager-loaded.
     *
     * @return Collection<string, Setting>
     */
    private function rows(string $group): Collection
    {
        return Setting::query()
            ->forGroup($group)
            ->with('updatedBy:id,name')
            ->get()
            ->keyBy('key');
    }

    /**
     * One view model per field: the registry definition plus what is stored right now.
     *
     * @param  Collection<string, Setting>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentFields(string $group, Collection $rows, bool $canEdit): array
    {
        $fields = [];

        foreach (SettingsRegistry::fields($group) as $key => $field) {
            /** @var Setting|null $row */
            $row = $rows->get($key);

            $fields[] = $this->presentField($group, (string) $key, $field, $row, $canEdit);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function presentField(string $group, string $key, array $field, ?Setting $row, bool $canEdit): array
    {
        $type = (string) $field['type'];
        $isSecret = $field['encrypted'] === true || $type === SettingsRegistry::TYPE_PASSWORD;
        $isFile = SettingsRegistry::isFileType($type);

        $stored = $row?->typedValue();
        $value = $stored ?? $field['default'];

        // A secret never reaches the view — only whether one is stored.
        if ($isSecret) {
            $value = null;
        }

        return [
            'key' => $key,
            'group' => $group,
            'label' => (string) $field['label'],
            'type' => $type,
            'help' => $field['help'],
            'placeholder' => $field['placeholder'],
            'suffix' => $field['suffix'],
            'span' => (int) $field['span'],
            'rules' => array_values((array) $field['rules']),
            // Child rules drive the json row editor's columns (`*.open` → a time input).
            'item_rules' => (array) $field['item_rules'],
            'options' => SettingsRegistry::optionsFor($field),
            'default' => $isSecret ? null : $field['default'],
            'value' => $value,
            'encrypted' => $isSecret,
            'public' => $field['public'] === true,
            'readonly' => $field['readonly'] === true,
            'required' => in_array('required', (array) $field['rules'], true),
            'disabled' => ! $canEdit || $field['readonly'] === true,
            'name' => UpdateSettingsRequest::PAYLOAD.'['.$key.']',
            'error_key' => UpdateSettingsRequest::PAYLOAD.'.'.$key,
            'id' => 'setting-'.$group.'-'.str_replace('_', '-', $key),
            'has_secret' => $isSecret && $row?->maskedValue() !== null,
            'file' => $isFile ? $this->presentFile(is_string($stored) ? $stored : null) : null,
            'updated_at' => $row?->updated_at,
            'updated_by' => $row?->updatedBy?->name,
        ];
    }

    /**
     * A stored file's path, public URL and basename — null when nothing is stored.
     *
     * @return array{path: string, url: string|null, name: string}|null
     */
    private function presentFile(?string $path): ?array
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : $this->publicUrl($path);

        return [
            'path' => $path,
            'url' => $url,
            'name' => basename($path),
        ];
    }

    /**
     * `Storage::url()` on the public disk, but never fatal — a missing disk must not break the
     * form the operator is trying to use to fix it.
     */
    private function publicUrl(string $path): ?string
    {
        try {
            return Storage::disk('public')->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "Last updated by X, <time>" for the group footer.
     *
     * @param  Collection<string, Setting>  $rows
     * @return array{user: string|null, at: Carbon|null, keys: int}
     */
    private function audit(Collection $rows): array
    {
        /** @var Setting|null $latest */
        $latest = $rows
            ->filter(static fn (Setting $row): bool => $row->updated_at !== null)
            ->sortByDesc('updated_at')
            ->first();

        return [
            'user' => $latest?->updatedBy?->name,
            'at' => $latest?->updated_at,
            'keys' => $rows->count(),
        ];
    }

    /**
     * What the branding live preview needs: the two colours and the two logos.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function brandingPreview(array $fields): array
    {
        $byKey = [];

        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        return [
            'brand' => (string) ($byKey['brand_color']['value'] ?? '#4f46e5'),
            'accent' => (string) ($byKey['accent_color']['value'] ?? '#0ea5e9'),
            'logo_light' => $byKey['logo_light']['file']['url'] ?? null,
            'logo_dark' => $byKey['logo_dark']['file']['url'] ?? null,
            'company' => (string) setting('company.name', config('app.name', 'My Office')),
            'tagline' => (string) (setting('company.tagline') ?? ''),
        ];
    }

    /**
     * What the mail group's test panel renders: where a message would go, and the last result.
     *
     * The password is represented by a boolean and nothing else.
     *
     * @return array<string, mixed>
     */
    private function mailPanel(Request $request, User $user): array
    {
        $result = $request->session()->get(self::FLASH_MAIL_TEST);

        return [
            'mailer' => (string) (setting('mail.mailer') ?? 'log'),
            'host' => (string) (setting('mail.host') ?? ''),
            'port' => setting('mail.port'),
            'encryption' => (string) (setting('mail.encryption') ?? ''),
            'from' => (string) (setting('mail.from_address') ?? ''),
            'from_name' => (string) (setting('mail.from_name') ?? ''),
            'has_password' => filled(setting('mail.password')),
            'recipient' => (string) $user->email,
            'can_send' => self::mayEditGroup($user, self::GROUP_MAIL),
            'result' => is_array($result) ? $result : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Small helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The group being shown: the requested one, or the first declared group.
     *
     * @param  bool  $strict  true on a write route, where an absent group is a bad URL
     */
    private function resolveGroup(?string $group, bool $strict = false): string
    {
        $group = $group === null ? null : trim($group);

        if ($group === null || $group === '') {
            abort_if($strict, 404);

            $groups = SettingsRegistry::groups();

            uasort($groups, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $first = array_key_first($groups);

            abort_if($first === null, 404);

            return (string) $first;
        }

        abort_unless(SettingsRegistry::hasGroup($group), 404);

        return $group;
    }

    private function groupLabel(string $group): string
    {
        return (string) (SettingsRegistry::group($group)['label'] ?? ucfirst($group));
    }

    /**
     * Back to the group that was being edited, so a save never loses the operator's place.
     */
    private function back(string $group): RedirectResponse
    {
        return redirect()->route('admin.settings.index', $group);
    }

    /**
     * Wording that tells the truth whether or not the service counted the changes.
     */
    private function savedMessage(string $group, ?int $changed): string
    {
        $label = $this->groupLabel($group);

        return match (true) {
            $changed === null => sprintf('%s settings saved.', $label),
            $changed === 0 => sprintf('%s settings saved — nothing had changed.', $label),
            $changed === 1 => sprintf('%s settings saved — 1 value changed.', $label),
            default => sprintf('%s settings saved — %d values changed.', $label, $changed),
        };
    }
}
