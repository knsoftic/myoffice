<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Enums\ApprovalStatus;
use App\Enums\ContactInquiryStatus;
use App\Models\Cms\BlogPost;
use App\Models\Cms\JobApplication;
use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The permission matrix of phase-04 (§4, §7.2, §9.1; CLAUDE.md rule 7 — authorization is decided on the
 * backend), with the named authorization cases of §11:
 *
 *   · every admin marketing route: 403 without its permission while holding every other marketing
 *     permission, 403 with no permission at all, and — writes included — nothing written; the route's
 *     success answer with exactly its permission (plus the one policy companion `route-pending` needs);
 *   · a guest is sent to sign in; each seeded role holds exactly its §9.1 / integration §7 grant and is
 *     refused everything outside it;
 *   · §11 tests 7, 17, 25, 30, 37, 48 (the 403 half) and 58.
 */
final class MarketingAuthorizationMatrixTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    /** The four portal roles: they never pass `panel:admin` (§9.3). */
    private const PORTAL_ROLES = ['Student', 'Teacher', 'Client', 'Collaborator'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');
    }

    /*
    |--------------------------------------------------------------------------
    | The matrix — every route, both directions
    |--------------------------------------------------------------------------
    */

    public function test_contract_07_authorization_matrix_every_marketing_admin_route_both_directions(): void
    {
        $table = $this->marketingRouteTable();

        $this->assertSame(
            $this->registeredMarketingAdminRoutes(),
            collect(array_keys($table))->sort()->values()->all(),
            'The matrix must cover exactly the phase-04 admin routes that are registered (§7.2, integration §5.1).',
        );
        $this->assertCount(153, $table, 'Integration §5.1 registers 153 phase-04 admin routes.');

        $all = $this->marketingPermissions();
        $nobody = $this->createUserWithPermissions([]);

        // Refusals first. Every request is prepared from shared fixtures and every actor exists before the
        // fingerprint (creating a user writes the audit trail), so the whole sweep is fingerprinted once.
        $prepared = [];
        $withoutOne = [];

        foreach ($table as $name => $entry) {
            $permission = $entry['permission'];

            $this->assertContains($permission, $all, sprintf('%s names %s, which PermissionRegistry does not declare for a phase-04 module.', $name, $permission));

            $prepared[$name] = $this->prepareMarketingRequest($name, $entry);
            $withoutOne[$permission] ??= $this->createUserWithPermissions(array_values(array_diff($all, [$permission])));
        }

        $before = $this->marketingFingerprint();

        foreach ($table as $name => $entry) {
            $permission = $entry['permission'];

            foreach (['holding every marketing permission except '.$permission => $withoutOne[$permission], 'with no permission at all' => $nobody] as $context => $user) {
                $response = $this->actingAs($user)->sendPreparedMarketing($prepared[$name], true);

                $this->assertSame(
                    403,
                    $response->getStatusCode(),
                    sprintf('%s %s must be refused %s; it answered %d.', $prepared[$name]['method'], $name, $context, $response->getStatusCode()),
                );
            }
        }

        $this->assertSame($before, $this->marketingFingerprint(), 'A refused marketing request wrote to a marketing table or the audit trail.');

        // With exactly the permission its can: names, every route gives its success answer.
        $holders = [];

        foreach ($table as $name => $entry) {
            $grant = array_values(array_unique(array_merge([$entry['permission']], $entry['also'])));
            sort($grant);
            $key = implode('|', $grant);

            $holders[$key] ??= $this->createUserWithPermissions($grant);

            $this->assertMarketingRouteAnswers($holders[$key], $name, $entry, 'to a user holding only '.$key);
        }
    }

    public function test_authorization_matrix_sends_a_guest_to_sign_in_from_every_marketing_admin_route(): void
    {
        $prepared = [];

        foreach ($this->marketingRouteTable() as $name => $entry) {
            $prepared[$name] = $this->prepareMarketingRequest($name, $entry);
        }

        $this->signOut();
        $before = $this->marketingFingerprint();

        foreach ($prepared as $name => $request) {
            $this->sendPreparedMarketing($request, false)->assertRedirect(route('login'));
            $this->sendPreparedMarketing($request, true)->assertUnauthorized();
        }

        $this->assertSame($before, $this->marketingFingerprint(), 'A guest request wrote to a marketing table or the audit trail.');
    }

    /**
     * §9.1 "a test per role": every seeded role holds exactly the marketing grant the contract (and
     * integration §7) gives it, every route outside that grant is a 403 that writes nothing, and every
     * read inside it answers.
     */
    #[DataProvider('seededRoles')]
    public function test_authorization_matrix_follows_each_seeded_role(string $role): void
    {
        $user = $this->createUserWithRole($role);
        $grant = $this->contractMarketingGrant($role);

        $held = array_values(array_intersect($this->marketingPermissions(), $user->getAllPermissions()->pluck('name')->all()));
        $expected = $grant;
        sort($held);
        sort($expected);

        $this->assertSame($expected, $held, sprintf('The %s role must hold exactly the phase-04 §9.1 marketing grant.', $role));

        $table = $this->marketingRouteTable();
        $inGrant = static fn (array $entry): bool => array_diff(array_merge([$entry['permission']], $entry['also']), $grant) === [];
        $prepared = [];

        foreach ($table as $name => $entry) {
            if (! $inGrant($entry)) {
                $prepared[$name] = $this->prepareMarketingRequest($name, $entry);
            }
        }

        $before = $this->marketingFingerprint();

        foreach ($prepared as $name => $request) {
            $response = $this->actingAs($user)->sendPreparedMarketing($request, true);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                sprintf('The %s role must be refused %s %s (needs %s); it answered %d.', $role, $request['method'], $name, implode(' + ', array_merge([$table[$name]['permission']], $table[$name]['also'])), $response->getStatusCode()),
            );
        }

        $this->assertSame($before, $this->marketingFingerprint(), sprintf('A request refused to the %s role wrote something.', $role));

        foreach ($table as $name => $entry) {
            if (! $inGrant($entry)) {
                continue;
            }

            if ($entry['kind'] === 'write') {
                // Writes under a grant are the single-permission case of the matrix above.
                $this->assertTrue($user->can($entry['permission']), sprintf('The %s role must be allowed %s.', $role, $entry['permission']));

                continue;
            }

            $this->assertMarketingRouteAnswers($user, $name, $entry, 'to the '.$role.' role');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function seededRoles(): array
    {
        $roles = [
            User::SUPER_ADMIN_ROLE, 'Admin', 'HR', 'Accountant', 'Project Manager', 'Developer', 'Designer',
            'SEO Expert', 'Digital Marketer', 'Sales Executive', 'Receptionist', 'Support Agent',
            'Institute Manager', 'Course Coordinator', 'Teacher', 'Student', 'Client', 'Collaborator',
        ];

        $cases = [];

        foreach ($roles as $role) {
            $cases[$role] = [$role];
        }

        return $cases;
    }

    /*
    |--------------------------------------------------------------------------
    | §11 — the named authorization cases
    |--------------------------------------------------------------------------
    */

    /**
     * §11 test 7: no `services.*` permission → 403 on index, create, store, edit, update, destroy, reorder,
     * status and export; exactly `services.view_any` → the index is 200 and store is still 403.
     */
    public function test_contract_07_services_routes_refuse_without_a_permission_and_view_any_alone_opens_only_the_index(): void
    {
        $service = $this->makeService();
        $nothing = $this->createUserWithPermissions(array_values(array_diff($this->marketingPermissions(), PermissionRegistry::permissionNamesFor('services'))));

        $requests = [
            'index' => ['GET', route('admin.services.index'), []],
            'create' => ['GET', route('admin.services.create'), []],
            'store' => ['POST', route('admin.services.store'), ['name' => 'Refused service']],
            'edit' => ['GET', route('admin.services.edit', $service), []],
            'update' => ['PUT', route('admin.services.update', $service), ['name' => 'Refused rename']],
            'destroy' => ['DELETE', route('admin.services.destroy', $service), []],
            'reorder' => ['POST', route('admin.services.reorder'), ['ids' => [(int) $service->getKey()]]],
            'status' => ['POST', route('admin.services.status', $service), ['status' => 'published']],
            'export' => ['GET', route('admin.services.export'), []],
        ];

        // Both actors exist before the fingerprint: creating a user writes its own activity rows, which are
        // not an effect of the refused requests.
        $reader = $this->createUserWithPermissions(['services.view_any']);

        $before = $this->marketingFingerprint();

        foreach ($requests as $action => [$method, $url, $data]) {
            foreach ([true, false] as $json) {
                $status = $this->actingAs($nothing)->sendCms($method, $url, $data, $json)->getStatusCode();

                $this->assertSame(403, $status, sprintf('services %s must be 403 without any services.* permission (%s); it answered %d.', $action, $json ? 'JSON' : 'HTML', $status));
            }
        }

        $this->assertSame($before, $this->marketingFingerprint());

        $this->actingAs($reader)->get(route('admin.services.index'))->assertOk()->assertSee((string) $service->name, false);
        $this->actingAs($reader)->sendCms('POST', route('admin.services.store'), ['name' => 'Still refused'])->assertForbidden();

        $this->assertSame($before, $this->marketingFingerprint(), 'services.view_any alone must not be able to create a service.');
    }

    /**
     * §11 test 17: `testimonials.view_any` without `testimonials.approve` renders the queue and 403s the
     * approve and bulk-approve endpoints (the same for student reviews).
     */
    public function test_contract_17_view_any_without_approve_renders_the_queue_and_refuses_approval(): void
    {
        $testimonial = $this->makeTestimonial();
        $review = $this->makeStudentReview();

        $reader = $this->createUserWithPermissions(['testimonials.view_any', 'testimonials.view', 'student_reviews.view_any', 'student_reviews.view']);

        $this->actingAs($reader)->get(route('admin.testimonials.index'))->assertOk()->assertSee((string) $testimonial->author_name, false);
        $this->actingAs($reader)->get(route('admin.student-reviews.index'))->assertOk()->assertSee((string) $review->student_name, false);

        $before = $this->marketingFingerprint();

        $this->actingAs($reader)->sendCms('POST', route('admin.testimonials.approve', $testimonial))->assertForbidden();
        $this->actingAs($reader)->sendCms('POST', route('admin.testimonials.bulk-approve'), ['ids' => [(int) $testimonial->getKey()]])->assertForbidden();
        $this->actingAs($reader)->sendCms('POST', route('admin.testimonials.reject', $testimonial), ['reason' => 'Not allowed to reject'])->assertForbidden();
        $this->actingAs($reader)->sendCms('POST', route('admin.student-reviews.approve', $review))->assertForbidden();
        $this->actingAs($reader)->sendCms('POST', route('admin.student-reviews.bulk-approve'), ['ids' => [(int) $review->getKey()]])->assertForbidden();

        $this->assertSame($before, $this->marketingFingerprint());
        $this->assertSame(ApprovalStatus::Pending->value, (string) DB::table('testimonials')->where('id', $testimonial->getKey())->value('status'));
        $this->assertSame(ApprovalStatus::Pending->value, (string) DB::table('student_reviews')->where('id', $review->getKey())->value('status'));

        // Granting exactly the approve ability opens both endpoints.
        $this->grantPermissions($reader, 'testimonials.approve');

        $this->actingAs($reader)->sendCms('POST', route('admin.testimonials.approve', $testimonial))->assertOk();
        $this->assertSame(ApprovalStatus::Approved->value, (string) DB::table('testimonials')->where('id', $testimonial->getKey())->value('status'));

        $other = $this->makeTestimonial();
        $this->actingAs($reader)->sendCms('POST', route('admin.testimonials.bulk-approve'), ['ids' => [(int) $other->getKey()]])->assertOk();
        $this->assertSame(ApprovalStatus::Approved->value, (string) DB::table('testimonials')->where('id', $other->getKey())->value('status'));
    }

    /**
     * §11 test 25 (authorization half): a draft and a scheduled post are 404 publicly; the signed preview
     * renders for a permitted user with `X-Robots-Tag: noindex` and no view row, 403s for a signed-in user
     * without `blog_posts.view`, and 404s once the signature is tampered with — or missing.
     */
    public function test_contract_25_signed_preview_renders_for_a_permitted_user_only(): void
    {
        $author = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.view', 'blog_posts.create', 'blog_posts.edit']);
        $draft = $this->makeBlogPost($author, 'draft', ['title' => 'Preview draft '.$this->uniqueToken()]);
        $scheduled = $this->makeBlogPost($author, 'scheduled', ['title' => 'Preview scheduled '.$this->uniqueToken()]);

        foreach ([$draft, $scheduled] as $post) {
            $this->get(route('site.blog.show', ['blogPost' => $post->slug]))->assertNotFound();
        }

        $signed = URL::temporarySignedRoute('site.blog.preview', Carbon::now()->addHour(), ['blogPost' => $draft->getKey()]);

        // A guest never reaches the controller: `auth` sends it to sign in.
        $this->get($signed)->assertRedirect(route('login'));

        $this->actingAs($author)
            ->get($signed)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee((string) $draft->title, false);

        $this->assertSame(0, DB::table('blog_post_views')->where('blog_post_id', $draft->getKey())->count(), 'A preview never counts a view.');

        // The preview link the editor mints is the same signed route.
        $link = (string) $this->actingAs($author)->getJson(route('admin.blog-posts.preview-link', $scheduled))->assertOk()->json('url');
        $this->assertStringContainsString('signature=', $link);
        $this->actingAs($author)->get($link)->assertOk()->assertSee((string) $scheduled->title, false);

        // Signed in, valid signature, but no blog_posts.view: the policy refuses.
        $outsider = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.create']);
        $this->actingAs($outsider)->get($signed)->assertForbidden();

        // An editor-less, non-author holder of view is refused a draft that is not its own.
        $stranger = $this->createUserWithPermissions(['blog_posts.view']);
        $this->actingAs($stranger)->get($signed)->assertForbidden();

        // A tampered or missing signature is a 404 for everyone, the author included.
        $tampered = preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $signed);
        $this->assertNotSame($signed, $tampered);
        $this->actingAs($author)->get((string) $tampered)->assertNotFound();
        $this->actingAs($author)->get(route('site.blog.preview', ['blogPost' => $draft->getKey()]))->assertNotFound();

        $expired = URL::temporarySignedRoute('site.blog.preview', Carbon::now()->subMinute(), ['blogPost' => $draft->getKey()]);
        $this->actingAs($author)->get($expired)->assertNotFound();
    }

    /**
     * §11 test 30: an author holding `blog_posts.create` + `.edit` edits its own post and gets 403 on another
     * author's; granting `blog_posts.approve` makes both 200 and the index count rises accordingly.
     */
    public function test_contract_30_an_author_reaches_only_its_own_posts_until_it_holds_approve(): void
    {
        $author = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.view', 'blog_posts.create', 'blog_posts.edit']);
        $otherAuthor = $this->createUserWithPermissions(['blog_posts.view_any', 'blog_posts.create', 'blog_posts.edit']);

        $own = $this->makeBlogPost($author);
        $foreign = $this->makeBlogPost($otherAuthor);

        $this->actingAs($author)->get(route('admin.blog-posts.edit', $own))->assertOk();
        $this->actingAs($author)->sendCms('PUT', route('admin.blog-posts.update', $own), ['title' => 'My own post, renamed'])->assertOk();

        $foreignBefore = (array) DB::table('blog_posts')->where('id', $foreign->getKey())->first();

        $this->actingAs($author)->get(route('admin.blog-posts.edit', $foreign))->assertForbidden();
        $this->actingAs($author)->sendCms('PUT', route('admin.blog-posts.update', $foreign), ['title' => 'Hijacked title'])->assertForbidden();

        $this->assertSame($foreignBefore, (array) DB::table('blog_posts')->where('id', $foreign->getKey())->first(), 'A refused update must leave another author\'s post untouched.');

        $this->actingAs($author)
            ->get(route('admin.blog-posts.index'))
            ->assertOk()
            ->assertViewHas('counts', static fn (array $counts): bool => $counts['all'] === 1)
            ->assertDontSee((string) $foreign->title, false);

        // An author may not write under someone else's name.
        $this->actingAs($author)
            ->sendCms('POST', route('admin.blog-posts.store'), ['title' => 'Ghost-written', 'content' => '<p>Body.</p>', 'author_id' => (int) $otherAuthor->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('author_id');

        $this->grantPermissions($author, 'blog_posts.approve');

        $this->actingAs($author)->get(route('admin.blog-posts.edit', $foreign))->assertOk();
        $this->actingAs($author)->sendCms('PUT', route('admin.blog-posts.update', $foreign), ['title' => 'Edited by the editor'])->assertOk();
        $this->assertSame('Edited by the editor', (string) DB::table('blog_posts')->where('id', $foreign->getKey())->value('title'));

        $this->actingAs($author)
            ->get(route('admin.blog-posts.index'))
            ->assertOk()
            ->assertViewHas('counts', static fn (array $counts): bool => $counts['all'] === 2);
    }

    /**
     * §11 test 37: `GET /admin/job-applications/{id}/cv` 403s without `job_applications.download`, 200s with it,
     * sends `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`, and writes a CV-download
     * activity entry; no other route can reach the file.
     */
    public function test_contract_37_cv_download_is_gated_by_download_and_streamed_as_an_attachment(): void
    {
        $application = $this->makeJobApplication();
        $path = (string) $application->cv_path;

        Storage::disk('local')->assertExists($path);
        $this->assertSame([], Storage::disk('public')->allFiles('careers'), 'A CV never lands on the public disk.');

        $reviewer = $this->createUserWithPermissions(['job_applications.view_any', 'job_applications.view']);

        $this->actingAs($reviewer)->get(route('admin.job-applications.show', $application))->assertOk();
        $this->actingAs($reviewer)->get(route('admin.job-applications.cv', $application))->assertForbidden();
        $this->assertSame(0, DB::table('activity_log')->where('event', 'cv_downloaded')->count(), 'A refused download is not logged as a download.');

        $downloader = $this->createUserWithPermissions(['job_applications.view_any', 'job_applications.view', 'job_applications.download']);
        $response = $this->actingAs($downloader)->get(route('admin.job-applications.cv', $application));

        $response->assertOk();
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('%PDF-', $response->streamedContent());

        $entry = DB::table('activity_log')->where('event', 'cv_downloaded')->orderByDesc('id')->first();
        $this->assertNotNull($entry, 'A CV download writes its §107 activity entry.');
        $this->assertSame((int) $downloader->getKey(), (int) $entry->causer_id);
        $this->assertSame((int) $application->getKey(), (int) $entry->subject_id);

        // No other route serves the file: neither the public storage path nor the private disk's own
        // (signature-only) route answers with the bytes, and exactly one registered route streams a CV.
        $this->signOut();

        foreach (['/storage/'.$path, '/'.$path] as $probe) {
            $leak = $this->get($probe);

            $this->assertNotSame(200, $leak->getStatusCode(), sprintf('%s must not serve the CV.', $probe));
            $this->assertStringNotContainsString('%PDF-', (string) $leak->getContent(), sprintf('%s leaked the CV bytes.', $probe));
        }

        $routesToCv = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains((string) $route->getActionName(), 'JobApplicationController@cv'))
            ->map(static fn ($route): ?string => $route->getName())
            ->values()
            ->all();

        $this->assertSame(['admin.job-applications.cv'], $routesToCv, 'Exactly one route streams a CV.');
    }

    /**
     * §11 test 48 (the authorization half): the manual **Route now** endpoint 403s for a user without
     * `contact_inquiries.change_status`, writes nothing, and answers a holder.
     */
    public function test_contract_48_route_now_is_refused_without_change_status(): void
    {
        settings_repo()->set('website.inquiry_auto_route', false);

        $queueManager = $this->createUserWithPermissions(['contact_inquiries.view_any', 'contact_inquiries.view', 'contact_inquiries.edit', 'contact_inquiries.assign', 'contact_inquiries.export']);
        $inquiry = $this->makeContactInquiry([
            'inquiry_type' => 'service',
            'routing_status' => 'pending',
            'routing_target' => 'crm_lead',
        ]);

        $before = $this->marketingFingerprint();

        $this->actingAs($queueManager)->sendCms('POST', route('admin.contact-inquiries.route', $inquiry))->assertForbidden();
        $this->actingAs($queueManager)->sendCms('POST', route('admin.contact-inquiries.route-pending'))->assertForbidden();

        $this->assertSame($before, $this->marketingFingerprint(), 'A refused Route now touched the inquiry.');

        $router = $this->createUserWithPermissions(['contact_inquiries.view_any', 'contact_inquiries.view', 'contact_inquiries.change_status']);

        $this->actingAs($router)
            ->sendCms('POST', route('admin.contact-inquiries.route', $inquiry))
            ->assertOk()
            ->assertJsonPath('id', (int) $inquiry->getKey());

        $this->actingAs($router)->sendCms('POST', route('admin.contact-inquiries.route-pending'))->assertOk();
    }

    /**
     * §11 test 58: a Student, Teacher, Client and Collaborator each get 403 on every phase-04 admin route
     * (the route table is walked, not a sample), and nothing is written.
     */
    public function test_contract_58_every_portal_role_is_refused_every_marketing_admin_route(): void
    {
        $prepared = [];

        foreach ($this->marketingRouteTable() as $name => $entry) {
            $prepared[$name] = $this->prepareMarketingRequest($name, $entry);
        }

        $users = [];

        foreach (self::PORTAL_ROLES as $role) {
            $users[$role] = $this->createUserWithRole($role);
        }

        $before = $this->marketingFingerprint();

        foreach ($users as $role => $user) {
            foreach ($prepared as $name => $request) {
                $status = $this->actingAs($user)->sendPreparedMarketing($request, true)->getStatusCode();

                $this->assertSame(403, $status, sprintf('The %s role must be refused %s %s; it answered %d.', $role, $request['method'], $name, $status));
            }

            // And the HTML screens: a portal user is never shown a phase-04 admin page.
            foreach ($prepared as $name => $request) {
                if ($request['method'] === 'GET') {
                    $this->actingAs($user)->sendPreparedMarketing($request, false)->assertForbidden();
                }
            }
        }

        $this->assertSame($before, $this->marketingFingerprint(), 'A request from a portal role wrote something.');
    }

    /**
     * The record rules on top of the permission (§9.1.1-§9.1.3): holding the ability is not enough to act on
     * a row outside the user's slice — another author's post, an inquiry or application assigned elsewhere.
     */
    public function test_authorization_matrix_row_scopes_refuse_rows_outside_the_users_slice(): void
    {
        $author = $this->createUserWithPermissions(['blog_posts.view', 'blog_posts.edit', 'blog_posts.delete', 'blog_posts.change_status', 'blog_posts.view_reports']);
        $foreign = $this->makeBlogPost(null, 'published');

        $inquiryWorker = $this->createUserWithPermissions(['contact_inquiries.view', 'contact_inquiries.edit', 'contact_inquiries.delete', 'contact_inquiries.change_status', 'contact_inquiries.assign']);
        $inquiry = $this->makeContactInquiry(['assigned_to' => (int) $this->marketingAssignee()->getKey()]);

        $applicationWorker = $this->createUserWithPermissions(['job_applications.view', 'job_applications.edit', 'job_applications.delete', 'job_applications.change_status', 'job_applications.assign', 'job_applications.download']);
        $application = $this->makeJobApplication(null, $this->marketingAssignee());

        $before = $this->marketingFingerprint();

        $refusals = [
            [$author, 'PUT', route('admin.blog-posts.update', $foreign), ['title' => 'Not mine'], 403],
            [$author, 'DELETE', route('admin.blog-posts.destroy', $foreign), [], 403],
            [$author, 'POST', route('admin.blog-posts.unpublish', $foreign), [], 403],
            [$author, 'POST', route('admin.blog-posts.archive', $foreign), [], 403],
            [$author, 'GET', route('admin.blog-posts.stats', $foreign), [], 404],
            [$inquiryWorker, 'GET', route('admin.contact-inquiries.show', $inquiry->getKey()), [], 404],
            [$inquiryWorker, 'PUT', route('admin.contact-inquiries.update', $inquiry), ['response_notes' => 'Not mine'], 404],
            [$inquiryWorker, 'DELETE', route('admin.contact-inquiries.destroy', $inquiry), [], 404],
            [$inquiryWorker, 'POST', route('admin.contact-inquiries.status', $inquiry), ['status' => ContactInquiryStatus::Closed->value], 404],
            [$inquiryWorker, 'POST', route('admin.contact-inquiries.spam', $inquiry), ['reason' => 'Not mine'], 404],
            [$inquiryWorker, 'POST', route('admin.contact-inquiries.assign', $inquiry), ['user_id' => (int) $inquiryWorker->getKey()], 404],
            [$applicationWorker, 'GET', route('admin.job-applications.show', $application), [], 404],
            [$applicationWorker, 'GET', route('admin.job-applications.cv', $application), [], 404],
            [$applicationWorker, 'PUT', route('admin.job-applications.update', $application), ['rating' => 1], 404],
            [$applicationWorker, 'POST', route('admin.job-applications.status', $application), ['status' => 'reviewing'], 404],
            [$applicationWorker, 'DELETE', route('admin.job-applications.force-destroy', $application), [], 404],
        ];

        foreach ($refusals as [$user, $method, $url, $data, $status]) {
            $answered = $this->actingAs($user)->sendCms($method, $url, $data)->getStatusCode();

            $this->assertSame($status, $answered, sprintf('%s %s outside the user\'s slice must answer %d; it answered %d.', $method, $url, $status, $answered));
        }

        $this->assertSame($before, $this->marketingFingerprint(), 'A request outside the user\'s slice wrote something.');
        Storage::disk('local')->assertExists((string) JobApplication::query()->findOrFail($application->getKey())->cv_path);
        $this->assertTrue(BlogPost::query()->whereKey($foreign->getKey())->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<string>
     */
    private function registeredMarketingAdminRoutes(): array
    {
        $prefixes = array_values($this->marketingRoutePrefixes());

        return collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter(static fn (?string $name): bool => is_string($name) && Str::startsWith($name, $prefixes))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The marketing permissions phase-04 §9.1 and integration §7 grant a seeded role (the contract, not the
     * seeder, is the source).
     *
     * @return list<string>
     */
    private function contractMarketingGrant(string $role): array
    {
        $blogAuthor = array_values(array_diff(
            PermissionRegistry::permissionNamesFor('blog_posts'),
            ['blog_posts.approve', 'blog_posts.reject'],
        ));

        return array_values(array_unique(match ($role) {
            User::SUPER_ADMIN_ROLE, 'Admin' => $this->marketingPermissions(),
            'HR' => PermissionRegistry::permissionNamesFor(['jobs', 'job_applications']),
            'SEO Expert' => array_merge(
                $blogAuthor,
                PermissionRegistry::permissionNamesFor(['blog_categories', 'blog_tags']),
                ['services.view_any', 'services.view', 'services.edit'],
                ['portfolio.view_any', 'portfolio.view', 'portfolio.edit'],
                ['team.view_any', 'team.view', 'team.edit'],
            ),
            'Digital Marketer' => array_merge(
                $blogAuthor,
                PermissionRegistry::permissionNamesFor(['blog_categories', 'blog_tags']),
                ['contact_inquiries.view', 'contact_inquiries.edit', 'contact_inquiries.change_status'],
            ),
            'Sales Executive' => ['contact_inquiries.view', 'contact_inquiries.assign'],
            'Receptionist' => ['contact_inquiries.view'],
            'Institute Manager' => PermissionRegistry::permissionNamesFor(['blog_posts', 'blog_categories', 'blog_tags']),
            default => [],
        }));
    }
}
