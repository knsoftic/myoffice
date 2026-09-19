<?php

declare(strict_types=1);

namespace Tests\Unit\Cms\Marketing;

use App\Enums\Cms\ContentStatus;
use App\Services\Cms\BlogService;
use App\Support\SlugGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure rules behind phase-04 tests 2-5 and the blog status map (§6.1, §3 F-5.1).
 *
 *   · `SlugGenerator::normalise()` transliterates to ASCII, lower-cases and collapses separators; an emoji-only
 *     source normalises to nothing (which is what triggers the `{model}-{id}` fallback of test 5);
 *   · `SlugGenerator::isReserved()` refuses every reserved route word and every purely numeric slug (test 4), and
 *     the admin slug pattern accepts only lower-case words joined by single hyphens;
 *   · `BlogService::allowedNext()` is the blog's transition map: draft → scheduled / published, scheduled → draft /
 *     published, published → draft / archived, archived → draft.
 */
final class SlugAndTransitionRulesTest extends TestCase
{
    #[Test]
    public function test_02_05_normalise_transliterates_and_collapses_separators(): void
    {
        $this->assertSame('web-development', SlugGenerator::normalise('Web Development'));
        $this->assertSame('web-development', SlugGenerator::normalise('  Web   --  Development!! '));
        $this->assertSame('cafe-creme-brulee', SlugGenerator::normalise('Café Crème Brûlée'));
        $this->assertSame('laravel-php-8', SlugGenerator::normalise("Laravel\tPHP 8"), 'Control characters become separators.');
        $this->assertSame('', SlugGenerator::normalise("\u{1F680}\u{1F525}\u{2728}"), 'An emoji-only title normalises to nothing.');
    }

    #[Test]
    public function test_04_reserved_words_and_numbers_are_reserved(): void
    {
        foreach (['admin', 'blog', 'services', 'portfolio', 'team', 'careers', 'contact', 'sitemap.xml', 'robots.txt', 'preview', 'Admin', ' BLOG '] as $slug) {
            $this->assertTrue(SlugGenerator::isReserved($slug), sprintf('"%s" is reserved.', $slug));
        }

        foreach (['0', '17', '2026'] as $numeric) {
            $this->assertTrue(SlugGenerator::isReserved($numeric), sprintf('"%s" is purely numeric.', $numeric));
        }

        foreach (['web-development', 'blog-2', 'team-building', '2026-roadmap', 'admin-panel-design'] as $allowed) {
            $this->assertFalse(SlugGenerator::isReserved($allowed), sprintf('"%s" is an ordinary permalink.', $allowed));
        }

        $this->assertFalse(SlugGenerator::isReserved(''));

        foreach (['web-development', 'a1', 'x'] as $valid) {
            $this->assertSame(1, preg_match(SlugGenerator::PATTERN, $valid), $valid);
        }

        foreach (['Web-Development', 'web--development', '-web', 'web-', 'web_development', 'web development', ''] as $invalid) {
            $this->assertSame(0, preg_match(SlugGenerator::PATTERN, $invalid), sprintf('"%s" is not a valid hand-entered slug.', $invalid));
        }
    }

    #[Test]
    public function the_blog_transition_map_is_blog_policy(): void
    {
        /** @var BlogService $blog */
        $blog = (new ReflectionClass(BlogService::class))->newInstanceWithoutConstructor();

        $expected = [
            'draft' => ['scheduled', 'published'],
            'scheduled' => ['draft', 'published'],
            'published' => ['draft', 'archived'],
            'archived' => ['draft'],
        ];

        $this->assertSame(array_keys($expected), array_map(static fn (ContentStatus $case): string => $case->value, ContentStatus::cases()), 'ContentStatus has exactly the four phase-03 cases.');

        foreach (ContentStatus::cases() as $from) {
            $this->assertEqualsCanonicalizing(
                $expected[$from->value],
                array_map(static fn (ContentStatus $to): string => $to->value, $blog->allowedNext($from)),
                sprintf('allowedNext(%s).', $from->value),
            );
        }
    }
}
