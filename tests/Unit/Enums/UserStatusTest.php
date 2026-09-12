<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * App\Enums\UserStatus (phase-01 §2).
 *
 * `canLogin()` is the single rule the whole authentication area asks — LoginRequest, the `active`
 * middleware, `User::canLogin()` and LoginHistoryRecorder all defer to it. It must be true for
 * exactly one case, and stay that way when a case is added later.
 */
final class UserStatusTest extends TestCase
{
    #[Test]
    public function only_an_active_account_may_log_in(): void
    {
        $this->assertTrue(UserStatus::Active->canLogin());

        foreach (UserStatus::cases() as $case) {
            if ($case === UserStatus::Active) {
                continue;
            }

            $this->assertFalse(
                $case->canLogin(),
                sprintf('%s must not be able to authenticate.', $case->value)
            );
        }

        $this->assertCount(
            1,
            array_filter(UserStatus::cases(), static fn (UserStatus $case): bool => $case->canLogin()),
            'Exactly one status may log in.'
        );
    }

    #[Test]
    public function the_contracted_cases_exist_with_the_contracted_values(): void
    {
        $this->assertSame(['active', 'inactive', 'suspended', 'pending'], UserStatus::values());

        $this->assertSame('active', UserStatus::Active->value);
        $this->assertSame('inactive', UserStatus::Inactive->value);
        $this->assertSame('suspended', UserStatus::Suspended->value);
        $this->assertSame('pending', UserStatus::Pending->value);
    }

    /**
     * @return array<string, array{0: UserStatus, 1: string, 2: string}>
     */
    public static function presentationProvider(): array
    {
        return [
            'active' => [UserStatus::Active, 'Active', 'emerald'],
            'inactive' => [UserStatus::Inactive, 'Inactive', 'slate'],
            'suspended' => [UserStatus::Suspended, 'Suspended', 'rose'],
            'pending' => [UserStatus::Pending, 'Pending', 'amber'],
        ];
    }

    #[Test]
    #[DataProvider('presentationProvider')]
    public function each_case_has_a_label_and_a_colour(UserStatus $case, string $label, string $colour): void
    {
        $this->assertSame($label, $case->label());
        $this->assertSame($colour, $case->color());
    }

    #[Test]
    public function options_is_a_value_to_label_map_for_select_inputs(): void
    {
        $this->assertSame(
            [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'suspended' => 'Suspended',
                'pending' => 'Pending',
            ],
            UserStatus::options(),
        );
    }

    #[Test]
    public function an_unknown_stored_value_resolves_to_null_rather_than_throwing(): void
    {
        $this->assertNull(UserStatus::tryFrom('archived'));
        $this->assertNull(UserStatus::tryFrom(''));
        $this->assertSame(UserStatus::Suspended, UserStatus::tryFrom('suspended'));
    }
}
