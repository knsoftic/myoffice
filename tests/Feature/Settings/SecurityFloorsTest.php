<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Auth\PasswordPolicy;
use App\Services\Core\SettingsService;
use App\Support\ConfigureFromSettings;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * Phase 2 closing pass — the Security group's hard server floors.
 *
 * A security setting may TIGHTEN a Phase 1 protection, never loosen it. Two locks hold that line and
 * both are proven here by what the application does, not by a status code:
 *
 *   1. **the write paths refuse** a value outside the floor — the settings screen and
 *      `SettingsService` alike, with nothing stored and nothing logged;
 *   2. **every reader clamps** a stored value that got past the first lock anyway (raw SQL, an older
 *      release, a console edit): the sign-in throttle (`LoginRequest`), the lockout length, the session
 *      idle timeout (`ConfigureFromSettings::applySecurity()`), the password length (`PasswordPolicy`)
 *      and password expiry (`EnsureUserIsActive`).
 *
 * The raw-SQL rows are written with `DB::table('settings')`, deliberately bypassing every guard, which
 * is exactly the situation the second lock exists for.
 */
final class SecurityFloorsTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const CHANGE_PASSWORD_SCREEN = '/account/password';

    private const EXPIRED_MESSAGE = 'Your password has expired. Choose a new one to continue.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Lock 1: the write paths refuse a value outside the floor
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outOfRangeValues(): array
    {
        return [
            'login_max_attempts above 10' => ['login_max_attempts', '11'],
            'login_max_attempts of 20' => ['login_max_attempts', '20'],
            'login_max_attempts below 3' => ['login_max_attempts', '2'],
            'lockout_minutes below 5' => ['lockout_minutes', '4'],
            'lockout_minutes of 1' => ['lockout_minutes', '1'],
            'lockout_minutes of 0' => ['lockout_minutes', '0'],
            'lockout_minutes above a day' => ['lockout_minutes', '1441'],
            'session_lifetime above 1440' => ['session_lifetime', '1441'],
            'session_lifetime of 30 days' => ['session_lifetime', '43200'],
            'session_lifetime below 15' => ['session_lifetime', '14'],
            'password_min_length below 10' => ['password_min_length', '9'],
            'password_min_length of 6' => ['password_min_length', '6'],
            'password_min_length above 64' => ['password_min_length', '65'],
            'force_password_change_days negative' => ['force_password_change_days', '-1'],
            'force_password_change_days above a year' => ['force_password_change_days', '366'],
        ];
    }

    #[Test]
    #[DataProvider('outOfRangeValues')]
    public function the_security_screen_refuses_a_value_outside_the_floor(string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();

        $before = $this->rawSetting('security.'.$key);
        $payload = $this->browserPayload($admin, 'security', [$key => $value]);
        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->from('/admin/settings/security')
            ->put('/admin/settings/security', $payload)
            ->assertRedirect('/admin/settings/security')
            ->assertSessionHasErrors('settings.'.$key);

        $this->assertSame($before, $this->rawSetting('security.'.$key), sprintf('security.%s = %s must not be stored.', $key, $value));
        $this->assertCount(0, $this->settingsActivitySince($since), 'A refused save writes no activity.');
    }

    #[Test]
    #[DataProvider('outOfRangeValues')]
    public function the_settings_service_refuses_the_same_value_on_its_own(string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();
        $before = $this->rawSetting('security.'.$key);

        try {
            app(SettingsService::class)->update('security', [$key => $value], $admin);
            $this->fail(sprintf('SettingsService stored security.%s = %s, outside the server floor.', $key, $value));
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }

        $this->assertSame($before, $this->rawSetting('security.'.$key));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function edgeOfTheFloorValues(): array
    {
        return [
            'login_max_attempts of 10' => ['login_max_attempts', '10'],
            'login_max_attempts of 3' => ['login_max_attempts', '3'],
            'lockout_minutes of 5' => ['lockout_minutes', '5'],
            'lockout_minutes of a day' => ['lockout_minutes', '1440'],
            'session_lifetime of a day' => ['session_lifetime', '1440'],
            'session_lifetime of 15' => ['session_lifetime', '15'],
            'password_min_length of 10' => ['password_min_length', '10'],
            'force_password_change_days of 0' => ['force_password_change_days', '0'],
            'force_password_change_days of a year' => ['force_password_change_days', '365'],
        ];
    }

    #[Test]
    #[DataProvider('edgeOfTheFloorValues')]
    public function the_edge_of_each_floor_is_still_accepted(string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/security', $this->browserPayload($admin, 'security', [$key => $value]))
            ->assertSessionHasNoErrors();

        $this->assertSame($value, $this->rawSetting('security.'.$key), 'The floor refuses what is outside it, not the boundary itself.');
    }

    /*
    |--------------------------------------------------------------------------
    | Lock 2: the sign-in throttle clamps a stored attempt count
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function storedAttemptCounts(): array
    {
        return [
            'a raw 20 still locks after the 10th failure' => ['20', SettingsRegistry::LOGIN_MAX_ATTEMPTS_MAX],
            'a raw 11 still locks after the 10th failure' => ['11', SettingsRegistry::LOGIN_MAX_ATTEMPTS_MAX],
            'a raw 1 still allows 3 attempts' => ['1', SettingsRegistry::LOGIN_MAX_ATTEMPTS_MIN],
            'a raw 7 inside the floor is honoured' => ['7', 7],
        ];
    }

    #[Test]
    #[DataProvider('storedAttemptCounts')]
    public function a_stored_attempt_count_outside_the_floor_is_clamped_by_the_sign_in_form(string $stored, int $effective): void
    {
        $this->putRaw('security.login_max_attempts', $stored);

        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= $effective; $attempt++) {
            $this->from('/login')
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');

            $this->assertSame(
                trans('auth.failed'),
                (string) session('errors')->first('email'),
                sprintf('With %s stored, failure %d must still be answered by the credentials check.', $stored, $attempt),
            );
        }

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many login attempts',
            (string) session('errors')->first('email'),
            sprintf('With %s stored, attempt %d must be refused by the throttle.', $stored, $effective + 1),
        );

        // Locked means locked: the right password does not get through either.
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Lock 2: the lockout length is never shorter than five minutes
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_raw_one_minute_lockout_is_clamped_to_five_minutes(): void
    {
        $this->putRaw('security.lockout_minutes', '1');

        $user = User::factory()->create();
        $attempts = $this->effectiveAttempts();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $message = (string) session('errors')->first('email');
        $this->assertStringContainsString('Too many login attempts', $message);

        $seconds = RateLimiter::availableIn($this->throttleKey((string) $user->email));

        $this->assertGreaterThan(60, $seconds, 'A stored lockout of 1 minute must not be the lockout applied.');
        $this->assertLessThanOrEqual(SettingsRegistry::LOCKOUT_MINUTES_MIN * 60, $seconds);

        // Two minutes on — past the stored one minute, inside the five-minute floor: still locked.
        $this->travel(2)->minutes();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();
        $this->assertStringContainsString('Too many login attempts', (string) session('errors')->first('email'));

        // Past the floor the lock lifts on its own, so the clamp is a floor and not a permanent ban.
        $this->travel(4)->minutes();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_raw_lockout_above_a_day_is_clamped_to_a_day(): void
    {
        $this->putRaw('security.lockout_minutes', '100000');

        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= $this->effectiveAttempts(); $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $seconds = RateLimiter::availableIn($this->throttleKey((string) $user->email));

        $this->assertGreaterThan((SettingsRegistry::LOCKOUT_MINUTES_MAX - 1) * 60, $seconds);
        $this->assertLessThanOrEqual(SettingsRegistry::LOCKOUT_MINUTES_MAX * 60, $seconds);
    }

    /*
    |--------------------------------------------------------------------------
    | Lock 2: the session idle timeout is never longer than a day
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function storedSessionLifetimes(): array
    {
        return [
            'a raw 30 days is applied as a day' => ['43200', SettingsRegistry::SESSION_LIFETIME_MAX],
            'a raw 1441 is applied as a day' => ['1441', SettingsRegistry::SESSION_LIFETIME_MAX],
            'a raw 1 minute is applied as 15' => ['1', SettingsRegistry::SESSION_LIFETIME_MIN],
            'a raw 30 inside the floor is honoured' => ['30', 30],
        ];
    }

    #[Test]
    #[DataProvider('storedSessionLifetimes')]
    public function a_stored_session_lifetime_outside_the_floor_is_clamped_when_applied(string $stored, int $effective): void
    {
        $this->putRaw('security.session_lifetime', $stored);

        // What AppServiceProvider::boot() runs at the start of every process.
        ConfigureFromSettings::apply();

        $this->assertSame($effective, config('session.lifetime'));

        // …and what a browser is actually handed: the session cookie expires on the clamped lifetime.
        $now = Carbon::now();
        $cookie = $this->sessionCookie($this->get('/login')->assertOk()->headers->getCookies());

        $this->assertEqualsWithDelta(
            $now->copy()->addMinutes($effective)->getTimestamp(),
            $cookie->getExpiresTime(),
            90,
            sprintf('With %s stored, the session cookie must live %d minutes.', $stored, $effective),
        );
    }

    #[Test]
    public function a_settings_save_re_applies_the_clamped_lifetime_too(): void
    {
        $admin = $this->createSuperAdmin();

        ConfigureFromSettings::apply();
        $this->putRaw('security.session_lifetime', '43200');

        // Any successful save re-applies the runtime configuration; it must clamp just like boot does.
        app(SettingsService::class)->update('company', ['name' => 'Re-applies The Configuration Ltd'], $admin);

        $this->assertSame(SettingsRegistry::SESSION_LIFETIME_MAX, config('session.lifetime'));
    }

    /*
    |--------------------------------------------------------------------------
    | Lock 2: the password length can be raised, never lowered below 10
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_raw_minimum_below_ten_cannot_weaken_the_password_policy(): void
    {
        $this->withoutCompromisedPasswordCheck();
        $this->putRaw('security.password_min_length', '6');

        $this->assertSame(PasswordPolicy::MIN_LENGTH, PasswordPolicy::minLength());

        $user = User::factory()->create();

        // Nine characters, meeting every other rule: long enough for the stored 6, too short for the floor.
        $this->changePassword($user, 'Ab1!cdefg')->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password), 'A refused change must leave the password alone.');

        // Ten characters is the floor and is accepted.
        $this->changePassword($user, 'Ab1!cdefgh')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Ab1!cdefgh', (string) $user->fresh()->password));
    }

    #[Test]
    public function a_raw_minimum_of_zero_or_garbage_still_means_ten(): void
    {
        $this->withoutCompromisedPasswordCheck();

        foreach (['0', '-50', 'abc', ''] as $stored) {
            $this->putRaw('security.password_min_length', $stored);

            $this->assertSame(PasswordPolicy::MIN_LENGTH, PasswordPolicy::minLength(), sprintf('A stored %s must read as the floor.', var_export($stored, true)));

            $user = User::factory()->create();

            $this->changePassword($user, 'Ab1!cdefg')->assertSessionHasErrors('password');
        }
    }

    #[Test]
    public function a_minimum_above_ten_tightens_the_policy(): void
    {
        $this->withoutCompromisedPasswordCheck();

        app(SettingsService::class)->update('security', ['password_min_length' => 14], $this->createSuperAdmin());

        $user = User::factory()->create();

        // Twelve characters: enough for the floor, not for what the administrator asked for.
        $this->changePassword($user, 'Ab1!cdefghij')->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', (string) $user->fresh()->password));

        $this->changePassword($user, 'Ab1!cdefghijkl')->assertSessionHasNoErrors();
    }

    /*
    |--------------------------------------------------------------------------
    | Password expiry
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_password_older_than_the_policy_is_sent_to_the_change_password_screen(): void
    {
        $this->setPasswordExpiryThroughTheService(30);

        $expired = $this->adminWhosePasswordChanged(Carbon::now()->subDays(31));
        $fresh = $this->adminWhosePasswordChanged(Carbon::now()->subDays(29));

        foreach (['/admin', '/admin/users', '/account/profile'] as $path) {
            $this->actingAs($expired)
                ->get($path)
                ->assertRedirect(self::CHANGE_PASSWORD_SCREEN)
                ->assertSessionHas('toast.message', self::EXPIRED_MESSAGE);
        }

        // The screen itself stays reachable, otherwise the user could never leave.
        $this->actingAs($expired)->get(self::CHANGE_PASSWORD_SCREEN)->assertOk();

        $this->actingAs($fresh)->get('/admin')->assertOk();
    }

    #[Test]
    public function an_account_that_never_changed_its_password_ages_from_its_creation(): void
    {
        $this->setPasswordExpiryThroughTheService(30);

        $old = $this->createUserWithRole('Admin');
        $old->forceFill(['password_changed_at' => null, 'created_at' => Carbon::now()->subDays(45)])->saveQuietly();

        $this->actingAs($old->fresh())->get('/admin')->assertRedirect(self::CHANGE_PASSWORD_SCREEN);
    }

    #[Test]
    public function changing_the_expired_password_releases_the_account(): void
    {
        $this->withoutCompromisedPasswordCheck();
        $this->setPasswordExpiryThroughTheService(30);

        $user = $this->adminWhosePasswordChanged(Carbon::now()->subDays(60));

        $this->actingAs($user)->get('/admin')->assertRedirect(self::CHANGE_PASSWORD_SCREEN);

        $this->changePassword($user, 'Str0ng!Passw0rd')->assertSessionHasNoErrors();

        $this->actingAs($user->fresh())->get('/admin')->assertOk();
    }

    #[Test]
    public function zero_turns_expiry_off(): void
    {
        $this->setPasswordExpiryThroughTheService(30);

        $ancient = $this->adminWhosePasswordChanged(Carbon::now()->subYears(5));

        $this->actingAs($ancient)->get('/admin')->assertRedirect(self::CHANGE_PASSWORD_SCREEN);

        $this->setPasswordExpiryThroughTheService(0);

        $this->actingAs($ancient->fresh())->get('/admin')->assertOk();
    }

    #[Test]
    public function a_raw_expiry_above_a_year_is_clamped_to_a_year(): void
    {
        $this->putRaw('security.force_password_change_days', '9999');

        $overAYear = $this->adminWhosePasswordChanged(Carbon::now()->subDays(SettingsRegistry::FORCE_PASSWORD_CHANGE_DAYS_MAX + 5));
        $underAYear = $this->adminWhosePasswordChanged(Carbon::now()->subDays(300));

        $this->actingAs($overAYear)->get('/admin')->assertRedirect(self::CHANGE_PASSWORD_SCREEN);
        $this->actingAs($underAYear)->get('/admin')->assertOk();
    }

    #[Test]
    public function a_raw_negative_or_garbage_expiry_switches_expiry_off_rather_than_expiring_everyone(): void
    {
        $user = $this->adminWhosePasswordChanged(Carbon::now()->subYears(2));

        foreach (['-30', 'abc'] as $stored) {
            $this->putRaw('security.force_password_change_days', $stored);

            $this->actingAs($user)->get('/admin')->assertOk();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Write a security row the way an older release, a console edit or a raw SQL statement would —
     * past every guard — and drop the cached payload so the next read sees it.
     */
    private function putRaw(string $dotted, string $value): void
    {
        [$group, $key] = explode('.', $dotted, 2);

        $updated = DB::table('settings')->where('group', $group)->where('key', $key)->update(['value' => $value]);

        $this->assertSame(1, $updated, sprintf('The seeded [%s] row must exist.', $dotted));

        app(SettingsRepository::class)->flush();
    }

    private function setPasswordExpiryThroughTheService(int $days): void
    {
        app(SettingsService::class)->update('security', ['force_password_change_days' => $days], $this->createSuperAdmin());

        $this->assertSame($days, $this->freshSetting('security.force_password_change_days'));
    }

    private function adminWhosePasswordChanged(Carbon $at): User
    {
        $user = $this->createUserWithRole('Admin');
        $user->forceFill(['password_changed_at' => $at, 'must_change_password' => false])->saveQuietly();

        return $user->fresh();
    }

    private function changePassword(User $user, string $password): TestResponse
    {
        return $this->actingAs($user)
            ->from(self::CHANGE_PASSWORD_SCREEN)
            ->put('/account/password', [
                'current_password' => 'password',
                'password' => $password,
                'password_confirmation' => $password,
            ]);
    }

    private function effectiveAttempts(): int
    {
        $stored = setting('security.login_max_attempts');

        return max(SettingsRegistry::LOGIN_MAX_ATTEMPTS_MIN, min(SettingsRegistry::LOGIN_MAX_ATTEMPTS_MAX, (int) $stored));
    }

    /**
     * Mirrors LoginRequest::throttleKey().
     */
    private function throttleKey(string $email): string
    {
        return Str::transliterate(Str::lower($email).'|127.0.0.1');
    }

    /**
     * @param  array<int, Cookie>  $cookies
     */
    private function sessionCookie(array $cookies): Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        $this->fail('The response set no session cookie.');
    }
}
