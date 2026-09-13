<?php

declare(strict_types=1);

namespace Tests\Feature\Settings\Concerns;

use App\Models\Activity;
use App\Models\User;
use App\Support\SettingsRepository;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Helpers shared by the Phase 2 settings acceptance tests.
 *
 * The centrepiece is {@see self::browserPayload()}: it renders the real settings screen, finds the
 * group's form and serialises it **the way a browser does** — hidden inputs always, a checkbox
 * only while it is checked, a disabled control never, the selected option of a select (or its
 * first option when none is marked), a textarea's text. That is the difference between testing
 * the validator against a payload the test author imagined and testing it against the payload
 * the screen actually produces; several of the defects these tests pin down only exist in the
 * second.
 *
 * Alpine is not running in a PHPUnit response, so the one piece of client-side state that changes
 * what is posted is emulated explicitly: a business-hours row whose "closed" box is ticked
 * disables its time inputs (`x-bind:disabled="off || …"`).
 *
 * Not named `*Test.php`, so PHPUnit does not try to run it.
 */
trait InteractsWithSettingsForms
{
    /**
     * Render one group's screen as `$actor`, and return exactly what submitting its form would post.
     *
     * @param  array<string, mixed>  $overrides  bare key => value written over what the form holds
     * @param  list<string>  $uncheck  bare boolean keys whose switch the user turned off
     * @param  list<string>  $check  bare boolean keys whose switch the user turned on
     * @return array<string, mixed> the parsed request body (`settings`, `_method`, `_token`)
     */
    protected function browserPayload(
        User $actor,
        string $group,
        array $overrides = [],
        array $uncheck = [],
        array $check = [],
    ): array {
        $html = $this->actingAs($actor)
            ->get('/admin/settings/'.$group)
            ->assertOk()
            ->getContent();

        $pairs = $this->successfulControls((string) $html, '/admin/settings/'.$group, $uncheck, $check);

        $query = implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
            $pairs,
        ));

        parse_str($query, $body);

        foreach ($overrides as $key => $value) {
            $body['settings'][$key] = $value;
        }

        unset($body['_token']);

        return $body;
    }

    /**
     * Name/value pairs a browser would submit for the form posting to `$actionSuffix`.
     *
     * @param  list<string>  $uncheck
     * @param  list<string>  $check
     * @return list<array{0: string, 1: string}>
     */
    protected function successfulControls(string $html, string $actionSuffix, array $uncheck = [], array $check = []): array
    {
        $xpath = $this->xpath($html);
        $form = null;

        foreach ($xpath->query('//form') as $candidate) {
            /** @var DOMElement $candidate */
            $action = (string) $candidate->getAttribute('action');

            if (str_ends_with(rtrim($action, '/'), $actionSuffix) && $xpath->query('.//input[@name="_method"][@value="PUT"]', $candidate)->length === 1) {
                $form = $candidate;

                break;
            }
        }

        $this->assertNotNull($form, sprintf('The settings screen rendered no PUT form posting to %s.', $actionSuffix));

        $pairs = [];

        foreach ($xpath->query('.//input | .//select | .//textarea', $form) as $control) {
            /** @var DOMElement $control */
            $name = (string) $control->getAttribute('name');

            if ($name === '' || $control->hasAttribute('disabled') || $this->disabledByAlpineRow($control)) {
                continue;
            }

            $tag = strtolower($control->tagName);

            if ($tag === 'textarea') {
                $pairs[] = [$name, (string) $control->textContent];

                continue;
            }

            if ($tag === 'select') {
                $selected = [];
                $first = null;

                foreach ($xpath->query('.//option', $control) as $option) {
                    /** @var DOMElement $option */
                    $first ??= (string) $option->getAttribute('value');

                    if ($option->hasAttribute('selected')) {
                        $selected[] = (string) $option->getAttribute('value');
                    }
                }

                if ($selected === [] && ! $control->hasAttribute('multiple') && $first !== null) {
                    $selected = [$first];
                }

                foreach ($selected as $value) {
                    $pairs[] = [$name, $value];
                }

                continue;
            }

            $type = strtolower((string) ($control->getAttribute('type') ?: 'text'));

            if ($type === 'file' || $type === 'submit' || $type === 'button' || $type === 'reset') {
                continue;
            }

            if ($type === 'checkbox' || $type === 'radio') {
                $bare = $this->bareSettingKey($name);
                $checked = $control->hasAttribute('checked');

                if ($bare !== null && in_array($bare, $uncheck, true)) {
                    $checked = false;
                }

                if ($bare !== null && in_array($bare, $check, true)) {
                    $checked = true;
                }

                if ($checked) {
                    $pairs[] = [$name, (string) ($control->hasAttribute('value') ? $control->getAttribute('value') : 'on')];
                }

                continue;
            }

            $pairs[] = [$name, (string) $control->getAttribute('value')];
        }

        return $pairs;
    }

    /**
     * An XPath over a rendered page, tolerant of the HTML5 and Alpine attributes libxml dislikes.
     */
    protected function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /**
     * The raw `settings.value` column, bypassing the model's decryption and the cache.
     */
    protected function rawSetting(string $dotted): ?string
    {
        [$group, $key] = explode('.', $dotted, 2);

        $value = DB::table('settings')->where('group', $group)->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * Read a setting the way every page does, from a freshly flushed repository.
     */
    protected function freshSetting(string $dotted, mixed $default = null): mixed
    {
        app(SettingsRepository::class)->flush();

        return setting($dotted, $default);
    }

    /**
     * The activity rows the settings service wrote after `$afterId`, oldest first.
     *
     * @return Collection<int, Activity>
     */
    protected function settingsActivitySince(int $afterId): Collection
    {
        return Activity::query()
            ->where('id', '>', $afterId)
            ->where('module', 'settings')
            ->orderBy('id')
            ->get();
    }

    protected function lastActivityId(): int
    {
        return (int) (DB::table('activity_log')->max('id') ?? 0);
    }

    /**
     * `settings[maintenance_mode]` => `maintenance_mode`; anything nested deeper or not under
     * `settings` => null.
     */
    private function bareSettingKey(string $name): ?string
    {
        return preg_match('/^settings\[([a-z0-9_]+)\]$/', $name, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Emulate `x-bind:disabled="off || …"` on a business-hours row whose "closed" box is ticked.
     */
    private function disabledByAlpineRow(DOMElement $control): bool
    {
        $binding = (string) $control->getAttribute('x-bind:disabled');

        if (! str_starts_with(trim($binding), 'off')) {
            return false;
        }

        for ($node = $control->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $data = (string) $node->getAttribute('x-data');

            if (preg_match('/off:\s*(true|false)/', $data, $matches) === 1) {
                return $matches[1] === 'true';
            }
        }

        return false;
    }
}
