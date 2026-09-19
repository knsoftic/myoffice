<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The comparison keys duplicate detection runs on (phase-05 §6.2, [D-P5-5], D29). Pure: no database, no settings.
 *
 * `phone()` strips everything but digits, drops a leading international `00`, turns a leading trunk `0` into
 * the country's dialling code when the country is known, and keeps the **last 10 significant digits** — so
 * `+92 300 123 4567`, `03001234567` and `0300-1234567` are one key (`3001234567`, test 7). Fewer than five
 * digits is not a phone number worth comparing and normalises to null.
 *
 * `email()` trims and lower-cases and does **not** strip Gmail dots or `+tags`: two addresses that differ by a
 * dot are two addresses.
 *
 * The result lands in plain indexed `*_normalized` columns, written on every save by the services. It is a
 * heuristic — a family or an office can share a number — which is why a match is warned about, never blocked
 * by a constraint.
 */
final class ContactNormalizer
{
    /** Digits kept as the comparison key. */
    public const KEY_DIGITS = 10;

    /** Shorter than this, a value is not compared at all. */
    public const MIN_DIGITS = 5;

    /**
     * ISO-3166-1 alpha-2 => international dialling code, for the markets this business serves most. A country
     * not listed simply keeps its trunk `0`, which the last-10-digits key absorbs for most numbering plans.
     *
     * @var array<string, string>
     */
    public const DIALLING_CODES = [
        'PK' => '92', 'IN' => '91', 'BD' => '880', 'AF' => '93', 'CN' => '86', 'LK' => '94', 'NP' => '977',
        'AE' => '971', 'SA' => '966', 'QA' => '974', 'KW' => '965', 'OM' => '968', 'BH' => '973', 'IR' => '98',
        'TR' => '90', 'EG' => '20', 'JO' => '962', 'IQ' => '964', 'MY' => '60', 'ID' => '62', 'SG' => '65',
        'TH' => '66', 'PH' => '63', 'JP' => '81', 'KR' => '82', 'GB' => '44', 'IE' => '353', 'DE' => '49',
        'FR' => '33', 'IT' => '39', 'ES' => '34', 'NL' => '31', 'BE' => '32', 'SE' => '46', 'NO' => '47',
        'DK' => '45', 'FI' => '358', 'PL' => '48', 'CH' => '41', 'AT' => '43', 'PT' => '351', 'GR' => '30',
        'RU' => '7', 'UA' => '380', 'US' => '1', 'CA' => '1', 'MX' => '52', 'BR' => '55', 'AR' => '54',
        'AU' => '61', 'NZ' => '64', 'ZA' => '27', 'NG' => '234', 'KE' => '254',
    ];

    public static function phone(?string $raw, ?string $countryCode = null): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $international = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $international && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if (! $international && str_starts_with($digits, '0')) {
            $code = self::DIALLING_CODES[strtoupper(trim((string) $countryCode))] ?? null;

            if ($code !== null) {
                $digits = $code.ltrim($digits, '0');
            }
        }

        $digits = ltrim($digits, '0');

        if (strlen($digits) < self::MIN_DIGITS) {
            return null;
        }

        return strlen($digits) > self::KEY_DIGITS ? substr($digits, -self::KEY_DIGITS) : $digits;
    }

    public static function email(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtolower(trim($raw));

        return $value === '' ? null : mb_substr($value, 0, 150);
    }

    /**
     * The WhatsApp deep link for a number, from `crm.whatsapp_link_template` (`https://wa.me/{phone}`).
     *
     * The caller passes the template (a view never reads a setting). `{phone}` is the full international
     * digit string — the dialling code is added from the country when the number is written locally.
     */
    public static function whatsappLink(?string $raw, ?string $countryCode, string $template): ?string
    {
        if ($raw === null || trim($raw) === '' || trim($template) === '') {
            return null;
        }

        $raw = trim($raw);
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($raw, '+') && str_starts_with($digits, '0')) {
            $code = self::DIALLING_CODES[strtoupper(trim((string) $countryCode))] ?? null;
            $digits = $code === null ? ltrim($digits, '0') : $code.ltrim($digits, '0');
        }

        return str_replace('{phone}', rawurlencode($digits), $template);
    }
}
