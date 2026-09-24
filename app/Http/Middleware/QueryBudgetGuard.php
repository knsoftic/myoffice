<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Ops\QueryBudget;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts what a screen asked the database, and says so in a header (phase-24-25 §6.4).
 *
 * **Development only, and that is not timidity.** A query budget that throws is how an N+1 is found
 * before it ships; a query budget that throws in production is how a missed `with()` becomes a 500
 * for a paying client. So the environment decides, not the setting: outside local and testing this
 * middleware returns on its first line, attaching no listener and counting nothing, and
 * `ops.query_budget_enforced` can only choose between throwing and logging *within* those
 * environments.
 *
 * The gate is here rather than in `bootstrap/app.php` because the middleware stack is assembled
 * before the container can answer which environment this is.
 *
 * `X-Query-Count` and `X-Query-Time` go on every response it sees. A number in a header is read by
 * whoever is looking at the page; a number in a log file is read by whoever already suspects
 * something.
 */
final class QueryBudgetGuard
{
    public function __construct(private readonly QueryBudget $budget) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Production attaches no listener. See the class note.
        if (! app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        /** @var list<array{sql: string, ms: float}> $queries */
        $queries = [];

        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = ['sql' => $event->sql, 'ms' => (float) $event->time];
        });

        $response = $next($request);

        $profile = $this->budget->profile($queries);

        $response->headers->set('X-Query-Count', (string) $profile->count);
        $response->headers->set('X-Query-Time', sprintf('%.1fms', $profile->durationMs));

        if ($profile->duplicateCount() > 0) {
            $response->headers->set('X-Query-Duplicates', (string) $profile->duplicateCount());
        }

        $route = (string) ($request->route()?->getName() ?? '');

        if ($route === '' || ! $this->budget->knows($route)) {
            return $response;
        }

        try {
            $this->budget->assert($route, $profile);
        } catch (RuntimeException $exception) {
            if ($this->enforcing()) {
                throw $exception;
            }

            Log::warning('Query budget exceeded', [
                'route' => $route,
                'detail' => $exception->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * Whether a breach throws rather than logs.
     *
     * Two conditions, and both must hold. The environment check is the one that matters — it is
     * what makes it impossible for a settings row copied from a developer machine to start throwing
     * on a production screen.
     */
    private function enforcing(): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        return (bool) setting('ops.query_budget_enforced', false);
    }
}
