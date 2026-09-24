<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Models\Finance\Invoice;
use App\Models\User;
use App\Search\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Invoices (phase-19-23 6.23).
 *
 * **The amount needs `invoices.view_financial` and is otherwise absent.** An invoice is
 * findable by number by anybody who may see invoices at all - that is how somebody answers "has
 * INV-0042 been paid" - but the figure on it is a separate right. Absent rather than masked, for
 * the same reason as everywhere else in this phase.
 *
 * **`leftJoin` on clients again**: an invoice whose client was soft-deleted is still an invoice,
 * and an inner join would hide exactly the ones somebody is most likely to be chasing.
 */
final class InvoiceSearchProvider extends Provider
{
    public function type(): SearchEntityType
    {
        return SearchEntityType::Invoice;
    }

    public function columns(): array
    {
        return ['invoices.invoice_number', 'invoice_client.name'];
    }

    public function exactColumns(): array
    {
        return ['invoice_number'];
    }

    public function query(string $term, User $viewer, int $limit): Collection
    {
        $query = Invoice::query()
            ->leftJoin('clients as invoice_client', 'invoice_client.id', '=', 'invoices.client_id')
            ->select([
                'invoices.id', 'invoices.invoice_number', 'invoices.status', 'invoices.client_id',
                'invoices.total_amount', 'invoices.balance_amount', 'invoices.due_date',
            ])
            ->addSelect('invoice_client.name as client_name');

        if (! $this->can($viewer, 'view_any')) {
            // A client on the portal: their own invoices, through Phase 13's own scope.
            $query->visibleToClient();
        }

        return $this->match($query, $term)->limit($limit)->get();
    }

    public function present(Model $model, User $viewer): SearchHit
    {
        /** @var Invoice $model */
        $meta = ['Due' => app_date($model->due_date)];

        if ($this->can($viewer, 'view_financial')) {
            $meta['Total'] = money((string) $model->total_amount);
            $meta['Balance'] = money((string) $model->balance_amount);
        }

        return new SearchHit(
            type: $this->type(),
            id: $model->getKey(),
            title: (string) $model->invoice_number,
            subtitle: $model->getAttribute('client_name'),
            badge: $model->status?->label(),
            badgeColor: $model->status?->color(),
            meta: $meta,
            url: $this->urlFor('admin.invoices.show', [$model->getKey()], $viewer),
        );
    }
}
