<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\ClientFilters;
use App\Jobs\Crm\BuildCrmExport;
use App\Models\Crm\Client;
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
 * The clients CSV export (phase-05 §6.10).
 *
 * Streams through `CsvWriter` over `lazyById`; above `crm.export_max_rows` `stream()` queues `BuildCrmExport` and
 * returns null. No money column is exported until the owning phases' read models exist — a figure this phase
 * cannot source is left out, never written as `0.00` (D28).
 */
final class ClientExporter
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    public const TYPE = 'clients';

    /** column key => header */
    public const COLUMNS = [
        'client_code' => 'Client ID',
        'name' => 'Contact name',
        'company_name' => 'Company',
        'client_type' => 'Type',
        'email' => 'Email',
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'website' => 'Website',
        'industry' => 'Industry',
        'city' => 'City',
        'country' => 'Country',
        'status' => 'Status',
        'account_manager' => 'Account manager',
        'portal_enabled' => 'Portal enabled',
        'tax_registered' => 'Tax registered',
        'tax_number' => 'NTN',
        'sales_tax_number' => 'STRN',
        'created_at' => 'Created',
    ];

    public function __construct(
        private readonly CrmExportFiles $files,
    ) {}

    /**
     * Stream the CSV — or, above `crm.export_max_rows`, queue `BuildCrmExport` and return null.
     *
     * @param  list<string>  $columns  COLUMNS keys; unknown keys are ignored, none = all
     */
    public function stream(ClientFilters $filters, array $columns = []): ?StreamedResponse
    {
        $user = $this->requireUser();
        $columns = $this->resolveColumns($columns);
        $query = $this->query($filters);

        if ($query->toBase()->getCountForPagination() > $this->crmInt('export_max_rows', 20000, 1)) {
            $this->audit($user, 'Clients export queued', ['attributes' => ['columns' => $columns]], 'clients');

            BuildCrmExport::dispatch(self::TYPE, (int) $user->getKey(), $filters->toQuery(), $columns);

            return null;
        }

        $this->audit($user, 'Clients exported', ['attributes' => ['columns' => $columns]], 'clients');

        return (new CsvWriter)->download(
            sprintf('clients-%s.csv', CarbonImmutable::now()->format('Ymd-His')),
            array_map(static fn (string $key): string => self::COLUMNS[$key], $columns),
            fn (): iterable => CsvWriter::rowsFrom($query, fn (Client $client): array => $this->row($client, $columns), 500, 'clients.id', 'id'),
        );
    }

    /**
     * @param  array<string, mixed>  $filterInput  `ClientFilters::toQuery()` of the original request
     * @param  list<string>  $columns
     * @return array{0: string, 1: int}
     */
    public function writeFor(User $user, array $filterInput, array $columns): array
    {
        $columns = $this->resolveColumns($columns);
        $path = $this->files->newPath(self::TYPE, (int) $user->getKey());

        $rows = (new CsvWriter)->toFile(
            $this->files->absolutePath($path),
            array_map(static fn (string $key): string => self::COLUMNS[$key], $columns),
            CsvWriter::rowsFrom($this->query(ClientFilters::fromArray($filterInput)), fn (Client $client): array => $this->row($client, $columns), 500, 'clients.id', 'id'),
        );

        return [$path, $rows];
    }

    /**
     * @return Builder<Client>
     */
    public function query(ClientFilters $filters): Builder
    {
        return $filters->apply(Client::query())
            ->leftJoin('users as client_manager', 'client_manager.id', '=', 'clients.account_manager_id')
            ->select('clients.*')
            ->addSelect('client_manager.name as account_manager_name');
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
    private function row(Client $client, array $columns): array
    {
        $out = [];

        foreach ($columns as $column) {
            $value = $column === 'account_manager' ? $client->getAttribute('account_manager_name') : $client->getAttribute($column);

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
            throw new InvalidArgumentException('A client export needs a signed-in user.');
        }

        return $user;
    }
}
