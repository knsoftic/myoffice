<?php

declare(strict_types=1);

namespace App\Console\Commands\Cms;

use App\Models\Cms\Service;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Services\Cms\SeoService;
use App\Services\Core\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * `cms:seo-copy` — search titles, descriptions and keywords for KN Softic, written into the SEO store
 * (`SeoService`, `seo_meta`) and the `seo.*` defaults, where /admin/website › SEO edits them afterwards.
 *
 * Why: Search Console (2026-10-17) showed every page with the same description, none of it naming the
 * city, while the searches that found the site were "web development course(s) in faisalabad", "seo
 * course/training in faisalabad", "graphic designing courses in faisalabad", "digital marketing course
 * faisalabad", "software house near me" and the brand ("kn softic", "softic", "kn software", "kn soft").
 * The `meta keywords` tag is written as asked, but Google ignores it: what moves those searches is the
 * title and description, so those carry the phrases.
 *
 * Built from the database, not typed: the city and company come from settings, each category lists its
 * own published courses, each course states its own duration, the services page names the published
 * services — so the copy stays true on a server whose catalogue differs from this one.
 *
 * **Only empty fields are written.** A title or description an editor already set is left alone unless
 * `--overwrite` is given. Running it twice changes nothing. Competitors' names that appeared among the
 * queries ("softic era") are deliberately not used.
 */
final class WriteSearchCopy extends Command
{
    use ConfirmableTrait;

    protected $signature = 'cms:seo-copy
        {--overwrite : Replace titles, descriptions and keywords that are already set}
        {--force : Run in production without asking}';

    protected $description = 'Write search titles, descriptions and keywords (city, courses, services) into the SEO store — empty fields only';

    /** Ways people searched for the brand (Search Console, 2026-10-17). */
    private const BRAND_VARIANTS = ['Softic', 'KN Software', 'KN Soft'];

    /** Phrases a category's own name does not produce. */
    private const CATEGORY_EXTRAS = [
        'web-development' => ['courses for web development', 'website development course'],
        'graphic-designing' => ['graphic design course', 'graphic design courses'],
        'digital-marketing' => ['seo course', 'seo training', 'social media marketing course'],
    ];

    private const TITLE_MAX = 180;

    private const DESCRIPTION_MAX = 320;

    private const KEYWORDS_MAX = 500;

    private int $written = 0;

    public function handle(SeoService $seo, SettingsService $settings): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Artisan keeps one instance of a command per process, so a second call would count on from the first.
        $this->written = 0;

        $company = trim((string) setting('company.name', ''));
        $city = trim((string) setting('contact.city', ''));

        if ($company === '' || $city === '') {
            $this->error('Set the company name and Settings › Contact › City first: every title names them.');

            return self::FAILURE;
        }

        $this->line(sprintf('Database: <info>%s</info>  ·  %s, %s', DB::connection()->getDatabaseName(), $company, $city));

        $brand = array_merge([$company, $company.' '.$city], self::BRAND_VARIANTS);
        $categories = $this->categories();
        $services = Service::query()->public()->orderBy('sort_order')->pluck('name')->map(static fn ($name): string => (string) $name)->all();

        $this->defaults($settings, [
            'meta_description' => sprintf(
                '%s is an IT training institute and software house in %s: practical computer courses, and websites, apps and software for businesses.',
                $company, $city,
            ),
            'meta_keywords' => $this->keywords(array_merge($brand, [
                'IT courses in '.$city, 'computer courses in '.$city, 'IT institute in '.$city,
                'software house in '.$city, 'software house near me',
            ])),
        ]);

        $this->fill($seo, SeoService::HOME_ROUTE, 'home', [
            'title' => sprintf('%s – IT Courses & Software House in %s', $company, $city),
            'meta_description' => sprintf(
                'Learn web development, graphic design, digital marketing and SEO at %s, %s — or get your website, app or software built.',
                $company, $city,
            ),
            'meta_keywords' => $this->keywords(array_merge($brand, [
                'web development course in '.$city, 'web development courses in '.$city,
                'SEO course in '.$city, 'graphic designing courses in '.$city,
                'digital marketing course in '.$city, 'software house in '.$city, 'software house near me',
            ])),
        ]);

        $this->fill($seo, 'site.courses.index', '/courses', [
            'title' => 'Computer & IT Courses in '.$city,
            'meta_description' => $this->fit(sprintf(
                '%s courses at %s, %s — fees, duration and online admission.',
                $this->sentenceList($categories->pluck('name')->all()), $company, $city,
            ), self::DESCRIPTION_MAX),
            'meta_keywords' => $this->keywords(array_merge(
                ['IT courses in '.$city, 'computer courses in '.$city, 'courses for web development'],
                $categories->map(static fn (CourseCategory $category): string => mb_strtolower((string) $category->name).' courses in '.$city)->all(),
            )),
        ]);

        foreach ($categories as $category) {
            $this->category($seo, $category, $company, $city);
        }

        foreach (Course::query()->published()->whereHas('category', static fn (Builder $query): Builder => $query->where('is_active', true))->get() as $course) {
            $this->course($seo, $course, $company, $city);
        }

        $this->fill($seo, 'site.services.index', '/services', [
            'title' => 'Software House in '.$city,
            'meta_description' => $this->fit(
                $services === []
                    ? sprintf('%s is a software house in %s: websites, apps and custom software.', $company, $city)
                    : sprintf('%s is a software house in %s: %s.', $company, $city, $this->sentenceList($services)),
                self::DESCRIPTION_MAX,
            ),
            'meta_keywords' => $this->keywords([
                'software house in '.$city, 'software house near me', 'software company in '.$city,
                'website development in '.$city, 'app development in '.$city, 'KN Software',
            ]),
        ]);

        $phone = trim((string) setting('contact.phone', ''));
        $email = trim((string) setting('contact.email', ''));
        $reach = implode(', ', array_filter([$phone, filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null]));

        $this->fill($seo, 'site.contact.index', '/contact', [
            'title' => sprintf('Contact %s – %s', $company, $city),
            'meta_description' => sprintf(
                'Call, WhatsApp or visit %s in %s%s — for courses, admissions and software projects.',
                $company, $city, $reach !== '' ? ': '.$reach : '',
            ),
            'meta_keywords' => $this->keywords([$company.' contact number', $company.' address', $company.' '.$city]),
        ]);

        $this->fill($seo, 'site.fees.index', '/fee-structure', [
            'title' => 'Course Fees in '.$city,
            'meta_description' => sprintf(
                'The fee for every %s course in %s — course fee, admission fee and monthly instalments side by side.',
                $company, $city,
            ),
        ]);

        $this->fill($seo, 'site.admission.create', '/admission', [
            'title' => 'Apply for Admission – IT Courses in '.$city,
            'meta_description' => sprintf(
                'Apply online for a course at %s, %s. Nothing to pay to apply and no account needed — we call you back.',
                $company, $city,
            ),
        ]);

        $this->info($this->written === 0
            ? 'Nothing to write: every field already has a value (use --overwrite to replace them).'
            : sprintf('Search copy written to %d target(s). Edit any of it in /admin/website › SEO.', $this->written));

        return self::SUCCESS;
    }

    private function category(SeoService $seo, CourseCategory $category, string $company, string $city): void
    {
        $name = trim((string) $category->name);
        $lower = mb_strtolower($name);
        $courses = Course::query()->published()->where('course_category_id', $category->getKey())->catalogueOrder()->pluck('name')
            ->map(static fn ($course): string => (string) $course)->all();

        $this->fill($seo, $category, '/courses/category/'.$category->slug, [
            'title' => $name.' Courses in '.$city,
            'meta_description' => $this->fit(sprintf(
                '%s courses in %s at %s%s. Practical classes on campus — see the fees and apply online.',
                $name, $city, $company, $courses === [] ? '' : ': '.$this->sentenceList($courses),
            ), self::DESCRIPTION_MAX),
            'meta_keywords' => $this->keywords(array_merge(
                [$lower.' course in '.$city, $lower.' courses in '.$city, $lower.' training in '.$city],
                array_map(static fn (string $extra): string => $extra.' in '.$city, self::CATEGORY_EXTRAS[(string) $category->slug] ?? []),
            )),
        ]);
    }

    private function course(SeoService $seo, Course $course, string $company, string $city): void
    {
        $name = trim((string) $course->name);
        $lower = mb_strtolower($name);
        $noun = str_contains($lower, 'course') ? '' : ' Course';
        $duration = $course->durationLabel();

        $this->fill($seo, $course, '/courses/'.$course->slug, [
            'title' => $name.$noun.' in '.$city,
            'meta_description' => sprintf(
                '%s%s in %s at %s%s. Practical classes on campus — see the fee and apply online.',
                $name, mb_strtolower($noun), $city, $company, $duration === null ? '' : ' — '.$duration,
            ),
            'meta_keywords' => $this->keywords([
                trim($lower.mb_strtolower($noun)).' in '.$city,
                $lower.' training in '.$city,
                $lower.' classes in '.$city,
            ]),
        ]);
    }

    /**
     * Write the fields of one SEO target that are still empty (all of them with --overwrite).
     *
     * @param  array<string, string>  $values
     */
    private function fill(SeoService $seo, Model|string $target, string $label, array $values): void
    {
        $row = $seo->meta($target);
        $data = [];

        foreach ($values as $column => $value) {
            $current = trim((string) ($row?->getAttribute($column) ?? ''));

            if ($value !== '' && ($current === '' || $this->option('overwrite'))) {
                $data[$column] = $column === 'title' ? $this->fit($value, self::TITLE_MAX) : $value;
            }
        }

        if ($data === []) {
            $this->line(sprintf('  · %s: already set — left alone.', $label));

            return;
        }

        $seo->save($target, $data, 'cms:seo-copy');
        $this->written++;
        $this->line(sprintf('  · %s: %s', $label, $data['title'] ?? implode(', ', array_keys($data))));
    }

    /**
     * The site-wide fallbacks every page without its own copy uses. `seo.meta_title` is deliberately not
     * set: it would replace the default title of every route page ("Services", "Courses").
     *
     * @param  array<string, string>  $values
     */
    private function defaults(SettingsService $settings, array $values): void
    {
        $data = [];

        foreach ($values as $key => $value) {
            if (trim((string) setting('seo.'.$key, '')) === '' || $this->option('overwrite')) {
                $data[$key] = $value;
            }
        }

        if ($data === []) {
            $this->line('  · site-wide defaults: already set — left alone.');

            return;
        }

        $settings->asSystem()->update('seo', $data);
        $this->written++;
        $this->line('  · site-wide defaults: '.implode(', ', array_keys($data)));
    }

    /**
     * The active categories that have at least one published course, in catalogue order.
     *
     * @return \Illuminate\Support\Collection<int, CourseCategory>
     */
    private function categories()
    {
        return CourseCategory::query()
            ->where('is_active', true)
            ->whereIn('id', Course::query()->published()->select('course_category_id'))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  list<string>  $phrases
     */
    private function keywords(array $phrases): string
    {
        $kept = [];
        $length = 0;

        foreach ($phrases as $phrase) {
            $phrase = trim($phrase);

            if ($phrase === '' || in_array(mb_strtolower($phrase), array_map('mb_strtolower', $kept), true)) {
                continue;
            }

            $add = mb_strlen($phrase) + ($kept === [] ? 0 : 2);

            if ($length + $add > self::KEYWORDS_MAX) {
                break;
            }

            $kept[] = $phrase;
            $length += $add;
        }

        return implode(', ', $kept);
    }

    /**
     * "a, b and c".
     *
     * @param  list<string>  $items
     */
    private function sentenceList(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items)));

        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * Shorten at a word boundary so a long course list never breaks the column limit.
     */
    private function fit(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,;:—–-').'…';
    }
}
