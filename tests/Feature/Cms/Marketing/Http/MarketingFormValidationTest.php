<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Http;

use App\Enums\JobApplicationStatus;
use App\Http\Requests\Cms\StoreBlogPostRequest;
use App\Models\Cms\BlogPost;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\Service;
use App\Models\User;
use App\Services\Cms\ApplicationCvService;
use App\Services\Cms\JobApplicationService;
use App\Services\Cms\SeoService;
use App\Support\Format;
use App\Support\SettingsRepository;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Cms\Http\Concerns\CmsHttpFixtures;
use Tests\Feature\Cms\Marketing\Http\Concerns\MarketingHttpFixtures;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Form Request validation for every phase-04 write, hostile input included (CLAUDE.md §8 item 3; phase-04
 * §6.1, §6.6, §6.8, §6.9, §6.11).
 *
 * Every refusal is asserted three ways: the status is **422** (never a 500 from a TypeError or a raw database
 * error), the error names the offending field, and **nothing was written** (every marketing row, the media
 * library, the SEO store and the audit trail are fingerprinted around the request). The actor is a Super Admin
 * on purpose: holding every permission and bypassing every policy, only validation stands between the payload
 * and the data.
 *
 * The contract rows exercised through the forms: §11 tests 4, 10, 14, 16, 18, 28, 34, 39, 54, 62 and 65.
 */
final class MarketingFormValidationTest extends TestCase
{
    use CmsHttpFixtures;
    use InteractsWithRbac;
    use MarketingHttpFixtures;
    use RefreshDatabase;

    /** The seo_meta columns only the SEO service may validate or write (§6.11, ND-13). */
    private const SEO_COLUMNS = [
        'meta_description', 'meta_keywords', 'canonical_url', 'og_image_media_id', 'og_title', 'og_description',
        'og_type', 'sitemap_include', 'sitemap_priority', 'sitemap_changefreq',
    ];

    /** Phase 3's own SEO manager requests: the one SEO screen, which is where these columns belong. */
    private const SEO_MANAGER_REQUESTS = ['BulkSeoRequest.php', 'UpdateSeoRequest.php', 'SeoTargetRequest.php'];

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        app(SettingsRepository::class)->flush();
        Storage::fake('public');
        Storage::fake('local');

        $this->superAdmin = $this->createSuperAdmin();
    }

    /*
    |--------------------------------------------------------------------------
    | Hostile input on every phase-04 write
    |--------------------------------------------------------------------------
    */

    public function test_hostile_input_on_every_marketing_write_is_a_422_that_writes_nothing(): void
    {
        $category = $this->makeTerm('service_categories', ['name' => 'Web Design']);
        $this->makeService(false, ['service_category_id' => (int) $category->getKey()]);
        $technology = $this->makeTerm('technologies');
        $service = $this->makeService();
        $item = $this->makePortfolioItem();
        $attached = $this->makeMediaAsset();
        $loose = $this->makeMediaAsset();
        $gallery = $this->attachToGallery($this->makePortfolioItem(), $attached);
        $member = $this->makeTeamMember();
        $testimonial = $this->makeTestimonial();
        $review = $this->makeStudentReview();
        $story = $this->makeSuccessStory();
        $post = $this->makeBlogPost($this->superAdmin);
        $job = $this->makeJobOpening();
        $application = $this->makeJobApplication();
        $reviewing = $this->makeJobApplication();
        $this->withoutActor(fn () => app(JobApplicationService::class)->changeStatus($reviewing, JobApplicationStatus::Reviewing));
        $inquiry = $this->makeContactInquiry();
        $powerless = $this->createUserWithPermissions([]);

        $yesterday = Carbon::now(Format::displayTimezone())->subDay()->toDateString();
        $tomorrow = Carbon::now(Format::displayTimezone())->addDay()->toDateString();
        $pastMoment = $this->displayDateTime(Carbon::now()->subHour());

        $validJob = ['title' => 'A valid opening', 'employment_type' => 'full_time', 'description' => '<p>A valid description.</p>'];
        $validTestimonial = ['type' => 'other', 'author_name' => 'A valid author', 'review' => 'A valid review of the work.'];
        $validReview = ['student_name' => 'A valid student', 'review' => 'A valid review of the course.'];
        $validStory = ['student_name' => 'A valid graduate', 'story' => '<p>A valid story.</p>'];
        $validPost = ['title' => 'A valid post', 'content' => '<p>A valid body.</p>'];

        $cases = [
            // §8.1 taxonomies
            'a term name as an array' => ['POST', route('admin.service-categories.store'), ['name' => ['x']], 'name'],
            'a term with no name' => ['POST', route('admin.service-categories.store'), [], 'name'],
            'a duplicate term name in another case' => ['POST', route('admin.service-categories.store'), ['name' => 'WEB DESIGN'], 'name'],
            'a colour on a service category' => ['POST', route('admin.service-categories.store'), ['name' => 'Coloured', 'color' => '#ff0000'], 'color'],
            'a technology colour that is not hex' => ['POST', route('admin.technologies.store'), ['name' => 'Red tech', 'color' => 'red'], 'color'],
            'a description on a blog tag' => ['POST', route('admin.blog-tags.store'), ['name' => 'Described', 'description' => 'Tags have none'], 'description'],
            'an image on a blog tag' => ['POST', route('admin.blog-tags.store'), ['name' => 'Pictured', 'image_media_id' => (int) $loose->getKey()], 'image_media_id'],
            'a slug with spaces and punctuation' => ['POST', route('admin.service-categories.store'), ['name' => 'Sluggy', 'slug' => 'Not A Slug!'], 'slug'],
            'an unknown SEO field on a category' => ['POST', route('admin.service-categories.store'), ['name' => 'Seo probe', 'seo' => ['evil' => 'x']], 'seo'],
            'term order as a string' => ['POST', route('admin.service-categories.reorder'), ['ids' => '1,2,3'], 'ids'],
            'term order with a non-id' => ['POST', route('admin.service-categories.reorder'), ['ids' => ['abc']], 'ids.0'],
            'term order with a duplicate' => ['POST', route('admin.service-categories.reorder'), ['ids' => [(int) $category->getKey(), (int) $category->getKey()]], 'ids.0'],
            'term order with a foreign id' => ['POST', route('admin.service-categories.reorder'), ['ids' => [999999999]], 'ids'],
            'reassigning a term to itself' => ['DELETE', route('admin.service-categories.destroy', ['term' => $category->getKey()]), ['reassign_to' => (int) $category->getKey()], 'reassign_to'],
            'reassigning a term to nothing' => ['DELETE', route('admin.service-categories.destroy', ['term' => $category->getKey()]), ['reassign_to' => 999999999], 'reassign_to'],
            'deleting a term that still has services' => ['DELETE', route('admin.service-categories.destroy', ['term' => $category->getKey()]), [], 'reassign_to'],
            'a term update with an array name' => ['PUT', route('admin.technologies.update', ['term' => $technology->getKey()]), ['name' => ['x']], 'name'],

            // §8.2 services
            'a service name as an array' => ['POST', route('admin.services.store'), ['name' => ['x']], 'name'],
            'a service with no name' => ['POST', route('admin.services.store'), ['short_description' => 'Nameless'], 'name'],
            'a price that is not a number' => ['POST', route('admin.services.store'), ['name' => 'Priced', 'starting_price' => 'abc'], 'starting_price'],
            'a negative price' => ['POST', route('admin.services.store'), ['name' => 'Priced', 'starting_price' => '-5'], 'starting_price'],
            'a price with three decimals' => ['POST', route('admin.services.store'), ['name' => 'Priced', 'starting_price' => '1.234'], 'starting_price'],
            'a price above decimal(15,2)' => ['POST', route('admin.services.store'), ['name' => 'Priced', 'starting_price' => '99999999999999.99'], 'starting_price'],
            'features as a string' => ['POST', route('admin.services.store'), ['name' => 'Featured', 'features' => 'one, two'], 'features'],
            'a feature longer than 150' => ['POST', route('admin.services.store'), ['name' => 'Featured', 'features' => [str_repeat('f', 151)]], 'features.0'],
            'a technology that does not exist' => ['POST', route('admin.services.store'), ['name' => 'Teched', 'technology_ids' => [999999999]], 'technology_ids.0'],
            'a service scheduled' => ['POST', route('admin.services.store'), ['name' => 'Scheduled', 'status' => 'scheduled'], 'status'],
            'an image id that does not exist' => ['POST', route('admin.services.store'), ['name' => 'Imaged', 'image_media_id' => 999999999], 'image_media_id'],
            'an icon that is markup' => ['POST', route('admin.services.store'), ['name' => 'Iconic', 'icon' => '<script>'], 'icon'],
            'a category that does not exist' => ['POST', route('admin.services.store'), ['name' => 'Categorised', 'service_category_id' => 999999999], 'service_category_id'],
            'a service update with an array name' => ['PUT', route('admin.services.update', $service), ['name' => ['x']], 'name'],
            'a service status with none' => ['POST', route('admin.services.status', $service), [], 'status'],
            'a service status of scheduled' => ['POST', route('admin.services.status', $service), ['status' => 'scheduled'], 'status'],
            'service order with a foreign id' => ['POST', route('admin.services.reorder'), ['ids' => [999999999]], 'ids'],

            // §8.3 portfolio + gallery
            'a javascript: project URL' => ['POST', route('admin.portfolio.store'), ['title' => 'Linked', 'project_url' => 'javascript:alert(1)'], 'project_url'],
            'a completion date in the future' => ['POST', route('admin.portfolio.store'), ['title' => 'Future', 'completion_date' => $tomorrow], 'completion_date'],
            'a hand-set cover' => ['POST', route('admin.portfolio.store'), ['title' => 'Covered', 'cover_media_id' => (int) $loose->getKey()], 'cover_media_id'],
            'images on a project update' => ['PUT', route('admin.portfolio.update', $item), ['images' => ['x']], 'images'],
            'a gallery post with nothing' => ['POST', route('admin.portfolio.images.store', $item), [], 'images'],
            'a gallery pick that does not exist' => ['POST', route('admin.portfolio.images.store', $item), ['media_asset_ids' => [999999999]], 'media_asset_ids.0'],
            'a gallery order with an unattached asset' => ['POST', route('admin.portfolio.images.reorder', $gallery), ['ids' => [(int) $loose->getKey()]], 'ids'],
            'a cover that is not attached' => ['POST', route('admin.portfolio.images.cover', ['item' => $gallery->getKey(), 'image' => $loose->getKey()]), [], 'image'],
            'a caption as an array' => ['PUT', route('admin.portfolio.images.update', ['item' => $gallery->getKey(), 'image' => $attached->getKey()]), ['caption' => ['x']], 'caption'],

            // §8.4 team
            'an unknown social platform (test 14)' => ['POST', route('admin.team.store'), ['name' => 'Social', 'designation' => 'Dev', 'social_links' => ['myspace' => 'https://myspace.com/me']], 'social_links'],
            'a javascript: social link' => ['POST', route('admin.team.store'), ['name' => 'Social', 'designation' => 'Dev', 'social_links' => ['facebook' => 'javascript:alert(1)']], 'social_links.facebook'],
            'sixty-one years of experience' => ['POST', route('admin.team.store'), ['name' => 'Veteran', 'designation' => 'Dev', 'experience_years' => 61], 'experience_years'],
            'skills as a string' => ['POST', route('admin.team.store'), ['name' => 'Skilled', 'designation' => 'Dev', 'skills' => 'php, js'], 'skills'],
            'a member with no designation' => ['POST', route('admin.team.store'), ['name' => 'Undesignated'], 'designation'],
            'an ftp portfolio URL' => ['POST', route('admin.team.store'), ['name' => 'Linked', 'designation' => 'Dev', 'portfolio_url' => 'ftp://files.example/me'], 'portfolio_url'],
            'a member update with an unknown platform' => ['PUT', route('admin.team.update', $member), ['social_links' => ['myspace' => 'https://myspace.com/me']], 'social_links'],

            // §8.5 moderation
            'a testimonial of an unknown type' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['type' => 'vendor']), 'type'],
            'a rating of 0 (test 18)' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['rating' => 0]), 'rating'],
            'a rating of 6 (test 18)' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['rating' => 6]), 'rating'],
            'a rating in words' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['rating' => 'five']), 'rating'],
            'a testimonial with no review' => ['POST', route('admin.testimonials.store'), ['type' => 'other', 'author_name' => 'Silent'], 'review'],
            'a review longer than 2000' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['review' => str_repeat('r', 2001)]), 'review'],
            'a client testimonial with no company' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['type' => 'client']), 'author_company'],
            'a student testimonial with no course' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['type' => 'student']), 'course_name'],
            'a testimonial born approved' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['status' => 'approved']), 'status'],
            'a testimonial born featured' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['is_featured' => '1']), 'is_featured'],
            'a testimonial claiming a public source' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['source' => 'public_form']), 'source'],
            'a testimonial claiming its approver' => ['POST', route('admin.testimonials.store'), array_merge($validTestimonial, ['approved_by' => (int) $this->superAdmin->getKey()]), 'approved_by'],
            'a testimonial update approving itself' => ['PUT', route('admin.testimonials.update', $testimonial), ['status' => 'approved'], 'status'],
            'a rejection with no reason (test 16)' => ['POST', route('admin.testimonials.reject', $testimonial), [], 'reason'],
            'a rejection with a blank reason' => ['POST', route('admin.testimonials.reject', $testimonial), ['reason' => '   '], 'reason'],
            'a rejection reason longer than 255' => ['POST', route('admin.testimonials.reject', $testimonial), ['reason' => str_repeat('x', 256)], 'reason'],
            'a reject dodged as an approve' => ['POST', route('admin.testimonials.reject', $testimonial), ['action' => 'approve'], 'reason'],
            'bulk ids as a string' => ['POST', route('admin.testimonials.bulk-approve'), ['ids' => '1,2'], 'ids'],
            'more than 200 bulk ids' => ['POST', route('admin.testimonials.bulk-approve'), ['ids' => range(1, 201)], 'ids'],
            'a student review video from another host' => ['POST', route('admin.student-reviews.store'), array_merge($validReview, ['video_url' => 'https://evil.example/watch?v=1']), 'video_url'],
            'a student review rated 6' => ['POST', route('admin.student-reviews.store'), array_merge($validReview, ['rating' => 6]), 'rating'],
            'a student review with no student' => ['POST', route('admin.student-reviews.store'), ['review' => 'Anonymous praise for the course.'], 'student_name'],
            'a student review born approved' => ['POST', route('admin.student-reviews.store'), array_merge($validReview, ['status' => 'approved']), 'status'],
            'a student review rejected without reason' => ['POST', route('admin.student-reviews.reject', $review), [], 'reason'],

            // §8.6 success stories
            'a story with no story' => ['POST', route('admin.success-stories.store'), ['student_name' => 'Storyless'], 'story'],
            'a story video from another host' => ['POST', route('admin.success-stories.store'), array_merge($validStory, ['video_url' => 'https://evil.example/v/1']), 'video_url'],
            'a story scheduled' => ['POST', route('admin.success-stories.store'), array_merge($validStory, ['status' => 'scheduled']), 'status'],
            'a story status of scheduled' => ['POST', route('admin.success-stories.status', $story), ['status' => 'scheduled'], 'status'],

            // §8.7 blog
            'a post with no title' => ['POST', route('admin.blog-posts.store'), ['content' => '<p>Untitled.</p>'], 'title'],
            'a post with no content' => ['POST', route('admin.blog-posts.store'), ['title' => 'Empty'], 'content'],
            'tags as a string' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['tags' => 'laravel']), 'tags'],
            'a tag longer than 40' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['tags' => [str_repeat('t', 41)]]), 'tags.0'],
            'more than 40 tags' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['tags' => array_map(static fn (int $i): string => 'tag '.$i, range(1, 41))]), 'tags'],
            'a post born published' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['status' => 'published']), 'status'],
            'a post with a hand-set view count' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['views_count' => 5000]), 'views_count'],
            'a post with a hand-set reading time' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['reading_minutes' => 1]), 'reading_minutes'],
            'an unknown editor intent' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['intent' => 'hack']), 'intent'],
            'a schedule intent with no moment' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['intent' => 'schedule']), 'published_at'],
            'a schedule intent in the past' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['intent' => 'schedule', 'published_at' => $pastMoment]), 'published_at'],
            'a post category that does not exist' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['blog_category_id' => 999999999]), 'blog_category_id'],
            'a 400-character meta description (test 65)' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['seo' => ['meta_description' => str_repeat('d', 400)]]), 'seo.meta_description'],
            'an unknown SEO field on a post' => ['POST', route('admin.blog-posts.store'), array_merge($validPost, ['seo' => ['og_title' => 'Not on the entity form']]), 'seo'],
            'a post update with an array title' => ['PUT', route('admin.blog-posts.update', $post), ['title' => ['x']], 'title'],
            'a schedule with no moment' => ['POST', route('admin.blog-posts.schedule', $post), [], 'published_at'],
            'a schedule in the past (test 28)' => ['POST', route('admin.blog-posts.schedule', $post), ['published_at' => $pastMoment], 'published_at'],
            'a schedule that is not a date' => ['POST', route('admin.blog-posts.schedule', $post), ['published_at' => 'next tuesday-ish'], 'published_at'],
            'a schedule as an array' => ['POST', route('admin.blog-posts.schedule', $post), ['published_at' => ['2030-01-01']], 'published_at'],

            // §8.8 jobs
            'an unknown employment type' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['employment_type' => 'forever']), 'employment_type'],
            'an unknown work mode' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['work_mode' => 'moon']), 'work_mode'],
            'a maximum salary below the minimum (test 39)' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['salary_min' => '500', 'salary_max' => '100']), 'salary_max'],
            'a salary that is not a number' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['salary_min' => 'lots']), 'salary_min'],
            'a weekly salary period' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['salary_period' => 'weekly']), 'salary_period'],
            'an open opening with yesterday\'s deadline' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['status' => 'open', 'deadline' => $yesterday]), 'deadline'],
            'zero openings' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['openings_count' => 0]), 'openings_count'],
            'a hand-set application count' => ['POST', route('admin.jobs.store'), array_merge($validJob, ['applications_count' => 9]), 'applications_count'],
            'an opening with no description' => ['POST', route('admin.jobs.store'), ['title' => 'Undescribed', 'employment_type' => 'full_time'], 'description'],
            'a job status that does not exist' => ['POST', route('admin.jobs.status', $job), ['status' => 'archived'], 'status'],
            'a job update lowering the maximum below the minimum' => ['PUT', route('admin.jobs.update', $job), ['salary_min' => '900', 'salary_max' => '100'], 'salary_max'],

            // §8.9 applications
            'an application rated 6' => ['PUT', route('admin.job-applications.update', $application), ['rating' => 6], 'rating'],
            'notes as an array' => ['PUT', route('admin.job-applications.update', $application), ['internal_notes' => ['x']], 'internal_notes'],
            'new straight to interview (test 38)' => ['POST', route('admin.job-applications.status', $application), ['status' => 'interview', 'interview_at' => $this->displayDateTime(Carbon::now()->addDay()), 'interview_mode' => 'online'], 'status'],
            'a rejection of a candidate with no reason' => ['POST', route('admin.job-applications.status', $application), ['status' => 'rejected'], 'reason'],
            'an interview in the past' => ['POST', route('admin.job-applications.status', $reviewing), ['status' => 'interview', 'interview_at' => $pastMoment, 'interview_mode' => 'online'], 'interview_at'],
            'an interview with no mode' => ['POST', route('admin.job-applications.status', $reviewing), ['status' => 'interview', 'interview_at' => $this->displayDateTime(Carbon::now()->addDay())], 'interview_mode'],
            'a reviewer that does not exist' => ['POST', route('admin.job-applications.assign', $application), ['user_id' => 999999999], 'user_id'],
            'a reviewer who cannot see applications' => ['POST', route('admin.job-applications.assign', $application), ['user_id' => (int) $powerless->getKey()], 'user_id'],
            'an assignment with no user key' => ['POST', route('admin.job-applications.assign', $application), [], 'user_id'],

            // §8.10 inquiries
            'an inquiry status that does not exist' => ['POST', route('admin.contact-inquiries.status', $inquiry), ['status' => 'bogus'], 'status'],
            'an inquiry update with an unknown status' => ['PUT', route('admin.contact-inquiries.update', $inquiry), ['status' => 'bogus'], 'status'],
            'response notes longer than 5000' => ['PUT', route('admin.contact-inquiries.update', $inquiry), ['response_notes' => str_repeat('n', 5001)], 'response_notes'],
            'spam with no reason' => ['POST', route('admin.contact-inquiries.spam', $inquiry), [], 'reason'],
            'an inquiry assignee that does not exist' => ['POST', route('admin.contact-inquiries.assign', $inquiry), ['user_id' => 999999999], 'user_id'],
            'an inquiry assignee who cannot see inquiries' => ['POST', route('admin.contact-inquiries.assign', $inquiry), ['user_id' => (int) $powerless->getKey()], 'user_id'],
        ];

        foreach ($cases as $case => [$method, $url, $data, $field]) {
            $this->assertRefusedWith422($case, $method, $url, $data, $field);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | §11 — the named validation cases
    |--------------------------------------------------------------------------
    */

    /**
     * §11 test 4: a submitted slug of `admin`, `blog` or `0` is refused with a field error — on every editor
     * that carries a slug.
     */
    public function test_contract_04_a_reserved_or_numeric_slug_is_refused_with_a_field_error(): void
    {
        $stores = [
            'services' => [route('admin.services.store'), ['name' => 'Slugged service']],
            'portfolio' => [route('admin.portfolio.store'), ['title' => 'Slugged project']],
            'team' => [route('admin.team.store'), ['name' => 'Slugged member', 'designation' => 'Dev']],
            'blog posts' => [route('admin.blog-posts.store'), ['title' => 'Slugged post', 'content' => '<p>Body.</p>']],
            'jobs' => [route('admin.jobs.store'), ['title' => 'Slugged job', 'employment_type' => 'full_time', 'description' => '<p>Body.</p>']],
            'blog categories' => [route('admin.blog-categories.store'), ['name' => 'Slugged category']],
        ];

        foreach ($stores as $label => [$url, $valid]) {
            foreach (['admin', 'blog', '0', '2026', 'services'] as $slug) {
                $message = $this->assertRefusedWith422(sprintf('%s with the slug "%s"', $label, $slug), 'POST', $url, array_merge($valid, ['slug' => $slug]), 'slug');

                $this->assertNotSame('', $message, 'The slug refusal must say why.');
            }
        }

        // A normal typed slug is accepted and stored exactly.
        $this->actingAs($this->superAdmin)->sendCms('POST', route('admin.services.store'), ['name' => 'Slugged service', 'slug' => 'web-development'])->assertOk();
        $this->assertSame(1, Service::query()->where('slug', 'web-development')->count());
    }

    /**
     * §11 test 10: an `.svg`, a `.php` renamed `.jpg` (judged by its bytes) and an image above the effective
     * limit are each refused on `image`; the contract's 9 MB is set against an 8 MB `security.max_upload_mb`.
     */
    public function test_contract_10_service_image_uploads_are_judged_by_content_and_size(): void
    {
        settings_repo()->set('security.max_upload_mb', 8);

        $refused = [
            'an SVG' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'a PHP script renamed .jpg (named, fake)' => UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["c"]); ?>'),
            'a PHP script renamed .jpg (real bytes)' => $this->uploadWithRealBytes('photo.jpg', '<?php system($_GET["c"]); ?>'),
            'a PHP payload behind a JPEG magic number' => $this->uploadWithRealBytes('selfie.jpg', "\xFF\xD8\xFF\xE0".'<?php echo "owned"; ?>'),
            'a 9 MB image' => UploadedFile::fake()->create('huge.jpg', 9 * 1024, 'image/jpeg'),
        ];

        foreach ($refused as $case => $file) {
            $this->assertRefusedWith422($case, 'POST', route('admin.services.store'), ['name' => 'Imaged service '.$this->uniqueToken(), 'image' => $file], 'image');
            $this->assertSame([], Storage::disk('public')->allFiles(), $case.' left a file on the public disk.');
        }

        // A genuine JPEG is accepted and becomes a library asset on the service.
        $response = $this->actingAs($this->superAdmin)
            ->post(route('admin.services.store'), ['name' => 'Pictured service', 'image' => UploadedFile::fake()->image('photo.jpg', 640, 480)], ['Accept' => 'application/json'])
            ->assertOk();

        $service = Service::query()->findOrFail($response->json('id'));
        $this->assertNotNull($service->image_media_id, 'A genuine image is stored through MediaService and linked.');
    }

    /**
     * §11 test 14 (validation half): `social_links` with an unknown key (`myspace`) is a 422 on `social_links`,
     * on create and on update, and the known platforms still save.
     */
    public function test_contract_14_an_unknown_social_platform_is_refused(): void
    {
        $member = $this->makeTeamMember();

        $this->assertRefusedWith422('myspace on create', 'POST', route('admin.team.store'), ['name' => 'Social member', 'designation' => 'Dev', 'social_links' => ['myspace' => 'https://myspace.com/me']], 'social_links');
        $this->assertRefusedWith422('myspace on update', 'PUT', route('admin.team.update', $member), ['social_links' => ['github' => 'https://github.com/me', 'myspace' => 'https://myspace.com/me']], 'social_links');

        $this->actingAs($this->superAdmin)
            ->sendCms('PUT', route('admin.team.update', $member), ['social_links' => ['github' => 'https://github.com/me', 'linkedin' => '']])
            ->assertOk();

        $links = json_decode((string) DB::table('team_members')->where('id', $member->getKey())->value('social_links'), true);
        $this->assertSame(['github' => 'https://github.com/me'], $links, 'An empty row is "no link"; a known platform saves.');
    }

    /**
     * §11 test 16 (validation half): rejecting without a reason is a 422 and the row stays pending; with a
     * reason it stores `rejection_reason`.
     */
    public function test_contract_16_rejecting_needs_a_reason(): void
    {
        $testimonial = $this->makeTestimonial();

        $this->assertRefusedWith422('reject without a reason', 'POST', route('admin.testimonials.reject', $testimonial), [], 'reason');
        $this->assertSame('pending', (string) DB::table('testimonials')->where('id', $testimonial->getKey())->value('status'));

        $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.testimonials.reject', $testimonial), ['reason' => 'Could not verify the client'])
            ->assertOk();

        $row = DB::table('testimonials')->where('id', $testimonial->getKey())->first();
        $this->assertSame('rejected', (string) $row->status);
        $this->assertSame('Could not verify the client', (string) $row->rejection_reason);
    }

    /**
     * §11 test 18: a rating of 0 or 6 is refused, `null` is accepted and renders no stars.
     */
    public function test_contract_18_rating_bounds_and_a_null_rating_renders_no_stars(): void
    {
        $valid = ['type' => 'other', 'author_name' => 'Unrated author', 'review' => 'No stars given, only words.'];

        foreach ([0, 6, -1, '4.5'] as $rating) {
            $this->assertRefusedWith422('a rating of '.var_export($rating, true), 'POST', route('admin.testimonials.store'), array_merge($valid, ['rating' => $rating]), 'rating');
        }

        $response = $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.testimonials.store'), array_merge($valid, ['rating' => null]))
            ->assertOk();

        $id = (int) $response->json('id');
        $this->assertNull(DB::table('testimonials')->where('id', $id)->value('rating'));

        $this->actingAs($this->superAdmin)
            ->get(route('admin.testimonials.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('Unrated author', false)
            ->assertDontSee('aria-label="Rated', false);

        $rated = $this->actingAs($this->superAdmin)->sendCms('POST', route('admin.testimonials.store'), array_merge($valid, ['author_name' => 'Rated author', 'rating' => 5]))->assertOk();
        $this->assertSame(5, (int) DB::table('testimonials')->where('id', $rated->json('id'))->value('rating'));

        $this->actingAs($this->superAdmin)
            ->get(route('admin.testimonials.index', ['tab' => 'all']))
            ->assertOk()
            ->assertSee('aria-label="Rated 5 out of 5"', false);
    }

    /**
     * §11 test 28: `ScheduleBlogPostRequest` refuses a past datetime with 422, and the post keeps its status.
     */
    public function test_contract_28_scheduling_a_post_in_the_past_is_refused(): void
    {
        $post = $this->makeBlogPost($this->superAdmin);

        foreach (['one hour ago' => Carbon::now()->subHour(), 'one minute ago' => Carbon::now()->subMinute(), 'last year' => Carbon::now()->subYear()] as $label => $moment) {
            $this->assertRefusedWith422('schedule '.$label, 'POST', route('admin.blog-posts.schedule', $post), ['published_at' => $this->displayDateTime($moment)], 'published_at');
        }

        $this->assertSame('draft', (string) DB::table('blog_posts')->where('id', $post->getKey())->value('status'));

        $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.blog-posts.schedule', $post), ['published_at' => $this->displayDateTime(Carbon::now()->addDays(2))])
            ->assertOk()
            ->assertJsonPath('status', 'scheduled');
    }

    /**
     * §11 test 34: a `.php` renamed `.pdf`, a `.exe` and a CV above the effective limit are each refused on
     * `cv`, and nothing is written to disk or to the database.
     */
    public function test_contract_34_hostile_cvs_are_refused_and_nothing_is_written(): void
    {
        $job = $this->makeJobOpening('open');
        $limit = app(ApplicationCvService::class)->maxKilobytes();

        $refused = [
            'a PHP script renamed .pdf' => $this->uploadWithRealBytes('cv.pdf', "<?php\nsystem(\$_GET['c']);\n"),
            'a Windows executable' => $this->uploadWithRealBytes('cv.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 64).'This program cannot be run in DOS mode.'),
            'an HTML page renamed .docx' => $this->uploadWithRealBytes('cv.docx', '<html><body><script>alert(1)</script></body></html>'),
            'a CV one kilobyte over the effective limit' => UploadedFile::fake()->create('cv.pdf', $limit + 1, 'application/pdf'),
            'no CV at all' => null,
        ];

        $n = 0;

        foreach ($refused as $case => $file) {
            $n++;
            $before = $this->marketingFingerprint();

            $payload = array_merge($this->spamSafeFields(), [
                'applicant_name' => 'Hostile applicant '.$n,
                'email' => 'hostile-applicant-'.$n.'@gmail.com',
                'phone' => '+92 300 55500'.$n,
                'cover_letter' => 'Please consider my application for this position.',
            ]);

            if ($file !== null) {
                $payload['cv'] = $file;
            }

            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.(30 + $n)])
                ->post(route('site.careers.apply', ['jobOpening' => $job->slug]), $payload, ['Accept' => 'application/json']);

            $this->assertSame(422, $response->getStatusCode(), sprintf('%s must be refused with 422; it answered %d: %s', $case, $response->getStatusCode(), Str::limit((string) $response->getContent(), 300)));
            $this->assertArrayHasKey('cv', (array) $response->json('errors'), sprintf('%s must name the cv field; the errors were %s.', $case, json_encode(array_keys((array) $response->json('errors')))));
            $this->assertSame($before, $this->marketingFingerprint(), $case.' wrote a row.');
            $this->assertSame([], Storage::disk('local')->allFiles(), $case.' wrote a file to the private disk.');
            $this->assertSame([], Storage::disk('public')->allFiles(), $case.' wrote a file to the public disk.');
        }
    }

    /**
     * §11 test 39: `salary_max` lower than `salary_min` is refused; both round-trip as `decimal(15,2)` strings;
     * `salary_visible = false` renders "Negotiable" publicly and never emits the numbers in the HTML.
     */
    public function test_contract_39_salary_range_round_trip_and_withheld_salary(): void
    {
        $valid = ['title' => 'Senior Laravel developer', 'employment_type' => 'full_time', 'description' => '<p>Build the platform.</p>'];

        $this->assertRefusedWith422('max below min', 'POST', route('admin.jobs.store'), array_merge($valid, ['salary_min' => '250000.00', 'salary_max' => '150000.00']), 'salary_max');
        $this->assertRefusedWith422('max a cent below min', 'POST', route('admin.jobs.store'), array_merge($valid, ['salary_min' => '150000.01', 'salary_max' => '150000.00']), 'salary_max');

        $response = $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.jobs.store'), array_merge($valid, [
                'salary_min' => '150000.50',
                'salary_max' => '250000.75',
                'salary_period' => 'monthly',
                'salary_visible' => '0',
                'status' => 'open',
            ]))
            ->assertOk();

        $row = DB::table('job_openings')->where('id', $response->json('id'))->first();

        $this->assertSame('150000.50', (string) $row->salary_min);
        $this->assertSame('250000.75', (string) $row->salary_max);
        $this->assertSame(0, (int) $row->salary_visible);

        $this->assertSame(
            ['DATA_TYPE' => 'decimal', 'NUMERIC_PRECISION' => 15, 'NUMERIC_SCALE' => 2],
            $this->columnType('job_openings', 'salary_min'),
        );

        $this->signOut();

        foreach ([route('site.careers.index'), route('site.careers.show', ['jobOpening' => $row->slug])] as $url) {
            $html = (string) $this->get($url)->assertOk()->assertSee('Negotiable', false)->getContent();

            foreach (['150000.50', '150000.5', '250000.75', money('150000.50'), money('150000.50', false), money('250000.75', false)] as $number) {
                $this->assertStringNotContainsString($number, $html, sprintf('%s leaked the withheld salary (%s).', $url, $number));
            }
        }
    }

    /**
     * §11 test 54: a `<script>` in a contact message is stored without tags and rendered escaped on the admin
     * detail; a rich-text service description loses `onerror=` and an `<iframe>` on write.
     */
    public function test_contract_54_hostile_markup_is_stripped_on_write_and_escaped_on_render(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.54'])
            ->post(route('site.contact.store'), array_merge($this->spamSafeFields(), [
                'inquiry_type' => 'general',
                'name' => 'Script kiddie',
                'email' => 'script-54@example.com',
                'message' => '<script>alert(1)</script> Please call me back about a new website project.',
            ]))
            ->assertRedirect();

        $stored = ContactInquiry::query()->where('email', 'script-54@example.com')->firstOrFail();

        $this->assertStringNotContainsString('<script', (string) $stored->message);
        $this->assertStringContainsString('Please call me back', (string) $stored->message);
        $this->assertFalse((bool) $stored->is_spam, 'A genuine submission with markup is still a genuine submission.');

        // A message that reached the table with its markup (a later import, a legacy row) still renders escaped.
        $raw = $this->makeContactInquiry(['message' => '<script>alert("raw")</script><img src=x onerror=alert(2)>']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.contact-inquiries.show', $raw->getKey()))
            ->assertOk()
            ->assertDontSee('<script>alert("raw")</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertSee('&lt;script&gt;', false);

        $response = $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.services.store'), [
                'name' => 'Sanitised service',
                'full_description' => '<p onerror="alert(1)">Solid engineering.</p><iframe src="https://evil.example/frame"></iframe><img src="https://example.com/a.png" onerror="alert(3)">',
            ])
            ->assertOk();

        $description = (string) DB::table('services')->where('id', $response->json('id'))->value('full_description');

        $this->assertStringContainsString('Solid engineering.', $description);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $description);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $description);
        $this->assertStringNotContainsStringIgnoringCase('evil.example', $description);
    }

    /**
     * §11 test 62: a `collaborator_id` posted in the contact form body is discarded — as is every other
     * server-owned column a hostile form may post.
     */
    public function test_contract_62_posted_server_owned_columns_are_discarded(): void
    {
        $collaborator = $this->createUserWithRole('Collaborator');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62'])
            ->post(route('site.contact.store'), array_merge($this->spamSafeFields(), [
                'inquiry_type' => 'general',
                'name' => 'Mass assigner',
                'email' => 'mass-assign-62@example.com',
                'message' => 'I would like a quote for a mobile application please.',
                'collaborator_id' => (int) $collaborator->getKey(),
                'referral_visit_id' => 5151,
                'assigned_to' => (int) $this->superAdmin->getKey(),
                'status' => 'closed',
                'is_spam' => '1',
                'routing_status' => 'routed',
                'routed_id' => 77,
                'ip_address' => '6.6.6.6',
            ]))
            ->assertRedirect();

        $row = DB::table('contact_inquiries')->where('email', 'mass-assign-62@example.com')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->collaborator_id, 'A browser-posted collaborator_id is never stored.');
        $this->assertNull($row->referral_visit_id);
        $this->assertNull($row->assigned_to);
        $this->assertNull($row->routed_id);
        $this->assertSame('new', (string) $row->status);
        $this->assertSame(0, (int) $row->is_spam);
        $this->assertSame('not_applicable', (string) $row->routing_status);
        $this->assertSame('203.0.113.62', (string) $row->ip_address);
    }

    /**
     * §11 test 65 (ND-13): `StoreBlogPostRequest::rules()` declares no SEO rule of its own; a 300-character
     * `seo.meta_description` is accepted, a 400-character one is refused by `SeoService::rules()`; no
     * phase-04 Form Request names a `seo_meta` column.
     */
    public function test_contract_65_seo_rules_are_delegated_never_duplicated(): void
    {
        $request = StoreBlogPostRequest::createFrom(Request::create('/admin/blog-posts', 'POST'));
        $request->setContainer($this->app);
        $request->setUserResolver(fn (): User => $this->superAdmin);

        $rules = $request->rules();
        $seoRules = app(SeoService::class)->rules();

        $this->assertArrayNotHasKey('meta_description', $rules, 'StoreBlogPostRequest must not restate the meta description rule.');

        foreach (array_keys($rules) as $key) {
            $this->assertFalse(in_array($key, self::SEO_COLUMNS, true), sprintf('StoreBlogPostRequest declares the seo_meta column %s itself.', $key));

            if (str_starts_with($key, SeoService::DEFAULT_PREFIX.'.')) {
                $this->assertArrayHasKey($key, $seoRules, sprintf('%s is not a SeoService rule.', $key));
            }
        }

        foreach ($seoRules as $key => $rule) {
            $this->assertArrayHasKey($key, $rules, sprintf('SeoService rule %s is not merged into StoreBlogPostRequest.', $key));
            $this->assertEquals($rule, $rules[$key], sprintf('%s must be SeoService\'s rule verbatim.', $key));
        }

        // 300 characters fit seo_meta.meta_description (string 320) and are accepted.
        $description = str_repeat('Three hundred characters of honest description. ', 7);
        $description = mb_substr($description, 0, 300);

        $response = $this->actingAs($this->superAdmin)
            ->sendCms('POST', route('admin.blog-posts.store'), ['title' => 'Described post', 'content' => '<p>Body.</p>', 'seo' => ['meta_description' => $description]])
            ->assertOk();

        $stored = DB::table('seo_meta')
            ->where('seoable_type', (new BlogPost)->getMorphClass())
            ->where('seoable_id', $response->json('id'))
            ->value('meta_description');

        $this->assertSame(300, mb_strlen((string) $stored));

        // 400 is refused by the one SEO rule set.
        $this->assertRefusedWith422('a 400-character meta description', 'POST', route('admin.blog-posts.store'), ['title' => 'Over-described post', 'content' => '<p>Body.</p>', 'seo' => ['meta_description' => str_repeat('d', 400)]], 'seo.meta_description');

        // Static scan: no phase-04 Form Request names a seo_meta column (the SEO manager's own requests are
        // the SEO screen itself and are exempt by definition).
        $violations = [];
        $scanned = 0;
        $root = app_path('Http/Requests/Cms');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! str_ends_with($file->getFilename(), '.php') || in_array($file->getFilename(), self::SEO_MANAGER_REQUESTS, true)) {
                continue;
            }

            $scanned++;

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $literal = trim($token[1], '\'"');
                $column = str_starts_with($literal, SeoService::DEFAULT_PREFIX.'.') ? substr($literal, strlen(SeoService::DEFAULT_PREFIX) + 1) : $literal;

                if (in_array($column, self::SEO_COLUMNS, true)) {
                    $violations[] = sprintf('%s:%d names %s', str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1)), $token[2], $literal);
                }
            }
        }

        $this->assertGreaterThan(40, $scanned, 'The scan found too few Form Requests to be meaningful.');
        $this->assertSame([], $violations, "A Form Request restates an SEO column (ND-13):\n".implode("\n", $violations));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Send one request as the Super Admin and assert: 422, the named field carries an error, and no marketing
     * row, media asset, SEO row or audit row changed. Returns the field's first message.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRefusedWith422(string $case, string $method, string $url, array $data, string $field): string
    {
        $before = $this->marketingFingerprint();

        $response = $this->actingAs($this->superAdmin)->sendCms($method, $url, $data);

        $this->assertSame(
            422,
            $response->getStatusCode(),
            sprintf('%s (%s %s) must be refused with 422; it answered %d: %s', $case, $method, $url, $response->getStatusCode(), Str::limit((string) $response->getContent(), 400)),
        );

        $errors = (array) $response->json('errors');

        $this->assertArrayHasKey(
            $field,
            $errors,
            sprintf('%s must name the field [%s]; the errors were: %s', $case, $field, json_encode(array_keys($errors))),
        );

        $this->assertSame($before, $this->marketingFingerprint(), $case.' wrote something although it was refused.');

        return (string) (((array) $errors[$field])[0] ?? '');
    }

    /**
     * @return array{DATA_TYPE: string, NUMERIC_PRECISION: int, NUMERIC_SCALE: int}
     */
    private function columnType(string $table, string $column): array
    {
        $row = DB::selectOne(
            'SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return [
            'DATA_TYPE' => (string) ($row->DATA_TYPE ?? ''),
            'NUMERIC_PRECISION' => (int) ($row->NUMERIC_PRECISION ?? 0),
            'NUMERIC_SCALE' => (int) ($row->NUMERIC_SCALE ?? 0),
        ];
    }
}
