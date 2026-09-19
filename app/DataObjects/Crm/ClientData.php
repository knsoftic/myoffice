<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\ClientType;
use App\Enums\InquirySource;
use App\Support\Money;

/**
 * The writable record of a client (phase-05 §2.7, §6.7 `create()` / `update()`).
 *
 * `client_code`, `user_id`, `status` and `portal_enabled` are never written from here — each has its own
 * method. Tax percentages are `decimal(8,4)` strings (validated 0-100 by the Form Request and by
 * `chk_clients_tax_rate`). `$provided` lists the supplied payload keys; `update()` writes only those.
 */
final readonly class ClientData
{
    use ReadsInput;

    /**
     * Columns `create()` / `update()` write, payload key => property.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'client_type' => 'clientType',
        'name' => 'name',
        'company_name' => 'companyName',
        'email' => 'email',
        'phone' => 'phone',
        'whatsapp' => 'whatsapp',
        'website' => 'website',
        'industry' => 'industry',
        'about' => 'about',
        'logo_path' => 'logoPath',
        'address' => 'address',
        'city' => 'city',
        'state' => 'state',
        'postal_code' => 'postalCode',
        'country' => 'country',
        'country_code' => 'countryCode',
        'billing_same_as_address' => 'billingSameAsAddress',
        'billing_address' => 'billingAddress',
        'tax_registered' => 'taxRegistered',
        'tax_number' => 'taxNumber',
        'sales_tax_number' => 'salesTaxNumber',
        'cnic' => 'cnic',
        'tax_exempt' => 'taxExempt',
        'tax_rate_override' => 'taxRateOverride',
        'withholding_tax_rate' => 'withholdingTaxRate',
        'tax_notes' => 'taxNotes',
        'currency' => 'currency',
        'payment_terms_days' => 'paymentTermsDays',
        'account_manager_id' => 'accountManagerId',
        'source' => 'source',
        'notes' => 'notes',
    ];

    /**
     * @param  list<string>|null  $provided
     */
    public function __construct(
        public string $name,
        public ?ClientType $clientType = null,
        public ?string $companyName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $whatsapp = null,
        public ?string $website = null,
        public ?string $industry = null,
        public ?string $about = null,
        public ?string $logoPath = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $countryCode = null,
        public bool $billingSameAsAddress = true,
        public ?string $billingAddress = null,
        public bool $taxRegistered = false,
        public ?string $taxNumber = null,
        public ?string $salesTaxNumber = null,
        public ?string $cnic = null,
        public bool $taxExempt = false,
        public ?string $taxRateOverride = null,
        public ?string $withholdingTaxRate = null,
        public ?string $taxNotes = null,
        public ?string $currency = null,
        public ?int $paymentTermsDays = null,
        public ?int $accountManagerId = null,
        public ?InquirySource $source = null,
        public ?string $notes = null,
        public ?int $leadId = null,
        public ?string $referralCode = null,
        public ?ClientContactData $primaryContact = null,
        public ?array $provided = null,
    ) {}

    /**
     * Build from a validated `StoreClientRequest` / `UpdateClientRequest` payload; a nested `primary_contact`
     * array creates the primary contact in the same transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $country = self::str($data, 'country_code', 2);
        $currency = self::str($data, 'currency', 3);

        return new self(
            name: (string) self::str($data, 'name', 150),
            clientType: self::enum($data, 'client_type', ClientType::class),
            companyName: self::str($data, 'company_name', 150),
            email: self::str($data, 'email', 150),
            phone: self::str($data, 'phone', 32),
            whatsapp: self::str($data, 'whatsapp', 32),
            website: self::str($data, 'website', 255),
            industry: self::str($data, 'industry', 96),
            about: self::str($data, 'about'),
            logoPath: self::str($data, 'logo_path', 255),
            address: self::str($data, 'address', 255),
            city: self::str($data, 'city', 96),
            state: self::str($data, 'state', 96),
            postalCode: self::str($data, 'postal_code', 24),
            country: self::str($data, 'country', 64),
            countryCode: $country === null ? null : strtoupper($country),
            billingSameAsAddress: self::bool($data, 'billing_same_as_address', true),
            billingAddress: self::str($data, 'billing_address', 255),
            taxRegistered: self::bool($data, 'tax_registered'),
            taxNumber: self::str($data, 'tax_number', 64),
            salesTaxNumber: self::str($data, 'sales_tax_number', 64),
            cnic: self::str($data, 'cnic', 24),
            taxExempt: self::bool($data, 'tax_exempt'),
            taxRateOverride: self::rate($data, 'tax_rate_override'),
            withholdingTaxRate: self::rate($data, 'withholding_tax_rate'),
            taxNotes: self::str($data, 'tax_notes', 255),
            currency: $currency === null ? null : strtoupper($currency),
            paymentTermsDays: self::int($data, 'payment_terms_days'),
            accountManagerId: self::int($data, 'account_manager_id'),
            source: self::enum($data, 'source', InquirySource::class),
            notes: self::str($data, 'notes'),
            primaryContact: ClientContactData::fromNested($data, 'primary_contact'),
            provided: self::provided($data, array_keys(self::FIELDS)),
        );
    }

    public function supplies(string $field): bool
    {
        return $this->provided === null || in_array($field, $this->provided, true);
    }

    /**
     * A copy with some constructor arguments replaced.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * A percentage as a decimal(8,4) string, or null.
     *
     * @param  array<string, mixed>  $data
     */
    private static function rate(array $data, string $key): ?string
    {
        $value = self::str($data, $key, 16);

        if ($value === null || preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value) !== 1) {
            return null;
        }

        return Money::round($value, Money::RATE_SCALE);
    }
}
