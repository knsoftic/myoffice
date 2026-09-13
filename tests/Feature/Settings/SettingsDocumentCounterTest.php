<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Core\ActionNotAllowedException;
use App\Services\Core\SettingsService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Settings\Concerns\InteractsWithSettingsForms;
use Tests\TestCase;

/**
 * Decision D62: document counters are not settings an administrator can edit.
 *
 * Every `*_next_number` key is readonly in `SettingsRegistry`: displayed for information, never posted
 * by the settings form, refused by `SettingsService`. Only `DocumentNumberService` (Phase 5, D27) ever
 * advances a counter, under a row lock. The failure this rules out is concrete: an administrator opens
 * Settings › Finance while the counter reads 41, invoices 41 and 42 are issued, the administrator
 * changes the tax label and saves — and a form that posted every field would write 41 back, so the
 * next invoice collides with one that already exists.
 *
 * The counter is advanced in these tests by a direct row update, standing in for DocumentNumberService.
 */
final class SettingsDocumentCounterTest extends TestCase
{
    use InteractsWithRbac;
    use InteractsWithSettingsForms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
    }

    #[Test]
    public function every_counter_the_registry_declares_is_readonly(): void
    {
        $counters = $this->counters();

        $this->assertContains('finance.invoice_next_number', $counters);

        foreach ($counters as $counter) {
            $this->assertTrue(SettingsRegistry::field($counter)['readonly'], $counter.' must be readonly (D62).');
        }
    }

    #[Test]
    public function the_finance_screen_shows_the_counter_but_never_posts_it(): void
    {
        $admin = $this->createSuperAdmin();
        $this->advanceCounter(41);

        $html = (string) $this->actingAs($admin)->get('/admin/settings/finance')->assertOk()->getContent();

        $this->assertStringContainsString((string) SettingsRegistry::field('finance.invoice_next_number')['label'], $html, 'The counter is displayed for information.');
        $this->assertMatchesRegularExpression('/\b41\b/', $html, 'The counter shows its current value.');

        $payload = $this->browserPayload($admin, 'finance');

        foreach ($this->counters('finance') as $counter) {
            $this->assertArrayNotHasKey(
                explode('.', $counter, 2)[1],
                $payload['settings'],
                $counter.' is posted by the finance form, so a stale save can roll it back.',
            );
        }
    }

    #[Test]
    public function a_stale_finance_form_saved_after_invoices_were_issued_cannot_roll_the_counter_back(): void
    {
        $admin = $this->createSuperAdmin();
        $this->advanceCounter(41);

        // The administrator opens the screen while the counter reads 41…
        $stale = $this->browserPayload($admin, 'finance', ['tax_label' => 'Sales Tax (GST)']);

        // …DocumentNumberService issues INV-41 and INV-42 meanwhile…
        $this->advanceCounter(43);

        $since = $this->lastActivityId();

        // …and the administrator saves the page they opened before that.
        $this->actingAs($admin)
            ->from('/admin/settings/finance')
            ->put('/admin/settings/finance', $stale)
            ->assertRedirect('/admin/settings/finance')
            ->assertSessionHasNoErrors();

        $this->assertSame('Sales Tax (GST)', $this->rawSetting('finance.tax_label'), 'The edit the administrator made is saved.');
        $this->assertSame('43', $this->rawSetting('finance.invoice_next_number'), 'The counter is never rolled back by a settings save.');
        $this->assertSame(43, $this->freshSetting('finance.invoice_next_number'));

        $keys = $this->settingsActivitySince($since)->map(fn ($row): string => (string) $row->properties['key'])->all();
        $this->assertNotContains('finance.invoice_next_number', $keys);
    }

    #[Test]
    public function posting_a_counter_by_hand_is_refused_on_the_screen(): void
    {
        $admin = $this->createSuperAdmin();
        $this->advanceCounter(43);

        foreach (['41', '43', '9000'] as $value) {
            $payload = $this->browserPayload($admin, 'finance');
            $payload['settings']['invoice_next_number'] = $value;

            $this->actingAs($admin)
                ->from('/admin/settings/finance')
                ->put('/admin/settings/finance', $payload)
                ->assertRedirect('/admin/settings/finance')
                ->assertSessionHasErrors('settings.invoice_next_number');

            $this->assertSame('43', $this->rawSetting('finance.invoice_next_number'), 'Posting '.$value.' moved the counter.');
        }
    }

    #[Test]
    public function the_service_refuses_to_move_a_counter_in_either_direction(): void
    {
        $admin = $this->createSuperAdmin();
        $this->advanceCounter(43);

        foreach ([41, 44] as $value) {
            $since = $this->lastActivityId();

            try {
                app(SettingsService::class)->update('finance', ['invoice_next_number' => $value, 'tax_label' => 'Changed alongside'], $admin);
                $this->fail(sprintf('SettingsService wrote invoice_next_number = %d (D62: only DocumentNumberService may).', $value));
            } catch (ActionNotAllowedException|ValidationException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame('43', $this->rawSetting('finance.invoice_next_number'));
            $this->assertCount(0, $this->settingsActivitySince($since), 'A refused save writes nothing at all — not even the key beside it.');
        }
    }

    #[Test]
    public function resetting_the_finance_group_leaves_the_counter_where_it_is(): void
    {
        $admin = $this->createSuperAdmin();
        $this->advanceCounter(43);

        app(SettingsService::class)->update('finance', ['invoice_prefix' => 'ACME'], $admin);

        $this->actingAs($admin)->post('/admin/settings/finance/reset')->assertRedirect();

        $this->assertSame(SettingsRegistry::field('finance.invoice_prefix')['default'], $this->freshSetting('finance.invoice_prefix'), 'The reset ran.');
        $this->assertSame('43', $this->rawSetting('finance.invoice_next_number'), 'A reset never rewinds a document counter.');
    }

    /**
     * @return list<string>
     */
    private function counters(?string $group = null): array
    {
        return array_values(array_filter(
            SettingsRegistry::keys(),
            static fn (string $key): bool => str_ends_with($key, '_next_number') && ($group === null || str_starts_with($key, $group.'.')),
        ));
    }

    /**
     * What DocumentNumberService does under its row lock — not a settings save.
     */
    private function advanceCounter(int $to): void
    {
        DB::table('settings')
            ->where('group', 'finance')
            ->where('key', 'invoice_next_number')
            ->update(['value' => (string) $to]);

        app(SettingsRepository::class)->flush();
    }
}
