<?php

declare(strict_types=1);

namespace App\Support\Cms\Sections;

use App\Enums\InquiryType;
use App\Models\Cms\Service;
use App\Support\Modules;
use App\Support\SettingsRepository;

/**
 * `contact` section (Phase 4 owns the type, the form handling and the §17 routing, F-2.1): the option lists
 * the `<x-site.contact-form>` needs — inquiry types, published services, the configured budget labels —
 * and whether the form is accepting submissions (`maintenance.contact_form_enabled`).
 *
 * It deliberately carries **no** spam token: the signed render timestamp must be minted per request by the
 * form component (`SpamGuard::signedTimestamp()`), never cached or frozen into a snapshot.
 */
final class ContactSectionProvider extends MarketingSectionProvider
{
    public const DEFAULT_BUDGETS = ['Under 50,000', '50,000 – 150,000', '150,000 – 500,000', '500,000 – 1,000,000', 'Above 1,000,000', 'Not sure yet'];

    public function key(): string
    {
        return 'contact';
    }

    protected function module(): string
    {
        return 'contact_inquiries';
    }

    protected function build(array $options): array
    {
        $settings = app(SettingsRepository::class);

        $services = Modules::enabled('services')
            ? Service::query()->public()->ordered()->get(['id', 'name'])
                ->map(static fn (Service $service): array => ['id' => (int) $service->getKey(), 'name' => (string) $service->name])
                ->values()->all()
            : [];

        return [
            'items' => [],
            'form_enabled' => filter_var($settings->get('maintenance.contact_form_enabled', true), FILTER_VALIDATE_BOOLEAN),
            'action_url' => $this->url('site.contact.store'),
            'inquiry_types' => array_map(
                static fn (InquiryType $type): array => ['value' => $type->value, 'label' => $type->label()],
                InquiryType::cases(),
            ),
            'services' => $services,
            'budget_options' => self::budgetOptions($settings->get('website.contact_budget_options')),
        ];
    }

    /**
     * The configured budget labels (a JSON list of at most 12 strings), or the defaults of §5.
     *
     * @return list<string>
     */
    public static function budgetOptions(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($value)) {
            return self::DEFAULT_BUDGETS;
        }

        $labels = array_values(array_filter(
            array_map(static fn ($label): string => trim(strip_tags((string) (is_scalar($label) ? $label : ''))), $value),
            static fn (string $label): bool => $label !== '',
        ));

        return $labels === [] ? self::DEFAULT_BUDGETS : array_slice($labels, 0, 12);
    }
}
