<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Enums\ContactInquiryStatus;
use App\Enums\EmploymentType;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquirySource;
use App\Enums\InquiryType;
use App\Enums\JobOpeningStatus;
use App\Enums\TestimonialType;
use App\Enums\WorkMode;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\BlogTag;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Cms\MediaAsset;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\TeamMember;
use App\Models\Cms\Technology;
use App\Models\Cms\Testimonial;
use App\Models\User;
use App\Services\Cms\BlogService;
use App\Services\Cms\JobApplicationService;
use App\Services\Cms\JobOpeningService;
use App\Services\Cms\ModerationService;
use App\Services\Cms\PortfolioService;
use App\Services\Cms\ReviewContentService;
use App\Services\Cms\ServiceContentService;
use App\Services\Cms\SpamGuard;
use App\Services\Cms\SuccessStoryService;
use App\Services\Cms\TaxonomyService;
use App\Services\Cms\TeamService;
use App\Support\Format;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Fixtures and the route table shared by the phase-04 HTTP acceptance tests (`tests/Feature/Cms/Marketing/Http`):
 * the permission matrix, module gating, route contract, Form Request validation and screen rendering.
 *
 * Every row is written through the phase-04 services (never a hand-built model), except where a contract
 * test itself says "hand-written" (a contact inquiry, §11 test 64) — so a route under test sees what an
 * administrator's click or a visitor's form would have produced.
 *
 * `marketingRouteTable()` is phase-04 §7.2 as registered (integration §5.1, 153 routes): every admin route
 * with its method, URI, the one permission its `can:` names, how it answers and a request builder. A
 * builder receives the actor and a `$fresh` flag:
 *
 *   · `(null, false)` — only shared fixtures, for a request that must be refused (the `can:` middleware
 *     answers before the controller, so the payload is irrelevant but every bound model exists);
 *   · `($actor, true)` — a dedicated fixture the actor may reach (its own blog post, an inquiry or an
 *     application assigned to it, §9.1) and a **valid** payload, so a holder of the permission gets the
 *     route's success answer and no route's success depends on another having run first.
 *
 * The using class must also use `Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures` (media assets, unique
 * tokens, `sendCms()`), `Tests\Feature\Concerns\InteractsWithRbac` and `RefreshDatabase`, and must fake the
 * `public` and `local` disks. Not named `*Test.php`, so PHPUnit does not try to run it.
 */
trait MarketingHttpFixtures
{
    /** @var array<string, mixed> */
    private array $marketingShared = [];

    private ?User $marketingAssigneeUser = null;

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    /**
     * The fifteen module slugs whose admin routes phase-04 §7.2 registers.
     *
     * @return list<string>
     */
    protected function marketingModules(): array
    {
        return [
            'service_categories', 'technologies', 'portfolio_categories', 'blog_categories', 'blog_tags',
            'services', 'portfolio', 'team', 'testimonials', 'student_reviews', 'success_stories', 'blog_posts',
            'jobs', 'job_applications', 'contact_inquiries',
        ];
    }

    /**
     * Every permission of those modules, read from the registry (D4) — never spelled out here.
     *
     * @return list<string>
     */
    protected function marketingPermissions(): array
    {
        return array_values(PermissionRegistry::permissionNamesFor($this->marketingModules()));
    }

    /**
     * The admin route-name prefix of each module (the dot keeps `admin.portfolio.` apart from
     * `admin.portfolio-categories.`).
     *
     * @return array<string, string> module => route-name prefix
     */
    protected function marketingRoutePrefixes(): array
    {
        return [
            'service_categories' => 'admin.service-categories.',
            'technologies' => 'admin.technologies.',
            'portfolio_categories' => 'admin.portfolio-categories.',
            'blog_categories' => 'admin.blog-categories.',
            'blog_tags' => 'admin.blog-tags.',
            'services' => 'admin.services.',
            'portfolio' => 'admin.portfolio.',
            'team' => 'admin.team.',
            'testimonials' => 'admin.testimonials.',
            'student_reviews' => 'admin.student-reviews.',
            'success_stories' => 'admin.success-stories.',
            'blog_posts' => 'admin.blog-posts.',
            'jobs' => 'admin.jobs.',
            'job_applications' => 'admin.job-applications.',
            'contact_inquiries' => 'admin.contact-inquiries.',
        ];
    }

    /**
     * Each module's index screen — the sidebar's target (integration §6).
     *
     * @return array<string, string> module => route name
     */
    protected function marketingIndexRoutes(): array
    {
        $routes = [];

        foreach ($this->marketingRoutePrefixes() as $module => $prefix) {
            $routes[$module] = $prefix.'index';
        }

        return $routes;
    }

    /**
     * The fifteen Website-group sidebar entries phase-04 §8 appends, in order, with their module and the
     * permission of the route they link to (integration §6).
     *
     * @return array<string, array{module: string, permission: string, route: string}> label => entry
     */
    protected function marketingSidebarEntries(): array
    {
        return [
            'Services' => ['module' => 'services', 'permission' => 'services.view_any', 'route' => 'admin.services.index'],
            'Service Categories' => ['module' => 'service_categories', 'permission' => 'service_categories.view_any', 'route' => 'admin.service-categories.index'],
            'Technologies' => ['module' => 'technologies', 'permission' => 'technologies.view_any', 'route' => 'admin.technologies.index'],
            'Portfolio' => ['module' => 'portfolio', 'permission' => 'portfolio.view_any', 'route' => 'admin.portfolio.index'],
            'Portfolio Categories' => ['module' => 'portfolio_categories', 'permission' => 'portfolio_categories.view_any', 'route' => 'admin.portfolio-categories.index'],
            'Team' => ['module' => 'team', 'permission' => 'team.view_any', 'route' => 'admin.team.index'],
            'Testimonials' => ['module' => 'testimonials', 'permission' => 'testimonials.view_any', 'route' => 'admin.testimonials.index'],
            'Student Reviews' => ['module' => 'student_reviews', 'permission' => 'student_reviews.view_any', 'route' => 'admin.student-reviews.index'],
            'Success Stories' => ['module' => 'success_stories', 'permission' => 'success_stories.view_any', 'route' => 'admin.success-stories.index'],
            'Blog Posts' => ['module' => 'blog_posts', 'permission' => 'blog_posts.view_any', 'route' => 'admin.blog-posts.index'],
            'Blog Categories' => ['module' => 'blog_categories', 'permission' => 'blog_categories.view_any', 'route' => 'admin.blog-categories.index'],
            'Blog Tags' => ['module' => 'blog_tags', 'permission' => 'blog_tags.view_any', 'route' => 'admin.blog-tags.index'],
            'Jobs' => ['module' => 'jobs', 'permission' => 'jobs.view_any', 'route' => 'admin.jobs.index'],
            'Job Applications' => ['module' => 'job_applications', 'permission' => 'job_applications.view', 'route' => 'admin.job-applications.index'],
            'Contact Inquiries' => ['module' => 'contact_inquiries', 'permission' => 'contact_inquiries.view', 'route' => 'admin.contact-inquiries.index'],
        ];
    }

    /**
     * The twenty tables phase-04 §2 creates, plus the two Phase 3 stores its writes reach (`media_assets`
     * for gallery and image slots, `seo_meta` for the SEO boxes).
     *
     * @return list<string>
     */
    protected function marketingTables(): array
    {
        return [
            'service_categories', 'technologies', 'services', 'service_technology', 'portfolio_categories',
            'portfolio_items', 'portfolio_item_technology', 'portfolio_item_media', 'team_members', 'testimonials',
            'student_reviews', 'success_stories', 'blog_categories', 'blog_tags', 'blog_posts', 'blog_post_blog_tag',
            'blog_post_views', 'job_openings', 'job_applications', 'contact_inquiries', 'media_assets', 'seo_meta',
        ];
    }

    /**
     * A fingerprint of every marketing row (content, not only counts) plus the audit trail's length, so
     * "the refused request wrote nothing" also catches an UPDATE.
     *
     * @return array<string, string>
     */
    protected function marketingFingerprint(bool $withAudit = true): array
    {
        $print = [];

        foreach ($this->marketingTables() as $table) {
            $rows = DB::table($table)->get()
                ->map(static fn (object $row): string => (string) json_encode($row))
                ->sort()
                ->values()
                ->all();

            $print[$table] = count($rows).':'.md5(implode("\n", $rows));
        }

        if ($withAudit) {
            $print['activity_log'] = DB::table('activity_log')->count().':'.(int) DB::table('activity_log')->max('id');
        }

        return $print;
    }

    /**
     * @return array<string, int>
     */
    protected function marketingRowCounts(): array
    {
        $counts = [];

        foreach ($this->marketingTables() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Actors and tokens
    |--------------------------------------------------------------------------
    */

    /**
     * Run a fixture write with no authenticated user, so a service never mistakes the previous request's
     * actor for the author, the causer or the creator of the fixture.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    protected function withoutActor(Closure $callback): mixed
    {
        $this->app['auth']->forgetGuards();

        return $callback();
    }

    /**
     * An active account every queue accepts as an assignee (`AssignRequest` requires `view` or `view_any`).
     */
    protected function marketingAssignee(): User
    {
        return $this->marketingAssigneeUser ??= $this->createSuperAdmin();
    }

    /**
     * A `form_token` rendered a minute ago: past `website.contact_min_submit_seconds`, inside the 12-hour
     * window, signed with the application key exactly as `SpamGuard::signedTimestamp()` signs it.
     */
    protected function spamSafeToken(int $secondsAgo = 60): string
    {
        return app(Encrypter::class)->encryptString((string) (Carbon::now()->getTimestamp() - $secondsAgo));
    }

    /**
     * The hidden fields every public form posts: an empty honeypot and a valid token.
     *
     * @return array<string, string>
     */
    protected function spamSafeFields(): array
    {
        return [
            SpamGuard::HONEYPOT_FIELD => '',
            SpamGuard::TIMESTAMP_FIELD => $this->spamSafeToken(),
        ];
    }

    /**
     * A genuine (minimal) PDF, so `ApplicationCvService`'s finfo gate reads `application/pdf` from the bytes.
     */
    protected function fakeCv(string $name = 'curriculum-vitae.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
        );
    }

    /**
     * An upload whose MIME type is read from its **bytes** by finfo (a real Symfony upload in test mode),
     * never from the name it claims — the object a browser upload becomes.
     */
    protected function uploadWithRealBytes(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'p4-upload-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary upload.');
        }

        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures — always through the phase-04 services
    |--------------------------------------------------------------------------
    */

    /**
     * @return class-string<Model>
     */
    protected function taxonomyModel(string $module): string
    {
        return match ($module) {
            'service_categories' => ServiceCategory::class,
            'technologies' => Technology::class,
            'portfolio_categories' => PortfolioCategory::class,
            'blog_categories' => BlogCategory::class,
            'blog_tags' => BlogTag::class,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeTerm(string $module, array $extra = []): Model
    {
        $token = $this->uniqueToken();

        return $this->withoutActor(fn (): Model => app(TaxonomyService::class)->store(
            $this->taxonomyModel($module),
            array_merge(['name' => 'Http '.$module.' '.$token], $extra),
        ));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeService(bool $publish = false, array $extra = []): Service
    {
        $token = $this->uniqueToken();
        $services = app(ServiceContentService::class);

        return $this->withoutActor(function () use ($services, $token, $publish, $extra): Service {
            $service = $services->store(array_merge(['name' => 'Http service '.$token], $extra), null, []);

            return $publish ? $services->changeStatus($service, ContentStatus::Published) : $service;
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makePortfolioItem(bool $publish = false, array $extra = []): PortfolioItem
    {
        $token = $this->uniqueToken();
        $portfolio = app(PortfolioService::class);

        return $this->withoutActor(function () use ($portfolio, $token, $publish, $extra): PortfolioItem {
            $item = $portfolio->store(array_merge(['title' => 'Http project '.$token], $extra), [], []);

            return $publish ? $portfolio->changeStatus($item, ContentStatus::Published) : $item;
        });
    }

    /**
     * Attach library assets to a gallery through `PortfolioService::attachAssets()`.
     */
    protected function attachToGallery(PortfolioItem $item, MediaAsset ...$assets): PortfolioItem
    {
        $ids = array_map(static fn (MediaAsset $asset): int => (int) $asset->getKey(), $assets);

        $this->withoutActor(fn () => app(PortfolioService::class)->attachAssets($item, $ids));

        /** @var PortfolioItem */
        return PortfolioItem::query()->findOrFail($item->getKey());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeTeamMember(bool $publish = false, bool $public = true, array $extra = []): TeamMember
    {
        $token = $this->uniqueToken();
        $team = app(TeamService::class);

        return $this->withoutActor(function () use ($team, $token, $publish, $public, $extra): TeamMember {
            $member = $team->store(array_merge(['name' => 'Http member '.$token, 'designation' => 'Software engineer'], $extra), null);

            if ($publish) {
                $member = $team->changeStatus($member, ContentStatus::Published);
            }

            return $public ? $member : $team->togglePublic($member);
        });
    }

    /**
     * A staff-entered testimonial, left `pending` (auto-approve is off by default) or moved by
     * `ModerationService`.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function makeTestimonial(string $state = 'pending', array $extra = []): Testimonial
    {
        $token = $this->uniqueToken();

        return $this->withoutActor(function () use ($token, $state, $extra): Testimonial {
            $testimonial = app(ReviewContentService::class)->storeTestimonial(array_merge([
                'type' => TestimonialType::Other->value,
                'author_name' => 'Http author '.$token,
                'review' => 'Working with the team was a genuinely good experience '.$token.'.',
            ], $extra), null);

            $this->moderate($testimonial, $state);

            /** @var Testimonial */
            return Testimonial::query()->findOrFail($testimonial->getKey());
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeStudentReview(string $state = 'pending', array $extra = []): StudentReview
    {
        $token = $this->uniqueToken();

        return $this->withoutActor(function () use ($token, $state, $extra): StudentReview {
            $review = app(ReviewContentService::class)->storeStudentReview(array_merge([
                'student_name' => 'Http student '.$token,
                'review' => 'The course taught me far more than I expected '.$token.'.',
            ], $extra), null);

            $this->moderate($review, $state);

            /** @var StudentReview */
            return StudentReview::query()->findOrFail($review->getKey());
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeSuccessStory(bool $publish = false, array $extra = []): SuccessStory
    {
        $token = $this->uniqueToken();
        $stories = app(SuccessStoryService::class);

        return $this->withoutActor(function () use ($stories, $token, $publish, $extra): SuccessStory {
            $story = $stories->store(array_merge([
                'student_name' => 'Http graduate '.$token,
                'story' => '<p>From the first class to a first job in six months '.$token.'.</p>',
            ], $extra), null);

            return $publish ? $stories->changeStatus($story, ContentStatus::Published) : $story;
        });
    }

    /**
     * A blog post authored by `$author` (or nobody), in `draft`, `published` or `scheduled` state.
     *
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $extra
     */
    protected function makeBlogPost(?User $author = null, string $state = 'draft', array $extra = [], array $tags = []): BlogPost
    {
        $token = $this->uniqueToken();
        $blog = app(BlogService::class);

        return $this->withoutActor(function () use ($blog, $token, $author, $state, $extra, $tags): BlogPost {
            $post = $blog->store(array_merge([
                'title' => 'Http post '.$token,
                'content' => '<p>An article body with enough words to read like a real post '.$token.'.</p>',
                'author_id' => $author?->getKey(),
            ], $extra), null, $tags);

            $post = match ($state) {
                'published' => $blog->publish($post),
                'scheduled' => $blog->schedule($post, Carbon::now()->addDays(2)),
                default => $post,
            };

            /** @var BlogPost */
            return BlogPost::query()->findOrFail($post->getKey());
        });
    }

    /**
     * A job opening; `$owner` becomes its `created_by`, the §9.1.3 per-opening scope.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function makeJobOpening(string $status = 'draft', ?User $owner = null, array $extra = []): JobOpening
    {
        $token = $this->uniqueToken();

        $job = $this->withoutActor(fn (): JobOpening => app(JobOpeningService::class)->store(array_merge([
            'title' => 'Http opening '.$token,
            'employment_type' => EmploymentType::FullTime->value,
            'work_mode' => WorkMode::Remote->value,
            'description' => '<p>We are hiring for a role that matters '.$token.'.</p>',
            'status' => JobOpeningStatus::from($status)->value,
        ], $extra)));

        if ($owner !== null) {
            DB::table('job_openings')->where('id', $job->getKey())->update(['created_by' => $owner->getKey()]);
        }

        /** @var JobOpening */
        return JobOpening::query()->findOrFail($job->getKey());
    }

    /**
     * A genuine application through `JobApplicationService::apply()` (real PDF bytes, a valid spam token),
     * optionally assigned to `$assignee`.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function makeJobApplication(?JobOpening $job = null, ?User $assignee = null, array $extra = []): JobApplication
    {
        $job ??= $this->sharedOpenJob();
        $token = $this->uniqueToken();

        $request = Request::create('/careers/'.$job->slug.'/apply', 'POST', $this->spamSafeFields(), [], [], [
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_USER_AGENT' => 'Phase 4 HTTP fixture',
        ]);

        $application = $this->withoutActor(fn (): JobApplication => app(JobApplicationService::class)->apply($job, array_merge([
            'applicant_name' => 'Http applicant '.$token,
            'email' => 'applicant-'.$token.'@example.com',
            'phone' => '+92 300 1234567',
            'cover_letter' => 'I would like to join your engineering team as a developer.',
        ], $extra), $this->fakeCv('cv-'.$token.'.pdf'), $request));

        if (! $application->exists) {
            throw new RuntimeException('The application fixture was judged spam and not stored.');
        }

        if ($assignee !== null) {
            $application->forceFill(['assigned_to' => $assignee->getKey()])->save();
        }

        /** @var JobApplication */
        return JobApplication::query()->findOrFail($application->getKey());
    }

    /**
     * A hand-written contact inquiry (§11 test 64 writes its snapshot rows the same way).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeContactInquiry(array $attributes = []): ContactInquiry
    {
        $token = $this->uniqueToken();

        /** @var ContactInquiry */
        return $this->withoutActor(fn (): Model => ContactInquiry::query()->forceCreate(array_merge([
            'inquiry_type' => InquiryType::General->value,
            'name' => 'Http inquirer '.$token,
            'email' => 'inquirer-'.$token.'@example.com',
            'message' => 'Please call me back about a website for my business '.$token.'.',
            'source' => InquirySource::Website->value,
            'status' => ContactInquiryStatus::New->value,
            'routing_status' => InquiryRoutingStatus::NotApplicable->value,
            'is_spam' => false,
        ], $attributes)));
    }

    /**
     * One fixture per test for the requests that only need a bound model to exist.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $make
     * @return TValue
     */
    protected function sharedMarketing(string $key, Closure $make): mixed
    {
        if (! array_key_exists($key, $this->marketingShared)) {
            $this->marketingShared[$key] = $make();
        }

        return $this->marketingShared[$key];
    }

    protected function sharedOpenJob(): JobOpening
    {
        return $this->sharedMarketing('job:open', fn (): JobOpening => $this->makeJobOpening('open'));
    }

    /**
     * A shared gallery with one attached library asset.
     *
     * @return array{item: PortfolioItem, asset: MediaAsset}
     */
    protected function sharedGallery(): array
    {
        return $this->sharedMarketing('portfolio:gallery', function (): array {
            $asset = $this->makeMediaAsset();

            return ['item' => $this->attachToGallery($this->makePortfolioItem(), $asset), 'asset' => $asset];
        });
    }

    /**
     * Every id of a sortable model, current order reversed.
     *
     * @param  class-string<Model>  $model
     * @return list<int>
     */
    protected function reversedIds(string $model): array
    {
        return $model::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->reverse()
            ->values()
            ->all();
    }

    private function moderate(Model $record, string $state): void
    {
        $moderation = app(ModerationService::class);

        match ($state) {
            'approved' => $moderation->approve($record),
            'rejected' => $moderation->reject($record, 'Rejected by the phase-04 HTTP fixture'),
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve one route table entry into a concrete request.
     *
     * @param  array{method: string, uri: string, permission: string, kind: string, also: list<string>, build: Closure(?User, bool): array<string, mixed>}  $entry
     * @return array{method: string, url: string, data: array<string, mixed>, kind: string}
     */
    protected function prepareMarketingRequest(string $name, array $entry, ?User $actor = null, bool $fresh = false): array
    {
        $built = ($entry['build'])($actor, $fresh);

        return [
            'method' => $entry['method'],
            'url' => route($name, $built['params'] ?? []),
            'data' => $built['data'] ?? [],
            'kind' => $entry['kind'],
        ];
    }

    /**
     * Send a prepared request the way its screen or script would: an HTML request for a screen, a redirect
     * or a download; JSON for everything a `fetch()` or a form-with-toast posts.
     *
     * @param  array{method: string, url: string, data: array<string, mixed>, kind: string}  $request
     */
    protected function sendPreparedMarketing(array $request, ?bool $json = null): TestResponse
    {
        return $this->sendCms(
            $request['method'],
            $request['url'],
            $request['data'],
            $json ?? in_array($request['kind'], ['json', 'write'], true),
        );
    }

    /**
     * The status a permitted request answers with: a 302 for the show routes that forward to the editor,
     * a 200 for everything else.
     */
    protected function expectedMarketingStatus(string $kind): int
    {
        return $kind === 'redirect' ? 302 : 200;
    }

    /**
     * Assert a holder of the route's permission (plus any policy companion) gets the route's success answer
     * with a dedicated fixture and a valid payload.
     *
     * @param  array{method: string, uri: string, permission: string, kind: string, also: list<string>, build: Closure(?User, bool): array<string, mixed>}  $entry
     */
    protected function assertMarketingRouteAnswers(User $user, string $name, array $entry, string $context): void
    {
        $request = $this->prepareMarketingRequest($name, $entry, $user, true);
        $response = $this->actingAs($user)->sendPreparedMarketing($request);
        $expected = $this->expectedMarketingStatus($entry['kind']);

        $this->assertSame(
            $expected,
            $response->getStatusCode(),
            sprintf('%s %s (%s) must answer %d %s; it answered %d: %s', $request['method'], $name, $request['url'], $expected, $context, $response->getStatusCode(), Str::limit((string) $response->getContent(), 600)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The admin route table — phase-04 §7.2 as registered (integration §5.1)
    |--------------------------------------------------------------------------
    */

    /**
     * Route name => method, URI, the one permission its `can:` names, how it answers (`screen` an HTML
     * view, `redirect` a show route that forwards to the editor, `json` a JSON GET, `download` a CSV or a
     * CV stream, `write` a JSON write), `also` — the companion permission a policy adds for that route
     * (only `route-pending`, which walks every inquiry and so needs `contact_inquiries.view_any`, X-6) —
     * and the request builder.
     *
     * @return array<string, array{method: string, uri: string, permission: string, kind: string, also: list<string>, build: Closure(?User, bool): array<string, mixed>}>
     */
    protected function marketingRouteTable(): array
    {
        return array_merge(
            $this->taxonomyRouteTable('service_categories', 'service-categories', true),
            $this->taxonomyRouteTable('technologies', 'technologies', false),
            $this->taxonomyRouteTable('portfolio_categories', 'portfolio-categories', true),
            $this->taxonomyRouteTable('blog_categories', 'blog-categories', false),
            $this->taxonomyRouteTable('blog_tags', 'blog-tags', false),
            $this->serviceRouteTable(),
            $this->portfolioRouteTable(),
            $this->teamRouteTable(),
            $this->moderationRouteTable('testimonials', 'testimonials', 'testimonial'),
            $this->moderationRouteTable('student_reviews', 'student-reviews', 'review'),
            $this->successStoryRouteTable(),
            $this->blogRouteTable(),
            $this->jobRouteTable(),
            $this->applicationRouteTable(),
            $this->inquiryRouteTable(),
        );
    }

    /**
     * §8.1 — the five taxonomies (reorder only on the two sortable category lists that register it).
     *
     * @return array<string, array<string, mixed>>
     */
    private function taxonomyRouteTable(string $module, string $prefix, bool $reorder): array
    {
        $term = fn (bool $fresh): int => (int) ($fresh
            ? $this->makeTerm($module)
            : $this->sharedMarketing('term:'.$module, fn (): Model => $this->makeTerm($module))
        )->getKey();

        $routes = [
            "admin.$prefix.index" => $this->marketingRoute('GET', "admin/$prefix", "$module.view_any", 'screen'),
            "admin.$prefix.create" => $this->marketingRoute('GET', "admin/$prefix/create", "$module.create", 'json'),
            "admin.$prefix.store" => $this->marketingRoute('POST', "admin/$prefix", "$module.create", 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['name' => 'Matrix term '.$this->uniqueToken()]]),
            "admin.$prefix.show" => $this->marketingRoute('GET', "admin/$prefix/{term}", "$module.view", 'json',
                fn (?User $actor, bool $fresh): array => ['params' => ['term' => $term(false)]]),
            "admin.$prefix.edit" => $this->marketingRoute('GET', "admin/$prefix/{term}/edit", "$module.edit", 'json',
                fn (?User $actor, bool $fresh): array => ['params' => ['term' => $term(false)]]),
            "admin.$prefix.update" => $this->marketingRoute('PUT', "admin/$prefix/{term}", "$module.edit", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['term' => $term($fresh)], 'data' => ['name' => 'Renamed term '.$this->uniqueToken()]]),
            "admin.$prefix.toggle" => $this->marketingRoute('POST', "admin/$prefix/{term}/toggle", "$module.change_status", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['term' => $term($fresh)]]),
            "admin.$prefix.destroy" => $this->marketingRoute('DELETE', "admin/$prefix/{term}", "$module.delete", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['term' => $term($fresh)]]),
        ];

        if ($reorder) {
            $routes["admin.$prefix.reorder"] = $this->marketingRoute('POST', "admin/$prefix/reorder", "$module.edit", 'write',
                function (?User $actor, bool $fresh) use ($term, $module): array {
                    $term(false);

                    return ['data' => ['ids' => $this->reversedIds($this->taxonomyModel($module))]];
                });
        }

        return $routes;
    }

    /**
     * §8.2 — services.
     *
     * @return array<string, array<string, mixed>>
     */
    private function serviceRouteTable(): array
    {
        $service = fn (bool $fresh): int => (int) ($fresh
            ? $this->makeService()
            : $this->sharedMarketing('service', fn (): Service => $this->makeService())
        )->getKey();

        return [
            'admin.services.index' => $this->marketingRoute('GET', 'admin/services', 'services.view_any', 'screen'),
            'admin.services.export' => $this->marketingRoute('GET', 'admin/services/export', 'services.export', 'download'),
            'admin.services.create' => $this->marketingRoute('GET', 'admin/services/create', 'services.create', 'screen'),
            'admin.services.store' => $this->marketingRoute('POST', 'admin/services', 'services.create', 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['name' => 'Matrix service '.$this->uniqueToken()]]),
            'admin.services.reorder' => $this->marketingRoute('POST', 'admin/services/reorder', 'services.edit', 'write',
                function (?User $actor, bool $fresh) use ($service): array {
                    $service(false);

                    return ['data' => ['ids' => $this->reversedIds(Service::class)]];
                }),
            'admin.services.show' => $this->marketingRoute('GET', 'admin/services/{service}', 'services.view', 'redirect',
                fn (?User $actor, bool $fresh): array => ['params' => ['service' => $service(false)]]),
            'admin.services.edit' => $this->marketingRoute('GET', 'admin/services/{service}/edit', 'services.edit', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['service' => $service(false)]]),
            'admin.services.update' => $this->marketingRoute('PUT', 'admin/services/{service}', 'services.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['service' => $service($fresh)], 'data' => ['name' => 'Renamed service '.$this->uniqueToken()]]),
            'admin.services.destroy' => $this->marketingRoute('DELETE', 'admin/services/{service}', 'services.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['service' => $service($fresh)]]),
            'admin.services.status' => $this->marketingRoute('POST', 'admin/services/{service}/status', 'services.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['service' => $service($fresh)], 'data' => ['status' => ContentStatus::Published->value]]),
            'admin.services.featured' => $this->marketingRoute('POST', 'admin/services/{service}/featured', 'services.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['service' => $service($fresh)]]),
        ];
    }

    /**
     * §8.3 — portfolio and its gallery manager.
     *
     * @return array<string, array<string, mixed>>
     */
    private function portfolioRouteTable(): array
    {
        $item = fn (bool $fresh): int => (int) ($fresh ? $this->makePortfolioItem() : $this->sharedGallery()['item'])->getKey();

        $withImages = function (bool $fresh, int $count): array {
            if (! $fresh) {
                $gallery = $this->sharedGallery();

                return ['item' => (int) $gallery['item']->getKey(), 'assets' => [(int) $gallery['asset']->getKey()]];
            }

            $assets = [];

            for ($i = 0; $i < $count; $i++) {
                $assets[] = $this->makeMediaAsset();
            }

            $gallery = $this->attachToGallery($this->makePortfolioItem(), ...$assets);

            return ['item' => (int) $gallery->getKey(), 'assets' => array_map(static fn (MediaAsset $asset): int => (int) $asset->getKey(), $assets)];
        };

        return [
            'admin.portfolio.index' => $this->marketingRoute('GET', 'admin/portfolio', 'portfolio.view_any', 'screen'),
            'admin.portfolio.create' => $this->marketingRoute('GET', 'admin/portfolio/create', 'portfolio.create', 'screen'),
            'admin.portfolio.store' => $this->marketingRoute('POST', 'admin/portfolio', 'portfolio.create', 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['title' => 'Matrix project '.$this->uniqueToken()]]),
            'admin.portfolio.reorder' => $this->marketingRoute('POST', 'admin/portfolio/reorder', 'portfolio.edit', 'write',
                function (?User $actor, bool $fresh) use ($item): array {
                    $item(false);

                    return ['data' => ['ids' => $this->reversedIds(PortfolioItem::class)]];
                }),
            'admin.portfolio.show' => $this->marketingRoute('GET', 'admin/portfolio/{item}', 'portfolio.view', 'redirect',
                fn (?User $actor, bool $fresh): array => ['params' => ['item' => $item(false)]]),
            'admin.portfolio.edit' => $this->marketingRoute('GET', 'admin/portfolio/{item}/edit', 'portfolio.edit', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['item' => $item(false)]]),
            'admin.portfolio.update' => $this->marketingRoute('PUT', 'admin/portfolio/{item}', 'portfolio.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['item' => $item($fresh)], 'data' => ['title' => 'Renamed project '.$this->uniqueToken()]]),
            'admin.portfolio.destroy' => $this->marketingRoute('DELETE', 'admin/portfolio/{item}', 'portfolio.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['item' => $item($fresh)]]),
            'admin.portfolio.status' => $this->marketingRoute('POST', 'admin/portfolio/{item}/status', 'portfolio.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['item' => $item($fresh)], 'data' => ['status' => ContentStatus::Published->value]]),
            'admin.portfolio.featured' => $this->marketingRoute('POST', 'admin/portfolio/{item}/featured', 'portfolio.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['item' => $item($fresh)]]),
            'admin.portfolio.images.store' => $this->marketingRoute('POST', 'admin/portfolio/{item}/images', 'portfolio.upload', 'write',
                fn (?User $actor, bool $fresh): array => [
                    'params' => ['item' => $item($fresh)],
                    'data' => ['media_asset_ids' => [(int) ($fresh ? $this->makeMediaAsset() : $this->sharedMediaAsset())->getKey()]],
                ]),
            'admin.portfolio.images.reorder' => $this->marketingRoute('POST', 'admin/portfolio/{item}/images/reorder', 'portfolio.edit', 'write',
                function (?User $actor, bool $fresh) use ($withImages): array {
                    $gallery = $withImages($fresh, 2);

                    return ['params' => ['item' => $gallery['item']], 'data' => ['ids' => array_reverse($gallery['assets'])]];
                }),
            'admin.portfolio.images.cover' => $this->marketingRoute('POST', 'admin/portfolio/{item}/images/{image}/cover', 'portfolio.edit', 'write',
                function (?User $actor, bool $fresh) use ($withImages): array {
                    $gallery = $withImages($fresh, 2);

                    return ['params' => ['item' => $gallery['item'], 'image' => end($gallery['assets'])]];
                }),
            'admin.portfolio.images.update' => $this->marketingRoute('PUT', 'admin/portfolio/{item}/images/{image}', 'portfolio.edit', 'write',
                function (?User $actor, bool $fresh) use ($withImages): array {
                    $gallery = $withImages($fresh, 1);

                    return ['params' => ['item' => $gallery['item'], 'image' => $gallery['assets'][0]], 'data' => ['caption' => 'Captioned by the matrix']];
                }),
            'admin.portfolio.images.destroy' => $this->marketingRoute('DELETE', 'admin/portfolio/{item}/images/{image}', 'portfolio.edit', 'write',
                function (?User $actor, bool $fresh) use ($withImages): array {
                    $gallery = $withImages($fresh, 1);

                    return ['params' => ['item' => $gallery['item'], 'image' => $gallery['assets'][0]]];
                }),
        ];
    }

    /**
     * §8.4 — team.
     *
     * @return array<string, array<string, mixed>>
     */
    private function teamRouteTable(): array
    {
        $member = fn (bool $fresh): int => (int) ($fresh
            ? $this->makeTeamMember()
            : $this->sharedMarketing('team', fn (): TeamMember => $this->makeTeamMember())
        )->getKey();

        return [
            'admin.team.index' => $this->marketingRoute('GET', 'admin/team', 'team.view_any', 'screen'),
            'admin.team.create' => $this->marketingRoute('GET', 'admin/team/create', 'team.create', 'screen'),
            'admin.team.store' => $this->marketingRoute('POST', 'admin/team', 'team.create', 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['name' => 'Matrix member '.$this->uniqueToken(), 'designation' => 'Designer']]),
            'admin.team.reorder' => $this->marketingRoute('POST', 'admin/team/reorder', 'team.edit', 'write',
                function (?User $actor, bool $fresh) use ($member): array {
                    $member(false);

                    return ['data' => ['ids' => $this->reversedIds(TeamMember::class)]];
                }),
            'admin.team.show' => $this->marketingRoute('GET', 'admin/team/{member}', 'team.view', 'redirect',
                fn (?User $actor, bool $fresh): array => ['params' => ['member' => $member(false)]]),
            'admin.team.edit' => $this->marketingRoute('GET', 'admin/team/{member}/edit', 'team.edit', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['member' => $member(false)]]),
            'admin.team.update' => $this->marketingRoute('PUT', 'admin/team/{member}', 'team.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['member' => $member($fresh)], 'data' => ['name' => 'Renamed member '.$this->uniqueToken()]]),
            'admin.team.destroy' => $this->marketingRoute('DELETE', 'admin/team/{member}', 'team.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['member' => $member($fresh)]]),
            'admin.team.status' => $this->marketingRoute('POST', 'admin/team/{member}/status', 'team.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['member' => $member($fresh)], 'data' => ['status' => ContentStatus::Published->value]]),
            'admin.team.visibility' => $this->marketingRoute('POST', 'admin/team/{member}/visibility', 'team.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['member' => $member($fresh)]]),
        ];
    }

    /**
     * §8.5 — the two moderation queues.
     *
     * @return array<string, array<string, mixed>>
     */
    private function moderationRouteTable(string $module, string $prefix, string $parameter): array
    {
        $isTestimonial = $module === 'testimonials';

        $record = function (bool $fresh, string $state = 'pending') use ($isTestimonial, $module): int {
            $make = fn (): Model => $isTestimonial ? $this->makeTestimonial($state) : $this->makeStudentReview($state);

            return (int) ($fresh ? $make() : $this->sharedMarketing($module.':'.$state, $make))->getKey();
        };

        $store = fn (): array => $isTestimonial
            ? ['type' => TestimonialType::Other->value, 'author_name' => 'Matrix author '.$this->uniqueToken(), 'review' => 'Written down by the phase-04 authorization matrix.']
            : ['student_name' => 'Matrix student '.$this->uniqueToken(), 'review' => 'Written down by the phase-04 authorization matrix.'];

        return [
            "admin.$prefix.index" => $this->marketingRoute('GET', "admin/$prefix", "$module.view_any", 'screen'),
            "admin.$prefix.create" => $this->marketingRoute('GET', "admin/$prefix/create", "$module.create", 'screen'),
            "admin.$prefix.store" => $this->marketingRoute('POST', "admin/$prefix", "$module.create", 'write',
                fn (?User $actor, bool $fresh): array => ['data' => $store()]),
            "admin.$prefix.bulk-approve" => $this->marketingRoute('POST', "admin/$prefix/bulk-approve", "$module.approve", 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['ids' => [$record($fresh)]]]),
            "admin.$prefix.show" => $this->marketingRoute('GET', "admin/$prefix/{{$parameter}}", "$module.view", 'json',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record(false)]]),
            "admin.$prefix.edit" => $this->marketingRoute('GET', "admin/$prefix/{{$parameter}}/edit", "$module.edit", 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record(false)]]),
            "admin.$prefix.update" => $this->marketingRoute('PUT', "admin/$prefix/{{$parameter}}", "$module.edit", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record($fresh)], 'data' => ['review' => 'Corrected by the phase-04 authorization matrix.']]),
            "admin.$prefix.destroy" => $this->marketingRoute('DELETE', "admin/$prefix/{{$parameter}}", "$module.delete", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record($fresh)]]),
            "admin.$prefix.approve" => $this->marketingRoute('POST', "admin/$prefix/{{$parameter}}/approve", "$module.approve", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record($fresh)]]),
            "admin.$prefix.reject" => $this->marketingRoute('POST', "admin/$prefix/{{$parameter}}/reject", "$module.reject", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record($fresh)], 'data' => ['reason' => 'Not a genuine review']]),
            "admin.$prefix.featured" => $this->marketingRoute('POST', "admin/$prefix/{{$parameter}}/featured", "$module.change_status", 'write',
                fn (?User $actor, bool $fresh): array => ['params' => [$parameter => $record($fresh, 'approved')]]),
        ];
    }

    /**
     * §8.6 — success stories.
     *
     * @return array<string, array<string, mixed>>
     */
    private function successStoryRouteTable(): array
    {
        $story = fn (bool $fresh): int => (int) ($fresh
            ? $this->makeSuccessStory()
            : $this->sharedMarketing('story', fn (): SuccessStory => $this->makeSuccessStory())
        )->getKey();

        return [
            'admin.success-stories.index' => $this->marketingRoute('GET', 'admin/success-stories', 'success_stories.view_any', 'screen'),
            'admin.success-stories.create' => $this->marketingRoute('GET', 'admin/success-stories/create', 'success_stories.create', 'screen'),
            'admin.success-stories.store' => $this->marketingRoute('POST', 'admin/success-stories', 'success_stories.create', 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['student_name' => 'Matrix graduate '.$this->uniqueToken(), 'story' => '<p>Told by the phase-04 authorization matrix.</p>']]),
            'admin.success-stories.reorder' => $this->marketingRoute('POST', 'admin/success-stories/reorder', 'success_stories.edit', 'write',
                function (?User $actor, bool $fresh) use ($story): array {
                    $story(false);

                    return ['data' => ['ids' => $this->reversedIds(SuccessStory::class)]];
                }),
            'admin.success-stories.show' => $this->marketingRoute('GET', 'admin/success-stories/{story}', 'success_stories.view', 'redirect',
                fn (?User $actor, bool $fresh): array => ['params' => ['story' => $story(false)]]),
            'admin.success-stories.edit' => $this->marketingRoute('GET', 'admin/success-stories/{story}/edit', 'success_stories.edit', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['story' => $story(false)]]),
            'admin.success-stories.update' => $this->marketingRoute('PUT', 'admin/success-stories/{story}', 'success_stories.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['story' => $story($fresh)], 'data' => ['headline' => 'Headline from the matrix '.$this->uniqueToken()]]),
            'admin.success-stories.destroy' => $this->marketingRoute('DELETE', 'admin/success-stories/{story}', 'success_stories.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['story' => $story($fresh)]]),
            'admin.success-stories.status' => $this->marketingRoute('POST', 'admin/success-stories/{story}/status', 'success_stories.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['story' => $story($fresh)], 'data' => ['status' => ContentStatus::Published->value]]),
            'admin.success-stories.featured' => $this->marketingRoute('POST', 'admin/success-stories/{story}/featured', 'success_stories.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['story' => $story($fresh)]]),
        ];
    }

    /**
     * §8.7 — blog posts. A permitted actor always works on its **own** post (§9.1.1: without
     * `blog_posts.approve` a user reaches only what it authored).
     *
     * @return array<string, array<string, mixed>>
     */
    private function blogRouteTable(): array
    {
        $post = function (?User $actor, bool $fresh, string $state = 'draft'): int {
            if ($actor === null && ! $fresh) {
                return (int) $this->sharedMarketing('blog:'.$state, fn (): BlogPost => $this->makeBlogPost(null, $state))->getKey();
            }

            return (int) $this->makeBlogPost($actor, $state)->getKey();
        };

        return [
            'admin.blog-posts.index' => $this->marketingRoute('GET', 'admin/blog-posts', 'blog_posts.view_any', 'screen'),
            'admin.blog-posts.calendar' => $this->marketingRoute('GET', 'admin/blog-posts/calendar', 'blog_posts.view_any', 'screen'),
            'admin.blog-posts.create' => $this->marketingRoute('GET', 'admin/blog-posts/create', 'blog_posts.create', 'screen'),
            'admin.blog-posts.store' => $this->marketingRoute('POST', 'admin/blog-posts', 'blog_posts.create', 'write',
                fn (?User $actor, bool $fresh): array => ['data' => ['title' => 'Matrix post '.$this->uniqueToken(), 'content' => '<p>Written by the phase-04 authorization matrix.</p>']]),
            'admin.blog-posts.show' => $this->marketingRoute('GET', 'admin/blog-posts/{post}', 'blog_posts.view', 'redirect',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh)]]),
            'admin.blog-posts.edit' => $this->marketingRoute('GET', 'admin/blog-posts/{post}/edit', 'blog_posts.edit', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh)]]),
            'admin.blog-posts.update' => $this->marketingRoute('PUT', 'admin/blog-posts/{post}', 'blog_posts.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh)], 'data' => ['title' => 'Renamed post '.$this->uniqueToken()]]),
            'admin.blog-posts.destroy' => $this->marketingRoute('DELETE', 'admin/blog-posts/{post}', 'blog_posts.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh)]]),
            'admin.blog-posts.publish' => $this->marketingRoute('POST', 'admin/blog-posts/{post}/publish', 'blog_posts.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh)]]),
            'admin.blog-posts.schedule' => $this->marketingRoute('POST', 'admin/blog-posts/{post}/schedule', 'blog_posts.change_status', 'write',
                fn (?User $actor, bool $fresh): array => [
                    'params' => ['post' => $post($actor, $fresh)],
                    'data' => ['published_at' => $this->displayDateTime(Carbon::now()->addDays(3))],
                ]),
            'admin.blog-posts.unpublish' => $this->marketingRoute('POST', 'admin/blog-posts/{post}/unpublish', 'blog_posts.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh, 'published')]]),
            'admin.blog-posts.archive' => $this->marketingRoute('POST', 'admin/blog-posts/{post}/archive', 'blog_posts.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh, 'published')]]),
            'admin.blog-posts.stats' => $this->marketingRoute('GET', 'admin/blog-posts/{post}/stats', 'blog_posts.view_reports', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh, 'published')]]),
            'admin.blog-posts.preview-link' => $this->marketingRoute('GET', 'admin/blog-posts/{post}/preview-link', 'blog_posts.view', 'json',
                fn (?User $actor, bool $fresh): array => ['params' => ['post' => $post($actor, $fresh)]]),
        ];
    }

    /**
     * §8.8 — job openings.
     *
     * @return array<string, array<string, mixed>>
     */
    private function jobRouteTable(): array
    {
        $job = fn (bool $fresh): int => (int) ($fresh
            ? $this->makeJobOpening()
            : $this->sharedMarketing('job:draft', fn (): JobOpening => $this->makeJobOpening())
        )->getKey();

        return [
            'admin.jobs.index' => $this->marketingRoute('GET', 'admin/jobs', 'jobs.view_any', 'screen'),
            'admin.jobs.create' => $this->marketingRoute('GET', 'admin/jobs/create', 'jobs.create', 'screen'),
            'admin.jobs.store' => $this->marketingRoute('POST', 'admin/jobs', 'jobs.create', 'write',
                fn (?User $actor, bool $fresh): array => ['data' => [
                    'title' => 'Matrix opening '.$this->uniqueToken(),
                    'employment_type' => EmploymentType::FullTime->value,
                    'description' => '<p>Posted by the phase-04 authorization matrix.</p>',
                ]]),
            'admin.jobs.reorder' => $this->marketingRoute('POST', 'admin/jobs/reorder', 'jobs.edit', 'write',
                function (?User $actor, bool $fresh) use ($job): array {
                    $job(false);

                    return ['data' => ['ids' => $this->reversedIds(JobOpening::class)]];
                }),
            'admin.jobs.show' => $this->marketingRoute('GET', 'admin/jobs/{job}', 'jobs.view', 'redirect',
                fn (?User $actor, bool $fresh): array => ['params' => ['job' => $job(false)]]),
            'admin.jobs.edit' => $this->marketingRoute('GET', 'admin/jobs/{job}/edit', 'jobs.edit', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['job' => $job(false)]]),
            'admin.jobs.update' => $this->marketingRoute('PUT', 'admin/jobs/{job}', 'jobs.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['job' => $job($fresh)], 'data' => ['title' => 'Renamed opening '.$this->uniqueToken()]]),
            'admin.jobs.destroy' => $this->marketingRoute('DELETE', 'admin/jobs/{job}', 'jobs.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['job' => $job($fresh)]]),
            'admin.jobs.status' => $this->marketingRoute('POST', 'admin/jobs/{job}/status', 'jobs.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['job' => $job($fresh)], 'data' => ['status' => JobOpeningStatus::Open->value]]),
        ];
    }

    /**
     * §8.9 — the application pipeline. A permitted actor works on an application **assigned** to it
     * (§9.1.3), so a `view`-only reviewer reaches its row exactly as the contract says.
     *
     * @return array<string, array<string, mixed>>
     */
    private function applicationRouteTable(): array
    {
        $application = function (?User $actor, bool $fresh): int {
            if ($actor === null && ! $fresh) {
                return (int) $this->sharedMarketing('application', fn (): JobApplication => $this->makeJobApplication())->getKey();
            }

            return (int) $this->makeJobApplication(null, $actor)->getKey();
        };

        return [
            'admin.job-applications.index' => $this->marketingRoute('GET', 'admin/job-applications', 'job_applications.view', 'screen'),
            'admin.job-applications.export' => $this->marketingRoute('GET', 'admin/job-applications/export', 'job_applications.export', 'download'),
            'admin.job-applications.show' => $this->marketingRoute('GET', 'admin/job-applications/{application}', 'job_applications.view', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)]]),
            'admin.job-applications.update' => $this->marketingRoute('PUT', 'admin/job-applications/{application}', 'job_applications.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)], 'data' => ['rating' => 4]]),
            'admin.job-applications.destroy' => $this->marketingRoute('DELETE', 'admin/job-applications/{application}', 'job_applications.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)]]),
            'admin.job-applications.force-destroy' => $this->marketingRoute('DELETE', 'admin/job-applications/{application}/force', 'job_applications.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)]]),
            'admin.job-applications.status' => $this->marketingRoute('POST', 'admin/job-applications/{application}/status', 'job_applications.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)], 'data' => ['status' => 'reviewing']]),
            'admin.job-applications.assign' => $this->marketingRoute('POST', 'admin/job-applications/{application}/assign', 'job_applications.assign', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)], 'data' => ['user_id' => (int) $this->marketingAssignee()->getKey()]]),
            'admin.job-applications.cv' => $this->marketingRoute('GET', 'admin/job-applications/{application}/cv', 'job_applications.download', 'download',
                fn (?User $actor, bool $fresh): array => ['params' => ['application' => $application($actor, $fresh)]]),
        ];
    }

    /**
     * §8.10 — the inquiry routing queue. A permitted actor works on an inquiry **assigned** to it (§9.1.2).
     *
     * @return array<string, array<string, mixed>>
     */
    private function inquiryRouteTable(): array
    {
        $inquiry = function (?User $actor, bool $fresh, array $attributes = []): int {
            if ($actor === null && ! $fresh) {
                return (int) $this->sharedMarketing('inquiry', fn (): ContactInquiry => $this->makeContactInquiry())->getKey();
            }

            return (int) $this->makeContactInquiry(array_merge(['assigned_to' => $actor?->getKey()], $attributes))->getKey();
        };

        return [
            'admin.contact-inquiries.index' => $this->marketingRoute('GET', 'admin/contact-inquiries', 'contact_inquiries.view', 'screen'),
            'admin.contact-inquiries.export' => $this->marketingRoute('GET', 'admin/contact-inquiries/export', 'contact_inquiries.export', 'download'),
            'admin.contact-inquiries.route-pending' => $this->marketingRoute('POST', 'admin/contact-inquiries/route-pending', 'contact_inquiries.change_status', 'write',
                null, ['contact_inquiries.view_any']),
            'admin.contact-inquiries.show' => $this->marketingRoute('GET', 'admin/contact-inquiries/{inquiry}', 'contact_inquiries.view', 'screen',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)]]),
            'admin.contact-inquiries.update' => $this->marketingRoute('PUT', 'admin/contact-inquiries/{inquiry}', 'contact_inquiries.edit', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)], 'data' => ['response_notes' => 'Called back by the phase-04 matrix.']]),
            'admin.contact-inquiries.destroy' => $this->marketingRoute('DELETE', 'admin/contact-inquiries/{inquiry}', 'contact_inquiries.delete', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)]]),
            'admin.contact-inquiries.status' => $this->marketingRoute('POST', 'admin/contact-inquiries/{inquiry}/status', 'contact_inquiries.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)], 'data' => ['status' => ContactInquiryStatus::InProgress->value]]),
            'admin.contact-inquiries.route' => $this->marketingRoute('POST', 'admin/contact-inquiries/{inquiry}/route', 'contact_inquiries.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)]]),
            'admin.contact-inquiries.assign' => $this->marketingRoute('POST', 'admin/contact-inquiries/{inquiry}/assign', 'contact_inquiries.assign', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)], 'data' => ['user_id' => (int) $this->marketingAssignee()->getKey()]]),
            'admin.contact-inquiries.spam' => $this->marketingRoute('POST', 'admin/contact-inquiries/{inquiry}/spam', 'contact_inquiries.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh)], 'data' => ['reason' => 'Promotional content']]),
            'admin.contact-inquiries.not-spam' => $this->marketingRoute('POST', 'admin/contact-inquiries/{inquiry}/not-spam', 'contact_inquiries.change_status', 'write',
                fn (?User $actor, bool $fresh): array => ['params' => ['inquiry' => $inquiry($actor, $fresh, ['is_spam' => true, 'spam_reason' => 'honeypot'])]]),
        ];
    }

    /**
     * @param  (Closure(?User, bool): array<string, mixed>)|null  $build
     * @param  list<string>  $also
     * @return array{method: string, uri: string, permission: string, kind: string, also: list<string>, build: Closure(?User, bool): array<string, mixed>}
     */
    private function marketingRoute(string $method, string $uri, string $permission, string $kind, ?Closure $build = null, array $also = []): array
    {
        return [
            'method' => $method,
            'uri' => $uri,
            'permission' => $permission,
            'kind' => $kind,
            'also' => $also,
            'build' => $build ?? static fn (?User $actor, bool $fresh): array => [],
        ];
    }

    /**
     * A wall-clock moment in the display timezone, the way the editor's picker posts it (D61).
     */
    protected function displayDateTime(Carbon $moment): string
    {
        return $moment->copy()->setTimezone(Format::displayTimezone())->format('Y-m-d H:i');
    }
}
