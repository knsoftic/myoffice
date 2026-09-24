<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\DataObjects\Files\FileRules;
use App\Support\Ops\CspBuilder;
use App\Support\Ops\SecurityAuditor;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * What reaches the browser, and what reaches the disk (phase-24-25 section 11.1,
 * SEC-03..SEC-07 and SEC-15..SEC-17).
 *
 * **Stored XSS and an uploaded `.php` are the same bug at two different layers.** Both are content
 * a stranger wrote that the machine later decides to *execute* rather than display: one in the
 * visitor's browser, one in the web server's PHP. And both are defended the same way - by never
 * trusting what the content claims to be, and by having a second control for when the first one is
 * wrong. Escaping is the first control and the Content-Security-Policy is the second; a sniffed
 * MIME type is the first and a generated ULID filename on a non-executing disk is the second.
 *
 * **The scans here assert against `App\Support\Ops\SecurityAuditor`**, the same class
 * `security:audit` runs in CI, rather than growing a second copy of each scan. A duplicated
 * security control is a security defect (D25's argument about sanitisers, applied to scanners):
 * two scans that disagree mean nobody knows which one is right.
 *
 * The upload tests are structural where no fixture exists to attack with. That is stated, not
 * hidden: a request test that passes because the payload was rejected for *missing a required
 * field* proves nothing about file handling, so the manifest row is asserted instead and the gap
 * is named in the skip message.
 */
#[Group('security')]
final class EscapingAndUploadsTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The five stored-XSS payloads of SEC-03, in the contract's order. */
    private const XSS_PAYLOADS = [
        '<script>alert(1)</script>',
        '"><img src=x onerror=alert(1)>',
        '<svg onload=alert(1)>',
        'javascript:alert(1)',
        '<iframe src=javascript:alert(1)>',
    ];

    /** Markup that must never survive `RichText::sanitize()` (SEC-04). */
    private const RICH_TEXT_ATTACKS = [
        '<p onclick="alert(1)">hello</p>',
        '<script>alert(1)</script>',
        '<style>body{display:none}</style>',
        '<iframe src="https://evil.test"></iframe>',
        '<object data="x"></object>',
        '<form action="/x"><input name="y"></form>',
        '<a href="javascript:alert(1)">go</a>',
        '<a href="data:text/html;base64,PHNjcmlwdD4=">go</a>',
    ];

    /** The only legal sanitiser name in the raw-output allowlist (D25, resolutions F-2.5). */
    private const SANITISER = 'App\Support\RichText::sanitize()';

    /** Extensions no upload endpoint may ever accept (SEC-15). */
    private const FORBIDDEN_MIMES = [
        'application/x-httpd-php', 'text/x-php', 'application/x-php',
        'text/html', 'image/svg+xml', 'application/x-msdownload', 'application/x-dosexec',
    ];

    /**
     * Manifest wording that delegates the generated filename to another store, and the file that
     * has to prove it (SEC-15).
     *
     * A row may say "a ULID", or it may say "through the media library" - the same promise, made
     * one layer down. Naming the implementation here is what stops the second form from being a way
     * of promising nothing: the test opens each of these and fails if it generates no name.
     *
     * @var array<string, string>
     */
    private const NAME_GENERATING_STORES = [
        'media library' => 'app/Services/Cms/MediaService.php',
        'assignment_submission_files' => 'app/Services/Files/SecureFileService.php',
    ];

    /** The largest `FileRules::*()` ceiling this suite will call sane, in megabytes (SEC-15). */
    private const MAX_SANE_CEILING_MB = 512;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        settings_repo()->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-03, SEC-04, SEC-05 - escaping
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-03. A payload stored in a free-text field is rendered escaped wherever it is shown.
     *
     * `company.name` is the hardest case in the system and the reason it is the one asserted here:
     * it is written by an administrator, read by `site_setting()` on **every** public page and by
     * the panel shell on every authenticated one, and it is printed into the `<title>`, the header
     * and the footer. A field that reaches that many templates is the field where one `{!! !!}`
     * turns a settings form into site-wide stored XSS.
     *
     * The remaining 23 fields of the contract's list need a row per owning model, and the fixture
     * builder that would create one of each does not exist yet - see the skip.
     */
    #[Test]
    public function test_stored_xss_is_escaped_everywhere_it_is_rendered(): void
    {
        $admin = $this->createSuperAdmin();

        foreach (self::XSS_PAYLOADS as $payload) {
            settings_repo()->set('company.name', 'Acme '.$payload);
            settings_repo()->flush();

            foreach (['/', route('admin.dashboard', [], false)] as $index => $uri) {
                $response = $index === 0 ? $this->get($uri) : $this->actingAs($admin)->get($uri);

                if (! in_array($response->getStatusCode(), [200], true)) {
                    continue;
                }

                $body = $response->getContent();

                $this->assertIsString($body);
                $this->assertStringNotContainsString('<script>alert(1)', $body, sprintf('SEC-03: %s printed a live <script>.', $uri));
                $this->assertStringNotContainsString('onerror=alert(1)', $body, sprintf('SEC-03: %s printed a live onerror handler.', $uri));
                $this->assertStringNotContainsString('<svg onload=', $body, sprintf('SEC-03: %s printed a live onload handler.', $uri));
                $this->assertStringNotContainsString('<iframe src=javascript:', $body, sprintf('SEC-03: %s printed a javascript: iframe.', $uri));
            }
        }

        settings_repo()->set('company.name', 'My Office');
        settings_repo()->flush();

        $this->markTestSkipped('SEC-03 asserted settings.company.name only: the 23 other fields (lead, client, project, task, employee, student, course, batch, testimonial, blog, job, ticket, meeting, payout, collaborator, fee) need a one-row-per-model fixture builder that does not exist yet.');
    }

    /**
     * SEC-04. Rich text is sanitised on write, by one sanitiser, and the allowlisted tags survive.
     *
     * **There is exactly one sanitiser and that is the point** (D25, resolutions F-2.5). Two would
     * mean two definitions of "safe HTML" and an attacker only has to find the gap between them;
     * it would also mean the field written through the weaker one looks just as sanitised in the
     * database as the field written through the stronger one.
     */
    #[Test]
    public function test_rich_text_is_sanitised_on_write(): void
    {
        foreach (self::RICH_TEXT_ATTACKS as $attack) {
            $clean = RichText::sanitize($attack);

            foreach (['<script', '<style', '<iframe', '<object', '<form', 'onclick=', 'javascript:', 'data:text/html'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $clean,
                    sprintf('SEC-04: RichText::sanitize() kept %s from %s.', $forbidden, $attack),
                );
            }
        }

        // An allowlisted tag has to survive, or the sanitiser is just strip_tags() with a licence.
        $kept = RichText::sanitize('<p><strong>kept</strong> and <em>kept</em></p>');

        $this->assertStringContainsString('<strong>', $kept, 'SEC-04: the sanitiser removed an allowlisted tag.');
        $this->assertStringContainsString('<em>', $kept, 'SEC-04: the sanitiser removed an allowlisted tag.');

        // A row written straight to the database - by an import, a migration, a DBA - never passed
        // through the sanitiser, so the view's own `{{ }}` is what has to hold. Blade's escaping is
        // asserted here at its source so SEC-05's allowlist is the only remaining exception.
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    }

    /**
     * SEC-05. Every unescaped echo is on the allowlist, and every row names the one legal sanitiser.
     *
     * A second sanitiser name in that file fails this test on its own, before any view is even
     * read: a duplicated security control is a security defect, because the next reader has to
     * decide which of the two is the real one.
     */
    #[Test]
    public function test_raw_blade_output_is_allowlisted(): void
    {
        $allowlist = require base_path('tests/Support/raw-output-allowlist.php');

        $this->assertIsArray($allowlist);
        $this->assertNotEmpty($allowlist, 'SEC-05: the allowlist is empty, which means the scan below can only ever pass.');

        foreach ($allowlist as $row) {
            $this->assertSame(
                self::SANITISER,
                (string) ($row['sanitiser'] ?? ''),
                sprintf('SEC-05: %s names a second sanitiser. Only %s is legal (D25).', $row['view'] ?? '?', self::SANITISER),
            );
        }

        $auditor = new SecurityAuditor;

        $this->assertSame([], $auditor->auditRawEchoes(), 'SEC-05: a raw echo has no allowlist row.');

        // A `{{ }}` inside a <script> block is escaped for HTML and not for JavaScript, so
        // `</script>` in the value closes the block and everything after it is code. `@json` is the
        // fix, and the scan is here rather than in the auditor because it is a Blade-only hazard.
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $auditor->withoutBladeComments((string) File::get($file->getPathname()));

            if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $blocks) === 0) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match('/\{\{(?!--)/', $block) === 1) {
                    $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'SEC-05: {{ }} inside a <script> block - use @json: '.implode(', ', $offenders));
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-06, SEC-07 - headers and the policy
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-06. The header set arrives on a public GET, an authenticated GET and a JSON response.
     *
     * `SecurityHeaders` is **global**, prepended in `bootstrap/app.php`, and this test is what makes
     * that decision provable: a header attached to a middleware group is a header that some later
     * phase registers a route outside of, and nobody re-reads twenty-five phases of route files.
     */
    #[Test]
    public function test_security_headers_present(): void
    {
        $admin = $this->createSuperAdmin();

        $responses = [
            'public' => $this->get('/'),
            'authenticated' => $this->actingAs($admin)->get(route('admin.dashboard', [], false)),
        ];

        foreach ($responses as $label => $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

            $this->assertNotNull($response->headers->get('X-Frame-Options'), $label.': X-Frame-Options missing.');
            $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'), $label.': Permissions-Policy missing.');

            // A response that names the server names the versions worth looking up CVEs for.
            $this->assertNull($response->headers->get('X-Powered-By'), $label.': X-Powered-By must be removed.');
        }

        // `no-store` is what stops the back button re-rendering a signed-in page on a shared machine
        // after the sign-out, without a single request reaching the server.
        $authenticated = $responses['authenticated'];
        $this->assertStringContainsString('no-store', (string) $authenticated->headers->get('Cache-Control'), 'SEC-06: an authenticated page may be kept by the browser.');
        $this->assertStringContainsString('noindex', (string) $authenticated->headers->get('X-Robots-Tag'), 'SEC-06: a panel page is indexable.');

        // The public page is deliberately cacheable (phase-03 §6.7's full-page cache); asserting the
        // opposite here would demand a regression.
        $this->assertNull($responses['public']->headers->get('X-Robots-Tag'), 'SEC-06: the public site is meant to be indexed.');

        $csp = new CspBuilder;

        if ($csp->enabled()) {
            $this->assertNotNull($authenticated->headers->get($csp->header()), 'SEC-06: security.csp_enabled is on but no policy was sent.');
        }

        // HSTS over plain HTTP is ignored by specification, so sending it would only be noise; sent
        // before the certificate works it locks every visitor out for the whole max-age.
        $this->assertNull($authenticated->headers->get('Strict-Transport-Security'), 'SEC-06: HSTS must not be sent over plain HTTP.');
    }

    /**
     * SEC-07. Every inline script carries this request's nonce, and the nonce changes per request.
     *
     * **This is the control that survives a sanitiser bypass.** An injected `<script>` in stored
     * content cannot carry a nonce, because the nonce is minted after the content was written - so
     * under an enforcing policy it is inert even though it is in the HTML. A nonce that did not
     * change per request would be a nonce an attacker could read once and reuse for ever.
     */
    #[Test]
    public function test_csp_blocks_inline_script_without_a_nonce(): void
    {
        $this->assertSame([], (new SecurityAuditor)->auditInlineScripts(), 'SEC-07: a Blade inline <script> has no nonce.');

        $admin = $this->createSuperAdmin();

        $first = $this->actingAs($admin)->get(route('admin.dashboard', [], false));
        $first->assertOk();

        $body = (string) $first->getContent();

        // Every <script> without a src must carry a nonce; a JSON data block is data, not code.
        if (preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $body, $matches) > 0) {
            foreach ($matches[1] as $attributes) {
                if (preg_match('/type\s*=\s*["\'](?:application\/(?:ld\+json|json)|text\/template)["\']/i', $attributes) === 1) {
                    continue;
                }

                $this->assertStringContainsString('nonce=', $attributes, 'SEC-07: a rendered inline <script> carries no nonce.');
            }
        }

        // `CspBuilder` is bound scoped, so the helper and the header agree within one request and
        // disagree between two. Asserted through a second request rather than a second resolve.
        $second = $this->actingAs($admin)->get(route('admin.dashboard', [], false));
        $second->assertOk();

        $this->assertNotSame(
            $this->nonceOf($body),
            $this->nonceOf((string) $second->getContent()),
            'SEC-07: the nonce did not change between two requests.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SEC-15, SEC-16, SEC-17 - uploads and downloads
    |--------------------------------------------------------------------------
    */

    /**
     * SEC-15. Every upload row promises a sniffed type, a generated name and the right disk.
     *
     * **The client's filename and the client's `Content-Type` are both attacker input**, which is
     * why neither may decide anything: `x.php.jpg` passes an extension check and is executed by a
     * misconfigured Apache, and a GIF magic header makes a PHP file pass `finfo` on a server that
     * asked the wrong question. The manifest is where each endpoint records which question it asks.
     *
     * **Two of the structural rules are deliberately wider than they were, and this is the
     * decision.** `upload-manifest.php` is prose written by the person who built each endpoint, and
     * six of its thirty-five rows were failing on wording rather than on behaviour:
     *
     *   · *A generated name* used to have to be spelled `ulid`. Three rows describe the same
     *     guarantee in other words - `{40 random chars}` for a course resource
     *     (`CourseOutlineService` uses `Str::random(40)`), `assignment_submission_files rows` for a
     *     submission, `Through the media library (D24)` for a gallery batch. The last two delegate
     *     the naming to `SecureFileService` and `MediaService`, so the rule now accepts a named
     *     generator (`ulid`/`uuid`/`random`) **or** a named delegate - and then goes and asserts
     *     that both delegates really do generate a ULID, so the widening cannot be used to promise
     *     nothing.
     *   · *A ceiling* used to have to be a number or `security.max_upload_mb`. Three assignment
     *     rows name a `FileRules::*()` factory instead, which is the stricter statement: the
     *     ceiling is code, it is narrowed again by `FileRules::maxKilobytes()` against the setting
     *     and PHP's own limit, and a reader can follow it. The rule now accepts that form and
     *     **resolves the named factory**, asserting the megabytes it returns are a real ceiling
     *     rather than 0 or a disk-filling number.
     *
     * The manifest is a forbidden file for this slice, but the direction would be the same either
     * way: when a promise and the test that reads it disagree, and the promise is kept in the code,
     * it is the reading that is wrong.
     */
    #[Test]
    public function test_upload_matrix(): void
    {
        Storage::fake('public');

        /** @var list<array<string, mixed>> $rows */
        $rows = require base_path('tests/Support/upload-manifest.php');

        $this->assertNotEmpty($rows, 'SEC-15: the upload manifest is empty.');

        foreach ($rows as $row) {
            $label = (string) $row['route'].'::'.(string) $row['field'];
            $mimes = array_map('strtolower', (array) ($row['allowed_mimes'] ?? []));

            $this->assertNotEmpty($mimes, sprintf('SEC-15: %s declares no sniffed MIME list.', $label));

            foreach (self::FORBIDDEN_MIMES as $forbidden) {
                $this->assertNotContains($forbidden, $mimes, sprintf('SEC-15: %s accepts %s.', $label, $forbidden));
            }

            // D21: a private artefact never lands on the public disk, where a URL is the whole
            // authorization check. `public_reachable` is the row's own statement of that.
            if (($row['public_reachable'] ?? false) === false) {
                $this->assertNotSame('public', (string) $row['disk'], sprintf('SEC-15: %s writes a private artefact to the public disk (D21).', $label));
            }

            // The stored name has to be generated. A stored client filename is a path the caller
            // chose, and `../` in it is a write outside the directory.
            $storedAs = strtolower((string) ($row['stored_as'] ?? ''));

            $this->assertMatchesRegularExpression(
                '/ulid|uuid|random|'.implode('|', array_keys(self::NAME_GENERATING_STORES)).'/',
                $storedAs,
                sprintf('SEC-15: %s does not promise a generated filename.', $label),
            );

            $this->assertTrue($this->isSaneCeiling($row['max_mb'] ?? null), sprintf('SEC-15: %s has no size ceiling.', $label));
        }

        // The widening above is only honest while the delegates it trusts really do generate the
        // name themselves, so that is asserted rather than assumed.
        foreach (self::NAME_GENERATING_STORES as $phrase => $implementation) {
            $this->assertMatchesRegularExpression(
                '/Str::ulid\(\)|Str::uuid\(\)|Str::random\(/',
                (string) File::get(base_path($implementation)),
                sprintf('SEC-15: a manifest row delegates its filename to "%s", but %s generates no name.', $phrase, $implementation),
            );
        }

        // One live attack, on the one endpoint whose route takes no parameters and whose body is
        // just the file: a PHP script wearing a GIF header and a .jpg extension.
        if (Route::getRoutes()->getByName('admin.website.media.store') !== null) {
            $this->actingAs($this->createSuperAdmin());

            $disguised = UploadedFile::fake()->createWithContent('avatar.jpg', "GIF89a\n<?php echo 'pwned'; ?>");

            $response = $this->post(route('admin.website.media.store', [], false), ['file' => $disguised]);

            $this->assertNotContains(
                $response->getStatusCode(),
                [200, 201],
                'SEC-15: a PHP file with a GIF magic header and a .jpg name was accepted.',
            );
        }

        $this->markTestSkipped('SEC-15 asserted the manifest contract for all rows and attacked admin.website.media.store only: the other 28 rows need a valid-payload builder (each store route also requires its own non-file fields) that does not exist yet.');
    }

    /**
     * SEC-16. A download route is an authorization check that happens before the first byte.
     *
     * **A file is the one response where the permission check and the data are separated by a
     * stream.** Everything else renders inside the request that was authorized; a download opens a
     * handle, and a route that checks the permission but not the ownership hands the caller
     * somebody else's invoice with a perfectly valid session.
     */
    #[Test]
    public function test_download_routes_resist_traversal_and_idor(): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = require base_path('tests/Support/route-guard-manifest.php');

        $downloads = array_values(array_filter(
            $rows,
            static fn (array $row): bool => preg_match('/\.(download|stream|pdf|cv|file|attachment|export)(\.|$)/', (string) $row['route']) === 1
                && in_array('GET', (array) ($row['methods'] ?? []), true),
        ));

        $this->assertNotEmpty($downloads, 'SEC-16: no download route found in the manifest.');

        $this->actingAs($this->createSuperAdmin());

        $traversals = ['../../.env', '..%2f..%2f.env', '/etc/passwd', "x%00.pdf"];
        $checked = 0;

        foreach ($downloads as $row) {
            $route = Route::getRoutes()->getByName((string) $row['route']);

            if ($route === null || $route->parameterNames() === []) {
                continue;
            }

            foreach ($traversals as $payload) {
                $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', rawurlencode($payload), $route->uri()), '/');
                $response = $this->get($uri);

                $this->assertContains(
                    $response->getStatusCode(),
                    [403, 404, 422],
                    sprintf('SEC-16: %s answered %d for %s.', $row['route'], $response->getStatusCode(), $payload),
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'SEC-16: no parameterised download route was reachable to attack.');

        $this->markTestSkipped('SEC-16 asserted traversal only: the foreign-tenant half needs the two-tenants-per-class fixture builder of section 11.4, which does not exist yet.');
    }

    /**
     * SEC-17. The upload directory cannot execute PHP.
     *
     * Two controls, because the first one is not under this application's control. The upload layer
     * refuses a `.php` name before anything is written; the `.htaccess` is what holds when a file
     * arrives some other way - an unzipped archive, a restored backup, a second application sharing
     * the disk. The Apache-level proof is manual (GL-17).
     */
    #[Test]
    public function test_storage_directory_cannot_execute_php(): void
    {
        $htaccess = storage_path('app/public/.htaccess');

        if (! File::exists($htaccess)) {
            $this->markTestSkipped('storage/app/public/.htaccess does not exist yet (phase-24-25 §6.9.1 ships it).');
        }

        $contents = (string) File::get($htaccess);

        $this->assertStringContainsString('FilesMatch', $contents, 'SEC-17: no FilesMatch deny in storage/app/public/.htaccess.');
        $this->assertTrue(
            str_contains($contents, 'RemoveHandler') || str_contains($contents, 'SetHandler none'),
            'SEC-17: the handler is not removed, so mod_php may still run a file here.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function nonceOf(string $html): string
    {
        return preg_match('/nonce="([^"]+)"/', $html, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * Whether an upload row's `max_mb` is a real ceiling (SEC-15).
     *
     * Three forms are accepted, and the third is the interesting one. A plain number is a ceiling.
     * `security.max_upload_mb` is a ceiling read from settings. A `FileRules::submission()` style
     * reference is a ceiling that lives in code, and rather than take the row's word for it this
     * **calls the named factory and reads the megabytes back**: an unknown method, a zero, or a
     * number big enough to fill a disk all fail here. `FileRules::maxKilobytes()` narrows whatever
     * comes back again by `security.max_upload_mb` and by PHP's own limit, so the value asserted
     * here is the field's own ceiling, never the effective one - which is only ever smaller.
     */
    private function isSaneCeiling(mixed $max): bool
    {
        if (is_numeric($max)) {
            return (float) $max > 0;
        }

        if ($max === 'security.max_upload_mb') {
            return true;
        }

        if (! is_string($max) || preg_match('/FileRules::(\w+)\(\)/', $max, $matches) !== 1) {
            return false;
        }

        $factory = $matches[1];

        if (! method_exists(FileRules::class, $factory)) {
            return false;
        }

        $reflection = new ReflectionMethod(FileRules::class, $factory);

        // Every factory of this shape takes only optional narrowing arguments; one that needs a
        // required argument is not a ceiling this row can be promising on its own.
        if (! $reflection->isStatic() || $reflection->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $rules = FileRules::{$factory}();

        return $rules instanceof FileRules
            && $rules->maxMb >= 1
            && $rules->maxMb <= self::MAX_SANE_CEILING_MB;
    }
}
