<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The kind of paper a client document is (phase-05 §3, `client_documents.category`).
 *
 * {@see defaultVisibleToClient()} pre-sets the "Visible to client" toggle on upload (§6.8); `visible_to_client`
 * stays the only gate that exposes a file to the panel ([D-P5-10]).
 */
enum ClientDocumentCategory: string
{
    use HasOptions;

    case Contract = 'contract';
    case Nda = 'nda';
    case Proposal = 'proposal';
    case Quotation = 'quotation';
    case PurchaseOrder = 'purchase_order';
    case TaxCertificate = 'tax_certificate';
    case Identity = 'identity';
    case Registration = 'registration';
    case InvoiceCopy = 'invoice_copy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::Nda => 'NDA',
            self::Proposal => 'Proposal',
            self::Quotation => 'Quotation',
            self::PurchaseOrder => 'Purchase order',
            self::TaxCertificate => 'Tax certificate',
            self::Identity => 'Identity document',
            self::Registration => 'Registration',
            self::InvoiceCopy => 'Invoice copy',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Contract => 'indigo',
            self::Nda => 'violet',
            self::Proposal => 'sky',
            self::Quotation => 'cyan',
            self::PurchaseOrder => 'teal',
            self::TaxCertificate => 'amber',
            self::Identity => 'rose',
            self::Registration => 'orange',
            self::InvoiceCopy => 'emerald',
            self::Other => 'slate',
        };
    }

    /**
     * Shared with the client by default on upload: contracts, proposals, quotations and invoice copies only.
     */
    public function defaultVisibleToClient(): bool
    {
        return in_array($this, [self::Contract, self::Proposal, self::Quotation, self::InvoiceCopy], true);
    }
}
