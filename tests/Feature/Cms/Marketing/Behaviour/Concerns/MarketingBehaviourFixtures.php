<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Enums\EmploymentType;
use App\Enums\InquiryType;
use App\Models\Activity;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Cms\Service;
use App\Models\User;
use App\Services\Cms\BlogService;
use App\Services\Cms\ContactInquiryService;
use App\Services\Cms\InquiryRouter;
use App\Services\Cms\JobApplicationService;
use App\Services\Cms\JobOpeningService;
use App\Services\Cms\ServiceContentService;
use App\Services\Cms\SpamGuard;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Cms\Behaviour\Concerns\CmsBehaviourFixtures;
use Tests\Feature\Cms\Marketing\Behaviour\Fixtures\FakeInquiryTarget;

/**
 * Fixture helpers shared by the phase-04 behaviour, invariant and data-isolation acceptance tests
 * (`tests/Feature/Cms/Marketing/Behaviour`).
 *
 * Built on the real seeded site (Phase 1-3 seeders) and Phase 3's CMS fixture (`setSetting()`,
 * `bumpPublicCache()`, `makeImageAsset()`, `becomeGuest()`, `squashedText()`), and written through the real
 * Phase 4 services wherever the behaviour under test is the service's. Raw inserts are used only where a test
 * needs volume (the blog query budget, related posts) or a state the services refuse to produce (a row
 * assigned to a reviewer without going through the queue screen).
 *
 * Not named `*Test.php`, so PHPUnit does not try to run it.
 */
trait MarketingBehaviourFixtures
{
    use CmsBehaviourFixtures;

    private int $marketingSequence = 0;

    /*
    |--------------------------------------------------------------------------
    | Actors
    |--------------------------------------------------------------------------
    */

    protected function actAsSuperAdmin(): User
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        return $admin;
    }

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    */

    protected function serviceContent(): ServiceContentService
    {
        return app(ServiceContentService::class);
    }

    protected function blog(): BlogService
    {
        return app(BlogService::class);
    }

    protected function openings(): JobOpeningService
    {
        return app(JobOpeningService::class);
    }

    protected function applications(): JobApplicationService
    {
        return app(JobApplicationService::class);
    }

    protected function inquiries(): ContactInquiryService
    {
        return app(ContactInquiryService::class);
    }

    protected function router(): InquiryRouter
    {
        return app(InquiryRouter::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function makeService(string $name, array $extra = []): Service
    {
        return $this->serviceContent()->store(array_merge(['name' => $name], $extra), null);
    }

    /**
     * A blog post written straight to the table (volume fixtures). Public by default: published an hour ago.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    protected function insertPost(string $title, array $attributes = [], array $tagIds = []): BlogPost
    {
        $now = Carbon::now();

        $id = DB::table('blog_posts')->insertGetId(array_merge([
            'title' => $title,
            'slug' => Str::slug($title).'-'.(++$this->marketingSequence),
            'excerpt' => 'Excerpt of '.$title,
            'content' => '<p>Body of '.e($title).'.</p>',
            'status' => ContentStatus::Published->value,
            'published_at' => $now->copy()->subHour(),
            'is_featured' => false,
            'reading_minutes' => 1,
            'views_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        if ($tagIds !== []) {
            DB::table('blog_post_blog_tag')->insert(array_map(
                static fn (int $tagId): array => ['blog_post_id' => $id, 'blog_tag_id' => $tagId],
                $tagIds,
            ));
        }

        // withTrashed(): a caller may insert a post that is already in the trash (§11 test 29).
        /** @var BlogPost */
        return BlogPost::withTrashed()->findOrFail($id);
    }

    protected function insertBlogCategory(string $name): BlogCategory
    {
        $now = Carbon::now();

        $id = DB::table('blog_categories')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name).'-'.(++$this->marketingSequence),
            'sort_order' => $this->marketingSequence,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var BlogCategory */
        return BlogCategory::query()->findOrFail($id);
    }

    protected function insertBlogTag(string $name): int
    {
        $now = Carbon::now();

        return (int) DB::table('blog_tags')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name).'-'.(++$this->marketingSequence),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Careers
    |--------------------------------------------------------------------------
    */

    /**
     * A job opening through `JobOpeningService`, open by default.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function makeOpening(string $title, array $extra = []): JobOpening
    {
        return $this->openings()->store(array_merge([
            'title' => $title,
            'employment_type' => EmploymentType::FullTime->value,
            'description' => '<p>We are hiring for '.e($title).'.</p>',
            'status' => 'open',
        ], $extra));
    }

    /**
     * Real PDF bytes (finfo reports `application/pdf` from the `%PDF-` signature), optionally padded.
     */
    protected function pdfUpload(string $name = 'My CV.pdf', int $padBytes = 0): UploadedFile
    {
        $bytes = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n"
            .str_repeat('%', max(0, $padBytes))
            ."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * The payload of a genuine application: a render token issued long enough ago, no honeypot.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function applicationData(string $email, array $extra = []): array
    {
        return array_merge([
            'applicant_name' => 'Candidate '.Str::before($email, '@'),
            'email' => $email,
            'phone' => '+92 300 1234567',
            SpamGuard::TIMESTAMP_FIELD => $this->agedFormToken(),
        ], $extra);
    }

    /**
     * An application row written with the model (isolation fixtures — no file behind `cv_path`).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeApplication(JobOpening $job, string $name, array $overrides = []): JobApplication
    {
        $application = new JobApplication;

        $application->forceFill(array_merge([
            'job_opening_id' => (int) $job->getKey(),
            'applicant_name' => $name,
            'email' => Str::slug($name).'-'.(++$this->marketingSequence).'@example.test',
            'phone' => '+92 300 7654321',
            'cv_path' => 'careers/applications/fixture/'.Str::lower((string) Str::ulid()).'.pdf',
            'cv_original_name' => 'cv.pdf',
            'cv_mime' => 'application/pdf',
            'cv_size' => 1024,
            'status' => 'new',
            'source' => 'website',
        ], $overrides))->save();

        return $application->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Public forms
    |--------------------------------------------------------------------------
    */

    /**
     * A SpamGuard render token issued `$seconds` seconds ago (the clock is moved forward, never back).
     */
    protected function agedFormToken(int $seconds = 10): string
    {
        $token = app(SpamGuard::class)->signedTimestamp();

        $this->travel($seconds)->seconds();

        return $token;
    }

    /**
     * A public request as the contact / apply form sends it.
     *
     * @param  array<string, mixed>  $input
     */
    protected function publicRequest(array $input = [], string $ip = '203.0.113.10', string $userAgent = 'Mozilla/5.0 (Marketing acceptance tests)', string $uri = '/contact'): Request
    {
        return Request::create($uri, 'POST', $input, [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
        ]);
    }

    /**
     * A genuine contact inquiry through `ContactInquiryService::submit()` (unless the data says otherwise).
     *
     * @param  array<string, mixed>  $data
     */
    protected function submitInquiry(array $data = [], ?Request $request = null): ContactInquiry
    {
        $sequence = ++$this->marketingSequence;

        $data = array_merge([
            'inquiry_type' => InquiryType::General->value,
            'name' => 'Visitor '.$sequence,
            'email' => 'visitor'.$sequence.'@example.test',
            'message' => 'We would like to discuss a new project with your team, reference '.$sequence.'.',
            SpamGuard::HONEYPOT_FIELD => '',
        ], $data);

        if (! array_key_exists(SpamGuard::TIMESTAMP_FIELD, $data)) {
            $data[SpamGuard::TIMESTAMP_FIELD] = $this->agedFormToken();
        }

        return $this->inquiries()->submit($data, $request ?? $this->publicRequest($data))->fresh();
    }

    /**
     * The fields a genuine public contact form POST carries.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function contactForm(array $extra = []): array
    {
        $sequence = ++$this->marketingSequence;

        return array_merge([
            'inquiry_type' => InquiryType::General->value,
            'name' => 'Form Visitor '.$sequence,
            'email' => 'form.visitor'.$sequence.'@example.test',
            'subject' => 'Project inquiry '.$sequence,
            'message' => 'Hello team, we are planning a new web platform and want a quote, ref '.$sequence.'.',
            SpamGuard::HONEYPOT_FIELD => '',
            SpamGuard::TIMESTAMP_FIELD => $this->agedFormToken(),
        ], $extra);
    }

    protected function registerFakeTarget(string $key = InquiryType::TARGET_CRM_LEAD, ?string $module = null): FakeInquiryTarget
    {
        $target = new FakeInquiryTarget($key, $module);

        $this->router()->register($target);

        return $target;
    }

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    protected function lastActivityId(): int
    {
        return (int) Activity::query()->max('id');
    }

    /**
     * @return Collection<int, Activity>
     */
    protected function activitiesSince(int $afterId, string $module, string $event): Collection
    {
        return Activity::query()
            ->where('id', '>', $afterId)
            ->where('module', $module)
            ->where('event', $event)
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * @return array<string, mixed>
     */
    protected function propertiesOf(Activity $activity): array
    {
        $properties = $activity->getAttribute('properties');

        if ($properties instanceof Collection) {
            return $properties->toArray();
        }

        if (is_string($properties)) {
            return (array) json_decode($properties, true);
        }

        return is_iterable($properties) ? (array) collect($properties)->toArray() : [];
    }
}
