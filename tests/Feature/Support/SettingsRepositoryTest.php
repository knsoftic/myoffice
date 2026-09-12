<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\SettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The settings read contract (phase-01 §3).
 *
 * §3 fixes `get($group, $key, $default)`; the implementation grew up around a single dotted key,
 * `get('company.name')`. Called the contracted way it used to treat 'company' as the key, miss it
 * and hand back the *second argument* as the default — so `get('mail', 'host')` returned the
 * literal string 'host' with no exception and no log, and a Phase-2 author following the contract
 * would have fed that into the mailer (or, for a rate or a currency, into the ledger).
 *
 * Both shapes are first-class now, and the rule that decides between them is what these tests pin
 * down, together with the guarantees Phase 2's settings UI is going to lean on: encrypted values
 * decrypt on read and are never cached in clear text, and a missing table degrades to defaults
 * instead of throwing.
 */
final class SettingsRepositoryTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        $this->settings = app(SettingsRepository::class);
        $this->settings->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | The two call shapes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_contracted_group_and_key_form_returns_the_value(): void
    {
        $dotted = $this->settings->get('company.name');

        $this->assertIsString($dotted);
        $this->assertNotSame('', $dotted);

        // The exact failure the finding describes: the key came back as the value.
        $this->assertNotSame('name', $this->settings->get('company', 'name'));
        $this->assertSame($dotted, $this->settings->get('company', 'name'));
        $this->assertSame($dotted, $this->settings->get('company', 'name', 'a default nobody needs'));
    }

    #[Test]
    public function the_dotted_form_keeps_working_exactly_as_before(): void
    {
        $this->assertSame(
            $this->settings->get('company.name'),
            setting('company.name'),
            'The setting() helper forwards to the same reader.'
        );

        $this->assertSame('fallback', $this->settings->get('company.no_such_key', 'fallback'));
        $this->assertNull($this->settings->get('company.no_such_key'));

        // A two-part key keeps its tail: group `mail`, key `smtp.host`.
        $this->settings->set('mail.smtp.host', 'mail.example.test');

        $this->assertSame('mail.example.test', $this->settings->get('mail.smtp.host'));
        $this->assertSame('mail.example.test', $this->settings->get('mail', 'smtp.host'));
    }

    /**
     * A second argument is only read as a key when the first argument names a group that exists.
     * Everything else — a dotted first argument, an unknown first argument, a non-string second
     * argument — keeps the "key, default" reading every current caller relies on.
     */
    #[Test]
    public function a_default_is_not_mistaken_for_a_key(): void
    {
        // 'company.support_email' is dotted, so the second argument is a default, even though it is
        // a string (this is a real call in layouts/partials/footer.blade.php).
        $this->assertSame(
            'nobody@myoffice.test',
            $this->settings->get('company.no_such_key', 'nobody@myoffice.test')
        );

        // No such group: 'dark' is the default, not a key.
        $this->assertSame('dark', $this->settings->get('no_such_group_at_all', 'dark'));

        // A non-string second argument is always a default.
        $this->assertSame(42, $this->settings->get('company', 42));
        $this->assertNull($this->settings->get('company'));
    }

    #[Test]
    public function a_missing_key_inside_a_real_group_returns_null_not_the_key(): void
    {
        $this->assertTrue($this->settings->hasGroup('mail'));
        $this->assertFalse($this->settings->has('mail', 'no_such_key'));

        $this->assertNull(
            $this->settings->get('mail', 'no_such_key'),
            'A key that does not exist must read as "no value", never as its own name.'
        );

        $this->assertSame('smtp.example.test', $this->settings->get('mail', 'no_such_key', 'smtp.example.test'));
    }

    #[Test]
    public function has_accepts_both_shapes(): void
    {
        $this->assertTrue($this->settings->has('company.name'));
        $this->assertTrue($this->settings->has('company', 'name'));
        $this->assertFalse($this->settings->has('company.no_such_key'));
        $this->assertFalse($this->settings->has('company', 'no_such_key'));
        $this->assertFalse($this->settings->has('no_such_group', 'name'));
    }

    /*
    |--------------------------------------------------------------------------
    | Types, groups and writes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function values_are_cast_by_their_type_through_both_shapes(): void
    {
        $this->settings->set('zz_contract.flag', true, ['type' => 'boolean']);
        $this->settings->set('zz_contract.count', 7, ['type' => 'integer']);
        $this->settings->set('zz_contract.rate', '12.50', ['type' => 'decimal']);
        $this->settings->set('zz_contract.list', ['a', 'b'], ['type' => 'json']);

        foreach ([true, false] as $useGroupForm) {
            $read = fn (string $key): mixed => $useGroupForm
                ? $this->settings->get('zz_contract', $key)
                : $this->settings->get('zz_contract.'.$key);

            $this->assertTrue($read('flag'));
            $this->assertSame(7, $read('count'));
            $this->assertSame('12.50', $read('rate'), 'Money never meets a float (CLAUDE.md §1.4).');
            $this->assertSame(['a', 'b'], $read('list'));
        }
    }

    #[Test]
    public function an_encrypted_value_decrypts_on_read_and_is_never_cached_in_clear_text(): void
    {
        $secret = 'sup3r-s3cret-smtp-p4ss';

        $this->settings->set('mail.password', $secret, ['is_encrypted' => true]);

        $this->assertSame($secret, $this->settings->get('mail.password'));
        $this->assertSame($secret, $this->settings->get('mail', 'password'));
        $this->assertSame($secret, $this->settings->get('mail', 'password', 'ignored'));

        $stored = (string) DB::table('settings')->where('group', 'mail')->where('key', 'password')->value('value');

        $this->assertNotSame($secret, $stored, 'The column must hold ciphertext.');
        $this->assertStringNotContainsString($secret, $stored);

        // The cached payload holds raw column values, so the cache cannot leak the secret either.
        $cached = Cache::get(SettingsRepository::CACHE_KEY);

        $this->assertIsArray($cached);
        $this->assertArrayHasKey('mail.password', $cached);
        $this->assertSame($stored, $cached['mail.password']['value']);
    }

    #[Test]
    public function all_and_groups_still_describe_the_payload(): void
    {
        $all = $this->settings->all();
        $group = $this->settings->all('company');

        $this->assertArrayHasKey('company.name', $all);
        $this->assertArrayHasKey('name', $group);
        $this->assertSame($all['company.name'], $group['name']);

        $this->assertContains('company', $this->settings->groups());
        $this->assertTrue($this->settings->hasGroup('company'));
        $this->assertFalse($this->settings->hasGroup('no_such_group'));
    }

    /*
    |--------------------------------------------------------------------------
    | Install safety
    |--------------------------------------------------------------------------
    */

    /**
     * Before the `settings` table exists — a fresh clone, a half-run install — `setting()` is still
     * called while Blade renders and while artisan boots, so a read must degrade to its default
     * rather than throw. The failing query is simulated, because dropping the table would be DDL:
     * MySQL commits the surrounding transaction for that and the rest of the suite would lose its
     * rollback.
     */
    #[Test]
    public function a_missing_settings_table_degrades_to_defaults(): void
    {
        $repository = new SettingsRepository;

        Cache::forget(SettingsRepository::CACHE_KEY);

        DB::shouldReceive('table')->andThrow(new QueryException(
            'mysql',
            'select * from `settings`',
            [],
            new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'settings' doesn't exist"),
        ));

        $this->assertNull($repository->get('company.name'));
        $this->assertSame('My Office', $repository->get('company.name', 'My Office'));
        $this->assertSame('My Office', $repository->get('company', 'name', 'My Office'));
        $this->assertFalse($repository->has('company.name'));
        $this->assertFalse($repository->hasGroup('company'));
        $this->assertSame([], $repository->all());
        $this->assertSame([], $repository->groups());
    }
}
