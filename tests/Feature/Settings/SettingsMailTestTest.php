<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Core\TestMailService;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * phase-02 §6 "Mail test": uses the saved SMTP settings rather than `.env`, surfaces the failure
 * message on a bad host, and is throttled.
 *
 * The "bad host" is 127.0.0.1 port 1: nothing listens there, the refusal is immediate and local,
 * and — unlike an invented hostname — it never depends on DNS, so the transport's own message is
 * deterministic. The environment is pointed somewhere else entirely before every send, so a test
 * that passes proves the saved row was used and not the configuration the process booted with.
 */
final class SettingsMailTestTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const PASSWORD = 'Never-In-A-Response-91c7!';

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

        $this->saveSmtp($admin, host: '127.0.0.1', port: '1');
        $this->pretendTheEnvironmentSaysOtherwise();

        $response = $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $message = (string) $response->json('message');

        $this->assertStringContainsString('127.0.0.1', $message, 'The failure must name the SAVED host — proof the saved settings were used.');
        $this->assertStringContainsString('Connection could not be established', $message, 'The transport’s own reason is surfaced, not a generic "failed".');
        $this->assertStringNotContainsString('env-only-smtp.invalid', $message, 'The environment’s host must not have been used.');

        $response->assertDontSee(self::PASSWORD, false);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->json('exception'));
    }

    #[Test]
    public function the_screen_renders_the_failure_underneath_without_the_password(): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveSmtp($admin, host: '127.0.0.1', port: '1');
        $this->pretendTheEnvironmentSaysOtherwise();

        $this->actingAs($admin)
            ->from('/admin/settings/mail')
            ->post('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertRedirect('/admin/settings/mail')
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error')
            ->assertSessionHas('settings.mail_test', fn (array $result): bool => $result['ok'] === false && str_contains($result['message'], '127.0.0.1'));

        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode(session()->all()));

        $this->actingAs($admin)
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertSee('Connection could not be established', false)
            ->assertDontSee(self::PASSWORD, false);
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
     * host, so only a send that re-reads the saved settings can reach 127.0.0.1.
     */
    private function pretendTheEnvironmentSaysOtherwise(): void
    {
        config([
            'mail.default' => 'array',
            'mail.mailers.smtp.host' => 'env-only-smtp.invalid',
            'mail.mailers.smtp.port' => 2525,
            'mail.mailers.smtp.password' => 'env-password',
        ]);

        app('mail.manager')->purge('smtp');
        app('mail.manager')->purge('log');
    }
}
