<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

/**
 * The same shape as {@see StoreInvoiceRequest}, under a different permission.
 *
 * Whether the invoice may be changed **at all** is not a validation question: it depends on its status
 * and on whether money has been received against it, both of which are checked inside
 * `InvoiceService::update()` under the row lock. A Form Request that read those a moment earlier could
 * pass while a payment was landing.
 */
final class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.edit') === true;
    }
}
