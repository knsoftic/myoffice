<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Core\SettingsService;
use App\Services\Core\TestMailService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * Phase 2 closing pass — the value shapes a settings write refuses, and what the mail test will
 * connect to and say.
 *
 *   · **Header injection.** Every email setting and `mail.from_name` refuse CR, LF and NUL (and the
 *     other ASCII control characters) — including a NUL byte in a quoted pair or in the local part,
 *     which `email:rfc` on its own accepts, so only the explicit control-character rule refuses it.
 *   · **business_hours** is exactly seven known day keys, each exactly `open` / `close` / `closed`.
 *   · **Decimals are never rounded.** More decimals than the field's scale is refused, a trailing dot
 *     is judged as the number it is (so `5000.` is a 5000 % commission rate), and exponent notation is
 *     refused on every decimal field.
 *   · **The mail test** refuses a non-mail port and every loopback, link-local and metadata address,
 *     still lets a private relay be tested, and shows the administrator one redacted sentence — no
 *     exception class, no password, not even a prefix of it.
 *
 * Every refusal is proven on the settings screen AND inside `SettingsService`, and every refusal also
 * proves the stored value did not move. `SettingsMailTestTest` already covers the loopback, metadata and
 * non-mail-port cases it names; the endpoints here are the ones it does not.
 */
final class SettingsInputHardeningTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    private const PASSWORD = 'Never-In-A-Response-91c7!';

    /** TEST-NET-1 (RFC 5737): allowed by the guard, never routed. */
    private const UNREACHABLE_HOST = '192.0.2.1';

    private const REFUSED_ADDRESS = 'loopback, link-local or cloud-metadata address';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | CR / LF / NUL in a mail header value
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_email_setting_refuses_a_control_character_on_the_screen(): void
    {
        $admin = $this->createSuperAdmin();
        $accepted = [];

        foreach ($this->emailFieldsByGroup() as $group => $keys) {
            $payload = $this->browserPayload($admin, $group);

            foreach ($keys as $key) {
                foreach ($this->controlCharacterEmails() as $label => $value) {
                    $accepted = array_merge($accepted, $this->screenAccepts($admin, $group, $key, $value, $payload, $label));
                }
            }
        }

        $this->assertSame([], $accepted, "The settings screen accepted an email carrying a control character:\n".implode("\n", $accepted));
    }

    #[Test]
    public function every_email_setting_refuses_a_control_character_in_the_service(): void
    {
        $admin = $this->createSuperAdmin();
        $accepted = [];

        foreach ($this->emailFieldsByGroup() as $group => $keys) {
            foreach ($keys as $key) {
                foreach ($this->controlCharacterEmails() as $label => $value) {
                    $accepted = array_merge($accepted, $this->serviceAccepts($admin, $group, $key, $value, $label));
                }
            }
        }

        $this->assertSame([], $accepted, "SettingsService accepted an email carrying a control character:\n".implode("\n", $accepted));
    }

    #[Test]
    public function the_from_name_refuses_a_control_character_on_the_screen_and_in_the_service(): void
    {
        $admin = $this->createSuperAdmin();
        $payload = $this->browserPayload($admin, 'mail');
        $accepted = [];

        foreach ($this->controlCharacterNames() as $label => $value) {
            $accepted = array_merge(
                $accepted,
                $this->screenAccepts($admin, 'mail', 'from_name', $value, $payload, $label),
                $this->serviceAccepts($admin, 'mail', 'from_name', $value, $label),
            );
        }

        $this->assertSame([], $accepted, "mail.from_name accepted a control character:\n".implode("\n", $accepted));
    }

    #[Test]
    public function a_clean_address_and_a_clean_name_are_still_accepted(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/mail', $this->browserPayload($admin, 'mail', [
                'from_address' => 'no-reply+alerts@example.test',
                'from_name' => 'Acme Learning — Lahore Campus',
                'reply_to' => 'desk@example.test',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('no-reply+alerts@example.test', $this->rawSetting('mail.from_address'));
        $this->assertSame('Acme Learning — Lahore Campus', $this->rawSetting('mail.from_name'));
    }

    /*
    |--------------------------------------------------------------------------
    | business_hours
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedBusinessHours(): array
    {
        $row = ['open' => '09:00', 'close' => '18:00', 'closed' => false];
        $closed = ['open' => null, 'close' => null, 'closed' => true];

        $week = [
            'monday' => $row, 'tuesday' => $row, 'wednesday' => $row, 'thursday' => $row,
            'friday' => $row, 'saturday' => $row, 'sunday' => $closed,
        ];

        $withoutSunday = $week;
        unset($withoutSunday['sunday']);

        return [
            'an eighth, unknown day' => [$week + ['funday' => $row]],
            'an unknown day in place of a real one' => [$withoutSunday + ['someday' => $closed]],
            'a day key in the wrong case' => [['Monday' => $row] + array_diff_key($week, ['monday' => true])],
            'an oversized day key' => [$week + [str_repeat('d', 140000) => $row]],
            'only six days' => [$withoutSunday],
            'a list instead of a map of days' => [array_values($week)],
            'a day that is a string' => [array_replace($week, ['monday' => '09:00-18:00'])],
            'a row with an extra child' => [array_replace($week, ['monday' => $row + ['note' => 'Lunch 1-2']])],
            'a time nested one level too deep' => [array_replace($week, ['monday' => ['open' => ['09:00'], 'close' => '18:00', 'closed' => false]])],
            'an impossible time' => [array_replace($week, ['monday' => ['open' => '25:61', 'close' => '18:00', 'closed' => false]])],
            'a whole value that is not an array' => ['Mon-Fri 9-6'],
        ];
    }

    #[Test]
    #[DataProvider('malformedBusinessHours')]
    public function malformed_business_hours_are_refused_on_the_screen(mixed $hours): void
    {
        $admin = $this->createSuperAdmin();

        $before = $this->rawSetting('contact.business_hours');
        $payload = $this->browserPayload($admin, 'contact', ['business_hours' => $hours]);

        $this->actingAs($admin)
            ->from('/admin/settings/contact')
            ->put('/admin/settings/contact', $payload)
            ->assertRedirect('/admin/settings/contact');

        $keys = array_keys(session('errors')?->getBag('default')->toArray() ?? []);

        $this->assertNotSame(
            [],
            array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'settings.business_hours')),
            'No business_hours error was raised; errors were: '.implode(', ', $keys),
        );
        $this->assertSame($before, $this->rawSetting('contact.business_hours'), 'Malformed business hours must not be stored.');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedBusinessHoursForTheService(): array
    {
        $row = ['open' => '09:00', 'close' => '18:00', 'closed' => false];
        $week = array_fill_keys(SettingsRegistry::WEEKDAYS, $row);

        return self::malformedBusinessHours() + [
            // The screen completes a row the browser sent without its disabled children and reads a
            // checkbox the way a browser posts one; a programmatic caller gets neither, so a short row
            // or a closed flag that is not a boolean is simply the wrong shape.
            'a row missing its closed flag' => [array_replace($week, ['monday' => ['open' => '09:00', 'close' => '18:00']])],
            'a closed flag that is not a boolean' => [array_replace($week, ['monday' => ['open' => '09:00', 'close' => '18:00', 'closed' => 'sometimes']])],
        ];
    }

    #[Test]
    #[DataProvider('malformedBusinessHoursForTheService')]
    public function malformed_business_hours_are_refused_by_the_service(mixed $hours): void
    {
        $admin = $this->createSuperAdmin();
        $before = $this->rawSetting('contact.business_hours');

        try {
            app(SettingsService::class)->update('contact', ['business_hours' => $hours], $admin);
            $this->fail('SettingsService stored malformed business hours.');
        } catch (ValidationException $exception) {
            $this->assertNotSame(
                [],
                array_filter(array_keys($exception->errors()), static fn (string $key): bool => str_starts_with($key, 'business_hours')),
            );
        }

        $this->assertSame($before, $this->rawSetting('contact.business_hours'));
    }

    #[Test]
    public function a_well_formed_week_is_still_accepted(): void
    {
        $admin = $this->createSuperAdmin();

        $week = SettingsRegistry::field('contact.business_hours')['default'];
        $week['saturday'] = ['open' => '11:00', 'close' => '15:30', 'closed' => false];

        app(SettingsService::class)->update('contact', ['business_hours' => $week], $admin);

        $this->assertSame($week, $this->freshSetting('contact.business_hours'));
    }

    /*
    |--------------------------------------------------------------------------
    | Decimals are never rounded
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function tooManyDecimals(): array
    {
        return [
            'minimum payout 1000.005' => ['collaborator', 'minimum_payout', '1000.005'],
            'minimum payout 1000.0050' => ['collaborator', 'minimum_payout', '1000.0050'],
            'minimum payout 0.001' => ['collaborator', 'minimum_payout', '0.001'],
            'student commission rate 12.34567' => ['collaborator', 'default_student_commission_rate', '12.34567'],
            'project commission rate 5.00001' => ['collaborator', 'default_project_commission_rate', '5.00001'],
            'tax rate 17.00001' => ['finance', 'default_tax_rate', '17.00001'],
            'latitude 31.52041234' => ['contact', 'latitude', '31.52041234'],
        ];
    }

    #[Test]
    #[DataProvider('tooManyDecimals')]
    public function more_decimals_than_the_scale_is_refused_on_the_screen_not_rounded(string $group, string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();

        $before = $this->rawSetting($group.'.'.$key);
        $since = $this->lastActivityId();

        $this->actingAs($admin)
            ->from('/admin/settings/'.$group)
            ->put('/admin/settings/'.$group, $this->browserPayload($admin, $group, [$key => $value]))
            ->assertRedirect('/admin/settings/'.$group)
            ->assertSessionHasErrors('settings.'.$key);

        $this->assertSame($before, $this->rawSetting($group.'.'.$key), sprintf('%s.%s = %s was stored (rounded) instead of refused.', $group, $key, $value));
        $this->assertCount(0, $this->settingsActivitySince($since));
    }

    #[Test]
    #[DataProvider('tooManyDecimals')]
    public function more_decimals_than_the_scale_is_refused_by_the_service_not_rounded(string $group, string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();
        $before = $this->rawSetting($group.'.'.$key);

        try {
            app(SettingsService::class)->update($group, [$key => $value], $admin);
            $this->fail(sprintf('SettingsService stored %s.%s = %s.', $group, $key, $value));
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }

        $this->assertSame($before, $this->rawSetting($group.'.'.$key));
    }

    #[Test]
    public function a_programmatic_float_with_too_many_decimals_is_refused_not_rounded(): void
    {
        $admin = $this->createSuperAdmin();
        $before = $this->rawSetting('collaborator.minimum_payout');

        try {
            app(SettingsService::class)->update('collaborator', ['minimum_payout' => 1000.005], $admin);
            $this->fail('SettingsService rounded the float 1000.005 instead of refusing it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('minimum_payout', $exception->errors());
        }

        $this->assertSame($before, $this->rawSetting('collaborator.minimum_payout'));

        // A float that is exactly representable at the scale is not refused over binary noise.
        app(SettingsService::class)->update('collaborator', ['minimum_payout' => 10000000.01], $admin);
        $this->assertSame('10000000.01', $this->rawSetting('collaborator.minimum_payout'));
    }

    #[Test]
    public function an_exact_restatement_is_accepted_and_stored_at_the_scale(): void
    {
        $admin = $this->createSuperAdmin();

        foreach ([['1000.500', '1000.50'], ['2500.', '2500.00'], ['-0', '0.00']] as [$submitted, $stored]) {
            $this->actingAs($admin)
                ->put('/admin/settings/collaborator', $this->browserPayload($admin, 'collaborator', ['minimum_payout' => $submitted]))
                ->assertSessionHasNoErrors();

            $this->assertSame($stored, $this->rawSetting('collaborator.minimum_payout'), $submitted.' is exactly '.$stored.'.');
        }

        app(SettingsService::class)->update('collaborator', ['default_student_commission_rate' => '12.50000000'], $admin);
        $this->assertSame('12.5000', $this->rawSetting('collaborator.default_student_commission_rate'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function trailingDotPercentages(): array
    {
        return [
            'student rate 5000.' => ['default_student_commission_rate', '5000.'],
            'project rate 5000.' => ['default_project_commission_rate', '5000.'],
            'student rate 5000.00000' => ['default_student_commission_rate', '5000.00000'],
            'project rate 100.01' => ['default_project_commission_rate', '100.01'],
        ];
    }

    #[Test]
    #[DataProvider('trailingDotPercentages')]
    public function a_trailing_dot_percentage_above_100_is_refused_on_the_screen_and_in_the_service(string $key, string $value): void
    {
        $admin = $this->createSuperAdmin();

        $typeKey = str_replace('_rate', '_type', $key);
        $this->assertSame('percentage', $this->freshSetting('collaborator.'.$typeKey), 'Precondition: the rate is a percentage.');

        $before = $this->rawSetting('collaborator.'.$key);

        $this->actingAs($admin)
            ->from('/admin/settings/collaborator')
            ->put('/admin/settings/collaborator', $this->browserPayload($admin, 'collaborator', [$key => $value]))
            ->assertSessionHasErrors('settings.'.$key);

        $this->assertSame($before, $this->rawSetting('collaborator.'.$key));

        try {
            app(SettingsService::class)->update('collaborator', [$key => $value], $admin);
            $this->fail(sprintf('SettingsService stored a %s %% commission rate.', $value));
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }

        $this->assertSame($before, $this->rawSetting('collaborator.'.$key));
    }

    #[Test]
    public function a_trailing_dot_percentage_of_exactly_100_is_accepted(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->put('/admin/settings/collaborator', $this->browserPayload($admin, 'collaborator', ['default_student_commission_rate' => '100.']))
            ->assertSessionHasNoErrors();

        $this->assertSame('100.0000', $this->rawSetting('collaborator.default_student_commission_rate'));
    }

    #[Test]
    public function exponent_notation_is_refused_on_every_decimal_field_on_the_screen(): void
    {
        $admin = $this->createSuperAdmin();
        $accepted = [];

        foreach ($this->decimalFieldsByGroup() as $group => $keys) {
            $payload = $this->browserPayload($admin, $group);

            foreach ($keys as $key) {
                foreach ($this->exponentValues() as $value) {
                    $accepted = array_merge($accepted, $this->screenAccepts($admin, $group, $key, $value, $payload, $value));
                }
            }
        }

        $this->assertSame([], $accepted, "The settings screen accepted exponent notation:\n".implode("\n", $accepted));
    }

    #[Test]
    public function exponent_notation_is_refused_on_every_decimal_field_by_the_service(): void
    {
        $admin = $this->createSuperAdmin();
        $accepted = [];

        foreach ($this->decimalFieldsByGroup() as $group => $keys) {
            foreach ($keys as $key) {
                foreach ($this->exponentValues() as $value) {
                    $accepted = array_merge($accepted, $this->serviceAccepts($admin, $group, $key, $value, $value));
                }
            }
        }

        $this->assertSame([], $accepted, "SettingsService accepted exponent notation:\n".implode("\n", $accepted));
    }

    /*
    |--------------------------------------------------------------------------
    | The mail test: what it connects to
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function refusedEndpoints(): array
    {
        return [
            'SSH port on a routable-looking host' => [self::UNREACHABLE_HOST, '22', 'the saved port 22 is refused'],
            'SSH port on loopback' => ['127.0.0.1', '22', 'the saved port 22 is refused'],
            'loopback on the submission port' => ['127.0.0.1', '587', self::REFUSED_ADDRESS],
            'another address inside 127/8' => ['127.10.20.30', '25', self::REFUSED_ADDRESS],
            'bracketed IPv6 loopback' => ['[::1]', '587', self::REFUSED_ADDRESS],
            'IPv6 loopback on implicit TLS' => ['::1', '465', self::REFUSED_ADDRESS],
            'cloud metadata on the submission port' => ['169.254.169.254', '587', self::REFUSED_ADDRESS],
            'a link-local address that is not the metadata endpoint' => ['169.254.10.20', '587', self::REFUSED_ADDRESS],
            'IPv6 link-local' => ['fe80::1', '587', self::REFUSED_ADDRESS],
            'IPv4-mapped metadata address' => ['::ffff:169.254.169.254', '465', self::REFUSED_ADDRESS],
            'Alibaba metadata outside every refused range' => ['100.100.100.200', '587', self::REFUSED_ADDRESS],
            'AWS IPv6 metadata endpoint' => ['fd00:ec2::254', '587', self::REFUSED_ADDRESS],
        ];
    }

    #[Test]
    #[DataProvider('refusedEndpoints')]
    public function the_mail_test_never_builds_a_transport_for_a_refused_endpoint(string $host, string $port, string $reason): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveSmtp($admin, $host, $port);

        $built = false;

        Mail::extend('smtp', static function () use (&$built): AbstractTransport {
            $built = true;

            return self::recordingTransport();
        });

        $response = $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertDontSee(self::PASSWORD, false);

        $this->assertStringContainsString($reason, (string) $response->json('message'));
        $this->assertFalse($built, sprintf('A transport was built for %s:%s — the guard must refuse before anything dials.', $host, $port));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privateRelays(): array
    {
        return [
            '10/8' => ['10.0.0.25'],
            '172.16/12' => ['172.16.5.10'],
            '192.168/16' => ['192.168.1.20'],
        ];
    }

    #[Test]
    #[DataProvider('privateRelays')]
    public function a_private_relay_on_the_submission_port_is_tested_rather_than_refused(string $host): void
    {
        $admin = $this->createSuperAdmin();

        $this->saveSmtp($admin, $host, '587');

        $dialled = null;

        // Stands in for the relay: records the endpoint the real transport would have connected to.
        Mail::extend('smtp', static function (array $config) use (&$dialled): AbstractTransport {
            $dialled = ($config['host'] ?? '?').':'.($config['port'] ?? '?');

            return self::recordingTransport();
        });

        $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Test email sent to ops@example.test through '.$host.':587.');

        $this->assertSame($host.':587', $dialled, 'The saved private relay is the endpoint the transport was built for.');
    }

    /*
    |--------------------------------------------------------------------------
    | The mail test: what it says
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_transport_failure_is_one_redacted_sentence_with_no_class_name_and_no_password(): void
    {
        Exceptions::fake();

        $admin = $this->createSuperAdmin();
        $this->saveSmtp($admin, self::UNREACHABLE_HOST, '2525');

        $this->transportThatFailsWith(sprintf(
            'Failed to authenticate on SMTP server with username "smtp-user@example.test" using the following authenticators: "LOGIN". '
            .'Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Incorrect authentication data for %s"." '
            .'(smtp://smtp-user:%s@%s:2525)',
            self::PASSWORD,
            rawurlencode(self::PASSWORD),
            self::UNREACHABLE_HOST,
        ));

        $response = $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('exception', null);

        $message = (string) $response->json('message');

        $this->assertStringStartsWith('The test email could not be sent: ', $message);
        $this->assertStringContainsString('Incorrect authentication data', $message, 'The transport’s own reason is kept.');
        $this->assertMessageCarriesNoSecretAndNoClass($message);
        $response->assertDontSee(self::PASSWORD, false)->assertDontSee(rawurlencode(self::PASSWORD), false);

        Exceptions::assertReported(TransportException::class);

        // The screen path renders the same result, and nothing on it names the class or the password.
        $this->actingAs($admin)
            ->from('/admin/settings/mail')
            ->post('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertRedirect('/admin/settings/mail');

        $this->actingAs($admin)
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertSee('Incorrect authentication data', false)
            ->assertDontSee('TransportException', false)
            ->assertDontSee(self::PASSWORD, false)
            ->assertDontSee(rawurlencode(self::PASSWORD), false);
    }

    #[Test]
    public function a_cut_that_lands_inside_the_password_leaves_no_prefix_of_it_behind(): void
    {
        Exceptions::fake();

        $admin = $this->createSuperAdmin();
        $this->saveSmtp($admin, self::UNREACHABLE_HOST, '2525');

        // The password straddles the 300-character cut: redacting after cutting would leave "Never-In-A".
        $this->transportThatFailsWith(str_repeat('x', 290).self::PASSWORD.' and then the rest of the server banner');

        $message = (string) $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->json('message');

        $this->assertStringContainsString(str_repeat('x', 290), $message, 'The failing transport’s reply is what is shown.');
        $this->assertMessageCarriesNoSecretAndNoClass($message);
        $this->assertLessThanOrEqual(
            mb_strlen('The test email could not be sent: ') + 301,
            mb_strlen($message),
            'The transport’s reply is cut short before it is shown.',
        );
    }

    #[Test]
    public function a_multi_line_or_empty_failure_is_still_one_plain_sentence(): void
    {
        Exceptions::fake();

        $admin = $this->createSuperAdmin();
        $this->saveSmtp($admin, self::UNREACHABLE_HOST, '2525');

        $this->transportThatFailsWith("421 4.7.0 Try again later\r\n421-Password was ".self::PASSWORD."\0\nBye");

        $multiLine = (string) $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertJsonPath('ok', false)
            ->json('message');

        $this->assertStringContainsString('Try again later', $multiLine);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $multiLine, 'The reply is flattened to one line with no control characters.');
        $this->assertMessageCarriesNoSecretAndNoClass($multiLine);

        $this->transportThatFailsWith('');

        $empty = (string) $this->actingAs($admin)
            ->postJson('/admin/settings/mail/test', ['email' => 'ops@example.test'])
            ->assertJsonPath('ok', false)
            ->assertJsonPath('exception', null)
            ->json('message');

        $this->assertStringContainsString('the transport gave no reason', $empty);
        $this->assertMessageCarriesNoSecretAndNoClass($empty);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, list<string>> group => bare keys of every email-typed setting
     */
    private function emailFieldsByGroup(): array
    {
        $fields = $this->fieldsWhere(static fn (array $field): bool => $field['type'] === SettingsRegistry::TYPE_EMAIL);

        $this->assertArrayHasKey('mail', $fields);
        $this->assertContains('from_address', $fields['mail']);
        $this->assertContains('reply_to', $fields['mail']);
        $this->assertArrayHasKey('contact', $fields);

        return $fields;
    }

    /**
     * @return array<string, list<string>> group => bare keys of every decimal setting
     */
    private function decimalFieldsByGroup(): array
    {
        $fields = $this->fieldsWhere(static fn (array $field): bool => $field['storage'] === 'decimal' && $field['readonly'] !== true);

        $this->assertArrayHasKey('collaborator', $fields);
        $this->assertContains('minimum_payout', $fields['collaborator']);

        return $fields;
    }

    /**
     * @param  callable(array<string, mixed>): bool  $matches
     * @return array<string, list<string>>
     */
    private function fieldsWhere(callable $matches): array
    {
        $found = [];

        foreach (SettingsRegistry::all() as $group => $fields) {
            foreach ($fields as $key => $field) {
                if ($matches($field)) {
                    $found[(string) $group][] = (string) $key;
                }
            }
        }

        return $found;
    }

    /**
     * Addresses carrying CR, LF or NUL. The two NUL shapes (quoted pair, local part) pass `email:rfc` on
     * its own, so they fail only if the explicit control-character rule is in force.
     *
     * @return array<string, string>
     */
    private function controlCharacterEmails(): array
    {
        $values = [];

        foreach (['CR' => "\r", 'LF' => "\n", 'NUL' => "\0"] as $name => $character) {
            $values[$name.' in a quoted pair'] = '"ops\\'.$character.'"@example.test';
            $values[$name.' inside the local part'] = 'ops'.$character.'desk@example.test';
            $values[$name.' inside the domain'] = 'ops@exam'.$character.'ple.test';
            $values[$name.' before an injected header'] = 'ops@example.test'.$character.'Bcc: victim@example.test';
        }

        $values['CRLF before an injected header'] = "ops@example.test\r\nBcc: victim@example.test";

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function controlCharacterNames(): array
    {
        return [
            'CRLF header injection' => "MyOffice\r\nBcc: victim@example.test",
            'bare CR' => "My\rOffice",
            'bare LF' => "My\nOffice",
            'NUL' => "My\0Office",
            'TAB' => "My\tOffice",
            'DEL' => "My\x7FOffice",
        ];
    }

    /**
     * @return list<string>
     */
    private function exponentValues(): array
    {
        return ['1e3', '1E1', '2.5e1', '-1e1'];
    }

    /**
     * PUT one value through the real screen; describe it when it was NOT refused.
     *
     * @param  array<string, mixed>  $payload  the group's rendered form, serialised
     * @return list<string>
     */
    private function screenAccepts(User $admin, string $group, string $key, mixed $value, array $payload, string $label): array
    {
        $before = $this->rawSetting($group.'.'.$key);
        $payload['settings'][$key] = $value;

        $this->actingAs($admin)
            ->from('/admin/settings/'.$group)
            ->put('/admin/settings/'.$group, $payload);

        $errors = session('errors')?->getBag('default');
        $problems = [];

        if ($errors === null || ! $errors->has('settings.'.$key)) {
            $problems[] = sprintf('  screen  %s.%s (%s): no error', $group, $key, $label);
        }

        if ($this->rawSetting($group.'.'.$key) !== $before) {
            $problems[] = sprintf('  screen  %s.%s (%s): the stored value moved', $group, $key, $label);
        }

        return $problems;
    }

    /**
     * Save one value through SettingsService; describe it when it was NOT refused.
     *
     * @return list<string>
     */
    private function serviceAccepts(User $admin, string $group, string $key, mixed $value, string $label): array
    {
        $before = $this->rawSetting($group.'.'.$key);
        $problems = [];

        try {
            app(SettingsService::class)->update($group, [$key => $value], $admin);
            $problems[] = sprintf('  service %s.%s (%s): accepted', $group, $key, $label);
        } catch (ValidationException $exception) {
            if (! array_key_exists($key, $exception->errors())) {
                $problems[] = sprintf('  service %s.%s (%s): refused under another key (%s)', $group, $key, $label, implode(', ', array_keys($exception->errors())));
            }
        }

        if ($this->rawSetting($group.'.'.$key) !== $before) {
            $problems[] = sprintf('  service %s.%s (%s): the stored value moved', $group, $key, $label);
        }

        return $problems;
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

        $this->assertSame($host, $this->freshSetting('mail.host'));

        // The environment points somewhere else, so only the saved row can be what the test uses.
        config(['mail.default' => 'array', 'mail.mailers.smtp.host' => 'env-only-smtp.invalid']);
    }

    private function transportThatFailsWith(string $failure): void
    {
        Mail::extend('smtp', static fn (): AbstractTransport => new class($failure) extends AbstractTransport
        {
            public function __construct(private readonly string $failure)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException($this->failure);
            }

            public function __toString(): string
            {
                return 'failing-smtp://';
            }
        });
    }

    private static function recordingTransport(): AbstractTransport
    {
        return new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                // Accepted and discarded.
            }

            public function __toString(): string
            {
                return 'recording-smtp://';
            }
        };
    }

    private function assertMessageCarriesNoSecretAndNoClass(string $message): void
    {
        $this->assertStringNotContainsString(self::PASSWORD, $message);
        $this->assertStringNotContainsString(rawurlencode(self::PASSWORD), $message);

        for ($length = 4; $length < strlen(self::PASSWORD); $length++) {
            $this->assertStringNotContainsString(
                substr(self::PASSWORD, 0, $length),
                $message,
                sprintf('The message carries the first %d characters of the password.', $length),
            );
        }

        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]+Exception\b/', $message, 'No exception class name reaches the administrator.');
        $this->assertStringNotContainsString('Symfony\\', $message);
        $this->assertStringNotContainsString(TestMailService::class, $message);
    }
}
