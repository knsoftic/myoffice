<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SettingsRegistry;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `App\Support\SettingsRegistry` (phase-02 §2) — the one definition of every setting, which drives the
 * seeder, the form and the server-side rules.
 *
 * A pure unit test: the registry is plain arrays with no database, and the rules it hands out are run
 * through a standalone validator factory. Because both the Form Request and `SettingsService` take their
 * rules from here, a value refused at this level is refused on every write path — which is why the
 * value-shape decisions are pinned here as well as in the feature suite:
 *
 *   · D62 — every `*_next_number` document counter is readonly;
 *   · money and rate fields accept only plain decimals (no `1e3`, which bcmath cannot read);
 *   · an email field never accepts a control character (an RFC-folded `CRLF` address breaks every send).
 */
final class SettingsRegistryTest extends TestCase
{
    private const GROUPS = [
        'company', 'branding', 'appearance', 'localization', 'contact', 'social', 'seo',
        'mail', 'website', 'collaborator', 'projects', 'institute', 'finance', 'security', 'crm',
        'maintenance',
    ];

    private const DEFINITION_KEYS = [
        'label', 'type', 'rules', 'default', 'options', 'help', 'placeholder', 'suffix',
        'encrypted', 'public', 'readonly', 'span', 'sort',
    ];

    /** Money amounts and rates: stored as decimal strings and read by `App\Support\Money`. */
    private const MONEY_AND_RATE_KEYS = [
        'collaborator.minimum_payout',
        'collaborator.default_student_commission_rate',
        'collaborator.default_project_commission_rate',
        'finance.default_tax_rate',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    #[Test]
    public function the_sixteen_groups_are_declared_with_their_metadata(): void
    {
        $this->assertSame(self::GROUPS, array_keys(SettingsRegistry::groups()));

        foreach (SettingsRegistry::groups() as $slug => $group) {
            foreach (['label', 'icon', 'description', 'sort', 'permission'] as $key) {
                $this->assertArrayHasKey($key, $group, sprintf('Group [%s] is missing "%s".', $slug, $key));
            }

            $this->assertStringStartsWith('settings.', (string) $group['permission']);
            $this->assertTrue(SettingsRegistry::hasGroup($slug));
            $this->assertNotSame([], SettingsRegistry::fields($slug), sprintf('Group [%s] declares no field.', $slug));
        }

        $this->assertFalse(SettingsRegistry::hasGroup('general'));
    }

    #[Test]
    public function every_field_definition_is_complete_and_well_typed(): void
    {
        foreach (SettingsRegistry::all() as $group => $fields) {
            foreach ($fields as $key => $field) {
                $name = $group.'.'.$key;

                foreach (self::DEFINITION_KEYS as $definitionKey) {
                    $this->assertArrayHasKey($definitionKey, $field, $name.' is missing "'.$definitionKey.'".');
                }

                $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', (string) $key, $name);
                $this->assertContains($field['type'], SettingsRegistry::TYPES, $name.' has an unknown type.');
                $this->assertIsArray($field['rules'], $name);
                $this->assertIsBool($field['encrypted'], $name);
                $this->assertIsBool($field['public'], $name);
                $this->assertIsBool($field['readonly'], $name);
                $this->assertGreaterThanOrEqual(1, $field['span'], $name);
                $this->assertLessThanOrEqual(12, $field['span'], $name);
                $this->assertIsInt($field['sort'], $name);
                $this->assertNotSame('', trim((string) $field['label']), $name.' has no label.');

                if ($field['encrypted']) {
                    $this->assertFalse($field['public'], $name.' is a secret and can never be readable by the public website.');
                }
            }
        }
    }

    #[Test]
    public function lookups_agree_with_the_declaration(): void
    {
        $count = array_sum(array_map('count', SettingsRegistry::all()));

        $this->assertCount($count, SettingsRegistry::keys());
        $this->assertSame(count(SettingsRegistry::keys()), count(array_unique(SettingsRegistry::keys())));

        foreach (SettingsRegistry::keys() as $dotted) {
            $this->assertTrue(SettingsRegistry::has($dotted), $dotted);
            $this->assertNotNull(SettingsRegistry::field($dotted), $dotted);
        }

        $this->assertNull(SettingsRegistry::field('mail.nope'));
        $this->assertNull(SettingsRegistry::field('nope.password'));
        $this->assertFalse(SettingsRegistry::has('company.is_super_admin'));
    }

    #[Test]
    public function rules_for_a_group_can_be_prefixed_for_the_nested_form_payload(): void
    {
        foreach (self::GROUPS as $group) {
            $bare = SettingsRegistry::rulesFor($group);
            $prefixed = SettingsRegistry::rulesFor($group, 'settings');

            $this->assertSame(
                array_map(static fn (string $key): string => 'settings.'.$key, array_map('strval', array_keys($bare))),
                array_map('strval', array_keys($prefixed)),
                $group,
            );
            $this->assertSame(array_values($bare), array_values($prefixed), $group);
        }
    }

    #[Test]
    public function only_the_smtp_password_is_encrypted(): void
    {
        $this->assertSame(['mail.password'], SettingsRegistry::encryptedKeys());
    }

    /**
     * D62: a document counter is advanced by DocumentNumberService under a row lock and by nothing
     * else, so a stale settings form can never roll it back.
     */
    #[Test]
    public function every_document_counter_is_readonly(): void
    {
        $counters = array_values(array_filter(SettingsRegistry::keys(), static fn (string $key): bool => str_ends_with($key, '_next_number')));

        $this->assertContains('finance.invoice_next_number', $counters, 'The finance group declares the invoice counter.');

        foreach ($counters as $counter) {
            $this->assertTrue(
                SettingsRegistry::field($counter)['readonly'],
                sprintf('%s must be readonly in SettingsRegistry (D62): only DocumentNumberService may advance a counter.', $counter),
            );
            $this->assertContains($counter, SettingsRegistry::readonlyKeys());
        }

        $this->assertContains('security.two_factor_enabled', SettingsRegistry::readonlyKeys());
    }

    /**
     * Resetting a group writes these defaults, so every default has to be a value the group's own
     * rules accept — otherwise "reset" stores something the next save refuses.
     */
    #[Test]
    public function every_default_passes_its_own_rules(): void
    {
        $failures = [];

        foreach (self::GROUPS as $group) {
            $rules = SettingsRegistry::rulesFor($group);

            foreach (SettingsRegistry::fields($group) as $key => $field) {
                if (SettingsRegistry::isFileType((string) $field['type']) || $this->needsDatabase($rules, (string) $key)) {
                    continue;
                }

                $subset = array_filter(
                    $rules,
                    static fn (mixed $ruleKey): bool => explode('.', (string) $ruleKey, 2)[0] === (string) $key,
                    ARRAY_FILTER_USE_KEY,
                );

                $validator = $this->validator()->make([$key => $field['default']], $subset);

                if ($validator->fails()) {
                    $failures[] = $group.'.'.$key.' = '.json_encode($field['default']).': '.implode(' ', $validator->errors()->all());
                }
            }
        }

        $this->assertSame([], $failures, "These registry defaults fail their own rules:\n".implode("\n", $failures));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function exponentProvider(): array
    {
        $cases = [];

        foreach (self::MONEY_AND_RATE_KEYS as $key) {
            foreach (['1e3', '1E2', '2.5e1', '1e-2'] as $value) {
                $cases[$key.' = '.$value] = [$key, $value];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('exponentProvider')]
    public function a_money_or_rate_field_refuses_exponent_notation(string $dotted, string $value): void
    {
        [$group, $key] = explode('.', $dotted, 2);

        $rules = array_filter(
            SettingsRegistry::rulesFor($group),
            static fn (mixed $ruleKey): bool => (string) $ruleKey === $key,
            ARRAY_FILTER_USE_KEY,
        );

        $this->assertTrue(
            $this->validator()->make([$key => $value], $rules)->fails(),
            sprintf('%s accepts %s: bcmath cannot read exponent notation, so every Money call on this setting would throw.', $dotted, $value),
        );
    }

    #[Test]
    public function a_money_or_rate_field_accepts_a_plain_decimal(): void
    {
        foreach (self::MONEY_AND_RATE_KEYS as $dotted) {
            [$group, $key] = explode('.', $dotted, 2);

            $rules = array_filter(SettingsRegistry::rulesFor($group), static fn (mixed $ruleKey): bool => (string) $ruleKey === $key, ARRAY_FILTER_USE_KEY);

            foreach (['0', '12.5', '99.99'] as $value) {
                $validator = $this->validator()->make([$key => $value], $rules);

                $this->assertFalse($validator->fails(), $dotted.' = '.$value.': '.implode(' ', $validator->errors()->all()));
            }
        }
    }

    /**
     * Addresses egulias' RFC validation accepts although they carry a CR/LF fold. Once one is saved as
     * the from or reply-to address, Symfony refuses to build the header and every outgoing mail throws.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function foldedEmailProvider(): array
    {
        $cases = [];

        foreach (SettingsRegistry::all() as $group => $fields) {
            foreach ($fields as $key => $field) {
                if ($field['type'] !== SettingsRegistry::TYPE_EMAIL) {
                    continue;
                }

                foreach (['folded local part' => "ops\r\n @example.test", 'folded comment' => "(x\r\n )ops@example.test", 'folded quoted string' => "\"ops\r\n x\"@example.test", 'bare line feed' => "ops\n @example.test"] as $label => $value) {
                    $cases[$group.'.'.$key.' — '.$label] = [$group.'.'.$key, $value];
                }
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('foldedEmailProvider')]
    public function an_email_field_refuses_a_control_character(string $dotted, string $value): void
    {
        [$group, $key] = explode('.', $dotted, 2);

        $rules = array_filter(SettingsRegistry::rulesFor($group), static fn (mixed $ruleKey): bool => (string) $ruleKey === $key, ARRAY_FILTER_USE_KEY);

        $this->assertTrue(
            $this->validator()->make([$key => $value], $rules)->fails(),
            sprintf('%s accepts %s — a CR/LF-folded address that breaks every outgoing mail.', $dotted, json_encode($value)),
        );
    }

    #[Test]
    public function the_tax_rate_is_a_percentage_bounded_at_one_hundred(): void
    {
        $rules = array_filter(SettingsRegistry::rulesFor('finance'), static fn (mixed $ruleKey): bool => (string) $ruleKey === 'default_tax_rate', ARRAY_FILTER_USE_KEY);

        $this->assertTrue($this->validator()->make(['default_tax_rate' => '100.01'], $rules)->fails());
        $this->assertTrue($this->validator()->make(['default_tax_rate' => '-0.01'], $rules)->fails());
        $this->assertFalse($this->validator()->make(['default_tax_rate' => '100'], $rules)->fails());
    }

    #[Test]
    public function the_localization_rules_refuse_what_formatting_cannot_use(): void
    {
        $rules = SettingsRegistry::rulesFor('localization');

        foreach (['currency' => ['XYZ', 'usd', 'BTC'], 'timezone' => ['Mars/Olympus_Mons', 'GMT+5', 'Asia/Lahore'], 'currency_position' => ['middle']] as $key => $values) {
            foreach ($values as $value) {
                $this->assertTrue(
                    $this->validator()->make([$key => $value], [$key => $rules[$key]])->fails(),
                    sprintf('localization.%s accepted %s.', $key, $value),
                );
            }
        }

        $this->assertFalse($this->validator()->make(['timezone' => 'Asia/Karachi'], ['timezone' => $rules['timezone']])->fails());
        $this->assertFalse($this->validator()->make(['currency' => 'PKR'], ['currency' => $rules['currency']])->fails());
    }

    private function validator(): ValidationFactory
    {
        return new ValidationFactory(new Translator(new ArrayLoader, 'en'));
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function needsDatabase(array $rules, string $key): bool
    {
        foreach ((array) ($rules[$key] ?? []) as $rule) {
            if (is_string($rule) && (str_starts_with($rule, 'exists:') || str_starts_with($rule, 'unique:'))) {
                return true;
            }

            if (is_object($rule)) {
                return true;
            }
        }

        return false;
    }
}
