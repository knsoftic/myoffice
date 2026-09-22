<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Core\TestMailService;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Mail test": uses the saved SMTP settings rather than `.env`, surfaces the failure
 * message on a bad host, and is throttled.
 *
 * The "bad host" is 192.0.2.1 port 2525. The test email refuses loopback addresses and non-mail
 * ports before it connects (the SSRF guard, proven separately below), so the old 127.0.0.1:1 target
 * can no longer reach the transport. 192.0.2.1 is TEST-NET-1 (RFC 5737): reserved for documentation,
 * never routed, not in any range the guard refuses, and — unlike an invented hostname — it never
 * depends on DNS. The connection attempt fails on its own, bounded by a one-second transport timeout,
 * so the transport's message is deterministic. The environment is pointed somewhere else entirely
 * before every send, so a test that passes proves the saved row was used and not the configuration
 * the process booted with.
 */
final class SettingsMailTestTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const PASSWORD = 'Never-In-A-Response-91c7!';

    /** TEST-NET-1 (RFC 5737): allowed by the guard, never reachable. */
    private const UNREACHABLE_HOST = '192.0.2.1';

    /**
     * The two sentences the application produces when it has **no** real reason to report — one from
     * `TestMailService` when the transport's message is empty, one from `SettingsController` when
     * something unexpected escapes. A failure message that is neither of these carries the transport's
     * own words, whichever way the connection actually failed.
     *
     * @var list<string>
     */
    private const GENERIC_FAILURES = [
        'the transport gave no reason',
        'because of an unexpected error',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    #[Test]
    public function a_bad_saved_host_is_attempted_and_its_real_failure_is_reported(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveSmtp($admin, host: self::UNREACHABLE_HOST, port: '2525');
        $this->pretendTheEnvironmentSaysOtherwise();

        $response = $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $message = (string) $response->json('message');

        $this->assertStringContainsString(self::UNREACHABLE_HOST, $message, 'The failure must name the SAVED host — proof the saved settings were used.');

        // The transport's own reason is surfaced, not a generic "failed" — asserted as "not one of the
        // two fallbacks" rather than by pinning a sentence. Symfony reports "Connection could not be
        // established" when the socket is refused and "Connection to ... timed out" when it opens and
        // the handshake stalls, and which one happens is the network's decision: 192.0.2.1 is TEST-NET-1
        // and is supposed to be dark, but plenty of ISPs run a middlebox that accepts any TCP connection.
        // Both sentences are the real reason; neither fallback is.
        foreach (self::GENERIC_FAILURES as $fallback) {
            $this->assertStringNotContainsString($fallback, $message, 'The transport’s own reason is surfaced, not a generic "failed".');
        }
        $this->assertStringNotContainsString('env-only-smtp.invalid', $message, 'The environment’s host must not have been used.');

        $response->assertDontSee(self::PASSWORD, false);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->json('exception'));
    }

    #[Test]
    public function the_screen_renders_the_failure_underneath_without_the_password(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveSmtp($admin, host: self::UNREACHABLE_HOST, port: '2525');
        $this->pretendTheEnvironmentSaysOtherwise();

        $this->actingAs($admin)
            ->from('/admin/settings/mail')
            ->post('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertRedirect('/admin/settings/mail')
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error')
            ->assertSessionHas('settings.mail_test', fn (array $result): bool => $result['ok'] === false && str_contains($result['message'], self::UNREACHABLE_HOST));

        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(session()->all()));

        $this->actingAs($admin)
            ->get('/admin/settings/mail')
            ->assertOk()
            // The saved host rather than a fixed sentence: see the note in the test above on why the
            // transport's wording is the network's decision and not the application's.
            ->assertSee(self::UNREACHABLE_HOST, false)
            ->assertDontSee(self::PASSWORD, false);
    }

    /**
     * The SSRF guard: a saved SMTP endpoint on a non-mail port, or on a loopback, link-local or
     * cloud-metadata address, is refused with the guard's own sentence — the transport is never
     * reached, so its "Connection could not be established" never appears.
     */
    #[Test]
    #[DataProvider('refusedEndpoints')]
    public function an_smtp_endpoint_the_guard_refuses_is_never_connected_to(string $host, string $port, string $reason): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveSmtp($admin, host: $host, port: $port);
        $this->pretendTheEnvironmentSaysOtherwise();

        $message = (string) $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertDontSee(self::PASSWORD, false)
            ->json('message');

        $this->assertStringContainsString($reason, $message);
        $this->assertStringNotContainsString('Connection could not be established', $message, 'The guard refuses before the transport dials.');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedEndpoints(): array
    {
        $address = 'loopback, link-local or cloud-metadata address';

        return [
            'loopback on a mail port' => ['127.0.0.1', '2525', $address],
            'loopback on a non-mail port' => ['127.0.0.1', '1', 'the saved port 1 is refused'],
            'a reachable-looking host on a non-mail port' => [self::UNREACHABLE_HOST, '6379', 'the saved port 6379 is refused'],
            'localhost by name' => ['localhost', '587', $address],
            'this host' => ['0.0.0.0', '25', $address],
            'cloud metadata' => ['169.254.169.254', '25', $address],
            'IPv6 loopback' => ['::1', '25', $address],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1', '25', $address],
        ];
    }

    #[Test]
    public function a_saved_log_transport_is_used_even_when_the_environment_names_another(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', ['mailer' => 'log', 'host' => '']))
            ->assertSessionHasNoErrors();

        $this->pretendTheEnvironmentSaysOtherwise();

        $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '"log"'));

        $this->assertSame('log', config('mail.default'), 'The saved transport is now the live one.');
    }

    #[Test]
    public function an_smtp_transport_with_no_saved_host_is_refused_rather_than_falling_back_to_the_environment(): void
    {
        $admin = $this->createSuperAdmin();

        // Written straight to the row: the screen refuses this pair, but an older release or a
        // console edit can leave it behind, and the test mail must still not "succeed" via .env.
        DB::table('settings')->where('group', 'mail')->where('key', 'mailer')->update(['value' => 'smtp']);
        DB::table('settings')->where('group', 'mail')->where('key', 'host')->update(['value' => null]);

        $this->pretendTheEnvironmentSaysOtherwise();

        $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Save an SMTP host'));
    }

    #[Test]
    public function the_endpoint_allows_three_attempts_a_minute_and_refuses_the_fourth(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', ['mailer' => 'log', 'host' => '']))
            ->assertSessionHasNoErrors();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($admin)
                ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
                ->assertOk();
        }

        $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertStatus(429);

        // One user's limit is not everyone's.
        $other = $this->createSuperAdmin();

        $this->actingAs($other)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk();
    }

    /**
     * An unnamed `throttle:x,y` keys its counter on the user alone, so every such route shares one
     * bucket: a Super Admin who has just loaded a few dashboard cards would be refused the mail test
     * with a bare 429. The mail test's three-a-minute limit has to count mail tests, and only them.
     */
    #[Test]
    public function the_mail_test_is_throttled_on_its_own_limiter_not_the_dashboards(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', ['mailer' => 'log', 'host' => '']))
            ->assertSessionHasNoErrors();

        // An ordinary dashboard visit: several widget refreshes in the same minute.
        for ($refresh = 1; $refresh <= 5; $refresh++) {
            $this->actingAs($admin)->getJson('/admin/dashboard/widget/users_by_status')->assertOk();
        }

        $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertStatus(200);

        // …and the other way round: using up the mail-test allowance does not lock the dashboard.
        $this->actingAs($admin)->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])->assertOk();
        $this->actingAs($admin)->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])->assertOk();
        $this->actingAs($admin)->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])->assertStatus(429);

        $this->actingAs($admin)->getJson('/admin/dashboard/widget/users_by_status')->assertOk();
    }

    #[Test]
    public function the_service_rate_limits_on_its_own_for_callers_that_bypass_the_route(): void
    {
        $admin = $this->createSuperAdmin();
        $service = app(TestMailService::class);

        $this->actingAs($admin)
            ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', ['mailer' => 'log', 'host' => '']))
            ->assertSessionHasNoErrors();

        RateLimiter::clear(TestMailService::RATE_LIMIT_KEY.':user:'.$admin->getKey());

        for ($attempt = 1; $attempt <= TestMailService::MAX_ATTEMPTS; $attempt++) {
            $this->assertTrue($service->send('ops@example.test', $admin)->ok, 'Attempt '.$attempt.' is within the limit.');
        }

        $refused = $service->send('ops@example.test', $admin);

        $this->assertFalse($refused->ok);
        $this->assertStringContainsString('Too many test emails', $refused->message);
    }

    #[Test]
    public function an_admin_without_the_mail_permission_may_not_send_a_test(): void
    {
        $admin = $this->createUserWithRole('Admin');

        $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertForbidden();
    }

    private function saveSmtp(User $admin, string $host, string $port): void
    {
        $this->actingAs($admin)
            ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', [
                'mailer' => 'smtp',
                'host' => $host,
                'port' => $port,
                'username' => 'smtp-user@example.test',
                'password' => self::PASSWORD,
                'encryption' => 'none',
            ]))
            ->assertSessionHasNoErrors();
    }

    /**
     * Make the booted configuration (the stand-in for `.env`) point at a different transport and
     * host, so only a send that re-reads the saved settings can name the saved host.
     *
     * The one-second transport timeout is not a mail setting (refreshMail() leaves it alone); it only
     * bounds how long the attempt on the unroutable TEST-NET address waits before failing.
     */
    private function pretendTheEnvironmentSaysOtherwise(): void
    {
        config([
            'mail.default' => 'array',
            'mail.mailers.smtp.host' => 'env-only-smtp.invalid',
            'mail.mailers.smtp.port' => 2525,
            'mail.mailers.smtp.password' => 'env-password',
            'mail.mailers.smtp.timeout' => 1,
        ]);

        app('mail.manager')->purge('smtp');
        app('mail.manager')->purge('log');
    }
}
