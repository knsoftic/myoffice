<?php

declare(strict_types=1);

namespace App\Jobs\Crm;

use App\Models\User;
use App\Notifications\Crm\CrmExportReady;
use App\Services\Crm\ClientExporter;
use App\Services\Crm\CrmExportFiles;
use App\Services\Crm\LeadExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * A CRM export above `crm.export_max_rows` (phase-05 §6.10, §10.4, test 84).
 *
 * Re-checks the requester's export permission when it runs (a permission revoked since the request is honoured),
 * builds the file through the exporter — so the leads export applies the requester's §9 scope explicitly — writes
 * it to the private disk, and delivers a `CrmExportReady` notification whose link re-runs the permission chain.
 */
final class BuildCrmExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $columns
     */
    public function __construct(
        public readonly string $type,
        public readonly int $userId,
        public readonly array $filters,
        public readonly array $columns,
    ) {
        $this->afterCommit();
    }

    public function handle(CrmExportFiles $files): void
    {
        $user = User::query()->active()->find($this->userId);

        if (! $user instanceof User || ! $user->can($this->type.'.export')) {
            return;
        }

        [$path, $rows] = match ($this->type) {
            LeadExporter::TYPE => app(LeadExporter::class)->writeFor($user, $this->filters, $this->columns),
            ClientExporter::TYPE => app(ClientExporter::class)->writeFor($user, $this->filters, $this->columns),
            default => [null, 0],
        };

        if ($path === null) {
            return;
        }

        $token = $files->token($this->type, $path, (int) $user->getKey());
        $route = $this->type === LeadExporter::TYPE ? 'admin.leads.export.download' : 'admin.clients.export.download';

        try {
            $url = Route::has($route) ? route($route, ['token' => $token]) : url('/admin/'.$this->type.'/export/download?token='.rawurlencode($token));
        } catch (Throwable) {
            $url = url('/admin/'.$this->type.'/export/download?token='.rawurlencode($token));
        }

        $user->notify(new CrmExportReady($this->type, $rows, $url));
    }
}
