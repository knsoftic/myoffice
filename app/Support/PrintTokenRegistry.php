<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PrintTemplateType;

/**
 * Every `{token}` a print template may contain, and what each one is worth (phase-19-23 §6.13).
 *
 * The same pattern as `PermissionRegistry` and `SettingsRegistry`: **pure arrays, no database, no
 * container**. It is the single place a token name exists — the editor's insert menu, the save-time
 * validation that names unknown tokens, the preview's example values and the renderer's replacement
 * map all read this one declaration. A token added anywhere else is a token that renders empty.
 *
 * **[D-21-2] is the reason this class exists at all.** A template is HTML with `{tokens}`, never
 * Blade: storing Blade and rendering it would hand remote code execution to anybody holding
 * `print_templates.edit`. A fixed token list replaced by `str_replace` has no expression language to
 * exploit, and INV-21-5 says so in as many words.
 *
 * **`raw` is the dangerous flag and it is deliberately short.** Every token's value is HTML-escaped on
 * the way into the document unless it is declared `raw`, and the only `raw` tokens are ones **this
 * application builds itself** — a QR image, a photo, a logo, a signatory image, a results table, a
 * grade legend. None of them is user-typed text. A token that interpolated a student's name unescaped
 * would turn a name into markup, which is stored XSS with extra steps even inside a PDF.
 *
 * **Every value is formatted through `Format`** rather than by the caller, so a date-format change
 * restyles every future print and alters no past one — the printed document keeps its own snapshot
 * columns, and this only decides how they are rendered.
 */
final class PrintTokenRegistry
{
    /** Groups, in the order the editor's insert menu shows them. */
    public const GROUPS = [
        'document' => 'The document',
        'student' => 'The student',
        'course' => 'Course and batch',
        'marks' => 'Marks and grades',
        'institute' => 'Institute',
        'signatories' => 'Signatories',
    ];

    /**
     * The tokens each type declares, verbatim from §6.13's table.
     *
     * `formatter` names how `resolve()` renders the value: `text` (escaped), `date`, `percentage`,
     * `number`, or `raw` (inserted unescaped — see the class note).
     *
     * @return array<string, array{label: string, example: string, group: string, formatter: string}>
     */
    public static function tokens(PrintTemplateType $type): array
    {
        return match ($type) {
            PrintTemplateType::Certificate => self::certificate(),
            PrintTemplateType::StudentIdCard => self::studentIdCard(),
            PrintTemplateType::ResultCard => self::resultCard(),
        };
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    public static function all(): array
    {
        $all = [];

        foreach (PrintTemplateType::cases() as $type) {
            $all[$type->value] = self::tokens($type);
        }

        return $all;
    }

    /**
     * The tokens a template mentions that this type does not declare.
     *
     * **A warning, not a refusal.** An unknown token renders empty, which is survivable; refusing the
     * save would lose an hour of layout work over a typo. Naming each one is what makes the warning
     * actionable — "there is an unknown token somewhere" sends a designer hunting through 200 lines.
     *
     * @return list<string>
     */
    public static function unknownIn(PrintTemplateType $type, ?string $html): array
    {
        $known = array_keys(self::tokens($type));
        $found = self::mentionedIn($html);

        return array_values(array_diff($found, $known));
    }

    /**
     * Every `{token}` the HTML mentions, in the order they first appear, without duplicates.
     *
     * The pattern is deliberately narrow — lowercase, digits and underscores between single braces —
     * so `{{` never matches. A Blade echo is not a token and must not be treated as one; the
     * sanitiser strips it, and this refusing to see it is the second net.
     *
     * @return list<string>
     */
    public static function mentionedIn(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all('/(?<!\{)\{([a-z][a-z0-9_]*)\}(?!\})/', $html, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** Is this token declared for this type? */
    public static function knows(PrintTemplateType $type, string $token): bool
    {
        return array_key_exists($token, self::tokens($type));
    }

    /**
     * Tokens whose value is inserted **without** escaping. See the class note: each one is built by
     * this application, never typed by anybody.
     *
     * @return list<string>
     */
    public static function rawTokens(PrintTemplateType $type): array
    {
        return array_keys(array_filter(
            self::tokens($type),
            static fn (array $spec): bool => $spec['formatter'] === 'raw',
        ));
    }

    /**
     * The example values a preview renders with — **never a real student's data** (§6.13).
     *
     * A preview that pulled a live record would put a real name and a real certificate number on a
     * screen somebody is only designing a layout on, and would make the preview's correctness depend
     * on whether any student exists yet.
     *
     * @return array<string, string>
     */
    public static function examples(PrintTemplateType $type): array
    {
        $examples = [];

        foreach (self::tokens($type) as $token => $spec) {
            $examples[$token] = $spec['example'];
        }

        return $examples;
    }

    /**
     * Tokens grouped for the editor's insert menu, in `GROUPS` order.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public static function grouped(PrintTemplateType $type): array
    {
        $grouped = [];

        foreach (array_keys(self::GROUPS) as $group) {
            $inGroup = array_filter(
                self::tokens($type),
                static fn (array $spec): bool => $spec['group'] === $group,
            );

            if ($inGroup !== []) {
                $grouped[$group] = $inGroup;
            }
        }

        return $grouped;
    }

    // ===========================================================================================

    /**
     * @return array<string, array{label: string, example: string, group: string, formatter: string}>
     */
    private static function certificate(): array
    {
        return [
            'certificate_number' => self::t('Certificate number', 'CERT-2026-00147', 'document'),
            'verification_code' => self::t('Verification code', 'K7M2P9X4T6B8N3QD', 'document'),
            'verification_url' => self::t('Verification link', 'https://example.test/verify/K7M2P9X4T6B8N3QD', 'document'),
            'qr' => self::t('QR code image', '[QR code]', 'document', 'raw'),
            'issued_on' => self::t('Issued on', '14 March 2026', 'document', 'date'),

            'student_name' => self::t('Student name', 'Ayesha Siddiqui', 'student'),
            'father_name' => self::t('Father’s name', 'Muhammad Siddiqui', 'student'),
            'student_code' => self::t('Roll number', 'STU-002841', 'student'),
            'registration_number' => self::t('Registration number', 'REG-2025-0412', 'student'),

            'course_name' => self::t('Course', 'Full-Stack Web Development', 'course'),
            'batch_name' => self::t('Batch', 'WEB-2025-EVE-03', 'course'),
            'trainer_name' => self::t('Trainer', 'Bilal Ahmed', 'course'),
            'branch_name' => self::t('Branch', 'Gulberg Campus', 'course'),
            'course_start_date' => self::t('Course started', '6 October 2025', 'course', 'date'),
            'completion_date' => self::t('Completed on', '9 March 2026', 'course', 'date'),

            'grade' => self::t('Grade', 'A', 'marks'),
            'grade_point' => self::t('Grade points', '3.70', 'marks', 'number'),
            'percentage' => self::t('Percentage', '87.25%', 'marks', 'percentage'),
            'attendance_percentage' => self::t('Attendance', '92.00%', 'marks', 'percentage'),

            'company_name' => self::t('Institute name', 'My Office Institute', 'institute'),
            'company_logo' => self::t('Institute logo', '[logo]', 'institute', 'raw'),
            'footer_note' => self::t('Footer note', 'This certificate may be verified online.', 'institute'),

            'signatory_1_name' => self::t('Signatory 1 — name', 'Dr Nadia Khan', 'signatories'),
            'signatory_1_title' => self::t('Signatory 1 — title', 'Director', 'signatories'),
            'signatory_1_image' => self::t('Signatory 1 — signature', '[signature]', 'signatories', 'raw'),
            'signatory_2_name' => self::t('Signatory 2 — name', 'Imran Yousaf', 'signatories'),
            'signatory_2_title' => self::t('Signatory 2 — title', 'Head of Training', 'signatories'),
            'signatory_2_image' => self::t('Signatory 2 — signature', '[signature]', 'signatories', 'raw'),
            'signatory_3_name' => self::t('Signatory 3 — name', 'Sana Tariq', 'signatories'),
            'signatory_3_title' => self::t('Signatory 3 — title', 'Registrar', 'signatories'),
            'signatory_3_image' => self::t('Signatory 3 — signature', '[signature]', 'signatories', 'raw'),
        ];
    }

    /**
     * @return array<string, array{label: string, example: string, group: string, formatter: string}>
     */
    private static function studentIdCard(): array
    {
        return [
            'card_number' => self::t('Card number', 'ID-2026-00981', 'document'),
            'verification_code' => self::t('Verification code', 'R4W8L2J6Y9C5H1ZK', 'document'),
            'verification_url' => self::t('Verification link', 'https://example.test/verify/R4W8L2J6Y9C5H1ZK', 'document'),
            'qr' => self::t('QR code image', '[QR code]', 'document', 'raw'),
            'issued_on' => self::t('Issued on', '14 March 2026', 'document', 'date'),
            'valid_until' => self::t('Valid until', '13 March 2027', 'document', 'date'),

            'student_name' => self::t('Student name', 'Ayesha Siddiqui', 'student'),
            'father_name' => self::t('Father’s name', 'Muhammad Siddiqui', 'student'),
            'student_code' => self::t('Roll number', 'STU-002841', 'student'),
            'registration_number' => self::t('Registration number', 'REG-2025-0412', 'student'),
            'guardian_phone' => self::t('Guardian’s phone', '+92 300 1234567', 'student'),
            'photo' => self::t('Student photograph', '[photo]', 'student', 'raw'),

            'course_name' => self::t('Course', 'Full-Stack Web Development', 'course'),
            'batch_name' => self::t('Batch', 'WEB-2025-EVE-03', 'course'),
            'joining_date' => self::t('Joined on', '6 October 2025', 'course', 'date'),

            'company_name' => self::t('Institute name', 'My Office Institute', 'institute'),
            'company_logo' => self::t('Institute logo', '[logo]', 'institute', 'raw'),
            'branch_name' => self::t('Branch', 'Gulberg Campus', 'institute'),
            'branch_phone' => self::t('Branch phone', '+92 42 35800000', 'institute'),
            'branch_address' => self::t('Branch address', '12 Main Boulevard, Gulberg III, Lahore', 'institute'),
        ];
    }

    /**
     * @return array<string, array{label: string, example: string, group: string, formatter: string}>
     */
    private static function resultCard(): array
    {
        return [
            'issued_on' => self::t('Printed on', '14 March 2026', 'document', 'date'),

            'student_name' => self::t('Student name', 'Ayesha Siddiqui', 'student'),
            'student_code' => self::t('Roll number', 'STU-002841', 'student'),
            'registration_number' => self::t('Registration number', 'REG-2025-0412', 'student'),
            'father_name' => self::t('Father’s name', 'Muhammad Siddiqui', 'student'),
            'attendance_percentage' => self::t('Attendance', '92.00%', 'student', 'percentage'),

            'course_name' => self::t('Course', 'Full-Stack Web Development', 'course'),
            'batch_name' => self::t('Batch', 'WEB-2025-EVE-03', 'course'),
            'teacher_name' => self::t('Teacher', 'Bilal Ahmed', 'course'),

            'exam_name' => self::t('Exam', 'Midterm — HTML and CSS', 'marks'),
            'exam_type' => self::t('Kind of exam', 'Midterm', 'marks'),
            'exam_date' => self::t('Exam date', '2 February 2026', 'marks', 'date'),
            'obtained_marks' => self::t('Marks obtained', '87.00', 'marks', 'number'),
            'total_marks' => self::t('Out of', '100.00', 'marks', 'number'),
            'percentage' => self::t('Percentage', '87.00%', 'marks', 'percentage'),
            'grade' => self::t('Grade', 'A', 'marks'),
            'grade_point' => self::t('Grade points', '3.70', 'marks', 'number'),
            'result_status' => self::t('Passed or failed', 'Passed', 'marks'),
            'position' => self::t('Position in batch', '2nd', 'marks'),
            'remarks' => self::t('Remarks', 'Strong on the practical half.', 'marks'),

            // Built by the service, so raw — see the class note.
            'results_table' => self::t('Table of every exam', '[results table]', 'marks', 'raw'),
            'aggregate_percentage' => self::t('Overall percentage', '84.50%', 'marks', 'percentage'),
            'aggregate_grade' => self::t('Overall grade', 'A', 'marks'),
            'grade_scale_legend' => self::t('Grade scale key', '[grade scale]', 'marks', 'raw'),

            'company_name' => self::t('Institute name', 'My Office Institute', 'institute'),
            'company_logo' => self::t('Institute logo', '[logo]', 'institute', 'raw'),
        ];
    }

    /**
     * @return array{label: string, example: string, group: string, formatter: string}
     */
    private static function t(string $label, string $example, string $group, string $formatter = 'text'): array
    {
        return [
            'label' => $label,
            'example' => $example,
            'group' => $group,
            'formatter' => $formatter,
        ];
    }
}
