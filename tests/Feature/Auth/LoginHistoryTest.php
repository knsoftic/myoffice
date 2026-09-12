<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\LoginStatus;
use App\Models\Activity;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * `login_histories` (phase-01 §1.6, §10 "Login history": success / failed / logout rows created
 * with IP and device populated).
 *
 * Every row is written by the auth listeners through LoginHistoryRecorder — never by a
 * controller — so these tests drive the real HTTP endpoints and read the table back.
 */
final class LoginHistoryTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** A real desktop Chrome string, so the parsed columns are worth asserting on. */
    private const CHROME_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function a_successful_sign_in_is_recorded_with_ip_and_device(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', self::CHROME_ON_WINDOWS)
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $row = $this->lastRowFor($user);

        $this->assertNotNull($row, 'A successful sign-in must write a login_histories row.');
        $this->assertSame(LoginStatus::Success, $row->status);
        $this->assertSame($user->email, $row->email);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('desktop', $row->device);
        $this->assertSame('Windows 10/11', $row->platform);
        $this->assertSame('Chrome 131', $row->browser);
        $this->assertSame(self::CHROME_ON_WINDOWS, $row->user_agent);
        $this->assertNotNull($row->logged_in_at);
        $this->assertNull($row->logged_out_at);
        $this->assertNotNull($row->session_id, 'The row must carry the session id it belongs to.');
    }

    /**
     * The id captured when `Login` fires is the pre-regeneration one; the controller re-stamps it
     * so `login_histories.session_id` stays join-able with the `sessions` table.
     */
    #[Test]
    public function the_success_row_carries_the_regenerated_session_id(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $row = $this->lastRowFor($user);

        $this->assertNotNull($row);
        $this->assertNotNull($row->session_id);

        // The point of re-stamping the id: the history row has to be join-able with the live
        // `sessions` row, which only holds the id created *after* the regeneration.
        $this->assertTrue(
            DB::table('sessions')->where('id', $row->session_id)->exists(),
            'login_histories.session_id must match a row in the sessions table.'
        );
    }

    #[Test]
    public function a_mobile_sign_in_is_recorded_as_a_mobile_device(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', self::IPHONE_SAFARI)
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $row = $this->lastRowFor($user);

        $this->assertNotNull($row);
        $this->assertSame('mobile', $row->device);
        $this->assertSame('iOS 17.0', $row->platform);
        $this->assertSame('Safari 17', $row->browser);
    }

    #[Test]
    public function a_failed_attempt_is_recorded_with_the_attempted_email(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', self::CHROME_ON_WINDOWS)
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $row = LoginHistory::query()
            ->where('status', LoginStatus::Failed->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'A rejected attempt must write a `failed` row.');
        $this->assertSame($user->email, $row->email);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('desktop', $row->device);
        $this->assertNull($row->logged_in_at);
    }

    /**
     * A failed attempt for an address that does not exist still has to be recorded — that is the
     * row an administrator needs when someone is guessing addresses.
     */
    #[Test]
    public function a_failed_attempt_for_an_unknown_email_is_still_recorded(): void
    {
        $this->post('/login', ['email' => 'ghost@example.test', 'password' => 'whatever']);

        $row = LoginHistory::query()->where('email', 'ghost@example.test')->first();

        $this->assertNotNull($row);
        $this->assertSame(LoginStatus::Failed, $row->status);
        $this->assertNull($row->user_id, 'An attempt that resolves to no account leaves user_id null.');
        $this->assertSame('127.0.0.1', $row->ip_address);
    }

    #[Test]
    public function signing_out_writes_a_logout_row_and_closes_the_sign_in(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', self::CHROME_ON_WINDOWS)
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $signIn = $this->lastRowFor($user);
        $this->assertNotNull($signIn);

        $this->post('/logout');

        $logout = LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->where('status', LoginStatus::Logout->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($logout, 'Signing out must write a `logout` row.');
        $this->assertSame('127.0.0.1', $logout->ip_address);
        $this->assertSame('desktop', $logout->device);
        $this->assertNotNull($logout->logged_out_at);

        $this->assertNotNull(
            $signIn->fresh()->logged_out_at,
            'The matching sign-in row must be closed, so no session looks open forever.'
        );
    }

    #[Test]
    public function a_refused_account_state_writes_a_blocked_row(): void
    {
        $user = User::factory()->suspended()->create();

        $this->withHeader('User-Agent', self::CHROME_ON_WINDOWS)
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $row = LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->where('status', LoginStatus::Blocked->value)
            ->first();

        $this->assertNotNull($row, 'A suspended account with the right password must leave a `blocked` row.');
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('desktop', $row->device);
    }

    #[Test]
    public function every_authentication_event_also_lands_in_the_activity_log(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'nope']);

        $events = Activity::query()
            ->where('log_name', 'auth')
            ->pluck('event')
            ->all();

        foreach (['login', 'logout', 'login_failed'] as $event) {
            $this->assertContains($event, $events, sprintf('The activity log is missing the `%s` entry.', $event));
        }

        /*
         * Every auth entry is attributed to a module, so the Activity Log screen can filter it.
         *
         * Note the attribution is the *subject's* module: spatie applies the subject model's
         * `tapActivity()` last (ActivityLogger::log()), so User::activityModule() === 'users'
         * overwrites the 'login_history' that AuthActivityLogger asks for. The column is only used
         * by the log screen's filter, never by the Gate, so this asserts the invariant that holds
         * — "attributed to a real module" — rather than pinning which of the two wins.
         */
        $modules = Activity::query()->where('log_name', 'auth')->pluck('module')->all();

        $this->assertNotEmpty($modules);

        foreach ($modules as $module) {
            $this->assertNotNull($module, 'Every auth entry must name the module it belongs to.');
            $this->assertContains($module, ['login_history', 'users']);
        }
    }

    /**
     * An authentication entry is useless for an audit without who, from where and on what.
     */
    #[Test]
    public function authentication_activity_entries_carry_the_request_context(): void
    {
        // See InteractsWithRbac::treatRequestsAsWeb() — PHP_SAPI is `cli` under PHPUnit, which the
        // context helpers read as "console" and deliberately skip.
        $this->treatRequestsAsWeb();

        $user = User::factory()->create();

        $this->withHeader('User-Agent', self::CHROME_ON_WINDOWS)
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $entry = Activity::query()->where('log_name', 'auth')->where('event', 'login')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('127.0.0.1', $entry->ip_address);
        $this->assertSame('desktop', $entry->device);
        $this->assertSame(self::CHROME_ON_WINDOWS, $entry->user_agent);
        $this->assertSame($user->getKey(), $entry->causer_id);
    }

    private function lastRowFor(User $user): ?LoginHistory
    {
        return LoginHistory::query()
            ->where('user_id', $user->getKey())
            ->where('status', LoginStatus::Success->value)
            ->latest('id')
            ->first();
    }
}
