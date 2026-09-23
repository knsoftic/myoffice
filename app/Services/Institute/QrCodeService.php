<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Models\Institute\Certificate;
use App\Models\Institute\StudentIdCard;
use App\Support\SettingsRepository;
use RuntimeException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

/**
 * QR codes and verification codes for certificates and ID cards
 * (phase-19-23 §6.14, requirements §84, §85).
 *
 * **Everything this class returns is a `data:` URI, and that is a security decision rather than a
 * convenience.** dompdf runs with `isRemoteEnabled = false`, because with it enabled an `<img src>`
 * inside a print template — or inside sanitised-but-perfectly-legal HTML — makes the *server* fetch a
 * URL chosen by whoever holds `print_templates.edit`. That is SSRF with a friendly editor attached.
 * A QR code that arrives as bytes needs no fetch, so the renderer never has to be trusted with one.
 *
 * **Error correction `M`, quiet zone 2 — the contract's figures, and they are the right ones.** Level
 * `H` is the obvious "safer" choice and is wrong here: it encodes the same payload in roughly 30%
 * more modules, and the code has a **fixed 25 mm** to live in, so more modules means each one is
 * smaller. Past a point a phone camera stops resolving them at all, which is a worse failure than the
 * scuffing `H` would have survived. `M` still corrects 15%.
 *
 * **A code that is already printed is never rebuilt.** `forCertificate()` and `forIdCard()` read the
 * row's snapshotted `qr_payload`; `verificationUrl()` is what *creates* one, and only the issuing
 * transaction calls it. Changing `institute.certificate_verification_url` next year must not silently
 * redirect a certificate somebody is holding (INV-21-4).
 */
final class QrCodeService
{
    /** See the class note: `M` corrects 15% and keeps the modules big enough to resolve at 25 mm. */
    private const ERROR_CORRECTION = 'M';

    /** Quiet-zone modules, per the contract. */
    private const MARGIN = 2;

    /**
     * Crockford's base32: the digits plus every letter except **I, L, O and U**.
     *
     * I, L and O are dropped because they are indistinguishable from 1, 1 and 0 when read off a
     * printed certificate or spelled down a phone — and U because removing it is what keeps an
     * accidental obscenity out of a randomly generated code an institute will print and hand over.
     *
     * Exactly 32 symbols, so `random_int(0, 31)` indexes it directly.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * How a typed character folds back into the alphabet.
     *
     * The direction matters and is easy to get backwards: `O` is **not** in the alphabet and `0` is,
     * so somebody who wrote `O` meant zero. Same for `I` and `L` meaning one. This is Crockford's
     * decoding rule, and it is only safe *because* the alphabet excludes the letters — folding
     * towards a symbol that could also appear in a real code would turn one person's typo into
     * somebody else's certificate.
     */
    private const FOLD = ['O' => '0', 'I' => '1', 'L' => '1'];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The QR code printed on a certificate, from its **stored** payload.
     *
     * Throws rather than falling back to a rebuilt URL: a certificate with no `qr_payload` is one
     * that was never issued, and printing it with a freshly computed link would put a code on paper
     * that the row cannot account for.
     */
    public function forCertificate(Certificate $certificate, int $sizePx = 300): string
    {
        $payload = (string) $certificate->getAttribute('qr_payload');

        if (trim($payload) === '') {
            throw new RuntimeException(sprintf(
                'Certificate %s has no verification link stored, so there is nothing to print — it '
                .'has not been issued.',
                (string) ($certificate->getAttribute('certificate_number') ?? '#'.$certificate->getKey()),
            ));
        }

        return $this->dataUri($payload, $sizePx);
    }

    /** The QR code printed on an ID card, from its **stored** payload. */
    public function forIdCard(StudentIdCard $card, int $sizePx = 300): string
    {
        $payload = (string) $card->getAttribute('qr_payload');

        if (trim($payload) === '') {
            throw new RuntimeException(sprintf(
                'Card %s has no verification link stored, so there is nothing to print.',
                (string) ($card->getAttribute('card_number') ?? '#'.$card->getKey()),
            ));
        }

        return $this->dataUri($payload, $sizePx);
    }

    /**
     * An SVG QR code as a `data:` URI, ready to drop into an `<img src>`.
     *
     * SVG rather than PNG: a QR code is line art, so it scales to whatever `qr_size_mm` the template
     * asks for without the blur a raster picks up, and it embeds smaller.
     */
    public function dataUri(string $payload, int $sizePx = 300): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($payload, $sizePx));
    }

    /** The raw SVG, for a caller that wants to inline it rather than reference it. */
    public function svg(string $payload, int $sizePx = 300): string
    {
        $payload = trim($payload);

        if ($payload === '') {
            throw new RuntimeException('A QR code needs something to encode.');
        }

        try {
            return (string) QrCode::format('svg')
                ->errorCorrection(self::ERROR_CORRECTION)
                ->margin(self::MARGIN)
                ->size(max(64, $sizePx))
                ->generate($payload);
        } catch (Throwable $e) {
            throw new RuntimeException('The QR code could not be generated: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * A PNG `data:` URI, for the rare card-printer driver that will not take an SVG.
     *
     * Needs `imagick`, which this install does not have. It is checked rather than assumed, because
     * the failure without it is a `BadMethodCallException` from inside the library that says nothing
     * about extensions — and somebody debugging a blank square on a card deserves better.
     */
    public function pngDataUri(string $payload, int $sizePx = 300): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException(
                'A PNG QR code needs the imagick PHP extension, which is not installed. Use the SVG '
                .'form, which needs nothing and prints sharper anyway.'
            );
        }

        $png = (string) QrCode::format('png')
            ->errorCorrection(self::ERROR_CORRECTION)
            ->margin(self::MARGIN)
            ->size(max(64, $sizePx))
            ->generate(trim($payload));

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Build the absolute URL a code resolves to — called **only** when a document is issued, and the
     * result stored on the row (INV-21-4).
     *
     * `institute.certificate_verification_url` overrides the application URL, for an institute that
     * prints a short domain on the paper. Both sides lose a trailing slash: somebody will type one,
     * and a double slash in a QR payload is a link that works everywhere except the one place it is
     * tested.
     */
    public function verificationUrl(string $code): string
    {
        $base = trim((string) $this->settings->get('institute.certificate_verification_url', ''));

        if ($base === '') {
            $base = (string) config('app.url');
        }

        return rtrim($base, '/').'/verify/'.rawurlencode($this->normaliseCode($code));
    }

    /**
     * A fresh verification code: 16 characters of the 32-symbol alphabet (INV-21-2).
     *
     * `random_int()` rather than `rand()` or `Str::random()`: it is the CSPRNG, and a predictable
     * verification code would let somebody print a QR sticker that resolves to a real certificate.
     *
     * 32^16 is about 1.2 × 10^24, so guessing one is not the threat. **Enumeration is**, which is
     * what the per-address rate limit and the `certificate_verifications` log are for.
     */
    public function newCode(int $length = 16): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * Normalise what somebody typed into the public form.
     *
     * Uppercased, everything that is not a letter or digit stripped — people break a 16-character
     * code into groups of four to read it off a certificate, and refusing `K7M2-P9X4-T6B8-N3QD` over
     * the dashes they added would be refusing them for being helpful.
     *
     * Then `O → 0`, `I → 1`, `L → 1`, which is Crockford's decoding rule. See `FOLD`: the direction
     * is the part that is easy to get backwards, and it is only safe because the alphabet excludes
     * the letters being folded away.
     */
    public function normaliseCode(?string $input): string
    {
        $code = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $input)));

        return strtr($code, self::FOLD);
    }

    /**
     * Group a code for display: `K7M2 P9X4 T6B8 N3QD`.
     *
     * For reading and printing only. Everything that compares a code normalises it first, so the
     * spaces this adds never reach a lookup.
     */
    public function forDisplay(?string $code, int $group = 4): string
    {
        $code = $this->normaliseCode($code);

        return $code === '' ? '' : implode(' ', str_split($code, max(1, $group)));
    }
}
