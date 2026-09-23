<?php

declare(strict_types=1);

namespace Tests\Feature\Institute\Materials;

use App\DataObjects\Files\FileRules;
use App\DataObjects\Files\FileTarget;
use App\DataObjects\Files\StoredFile;
use App\DataObjects\Files\StreamOptions;
use App\Enums\CourseResourceType;
use App\Services\Files\Exceptions\FileRuleException;
use App\Services\Files\SecureFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * §6.1's ten-step gate — the phase's security centre (phase-19-23 §11, INV-19-2).
 *
 * Every refusal here is checked by *behaviour* rather than by reading the whitelist back: a test that
 * asserts the constant contains `php` proves only that somebody typed it, while one that feeds the
 * service a `.php` proves the gate runs.
 */
final class SecureFileGateTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /*
    |--------------------------------------------------------------------------
    | What it refuses
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileUploads(): array
    {
        return [
            'a php script' => ['shell.php', '<?php echo 1;'],
            'a php script with a version suffix' => ['shell.php5', '<?php echo 1;'],
            'a double extension hiding a script' => ['cv.pdf.php', self::PDF],
            'a double extension hiding a document' => ['scan.pdf.docx', self::PDF],
            'an htaccess' => ['.htaccess', 'Options +ExecCGI'],
            'no extension at all' => ['README', 'hello'],
            'an empty file' => ['empty.pdf', ''],
            'text pretending to be a pdf' => ['fake.pdf', 'this is plainly not a pdf'],
        ];
    }

    #[Test]
    #[DataProvider('hostileUploads')]
    public function the_gate_refuses_a_hostile_upload(string $name, string $content): void
    {
        $this->expectException(FileRuleException::class);

        app(SecureFileService::class)->validate(
            UploadedFile::fake()->createWithContent($name, $content),
            FileRules::courseMaterial(CourseResourceType::Pdf),
        );
    }

    /**
     * A PDF renamed `.docx` is refused by **content**, not by name — which is the whole of §111 and
     * the one check a `mimes:` rule cannot make.
     */
    #[Test]
    public function content_that_disagrees_with_the_extension_is_refused_naming_both(): void
    {
        try {
            app(SecureFileService::class)->validate(
                UploadedFile::fake()->createWithContent('report.docx', self::PDF),
                FileRules::courseMaterial(CourseResourceType::Document),
            );

            $this->fail('A PDF renamed .docx was accepted.');
        } catch (FileRuleException $e) {
            $message = implode(' ', $e->validator->errors()->all());

            $this->assertStringContainsString('application/pdf', $message, 'The message names what the content actually is.');
            $this->assertStringContainsString('.docx', $message, 'The message names what it claimed to be.');
        }
    }

    /**
     * **`CourseResourceType::Image` lists `image/svg+xml` and offers a `.svg`** — correctly, because
     * Phase 14 uses that enum for the public syllabus where an SVG diagram is harmless. Served from
     * our own origin it is stored XSS, so this phase's `ALWAYS_REFUSED` drops it first.
     */
    #[Test]
    public function an_svg_is_refused_even_though_the_shared_enum_permits_it(): void
    {
        $this->assertContains('image/svg+xml', CourseResourceType::Image->allowedMimes());
        $this->assertContains('svg', CourseResourceType::Image->allowedExtensions());

        $rules = FileRules::courseMaterial(CourseResourceType::Image);

        $this->assertNotContains('svg', $rules->allowedExtensions(), 'The uploader narrows the enum; it never widens it.');

        $this->expectException(FileRuleException::class);

        app(SecureFileService::class)->validate(
            UploadedFile::fake()->createWithContent('logo.svg', '<svg onload="alert(1)"></svg>'),
            $rules,
        );
    }

    /** The field's own list is intersected with the security setting — it can narrow, never widen. */
    #[Test]
    public function the_security_setting_narrows_a_field_and_never_widens_it(): void
    {
        $this->setting('security.allowed_file_types', 'pdf');

        $documents = FileRules::courseMaterial(CourseResourceType::Document);

        $this->assertSame([], $documents->allowedExtensions(), 'Document offers doc/docx/rtf/odt/txt, none of which the setting now permits.');

        $this->expectException(FileRuleException::class);

        app(SecureFileService::class)->validate(
            UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
            $documents,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | What it stores
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_stored_file_is_named_by_the_server_and_never_by_the_client(): void
    {
        $stored = app(SecureFileService::class)->store(
            UploadedFile::fake()->createWithContent('Week 1 — Handout (final).pdf', self::PDF),
            FileTarget::courseMaterial(7),
            FileRules::courseMaterial(CourseResourceType::Pdf),
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-z]{26}\.pdf$/',
            basename($stored->path),
            'INV-19-2: the name on disk is a ULID chosen by the server.',
        );

        $this->assertSame('Week 1 — Handout (final).pdf', $stored->originalName, 'The client name survives only on the row.');
        $this->assertSame('application/pdf', $stored->mimeType, 'The MIME is sniffed from the bytes.');
        $this->assertSame('pdf', $stored->extension, 'The extension is derived, not taken from the request.');
        $this->assertSame(FileRules::DISK_PRIVATE, $stored->disk);
        $this->assertSame(hash('sha256', self::PDF), $stored->checksumSha256);
        $this->assertTrue(app(SecureFileService::class)->exists($stored));
    }

    /** §6.3 binds the path, so it is asserted rather than trusted. */
    #[Test]
    public function the_path_follows_the_layout_the_contract_binds(): void
    {
        $this->assertSame(
            'institute/assignments/3/submissions/9/2',
            FileTarget::submission(3, 9, 2)->directory,
        );

        $this->assertSame(
            'institute/assignments/3/brief',
            FileTarget::assignmentBrief(3)->directory,
        );

        $this->assertStringStartsWith(
            'institute/courses/7/materials/',
            FileTarget::courseMaterial(7)->directory,
            'A material partitions by year and month so one course does not grow without bound.',
        );
    }

    /**
     * A path is assembled from integers and known literals only, so a traversal has no surface at all
     * (§6.3). The object refuses to hold one rather than relying on nobody passing one.
     */
    #[Test]
    public function a_file_target_cannot_be_talked_into_a_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileTarget(['institute', '../../etc']);
    }

    #[Test]
    public function a_file_target_refuses_a_zero_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileTarget(['institute', 'assignments', 0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    */

    /**
     * Every file is checked before any file is written, so one bad file in ten leaves nothing behind
     * rather than nine orphans nobody will ever find.
     */
    #[Test]
    public function a_batch_with_one_bad_file_writes_nothing_at_all(): void
    {
        $service = app(SecureFileService::class);
        $target = FileTarget::submission(3, 9, 1);
        $before = count(Storage::disk('private')->allFiles());

        try {
            $service->storeMany([
                UploadedFile::fake()->createWithContent('one.pdf', self::PDF),
                UploadedFile::fake()->createWithContent('two.pdf', self::PDF),
                UploadedFile::fake()->createWithContent('three.php', '<?php'),
            ], $target, FileRules::submission());

            $this->fail('A batch containing a .php was accepted.');
        } catch (FileRuleException) {
            // Expected.
        }

        $this->assertCount(
            $before,
            Storage::disk('private')->allFiles(),
            'The two good files must not be left on disk with no row pointing at them.',
        );
    }

    #[Test]
    public function a_batch_over_the_per_call_cap_is_refused(): void
    {
        $this->expectException(FileRuleException::class);

        app(SecureFileService::class)->storeMany(
            array_fill(0, 11, UploadedFile::fake()->createWithContent('x.pdf', self::PDF)),
            FileTarget::submission(3, 9, 1),
            FileRules::submission(),
        );
    }

    /**
     * The replacement lands first and the old bytes go only once the caller's transaction commits, so
     * a failed swap loses the new file rather than both.
     */
    #[Test]
    public function replacing_a_file_keeps_the_old_one_until_the_swap_commits(): void
    {
        $service = app(SecureFileService::class);
        $target = FileTarget::courseMaterial(7);
        $rules = FileRules::courseMaterial(CourseResourceType::Pdf);

        $original = $service->store($this->upload('v1.pdf'), $target, $rules);
        $replacement = $service->replace($original, $this->upload('v2.pdf', '% v2'), $target, $rules);

        $this->assertNotSame($original->path, $replacement->path, 'A replacement is a new ULID, never an overwrite.');
        $this->assertTrue($service->exists($replacement));
        $this->assertFalse($service->exists($original), 'With no open transaction the after-commit deletion runs at once.');
    }

    /*
    |--------------------------------------------------------------------------
    | Serving
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_private_file_is_served_with_the_hardened_headers(): void
    {
        $service = app(SecureFileService::class);
        $stored = $service->store($this->upload(), FileTarget::courseMaterial(7), FileRules::courseMaterial(CourseResourceType::Pdf));

        $headers = $service->stream($stored)->headers;

        $this->assertSame('application/pdf', $headers->get('content-type'), 'The stored MIME, never one guessed from the URL.');
        $this->assertSame('nosniff', $headers->get('x-content-type-options'));
        $this->assertSame(StreamOptions::CONTENT_SECURITY_POLICY, $headers->get('content-security-policy'));
        $this->assertSame('none', $headers->get('accept-ranges'), '§6.1: a range request would have to re-run the whole permission chain.');
        $this->assertStringContainsString('no-store', (string) $headers->get('cache-control'));
    }

    /** `inline` is a request, not a decision: only a PDF or an image gets it. */
    #[Test]
    public function inline_is_downgraded_for_anything_that_is_not_a_pdf_or_an_image(): void
    {
        $service = app(SecureFileService::class);
        $stored = $service->store($this->upload(), FileTarget::courseMaterial(7), FileRules::courseMaterial(CourseResourceType::Pdf));

        $this->assertStringStartsWith(
            'inline',
            (string) $service->stream($stored, StreamOptions::inline())->headers->get('content-disposition'),
        );

        $asZip = new StoredFile('private', $stored->path, 'src.zip', 'zip', 'application/zip', 10, null);

        $this->assertStringStartsWith(
            'attachment',
            (string) $service->stream($asZip, StreamOptions::inline())->headers->get('content-disposition'),
            'A zip asked for inline comes back as an attachment.',
        );
    }

    /**
     * A quote in the client's filename must not be able to close the directive and start another.
     *
     * The words themselves are allowed to survive — `evil.pdf` as *text* inside one filename is
     * harmless. What must not survive is the punctuation that turns one directive into two: the
     * quote and the semicolon.
     */
    #[Test]
    public function a_hostile_filename_cannot_break_out_of_the_disposition_header(): void
    {
        $service = app(SecureFileService::class);
        $stored = $service->store($this->upload(), FileTarget::courseMaterial(7), FileRules::courseMaterial(CourseResourceType::Pdf));

        $hostile = new StoredFile('private', $stored->path, 'a"; filename="evil.pdf', 'pdf', 'application/pdf', 10, null);
        $disposition = (string) $service->stream($hostile)->headers->get('content-disposition');

        $this->assertSame(1, substr_count($disposition, 'filename='), 'Exactly one filename directive survives.');
        $this->assertSame(
            2,
            substr_count($disposition, '"'),
            'Exactly the opening and closing quote — the one the client supplied is stripped.',
        );
        $this->assertSame(1, substr_count($disposition, ';'), 'One separator, between the disposition type and the filename.');
        $this->assertStringEndsWith('.pdf"', $disposition, 'The extension is the server-decided one.');
    }

    #[Test]
    public function a_row_whose_bytes_are_gone_is_a_404_rather_than_a_disk_error(): void
    {
        $service = app(SecureFileService::class);
        $stored = $service->store($this->upload(), FileTarget::courseMaterial(7), FileRules::courseMaterial(CourseResourceType::Pdf));

        $service->delete($stored);

        $this->expectException(NotFoundHttpException::class);

        $service->stream($stored);
    }

    /*
    |--------------------------------------------------------------------------
    | The disk
    |--------------------------------------------------------------------------
    */

    /**
     * §6.3 and D115. `private` is its own disk so it can carry `serve => false`: no framework route
     * may hand out one of these files, because §6.2 [D-19-4] puts the authorisation decision at the
     * moment the bytes are served.
     */
    #[Test]
    public function the_private_disk_is_rooted_where_the_contract_says_and_is_never_served_by_a_route(): void
    {
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.private.root'));
        $this->assertFalse((bool) config('filesystems.disks.private.serve'));
        $this->assertNull(config('filesystems.disks.private.url'), 'A disk with no URL cannot produce a public link.');
    }

    private function upload(string $name = 'handout.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF.$extra);
    }

    private function setting(string $key, mixed $value): void
    {
        settings_repo()->asSystem(fn ($settings) => $settings->set($key, $value));
    }
}
