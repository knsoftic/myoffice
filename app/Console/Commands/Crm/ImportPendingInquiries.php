<?php

declare(strict_types=1);

namespace App\Console\Commands\Crm;

use App\Enums\InquiryRoutingStatus;
use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use App\Services\Cms\InquiryRouter;
use App\Support\SettingsRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `crm:import-pending-inquiries` — every 10 minutes, the safety net under Phase 4's router (phase-05 §10.2, §10.5).
 *
 * Service inquiries that never became a lead — received before this phase existed, or whose routing attempt failed —
 * are handed to `InquiryRouter::route()`, which calls `CrmLeadInquiryTarget`. **Duplicate-proof by
 * `uq_leads_inquiry` and the 1062**, never by a SELECT (F-3.7): a second pass over an inquiry that already has its
 * lead creates nothing. Spam is never routed. With `website.inquiry_auto_route` off nothing is created unless
 * `--force` is given.
 */
#[AsCommand(name: 'crm:import-pending-inquiries')]
final class ImportPendingInquiries extends Command
{
    protected $signature = 'crm:import-pending-inquiries
        {--limit=200 : Maximum inquiries handled in this run}
        {--force : Route even when website.inquiry_auto_route is off}';

    protected $description = 'Turn service inquiries that were never routed into CRM leads';

    public function handle(InquiryRouter $router, SettingsRepository $settings): int
    {
        $autoRoute = filter_var($settings->get('website.inquiry_auto_route', true), FILTER_VALIDATE_BOOLEAN);

        if (! $autoRoute && ! $this->option('force')) {
            $this->warn('Automatic routing is switched off (website.inquiry_auto_route); nothing imported. Use --force to import anyway.');

            return self::SUCCESS;
        }

        if ($router->target(InquiryType::TARGET_CRM_LEAD) === null) {
            $this->warn('The CRM lead target is not registered with the inquiry router: nothing imported.');

            return self::SUCCESS;
        }

        $limit = max(1, min(5000, (int) $this->option('limit')));
        $counts = ['routed' => 0, 'pending' => 0, 'failed' => 0];

        $ids = ContactInquiry::query()
            ->where('is_spam', false)
            ->where('inquiry_type', InquiryType::Service->value)
            ->whereNull('routed_id')
            ->whereIn('routing_status', [InquiryRoutingStatus::Pending->value, InquiryRoutingStatus::Failed->value])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $inquiry = ContactInquiry::query()->find($id);

            if (! $inquiry instanceof ContactInquiry) {
                continue;
            }

            try {
                $status = $router->route($inquiry);
            } catch (Throwable $exception) {
                report($exception);
                $counts['failed']++;

                continue;
            }

            match ($status) {
                InquiryRoutingStatus::Routed => $counts['routed']++,
                InquiryRoutingStatus::Failed => $counts['failed']++,
                default => $counts['pending']++,
            };
        }

        $this->info(sprintf('%d inquiry(ies) became leads, %d still waiting, %d failed.', $counts['routed'], $counts['pending'], $counts['failed']));

        return self::SUCCESS;
    }
}
