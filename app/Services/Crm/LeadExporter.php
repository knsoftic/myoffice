<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\LeadFilters;
use App\Jobs\Crm\BuildCrmExport;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Support\CsvWriter;
use App\Support\Format;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The leads CSV export (phase-05 §6.10, tests 83-84).
 *
 * Always through the §9 visibility predicate for the **requesting** user — applied explicitly with
 * `LeadVisibilityScope::forUser()`, so a queued export built by a worker with nobody signed in still contains only
 * what its requester may see. Rows stream through `CsvWriter` (formula-injection escape) over `lazyById`.
 * Above `crm.export_max_rows` `stream()` queues `BuildCrmExport` instead and returns null, and the caller answers
 * "we will notify you".
 */
final class LeadExporter
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    public const TYPE = 'leads';

    /** column key => header */
    public const COLUMNS = [
        'lead_no' => 'Lead #',
        'name' => 'Name',
        'company' => 'Company',
        'email' => 'Email',
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'country' => 'Country',
        'interested_service' => 'Interested service',
        'budget_amount' => 'Budget',
        'source' => 'Source',
        'source_detail' => 'Source detail',
        'status' => 'Status',
        'assignee' => 'Assigned to',
        'follow_up_at' => 'Next follow-up',
        'last_contacted_at' => 'Last contacted',
        'last_activity_at' => 'Last activity',
        'referral_code_captured' => 'Referral code',
        'converted_at' => 'Converted',
        'created_at' => 'Created',
        'notes' => 'Notes',
    ];

    public function __construct(
        private readonly CrmExportFiles $files,
    ) {}

    /**
     * Stream the CSV — or, above `crm.export_max_rows`, queue `BuildCrmExport` for the signed-in user and return null.
     *
     * @param  list<string>  $columns  COLUMNS keys; unknown keys are ignored, none = all
     */
    public function stream(LeadFilters $filters, array $columns = []): ?StreamedResponse
    {
        $user = $this->requireUser();
        $columns = $this->resolveColumns($columns);

        if ($this->shouldQueue($filters, $user)) {
            $this->queue($filters, $columns, $user);

            return null;
        }

        $query = $this->query($filters, $user);

        $this->audit($user, 'Leads exported', ['attributes' => ['columns' => $columns, 'filters' => $filters->toQuery()]], 'leads');

        return (new CsvWriter)->download(
            sprintf('leads-%s.csv', CarbonImmutable::now()->format('Ymd-His')),
            array_map(static fn (string $key): string => self::COLUMNS[$key], $columns),
            fn (): iterable => CsvWriter::rowsFrom($query, fn (Lead $lead): array => $this->row($lead, $columns), 500, 'leads.id', 'id'),
        );
    }

    public function count(LeadFilters $filters, User $user): int
    {
        return $this->query($filters, $user)->toBase()->getCountForPagination();
    }

    public function shouldQueue(LeadFilters $filters, User $user): bool
    {
        return $this->count($filters, $user) > $this->crmInt('export_max_rows', 20000, 1);
    }

    /**
     * @param  list<string>  $columns
     */
    public function queue(LeadFilters $filters, array $columns, User $user): void
    {
        $this->audit($user, 'Leads export queued', ['attributes' => ['columns' => $columns, 'filters' => $filters->toQuery()]], 'leads');

        BuildCrmExport::dispatch(self::TYPE, (int) $user->getKey(), $filters->toQuery(), $this->resolveColumns($columns));
    }

    /**
     * Write the export for `$user` to the private disk — the body of `BuildCrmExport`. Returns [path, rows].
     *
     * @param  array<string, mixed>  $filterInput  `LeadFilters::toQuery()` of the original request
     * @param  list<string>  $columns
     * @return array{0: string, 1: int}
     */
    public function writeFor(User $user, array $filterInput, array $columns): array
    {
        $filters = LeadFilters::fromArray($filterInput);
        $columns = $this->resolveColumns($columns);
        $path = $this->files->newPath(self::TYPE, (int) $user->getKey());
        $query = $this->query($filters, $user);

        $rows = (new CsvWriter)->toFile(
            $this->files->absolutePath($path),
            array_map(static fn (string $key): string => self::COLUMNS[$key], $columns),
            CsvWriter::rowsFrom($query, fn (Lead $lead): array => $this->row($lead, $columns), 500, 'leads.id', 'id'),
        );

        return [$path, $rows];
    }

    /**
     * @return Builder<Lead>
     */
    public function query(LeadFilters $filters, User $user): Builder
    {
        $query = LeadVisibilityScope::forUser(Lead::query()->withoutGlobalScope(LeadVisibilityScope::class), $user);

        return $filters->apply($query, (int) $user->getKey())
            ->leftJoin('users as lead_assignee', 'lead_assignee.id', '=', 'leads.assigned_to')
            ->select('leads.*')
            ->addSelect('lead_assignee.name as assignee_name');
    }

    /**
     * @param  array<int, mixed>  $columns
     * @return list<string>
     */
    public function resolveColumns(array $columns): array
    {
        $columns = array_values(array_unique(array_filter(
            $columns,
            static fn (mixed $column): bool => is_string($column) && array_key_exists($column, self::COLUMNS),
        )));

        return $columns === [] ? array_keys(self::COLUMNS) : $columns;
    }

    /**
     * @param  list<string>  $columns
     * @return list<mixed>
     */
    private function row(Lead $lead, array $columns): array
    {
        $out = [];

        foreach ($columns as $column) {
            $value = $column === 'assignee' ? $lead->getAttribute('assignee_name') : $lead->getAttribute($column);

            $out[] = match (true) {
                $value instanceof CarbonInterface => Format::dateTime($value),
                $value instanceof BackedEnum => method_exists($value, 'label') ? $value->label() : $value->value,
                default => $value,
            };
        }

        return $out;
    }

    private function requireUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('A lead export needs a signed-in user.');
        }

        return $user;
    }
}
