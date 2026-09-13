<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Ability;
use App\Enums\LoginStatus;
use App\Enums\ModuleGroup;
use App\Enums\PanelType;
use App\Enums\ThemePreference;
use App\Enums\UserStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * The shape every enum in App\Enums has to have (phase-01 §2): string-backed, with `label()`,
 * `color()` returning a Tailwind colour token, and a static `options()` map for select inputs.
 *
 * Views and badge components call these on whatever enum they are handed, so a case added later
 * without a label or with a colour the theme does not define would be a runtime error in a Blade
 * template. This test makes it a test failure instead.
 */
final class EnumContractTest extends TestCase
{
    /**
     * Colour tokens the design system defines (phase-01 §2 names emerald / amber / rose / slate;
     * the brand scale and the remaining Tailwind hues used by the badges complete the list).
     *
     * @var list<string>
     */
    private const COLOUR_TOKENS = [
        'slate', 'brand', 'indigo', 'violet', 'sky', 'cyan', 'teal', 'emerald',
        'amber', 'orange', 'rose', 'red', 'green', 'blue', 'purple', 'pink',
    ];

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function enumProvider(): array
    {
        return [
            'UserStatus' => [UserStatus::class],
            'ThemePreference' => [ThemePreference::class],
            'PanelType' => [PanelType::class],
            'ModuleGroup' => [ModuleGroup::class],
            'Ability' => [Ability::class],
            'LoginStatus' => [LoginStatus::class],
        ];
    }

    /**
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function the_enum_is_string_backed(string $enum): void
    {
        $reflection = new ReflectionEnum($enum);

        $this->assertTrue($reflection->isBacked(), $enum.' must be backed.');
        $this->assertSame('string', (string) $reflection->getBackingType(), $enum.' must be string-backed.');
        $this->assertNotEmpty($enum::cases());
    }

    /**
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function every_case_has_a_non_empty_label(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertNotSame(
                '',
                trim($case->label()),
                sprintf('%s::%s has no label.', $enum, $case->name)
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function every_case_has_a_known_colour_token(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertContains(
                $case->color(),
                self::COLOUR_TOKENS,
                sprintf('%s::%s uses the unknown colour token "%s".', $enum, $case->name, $case->color())
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function options_maps_every_value_to_its_label(string $enum): void
    {
        $options = $enum::options();

        $this->assertCount(count($enum::cases()), $options);

        foreach ($enum::cases() as $case) {
            $this->assertArrayHasKey($case->value, $options);
            $this->assertSame($case->label(), $options[$case->value]);
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function values_lists_the_backing_values_in_declaration_order(string $enum): void
    {
        $this->assertSame(
            array_map(static fn (object $case): string => $case->value, $enum::cases()),
            $enum::values(),
        );
    }

    /**
     * A value stored in a string(32) column, so every case has to fit — and they have to be
     * snake_case, because enum values land in URLs, filters and permission names.
     *
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function every_value_is_a_short_snake_case_token(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $case->value,
                sprintf('%s::%s has a non-snake_case value.', $enum, $case->name)
            );

            $this->assertLessThanOrEqual(
                32,
                strlen($case->value),
                sprintf('%s::%s does not fit a string(32) column.', $enum, $case->name)
            );
        }
    }

    /**
     * @param  class-string  $enum
     */
    #[Test]
    #[DataProvider('enumProvider')]
    public function the_values_are_unique(string $enum): void
    {
        $values = $enum::values();

        $this->assertSame(count($values), count(array_unique($values)));
    }

    /*
    |--------------------------------------------------------------------------
    | Case lists the contract fixes
    |--------------------------------------------------------------------------
    */

    /**
     * The eighteen general abilities of phase-01 §2, in contract order, followed by the
     * narrowly-scoped ones CLAUDE.md §4 allows a single module to declare for one guarded
     * operation. Phase 2 adds the first of those: `settings.edit_mail`, the SMTP carve-out that
     * makes phase-01 §5's "Admin: everything except `settings.edit` of SMTP" enforceable as a
     * permission instead of a role check. `project_payments.link_invoice` (D43 / BT-1) joins the
     * same tail in Phase 10.
     *
     * A narrow ability is an enum case rather than a bare string because
     * PermissionRegistryTest requires every ability of a real module to resolve back through this
     * enum; the tail placement keeps the general list in its contracted order.
     */
    #[Test]
    public function the_ability_enum_holds_exactly_the_contracted_abilities(): void
    {
        $this->assertSame([
            'view_any', 'view', 'create', 'edit', 'delete', 'restore', 'approve', 'reject',
            'assign', 'print', 'export', 'import', 'upload', 'download', 'change_status',
            'view_financial', 'view_reports', 'view_logs',
            'edit_mail',
        ], Ability::values());
    }

    #[Test]
    public function the_module_group_enum_holds_exactly_the_contracted_groups(): void
    {
        $this->assertSame([
            'system', 'software_house', 'hr', 'finance', 'collaborator', 'institute', 'website', 'shared',
        ], ModuleGroup::values());
    }

    #[Test]
    public function the_login_status_enum_holds_exactly_the_contracted_outcomes(): void
    {
        $this->assertSame(['success', 'failed', 'logout', 'blocked'], LoginStatus::values());
    }

    #[Test]
    public function the_theme_preference_enum_holds_exactly_the_contracted_themes(): void
    {
        $this->assertSame(['light', 'dark', 'system'], ThemePreference::values());
    }
}
