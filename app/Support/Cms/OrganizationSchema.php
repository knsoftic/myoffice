<?php

declare(strict_types=1);

namespace App\Support\Cms;

use Illuminate\Support\Facades\Storage;

/**
 * The company as the public site states it: the office details a visitor reads, and the same facts as
 * the home page's Organization JSON-LD, so search engines are told exactly what the page shows.
 *
 * Everything comes from two places, and nothing is typed here:
 *   - public settings (`company.*`, `contact.*`, `social.*`, `branding.logo_light`), read through
 *     `site_setting()`, which refuses a key that is not public (INV-10);
 *   - the published home sections: `leadership` becomes a `Person` who works for the company (and its
 *     founder when the editor ticks that), `office` photos become the company's images.
 *
 * A fact that is not filled in is left out of the JSON-LD rather than sent empty; with no address the
 * company is an `Organization`, with one it is also a `LocalBusiness` with opening hours.
 */
final class OrganizationSchema
{
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** The JSON-LD lists at most this many office photos; the gallery itself shows them all. */
    private const MAX_IMAGES = 6;

    /**
     * The office as a visitor reads it.
     *
     * @return array{address: string, phone: string, phone_href: ?string, email: string, whatsapp_href: ?string, hours: list<array{days: string, value: string}>, directions_url: ?string}
     */
    public static function office(): array
    {
        $address = self::addressLine();

        $phone = self::setting('contact.phone');
        $email = self::setting('contact.email');
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';

        $whatsappDigits = (string) preg_replace('/\D/', '', self::setting('contact.whatsapp'));

        return [
            'address' => $address,
            'phone' => $phone,
            'phone_href' => $phone !== '' ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null,
            'email' => $email,
            'whatsapp_href' => strlen($whatsappDigits) >= 8 ? 'https://wa.me/'.$whatsappDigits : null,
            'hours' => self::hoursRows(),
            'directions_url' => self::directionsUrl($address),
        ];
    }

    /**
     * The home page's JSON-LD: the company, its website and, when the CEO section is published, the CEO.
     *
     * @param  iterable<int, mixed>  $sections  the published home sections (snapshot arrays)
     * @return array<string, mixed>
     */
    public static function forHome(iterable $sections): array
    {
        $home = rtrim(url('/'), '/').'/';
        $orgId = $home.'#organization';

        $leadership = self::firstSection($sections, 'leadership');
        $office = self::firstSection($sections, 'office');

        $name = self::setting('company.name') ?: (string) config('app.name', '');
        $address = self::postalAddress();

        $organization = self::compact([
            '@type' => $address === null ? 'Organization' : ['Organization', 'LocalBusiness'],
            '@id' => $orgId,
            'name' => $name,
            'legalName' => self::setting('company.legal_name'),
            'alternateName' => self::setting('company.short_name'),
            'url' => self::httpUrl(self::setting('company.website')) ?? $home,
            'logo' => self::logoUrl(),
            'image' => self::officeImages($office),
            'description' => self::setting('company.short_description') ?: self::setting('company.tagline'),
            'slogan' => self::setting('company.tagline'),
            'foundingDate' => preg_match('/^\d{4}$/', self::setting('company.founded_year')) === 1 ? self::setting('company.founded_year') : null,
            'email' => filter_var(self::setting('contact.email'), FILTER_VALIDATE_EMAIL) !== false ? self::setting('contact.email') : null,
            'telephone' => self::setting('contact.phone'),
            'address' => $address,
            'geo' => $address === null ? null : self::geo(),
            'hasMap' => $address === null ? null : self::directionsUrl(self::addressLine()),
            'openingHoursSpecification' => $address === null ? null : self::openingHours(),
            'sameAs' => self::socialProfiles(),
        ]);

        $graph = [$organization];

        $person = self::person($leadership, $orgId, $home);

        if ($person !== null) {
            $graph[] = $person;

            if ((bool) data_get($leadership, 'fields.is_founder', false)) {
                $graph[0]['founder'] = ['@id' => $person['@id']];
            }
        }

        $graph[] = [
            '@type' => 'WebSite',
            '@id' => $home.'#website',
            'url' => $home,
            'name' => $name,
            'publisher' => ['@id' => $orgId],
        ];

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * Business hours, consecutive days with the same hours folded into one row: "Mon–Fri 09:00–18:00".
     *
     * @return list<array{days: string, value: string}>
     */
    public static function hoursRows(): array
    {
        $groups = [];

        foreach (self::hours() as $index => [$day, $value]) {
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['value'] === $value && $groups[$last]['to_index'] === $index - 1) {
                $groups[$last]['to'] = $day;
                $groups[$last]['to_index'] = $index;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'to_index' => $index, 'value' => $value];
            }
        }

        return array_map(static function (array $group): array {
            $from = ucfirst(substr($group['from'], 0, 3));
            $to = ucfirst(substr($group['to'], 0, 3));

            return ['days' => $from === $to ? $from : $from.'–'.$to, 'value' => $group['value']];
        }, $groups);
    }

    /**
     * Each weekday that has a usable row, in week order, keyed by its position in the week:
     * [index => [day, "09:00–18:00" | "Closed"]].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function hours(): array
    {
        $raw = site_setting('contact.business_hours');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($raw)) {
            return [];
        }

        $days = [];

        foreach (self::WEEKDAYS as $index => $day) {
            $entry = $raw[$day] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            if (filter_var($entry['closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $days[$index] = [$day, 'Closed'];

                continue;
            }

            [$open, $close] = [(string) ($entry['open'] ?? ''), (string) ($entry['close'] ?? '')];

            if (self::isTime($open) && self::isTime($close)) {
                $days[$index] = [$day, $open.'–'.$close];
            }
        }

        return $days;
    }

    /**
     * Open days with the same hours share one `OpeningHoursSpecification`.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function openingHours(): ?array
    {
        $byHours = [];

        foreach (self::hours() as [$day, $value]) {
            if ($value === 'Closed') {
                continue;
            }

            $byHours[$value][] = 'https://schema.org/'.ucfirst($day);
        }

        $specs = [];

        foreach ($byHours as $value => $days) {
            [$opens, $closes] = explode('–', (string) $value);

            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
                'opens' => $opens,
                'closes' => $closes,
            ];
        }

        return $specs === [] ? null : $specs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function person(mixed $section, string $orgId, string $home): ?array
    {
        $name = trim((string) data_get($section, 'fields.name', ''));

        if ($section === null || $name === '') {
            return null;
        }

        $linkedin = self::httpUrl(trim((string) data_get($section, 'fields.linkedin_url', '')));

        return self::compact([
            '@type' => 'Person',
            '@id' => $home.'#ceo',
            'name' => $name,
            'jobTitle' => trim((string) data_get($section, 'fields.designation', '')),
            'image' => self::absolute(data_get($section, 'media.photo.url')),
            'worksFor' => ['@id' => $orgId],
            'sameAs' => $linkedin === null ? null : [$linkedin],
        ]);
    }

    /**
     * @return list<string>|null
     */
    private static function officeImages(mixed $section): ?array
    {
        $urls = collect(data_get($section, 'items.photo', []))
            ->map(static fn (mixed $item): ?string => self::absolute(data_get($item, 'media.url')))
            ->filter()
            ->unique()
            ->take(self::MAX_IMAGES)
            ->values()
            ->all();

        return $urls === [] ? null : $urls;
    }

    /**
     * @return array<string, string>|null
     */
    private static function postalAddress(): ?array
    {
        $street = self::setting('contact.address');
        $city = self::setting('contact.city');
        $country = self::setting('contact.country');

        if ($street === '' && $city === '') {
            return null;
        }

        return self::compact([
            '@type' => 'PostalAddress',
            'streetAddress' => $street,
            'addressLocality' => $city,
            'addressCountry' => $country,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function geo(): ?array
    {
        [$latitude, $longitude] = self::coordinates();

        return $latitude === null ? null : [
            '@type' => 'GeoCoordinates',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private static function coordinates(): array
    {
        $latitude = self::setting('contact.latitude');
        $longitude = self::setting('contact.longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [null, null];
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        // 0,0 is the settings form's untouched value, not an office in the Gulf of Guinea.
        if (abs($latitude) > 90 || abs($longitude) > 180 || ($latitude === 0.0 && $longitude === 0.0)) {
            return [null, null];
        }

        return [$latitude, $longitude];
    }

    /**
     * A Google Maps link to the office: the coordinates when they are set, otherwise a search for the
     * company name and address. Null with neither.
     */
    private static function directionsUrl(string $address): ?string
    {
        [$latitude, $longitude] = self::coordinates();

        if ($latitude !== null) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($latitude.','.$longitude);
        }

        if ($address === '') {
            return null;
        }

        // The name helps Maps find the right building, unless the address already starts with it.
        $name = self::setting('company.name');
        $query = $name !== '' && stripos($address, $name) === false ? $name.' '.$address : $address;

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    private static function addressLine(): string
    {
        return collect(['contact.address', 'contact.city', 'contact.country'])
            ->map(static fn (string $key): string => self::setting($key))
            ->filter()
            ->implode(', ');
    }

    /**
     * @return list<string>|null
     */
    private static function socialProfiles(): ?array
    {
        $urls = collect(['facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'x_twitter', 'github'])
            ->map(static fn (string $network): ?string => self::httpUrl(self::setting('social.'.$network)))
            ->filter()
            ->values()
            ->all();

        return $urls === [] ? null : $urls;
    }

    private static function logoUrl(): ?string
    {
        $path = self::setting('branding.logo_light');

        if ($path === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        $disk = Storage::disk('public');

        if (! rescue(static fn (): bool => $disk->exists(ltrim($path, '/')), false, false)) {
            return null;
        }

        return self::absolute($disk->url(ltrim($path, '/')));
    }

    /**
     * The first published section of a type, as its snapshot array.
     */
    private static function firstSection(iterable $sections, string $key): mixed
    {
        foreach ($sections as $section) {
            if ((data_get($section, 'section_key') ?? data_get($section, 'published_content.section_key')) === $key) {
                return $section;
            }
        }

        return null;
    }

    private static function setting(string $key): string
    {
        $value = site_setting($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function httpUrl(?string $url): ?string
    {
        return $url !== null && preg_match('~^https?://\S+$~i', $url) === 1 ? $url : null;
    }

    private static function absolute(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return preg_match('~^https?://~i', $url) === 1 ? $url : url($url);
    }

    private static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * Drop the facts that are not filled in.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
