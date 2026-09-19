<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\InquiryRouter;
use App\Support\SettingsRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `inquiries:route-pending` — retry routing for every `pending` / `failed` contact inquiry (phase-04
 * §6.10.2, §6.10.3, §10.4).
 *
 * Scheduled hourly with `withoutOverlapping(10)`. This is what drains the backlog the moment Phase 5 (CRM
 * leads) or Phase 15 (course inquiries) registers its target, or a disabled target module is switched back
 * on. Oldest first, spam excluded, idempotent — running it again creates nothing twice.
 *
 * With `website.inquiry_auto_route` off, nothing is created automatically (§5): the command reports that and
 * exits unless `--force` is given, which an administrator may run by hand as a bulk "Route now".
 */
#[AsCommand(name: 'inquiries:route-pending')]
final class RoutePendingInquiries extends Command
{
    protected $signature = 'inquiries:route-pending
        {--limit=200 : Maximum inquiries handled in this run}
        {--force : Route even when website.inquiry_auto_route is off}';

    protected $description = 'Route pending and failed contact inquiries to their target modules';

    public function handle(InquiryRouter $router, SettingsRepository $settings): int
    {
        $autoRoute = filter_var($settings->get('website.inquiry_auto_route', true), FILTER_VALIDATE_BOOLEAN);

        if (! $autoRoute && ! $this->option('force')) {
            $this->warn('Automatic routing is switched off (website.inquiry_auto_route); nothing routed. Use --force to route anyway.');

            return self::SUCCESS;
        }

        $limit = max(1, min(5000, (int) $this->option('limit')));
        $result = $router->routePending($limit);

        $this->info(sprintf(
            '%d routed, %d still waiting, %d failed',
            $result['routed'],
            $result['pending'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
