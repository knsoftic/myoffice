<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRF-14 — a query that took too long leaves a line behind (phase-24-25 §6.4, §11.7).
 *
 * **This id had no test at all, because its number was being worn by somebody else.** §6.4.1's
 * closing paragraph and PRF-09's own row both call the cache-key scan "PRF-14", while §11.7's id
 * table — the table the acceptance suite is built from — gives PRF-14 to
 * `test_slow_queries_are_logged_not_ignored` and gives the scan to PRF-09. The slice filed the scan
 * under PRF-14, and from that moment the slow-query log was a requirement nothing asserted and
 * nobody could notice, because the id it should have carried already had a method on it. §11.7 is
 * the authority; the numbering contradiction in §6.4.1 is a documentation defect recorded separately.
 *
 * **A slow query with no log line is a performance problem that only the client experiences.** The
 * page that takes nine seconds is reported as "the system is slow" weeks later, by which time the
 * query that did it has been run a hundred thousand more times and nothing anywhere says which one
 * it was. Two things make the line worth having:
 *
 *  - **The route, not the stack trace.** A stack trace names the method; the route names the screen
 *    somebody opened, which is what a report is about and what a digest can group by.
 *  - **The bindings never.** The SQL is the shape of the problem; the bindings are the row it was
 *    about — an email address, a national ID, a salary — and `storage/logs` is the one store in this
 *    system with no access control on it whatsoever. A log that redacts nothing turns a performance
 *    feature into a data-retention one.
 *
 * **One line per request, not one per query.** A screen running the same slow statement forty times
 * would otherwise write forty lines, and the fortieth says nothing the first did not — so "exactly
 * one" is asserted as a number, not implied.
 *
 * The slow query is a real `SLEEP` against MariaDB rather than a faked `QueryExecuted` event,
 * because what is being tested is the wiring: `DB::whenQueryingForLongerThan()` registered at boot
 * (`AppServiceProvider::logSlowQueries()`), against a threshold read from `ops.slow_query_ms`. A
 * dispatched event would prove the handler body works while the registration was missing.
 */
#[Group('perf')]
final class SlowQueryLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A binding the log must not contain.
     *
     * Shaped like the row data that actually flows through this system's bindings, and distinctive
     * enough that a substring search over the whole serialised context cannot match it by accident.
     */
    private const SENSITIVE_BINDING = 'prf14-national-id-3520112345678';

    #[Test]
    public function test_slow_queries_are_logged_not_ignored(): void
    {
        $threshold = (int) setting('ops.slow_query_ms', 250);

        if ($threshold > 2000) {
            $this->markTestSkipped(sprintf(
                'ops.slow_query_ms is %d ms, so provoking the handler would mean sleeping for longer '
                .'than that inside one test. PRF-14 is about the wiring, not about how long a suite '
                .'may block: lower the setting for the test run, or assert this against a fixture '
                .'that is genuinely that slow.',
                $threshold,
            ));
        }

        // Comfortably past the threshold, so a busy machine cannot turn this into a flake in the
        // direction of "no line was written".
        $sleepSeconds = round(($threshold + 250) / 1000, 3);

        // **The query carrying the binding runs first, and that is not an accident.**
        // `Connection::logQuery()` dispatches `QueryExecuted` — which is what the handler listens on
        // — *before* it appends the statement to `getQueryLog()`, so the query that trips the
        // threshold is never itself in the line. What the line carries is the statements that ran
        // before it, and those are the ones whose bindings have to be redacted.
        Route::middleware('web')->get('__prf14/slow-query', function () use ($sleepSeconds) {
            DB::select('select ? as subject', [self::SENSITIVE_BINDING]);
            DB::select('select sleep('.$sleepSeconds.') as slept');

            return response('ok');
        })->name('prf14.slow-query');

        // The handler reads `getQueryLog()`, which is empty unless query logging is on. Turning it
        // on here is what makes the redaction assertion mean something: with the log empty, "the
        // bindings are not in the line" would be true because *nothing* was in the line.
        DB::enableQueryLog();

        /** @var list<MessageLogged> $logged */
        $logged = [];

        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        // The handler fires once per connection and then latches. By this point in the test the
        // connection has already run a migration and a seeder, so without this reset the latch may
        // have been tripped long before the deliberately slow query — and the test would report a
        // missing feature that is in fact present.
        $connection = DB::connection();
        $connection->resetTotalQueryDuration();
        $connection->allowQueryDurationHandlersToRunAgain();

        $this->get('/__prf14/slow-query')->assertOk();

        $lines = array_values(array_filter(
            $logged,
            static fn (MessageLogged $event): bool => str_contains((string) $event->message, 'Slow query'),
        ));

        $this->assertCount(
            1,
            $lines,
            sprintf(
                'A query that took about %d ms against a %d ms threshold produced %d slow-query log '
                .'line(s), and §11.7 asks for exactly one. Zero means DB::whenQueryingForLongerThan() '
                .'is not registered (AppServiceProvider::logSlowQueries(), phase-24-25 §6.4) and a slow '
                .'query in production leaves nothing behind. More than one means the handler is '
                .'registered per query rather than per request, which floods the log with the same '
                .'finding.',
                (int) ($sleepSeconds * 1000),
                $threshold,
                count($lines),
            ),
        );

        $context = $lines[0]->context;

        $this->assertSame(
            'warning',
            $lines[0]->level,
            'A slow query is logged below warning level, so it sits under the threshold every log '
            .'shipper and every digest filters on — which is the same as not logging it.',
        );

        $this->assertSame(
            'prf14.slow-query',
            $context['route'] ?? null,
            'The slow-query line does not name the route it happened on. Without it the line says only '
            .'that something was slow somewhere, which no digest can group and no operator can act on.',
        );

        $this->assertSame(
            $threshold,
            $context['threshold_ms'] ?? null,
            'The logged threshold is not the one in ops.slow_query_ms, so the production threshold is '
            .'hard-coded somewhere and the setting screen is lying about what it controls (§5.2).',
        );

        // **The duration, not the threshold.** The threshold is the number somebody configured; the
        // duration is the number the query actually took, and it is the only one that says whether
        // this was 260 ms or nine seconds. §11.7 asks for the duration by name.
        $duration = null;

        foreach ($context as $key => $value) {
            if (is_string($key) && str_contains(strtolower($key), 'duration') && is_numeric($value)) {
                $duration = (float) $value;

                break;
            }
        }

        $this->assertNotNull(
            $duration,
            'The slow-query line carries the threshold but not the duration, so every line looks '
            ."exactly as bad as every other and a nine-second query is indistinguishable from a\n"
            ."261 ms one. §11.7 PRF-14 asks for \"the route and the duration\". The connection knows\n"
            ."it — add one key in AppServiceProvider::logSlowQueries():\n\n"
            ."    'duration_ms' => (int) round(\$connection->totalQueryDuration()),\n",
        );

        $this->assertGreaterThanOrEqual(
            $threshold,
            (float) $duration,
            'The logged duration is below the threshold that triggered the line, so it is measuring '
            .'something other than the time this request spent querying.',
        );

        $serialised = json_encode($context) ?: '';

        // Asserted before the redaction check, because "the bindings are not in the line" is a
        // statement about a line that has statements in it. With an empty `queries` array the
        // redaction assertion below would pass over nothing and report it as proof.
        $this->assertContains(
            'select ? as subject',
            (array) ($context['queries'] ?? []),
            'The slow-query line carries no SQL, so it says that something was slow without saying '
            .'what — and it makes the redaction assertion below vacuous. The handler reads '
            .'getQueryLog(), which this test switched on.',
        );

        $this->assertStringNotContainsString(
            self::SENSITIVE_BINDING,
            $serialised,
            'A query binding reached the log file. The SQL is the shape of the problem and may be '
            .'logged; the bindings are the row it was about — a national ID here, an email address or '
            .'a salary in the next one — and storage/logs has no access control on it at all '
            .'(phase-24-25 §6.4).',
        );
    }
}
