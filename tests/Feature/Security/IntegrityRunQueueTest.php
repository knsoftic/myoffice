<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\IntegrityCheckSuite;
use App\Jobs\Ops\RunIntegritySuite;
use App\Models\Ops\IntegrityCheckRun;
use App\Services\Ops\IntegrityCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * A suite that cannot run inside a web request (`perf:budget`, which sends screens through the HTTP
 * kernel and signs in to do it) is handed to the queue worker when "Run check" is pressed, and its
 * verdict is recorded under the person who pressed it.
 */
final class IntegrityRunQueueTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function only_the_query_budget_suite_needs_the_console(): void
    {
        foreach (IntegrityCheckSuite::cases() as $suite) {
            $this->assertSame($suite === IntegrityCheckSuite::Performance, $suite->requiresConsole(), $suite->value);
        }
    }

    #[Test]
    public function queueing_a_suite_hands_it_to_the_worker_with_the_person_who_asked(): void
    {
        $this->ensureSeeded();
        Queue::fake();

        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        app(IntegrityCheckService::class)->queue(IntegrityCheckSuite::Performance, ['trigger' => 'manual', 'run_uuid' => 'run-1']);

        Queue::assertPushedOn('default', RunIntegritySuite::class, static fn (RunIntegritySuite $job): bool => $job->suite === 'performance'
            && $job->requestedBy === (int) $admin->getKey()
            && ($job->context['run_uuid'] ?? null) === 'run-1');
    }

    #[Test]
    public function the_worker_records_the_verdict_under_that_person_and_leaves_nobody_signed_in(): void
    {
        $this->ensureSeeded();
        $admin = $this->createSuperAdmin();

        (new RunIntegritySuite(IntegrityCheckSuite::Constraints->value, ['trigger' => 'manual'], (int) $admin->getKey()))
            ->handle(app(IntegrityCheckService::class));

        $run = IntegrityCheckRun::query()->latest('id')->firstOrFail();

        $this->assertSame(IntegrityCheckSuite::Constraints, $run->suite);
        $this->assertSame((int) $admin->getKey(), (int) $run->created_by);
        $this->assertFalse(Auth::check(), 'The worker must not stay signed in as the admin after the run.');
    }
}
