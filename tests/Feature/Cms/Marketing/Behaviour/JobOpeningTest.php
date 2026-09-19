<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour;

use App\Enums\JobOpeningStatus;
use App\Models\Cms\JobOpening;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cms\Marketing\Behaviour\Concerns\MarketingBehaviourFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-04 §11 tests 39-40 — job openings: salary money and the deadline sweep (§2.18, §6.8).
 *
 *  39. `salary_max` below `salary_min` is refused (compared through `Money`, never a float); both amounts round-trip
 *      as `decimal(15,2)`; `salary_visible = false` renders "Negotiable" and never emits either number;
 *  40. `careers:close-expired` closes an open opening whose deadline was yesterday and stamps `closed_at`, and
 *      leaves an opening without a deadline open.
 */
final class JobOpeningTest extends TestCase
{
    use InteractsWithRbac;
    use MarketingBehaviourFixtures;
    use RefreshDatabase;

    private const MIN = '98765.43';

    private const MAX = '123456.78';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
        $this->setSetting('website.careers_enabled', true);
        $this->actAsSuperAdmin();
    }

    public function test_39_salary_range_is_money_checked_round_trips_and_can_be_hidden(): void
    {
        foreach (['salary_min', 'salary_max'] as $column) {
            $type = DB::selectOne(
                'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['job_openings', $column],
            );
            $this->assertSame('decimal(15,2)', strtolower((string) $type->type), sprintf('%s is a decimal(15,2) money column.', $column));
        }

        $before = JobOpening::withTrashed()->count();

        try {
            $this->makeOpening('Inverted Salary Opening', ['salary_min' => '150000.00', 'salary_max' => '100000.00']);
            $this->fail('A maximum below the minimum must be refused.');
        } catch (ContentRuleException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('salary_max', $exception->errors());
        }

        // Equal bounds are a valid range (Money::compare(min, max) <= 0) — a one-cent difference is not lost to a float.
        $this->assertSame($before, JobOpening::withTrashed()->count(), 'A refused opening stores nothing.');
        $tight = $this->makeOpening('Tight Range Opening', ['salary_min' => '100000.01', 'salary_max' => '100000.01', 'status' => 'draft']);
        $this->assertSame('100000.01', (string) DB::table('job_openings')->where('id', $tight->getKey())->value('salary_max'));

        try {
            $this->makeOpening('One Cent Inverted', ['salary_min' => '100000.02', 'salary_max' => '100000.01', 'status' => 'draft']);
            $this->fail('A one-cent inversion must be refused.');
        } catch (ContentRuleException $exception) {
            $this->assertArrayHasKey('salary_max', $exception->errors());
        }

        $job = $this->makeOpening('Hidden Salary Backend Role', [
            'salary_min' => self::MIN,
            'salary_max' => self::MAX,
            'salary_visible' => false,
        ]);

        $row = DB::table('job_openings')->where('id', $job->getKey())->first();
        $this->assertSame(self::MIN, (string) $row->salary_min);
        $this->assertSame(self::MAX, (string) $row->salary_max);
        $this->assertSame(0, Money::compare(self::MIN, (string) $job->fresh()->salary_min));
        $this->assertSame(0, Money::compare(self::MAX, (string) $job->fresh()->salary_max));

        $this->becomeGuest();
        $this->bumpPublicCache('test 39 hidden salary');

        $pages = [
            'detail' => (string) $this->get(route('site.careers.show', ['jobOpening' => $job->slug]))->assertOk()->getContent(),
            'board' => (string) $this->get(route('site.careers.index'))->assertOk()->getContent(),
        ];

        $this->assertStringContainsString('Negotiable', $pages['detail'], 'A hidden salary reads "Negotiable".');

        foreach ($pages as $page => $html) {
            $this->assertStringContainsString('Hidden Salary Backend Role', $html);

            // Raw HTML (attributes and JSON-LD included), then the visible text with formatting collapsed.
            foreach (['98765', '98,765', '123456', '123,456'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, sprintf('The %s page leaks the hidden salary (%s) in its HTML.', $page, $needle));
            }

            foreach ([money(self::MIN), money(self::MAX)] as $formatted) {
                $this->assertStringNotContainsString($this->squashedText($formatted), $this->squashedText($html), sprintf('The %s page renders a hidden salary.', $page));
            }
        }

        // Visible again: the numbers are rendered through money().
        $this->actAsSuperAdmin();
        $this->openings()->update($job->fresh(), ['salary_visible' => true]);
        $this->becomeGuest();

        $detail = (string) $this->get(route('site.careers.show', ['jobOpening' => $job->slug]))->assertOk()->getContent();
        $this->assertStringContainsString($this->squashedText(money(self::MIN)), $this->squashedText($detail));
        $this->assertStringContainsString($this->squashedText(money(self::MAX)), $this->squashedText($detail));
    }

    public function test_40_close_expired_closes_past_deadlines_and_leaves_open_ended_openings(): void
    {
        $expiring = $this->makeOpening('Deadline Today Opening', ['deadline' => JobOpening::businessToday()]);
        $openEnded = $this->makeOpening('No Deadline Opening');
        $future = $this->makeOpening('Future Deadline Opening', ['deadline' => now()->addDays(10)->toDateString()]);

        $this->assertNull($expiring->fresh()->closed_at);

        // A day later, today's deadline was yesterday.
        $this->travel(1)->days();

        $marker = $this->lastActivityId();

        $this->assertSame(0, Artisan::call('careers:close-expired'));
        $this->assertStringContainsString('closed 1 job opening(s)', Artisan::output());

        $this->assertSame(JobOpeningStatus::Closed, $expiring->fresh()->status);
        $this->assertNotNull($expiring->fresh()->closed_at, 'closed_at is stamped.');
        $this->assertFalse(JobOpening::query()->public()->whereKey($expiring->getKey())->exists());

        $this->assertSame(JobOpeningStatus::Open, $openEnded->fresh()->status, 'An opening without a deadline stays open.');
        $this->assertNull($openEnded->fresh()->closed_at);
        $this->assertSame(JobOpeningStatus::Open, $future->fresh()->status);

        $entries = $this->activitiesSince($marker, 'jobs', 'status_changed');
        $this->assertCount(1, $entries, 'One entry per closed opening.');
        $this->assertSame((int) $expiring->getKey(), (int) $entries->first()->subject_id);

        // Idempotent.
        $this->assertSame(0, Artisan::call('careers:close-expired'));
        $this->assertStringContainsString('closed 0 job opening(s)', Artisan::output());
    }
}
