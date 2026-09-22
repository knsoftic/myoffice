<?php

declare(strict_types=1);

namespace App\DataObjects\Files;

use App\Enums\CourseResourceType;
use App\Support\SettingsRegistry;

/**
 * What one upload field will accept (phase-19-23 §6.1).
 *
 * **One object per field, not one list for the whole application.** A course material may be a recorded
 * lecture; an assignment submission may not. A submission may be a `.zip` of source; an ID-card photo may
 * not. Phase 5 proved the shape — an extension map, an own ceiling, and `SettingsRegistry` narrowing both
 * — and this generalises it so the second uploader cannot get a detail slightly wrong.
 *
 * **A material's rules come from its declared `CourseResourceType`, not from a generic bundle.** §6.1
 * step 3 says so outright: the sniffed MIME must be one `CourseResourceType::allowedMimes()` permits
 * *for the declared type*. Phase 14 already wrote that whitelist and its docblock already calls it a
 * security control; building a second one here would mean two lists to keep in step, and the one that
 * drifted would be the one nobody was reading.
 *
 * **Three narrowing passes, and none of them can widen.** The type's own list, then
 * `security.allowed_file_types`, then whatever the field itself declares. `institute.material_extra_extensions`
 * looks like an exception and is not: it is intersected with `security.allowed_file_types` too, so the
 * design-course extras only appear if the security setting already permits them.
 *
 * **`svg` is the interesting case.** `CourseResourceType::Image` lists `image/svg+xml` and offers a
 * `.svg` extension, because Phase 14 uses that enum for the public syllabus where an SVG diagram is
 * harmless. Here it is not: an SVG served from our own origin is stored XSS, so `ALWAYS_REFUSED` drops
 * it before the type's list is ever consulted. The enum is narrowed by this object, never the reverse.
 */
final readonly class FileRules
{
    /**
     * §6.3's private disk: `storage/app/private`, the same directory Laravel 12 roots `local` at, under
     * the name every `storage_disk` column in these five phases defaults to. It is declared separately
     * from `local` for two reasons that matter here — `serve` is off, so no framework route can hand one
     * of these files out (§6.2 [D-19-4]), and `throw` is on, so a failed write is loud.
     */
    public const DISK_PRIVATE = 'private';

    /** §6.3: used by exactly one column in these five phases, `print_templates.background_image_path`. */
    public const DISK_PUBLIC = 'public';

    /**
     * §6.1 step 5 — refused regardless of any map, any type and any setting. Together with
     * `SettingsRegistry::NEVER_UPLOADABLE_EXTENSIONS` this covers the contract's list in full.
     */
    public const ALWAYS_REFUSED = ['php', 'phtml', 'svg', 'svgz', 'html', 'htm', 'xhtml'];

    /** Documents a teacher hands out, shared by assignment briefs and feedback. */
    public const DOCUMENTS = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'rtf' => ['text/rtf', 'application/rtf'],
    ];

    public const IMAGES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /** §6.1 step 6: accepted as bytes and never inspected or expanded. */
    public const ARCHIVES = [
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        '7z' => ['application/x-7z-compressed'],
    ];

    public const MEDIA = [
        'mp4' => ['video/mp4', 'application/mp4'],
        'webm' => ['video/webm'],
        'ogv' => ['video/ogg'],
        'mov' => ['video/quicktime'],
        'mp3' => ['audio/mpeg'],
        'ogg' => ['audio/ogg'],
        'weba' => ['audio/webm'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'wav' => ['audio/x-wav', 'audio/wav'],
    ];

    /**
     * `institute.material_extra_extensions` — the design-course extras. They need their own MIME
     * entries because no `CourseResourceType` claims them, and a `.psd` whose sniffed MIME matched
     * nothing would be refused at step 9 however carefully the extension was allowed.
     *
     * `application/octet-stream` appears here and nowhere else: these are proprietary binary formats
     * that `finfo` frequently cannot name. That is a real loosening, and it is confined to four
     * extensions that an administrator has to switch on deliberately.
     */
    public const DESIGN_EXTRAS = [
        'psd' => ['image/vnd.adobe.photoshop', 'application/x-photoshop', 'application/octet-stream'],
        'ai' => ['application/pdf', 'application/postscript', 'application/octet-stream'],
        'fig' => ['application/zip', 'application/octet-stream'],
        'sketch' => ['application/zip', 'application/octet-stream'],
    ];

    /**
     * @param  array<string, list<string>>  $mimes  extension => the MIME types its content may sniff as
     * @param  int  $maxMb  this field's own ceiling; `security.max_upload_mb` may only lower it
     * @param  string  $disk  `DISK_PRIVATE` for everything except a print-template background
     * @param  bool  $allowInline  whether a PDF or image from this field may be served `inline`
     * @param  int  $maxFiles  how many files one call of this field may carry
     */
    public function __construct(
        public array $mimes,
        public int $maxMb,
        public string $disk = self::DISK_PRIVATE,
        public bool $allowInline = true,
        public int $maxFiles = 1,
    ) {}

    /**
     * A course material of one declared kind (§6.1 step 3, §5.1).
     *
     * The ceiling is `institute.material_max_upload_mb`, which `maxKilobytes()` then narrows again by
     * `security.max_upload_mb` and by PHP's own limit — §5.1's "the effective limit is `min(this,
     * security.max_upload_mb)`", with PHP added because promising more than the runtime accepts is a
     * promise that breaks at the worst moment.
     *
     * `Link` carries no bytes, so it gets an empty map: every extension fails step 5, which is the
     * correct answer for a kind that should never have reached an uploader at all.
     */
    public static function courseMaterial(CourseResourceType $type): self
    {
        return new self(
            mimes: self::mapForType($type),
            maxMb: self::settingMb('institute.material_max_upload_mb', 50),
        );
    }

    /**
     * A student's submission. Deliberately far below a material's ceiling — a student has no reason to
     * hand in half a gigabyte, and the cap is the cheapest guard there is against one account filling
     * the disk.
     *
     * @param  list<string>|null  $assignmentExtensions  the assignment's own `allowed_extensions`, which
     *                                                   narrow this further and can never widen it
     */
    public static function submission(?array $assignmentExtensions = null, ?int $assignmentMaxMb = null): self
    {
        $mimes = [...self::DOCUMENTS, ...self::IMAGES, ...self::ARCHIVES];

        if ($assignmentExtensions !== null) {
            $wanted = array_map(static fn (string $e): string => strtolower(trim($e, " \t.")), $assignmentExtensions);
            $mimes = array_intersect_key($mimes, array_flip($wanted));
        }

        $ceiling = self::settingMb('institute.assignment_submission_max_mb', 20);

        return new self(
            mimes: $mimes,
            // The assignment may ask for less than the setting allows, never more.
            maxMb: $assignmentMaxMb === null ? $ceiling : min($ceiling, max(1, $assignmentMaxMb)),
            maxFiles: 10,
        );
    }

    /** The brief a teacher attaches to an assignment: documents and images, one file. */
    public static function assignmentBrief(): self
    {
        return new self(mimes: [...self::DOCUMENTS, ...self::IMAGES], maxMb: 50);
    }

    /** A teacher's feedback file on one submission. Same shape as a brief. */
    public static function feedback(): self
    {
        return new self(mimes: [...self::DOCUMENTS, ...self::IMAGES], maxMb: 50);
    }

    /** A ticket, reply, message or meeting attachment. */
    public static function attachment(): self
    {
        return new self(
            mimes: [...self::DOCUMENTS, ...self::IMAGES, ...self::ARCHIVES],
            maxMb: 25,
            maxFiles: 5,
        );
    }

    /** The photo printed onto an ID card. Never served as a URL — it is inlined into the PDF. */
    public static function idCardPhoto(): self
    {
        return new self(mimes: self::IMAGES, maxMb: 5, allowInline: false);
    }

    /**
     * §6.3's one `public` row: a print template's background. It is a border graphic with no PII, and
     * the web server serves it directly.
     */
    public static function printTemplateBackground(): self
    {
        return new self(
            mimes: [...self::IMAGES, 'pdf' => ['application/pdf']],
            maxMb: 10,
            disk: self::DISK_PUBLIC,
        );
    }

    /**
     * The extensions this field accepts right now — its own keys, minus the always-refused, narrowed
     * by `security.allowed_file_types`.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $own = array_values(array_diff(array_keys($this->mimes), self::ALWAYS_REFUSED));

        return SettingsRegistry::uploadExtensions($own, settings_repo()->get('security.allowed_file_types'));
    }

    /** The effective ceiling in kilobytes: the smallest of this field's own, the setting's, and PHP's. */
    public function maxKilobytes(): int
    {
        return SettingsRegistry::uploadKilobytes($this->maxMb * 1024, settings_repo()->get('security.max_upload_mb'));
    }

    /**
     * The MIME types the given extension may sniff as. Empty means "not accepted", which is what makes
     * an unknown extension fail closed at the sniff step even if it somehow reached that far.
     *
     * @return list<string>
     */
    public function mimesFor(string $extension): array
    {
        return $this->mimes[strtolower($extension)] ?? [];
    }

    public function isPrivate(): bool
    {
        return $this->disk !== self::DISK_PUBLIC;
    }

    /** For the `accept` attribute on the file input, so the picker and the server agree. */
    public function acceptAttribute(): string
    {
        return implode(',', array_map(static fn (string $e): string => '.'.$e, $this->allowedExtensions()));
    }

    /** "PDF, DOCX, PNG — up to 50 MB", for the hint under the field. */
    public function describe(): string
    {
        $extensions = array_map('strtoupper', $this->allowedExtensions());
        $kilobytes = $this->maxKilobytes();

        $size = $kilobytes >= 1024
            ? app_number($kilobytes / 1024, 0).' MB'
            : app_number($kilobytes, 0).' KB';

        return ($extensions === [] ? 'No file types are currently allowed' : implode(', ', $extensions)).' — up to '.$size;
    }

    /**
     * Build `extension => mimes` for one material kind: the type's own extensions, each mapped to what
     * this object knows that extension may be, **intersected** with what the type permits. Where this
     * object has no opinion about an extension — Phase 14 offers `.md` and `.7z` that no generic bundle
     * lists — the type's list stands alone.
     *
     * The intersection matters: `CourseResourceType::Note` permits `application/pdf`, and without it a
     * `.txt` whose bytes were actually a PDF would pass. The per-extension map is the tighter statement
     * and the type's list is the contract's, so applying both is neither looser than §6.1 nor as loose
     * as it would allow.
     *
     * @return array<string, list<string>>
     */
    private static function mapForType(CourseResourceType $type): array
    {
        $known = [...self::DOCUMENTS, ...self::IMAGES, ...self::ARCHIVES, ...self::MEDIA];
        $permitted = $type->allowedMimes();
        $map = [];

        foreach ($type->allowedExtensions() as $extension) {
            if (in_array($extension, self::ALWAYS_REFUSED, true)) {
                continue;
            }

            $narrowed = isset($known[$extension])
                ? array_values(array_intersect($known[$extension], $permitted))
                : [];

            $map[$extension] = $narrowed === [] ? $permitted : $narrowed;
        }

        foreach (self::extraExtensions() as $extension) {
            if (isset(self::DESIGN_EXTRAS[$extension])) {
                $map[$extension] = self::DESIGN_EXTRAS[$extension];
            }
        }

        return $map;
    }

    /**
     * `institute.material_extra_extensions`, cleaned. It is **not** intersected with
     * `security.allowed_file_types` here — `allowedExtensions()` does that to the whole map, so an
     * extra that the security setting does not permit never reaches the field however this is set.
     *
     * @return list<string>
     */
    private static function extraExtensions(): array
    {
        $raw = settings_repo()->get('institute.material_extra_extensions');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e, " \t.")),
            explode(',', $raw),
        ), static fn (string $e): bool => $e !== ''));
    }

    /** A megabyte ceiling from a setting, with the contract's default when it is unreadable. */
    private static function settingMb(string $key, int $fallback): int
    {
        $value = settings_repo()->get($key);

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $fallback;
    }
}
