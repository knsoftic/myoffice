<?php

declare(strict_types=1);

namespace App\Listeners\Crm;

use App\Events\Crm\LeadImportCompleted as LeadImportCompletedEvent;
use App\Models\User;
use App\Notifications\Crm\LeadImportCompleted as LeadImportCompletedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * One `LeadImportCompleted` notification to the importer, with the counts and the error-report link (phase-05 §10.3).
 */
final class NotifyImporterOfCompletion implements ShouldQueue
{
    public bool $deleteWhenMissingModels = true;

    public function handle(LeadImportCompletedEvent $event): void
    {
        $importerId = $event->import->getAttribute('created_by');

        if ($importerId === null) {
            return;
        }

        $user = User::query()->active()->find((int) $importerId);

        if (! $user instanceof User) {
            return;
        }

        try {
            $user->notify(new LeadImportCompletedNotification($event->import->fresh() ?? $event->import));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
