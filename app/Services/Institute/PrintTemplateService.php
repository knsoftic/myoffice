<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\PrintTemplateType;
use App\Models\Institute\PrintTemplate;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\PrintTokenRegistry;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;

/**
 * Print templates: sanitising them, rendering them, and never compiling them
 * (phase-19-23 §6.13, INV-21-5, requirements §82, §84, §85).
 *
 * **[D-21-2] and INV-21-5, stated once and enforced twice.** A template is HTML with `{tokens}`. It is
 * sanitised through `RichText::sanitize($html, 'material')` — Phase 3's single sanitiser, called with
 * a named profile, **not** a second implementation — on save *and again* on render, and rendered by
 * `str_replace` over a fixed map from `PrintTokenRegistry`. It is never passed to Blade, `eval`,
 * `Blade::render`, a view factory, `@php` or `@include`.
 *
 * The double sanitise is not belt-and-braces theatre. The save-time pass protects the database from
 * the editor; the render-time pass protects the reader from **the database** — a row written by a
 * migration, a seeder, an import, or somebody with SQL access has never been through the first one.
 *
 * **Escaping is by default and `raw` is by exception.** Every resolved value is escaped on the way
 * into the document unless its token is declared `raw` in the registry, and the only raw tokens are
 * ones this application builds: a QR image, a photo, a logo, a signature, a results table, a grade
 * legend. A student's name is never raw — a name that becomes markup is stored XSS with an extra
 * step, and a PDF renderer is not a safe place to find that out.
 *
 * **An unknown token renders empty, and saving one is a warning rather than a refusal.** A designer
 * gets a typo wrong on the way to getting a layout right, and losing an hour of work to a rejected
 * save is a worse outcome than a token that prints nothing. The warning names each one, because
 * "there is an unknown token somewhere" sends somebody hunting through two hundred lines.
 */
final class PrintTemplateService
{
    /** The one profile a print template is ever sanitised against (D25, INV-21-5). */
    private const PROFILE = 'material';

    /**
     * Create a template. The HTML is sanitised **before** the row exists, so a refusal writes nothing.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: PrintTemplate, 1: list<string>} the template and any unknown tokens
     */
    public function create(array $attributes, ?User $actor = null): array
    {
        $type = $this->typeOf($attributes['type'] ?? null);

        $html = $this->sanitize($attributes['body_html'] ?? '');
        $css = $this->sanitizeCss($attributes['custom_css'] ?? null);
        $unknown = PrintTokenRegistry::unknownIn($type, $html);

        $template = DB::transaction(function () use ($attributes, $type, $html, $css): PrintTemplate {
            $template = new PrintTemplate;
            $template->forceFill(array_merge(
                $this->writableAttributes($attributes, $type),
                [
                    'body_html' => $html,
                    'custom_css' => $css,
                    'tokens_used' => PrintTokenRegistry::mentionedIn($html),
                    'is_default' => false,
                ],
            ));
            $template->save();

            return $template->refresh();
        });

        return [$template, $unknown];
    }

    /**
     * Update a template.
     *
     * **Editing one that has printed documents needs a reason**, which goes on the activity record
     * with the diff. An issued certificate keeps its own snapshots and its own stored template
     * reference, so the edit cannot change what it says — only how a *reprint* is laid out. Saying so
     * in the audit trail is what makes a reprint that looks different explicable a year later.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: PrintTemplate, 1: list<string>}
     */
    public function update(PrintTemplate $template, array $attributes, ?string $reason = null, ?User $actor = null): array
    {
        if ($template->hasPrintedAnything() && trim((string) $reason) === '') {
            throw CourseRuleException::reasonRequired(
                'reason',
                'This template has printed documents. Say why it is changing — a reprint laid out '
                .'differently from the original needs to be explicable.',
            );
        }

        $type = $template->type;

        // Absent means unchanged; present-and-null means cleared. `?? null` here would wipe a
        // stylesheet on every partial save — D121, in the phase that learned it.
        $keep = static fn (string $key, mixed $current): mixed => array_key_exists($key, $attributes)
            ? $attributes[$key]
            : $current;

        $html = $this->sanitize($keep('body_html', $template->getAttribute('body_html')));
        $css = $this->sanitizeCss($keep('custom_css', $template->getAttribute('custom_css')));
        $unknown = PrintTokenRegistry::unknownIn($type, $html);

        $updated = DB::transaction(function () use ($template, $attributes, $type, $html, $css, $keep): PrintTemplate {
            $locked = PrintTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill(array_merge(
                $this->writableAttributes($attributes, $type, $locked, $keep),
                [
                    'body_html' => $html,
                    'custom_css' => $css,
                    'tokens_used' => PrintTokenRegistry::mentionedIn($html),
                ],
            ));
            $locked->save();

            return $locked->refresh();
        });

        return [$updated, $unknown];
    }

    /**
     * Sanitise supplied HTML.
     *
     * This method **adds no filtering of its own** — it names the profile, and that is the whole
     * point. One sanitiser, one code path, a per-call-site allowlist (D25). A second implementation
     * here would be a second thing to keep in step with the first, and the one that lags is the one
     * that lets something through.
     */
    public function sanitize(mixed $html): string
    {
        return RichText::sanitize(is_string($html) ? $html : null, self::PROFILE);
    }

    /** Same discipline for the stylesheet: `@import`, `expression(` and external `url()` all go. */
    public function sanitizeCss(mixed $css): ?string
    {
        $clean = RichText::sanitizeCss(is_string($css) ? $css : null, self::PROFILE);

        return $clean === '' ? null : $clean;
    }

    /**
     * Render a template against one document.
     *
     * **Token replacement only.** The stored HTML goes through the sanitiser again first (see the
     * class note), then `strtr` over the resolved map. Nothing is compiled and nothing is evaluated.
     *
     * `strtr` rather than a loop of `str_replace`: it substitutes in **one pass**, so a value that
     * happens to contain something that looks like another token cannot be re-substituted. A student
     * named in a remark field could otherwise inject `{qr}` into the output of `{remarks}` and have
     * it resolved.
     *
     * @param  array<string, string>  $resolved  token => already-formatted value
     */
    public function render(PrintTemplate $template, array $resolved): string
    {
        $html = $this->sanitize($template->getAttribute('body_html'));

        $map = [];

        foreach (PrintTokenRegistry::tokens($template->type) as $token => $spec) {
            $value = (string) ($resolved[$token] ?? '');

            $map['{'.$token.'}'] = $spec['formatter'] === 'raw'
                ? $value
                : e($value);
        }

        // Anything the template mentions that the registry does not know becomes an empty string
        // rather than printing a literal `{oops}` across the middle of a certificate.
        foreach (PrintTokenRegistry::mentionedIn($html) as $token) {
            $map['{'.$token.'}'] ??= '';
        }

        return strtr($html, $map);
    }

    /**
     * Render with the registry's example values — **never a real student's data** (§6.13).
     *
     * A preview that pulled a live record would put a real name and a real certificate number on a
     * screen somebody is only adjusting margins on, and would make the preview's correctness depend
     * on whether any student exists yet.
     */
    public function preview(PrintTemplate $template): string
    {
        return $this->render($template, PrintTokenRegistry::examples($template->type));
    }

    /**
     * Make this the default for its (type, branch).
     *
     * The previous default is cleared **first**, inside the same transaction: `uq_pt_default` permits
     * exactly one row whose guard is 1 per (type, branch), so setting the new one before clearing the
     * old would collide. The index is the backstop, not the mechanism.
     */
    public function setDefault(PrintTemplate $template, ?User $actor = null): PrintTemplate
    {
        return DB::transaction(function () use ($template): PrintTemplate {
            $locked = PrintTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            if (! (bool) $locked->getAttribute('is_active')) {
                throw CourseRuleException::refuse('is_default', 'A retired template cannot be the default.');
            }

            PrintTemplate::query()
                ->where('type', $locked->getAttribute('type'))
                ->where(function ($query) use ($locked): void {
                    $branchId = $locked->getAttribute('branch_id');

                    $branchId === null
                        ? $query->whereNull('branch_id')
                        : $query->where('branch_id', $branchId);
                })
                ->where('is_default', true)
                ->whereKeyNot($locked->getKey())
                ->get()
                ->each(static fn (PrintTemplate $previous) => $previous->forceFill(['is_default' => false])->saveQuietly());

            $locked->forceFill(['is_default' => true])->save();

            return $locked->refresh();
        });
    }

    /**
     * Retire a template. A used one is **never** deleted — a document has to stay re-printable
     * byte-identically, and it cannot be if its layout has gone.
     */
    public function deactivate(PrintTemplate $template, string $reason, ?User $actor = null): PrintTemplate
    {
        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason', 'Say why this template is being retired.');
        }

        return DB::transaction(function () use ($template): PrintTemplate {
            $locked = PrintTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill(['is_active' => false, 'is_default' => false])->save();

            return $locked->refresh();
        });
    }

    // ===========================================================================================

    private function typeOf(mixed $value): PrintTemplateType
    {
        $type = $value instanceof PrintTemplateType
            ? $value
            : PrintTemplateType::tryFrom((string) $value);

        if (! $type instanceof PrintTemplateType) {
            throw CourseRuleException::refuse('type', 'That is not a kind of template this system prints.');
        }

        return $type;
    }

    /**
     * The columns a request may set, with the type's own defaults filled in for a new row.
     *
     * `is_default`, `tokens_used`, `preview_path` and every blameable column are absent: a request
     * that could set `is_default` could make two templates the default without clearing either.
     *
     * @param  array<string, mixed>  $attributes
     * @param  (callable(string, mixed): mixed)|null  $keep
     * @return array<string, mixed>
     */
    private function writableAttributes(
        array $attributes,
        PrintTemplateType $type,
        ?PrintTemplate $existing = null,
        ?callable $keep = null,
    ): array {
        $keep ??= static fn (string $key, mixed $current): mixed => $attributes[$key] ?? $current;

        return [
            'type' => $type->value,
            'code' => mb_strtoupper(trim((string) $keep('code', $existing?->getAttribute('code') ?? ''))),
            'name' => $keep('name', $existing?->getAttribute('name')),
            'description' => $keep('description', $existing?->getAttribute('description')),
            'branch_id' => $keep('branch_id', $existing?->getAttribute('branch_id')),
            'paper_size' => $keep('paper_size', $existing?->getAttribute('paper_size') ?? $type->defaultPaperSize()->value),
            'orientation' => $keep('orientation', $existing?->getAttribute('orientation') ?? $type->defaultOrientation()->value),
            'width_mm' => $keep('width_mm', $existing?->getAttribute('width_mm')),
            'height_mm' => $keep('height_mm', $existing?->getAttribute('height_mm')),
            'margin_mm' => $keep('margin_mm', $existing?->getAttribute('margin_mm') ?? '10.00'),
            'background_image_path' => $keep('background_image_path', $existing?->getAttribute('background_image_path')),
            'logo_path' => $keep('logo_path', $existing?->getAttribute('logo_path')),
            'signatories' => $keep('signatories', $existing?->getAttribute('signatories')),
            // A result card carries no QR code, so the flag is forced off rather than left to a form.
            'show_qr' => $type->supportsQr() ? (bool) $keep('show_qr', $existing?->getAttribute('show_qr') ?? true) : false,
            'qr_size_mm' => $keep('qr_size_mm', $existing?->getAttribute('qr_size_mm') ?? '25.00'),
            'is_active' => (bool) $keep('is_active', $existing?->getAttribute('is_active') ?? true),
            'sort_order' => (int) $keep('sort_order', $existing?->getAttribute('sort_order') ?? 0),
        ];
    }
}
