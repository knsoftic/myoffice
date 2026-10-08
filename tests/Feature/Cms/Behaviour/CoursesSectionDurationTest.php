<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Behaviour;

use App\Support\Cms\Sections\CoursesSectionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * The home page's course cards say how long a course takes in words a person would use.
 *
 * `CoursesSectionProvider` built the card's meta line as `sprintf('%d %s', $value, $unit->value)`, and
 * the enum's value is `months` whatever the number. The live home page therefore printed **"1 MONTHS"**
 * on three cards — Adobe Photoshop, Computer Basics, Freelancing Basics — in the section a prospective
 * student reads first. `Course::durationLabel()` already answered "1 month" / "4 months" through
 * `DurationUnit::labelFor()`, and every other screen asked it; this one section did its own formatting.
 *
 * Nothing caught it because nothing tested this provider at all.
 */
final class CoursesSectionDurationTest extends TestCase
{
    use BuildsCatalogue;
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
    }

    #[Test]
    public function a_one_month_course_says_one_month(): void
    {
        $this->publishedCourse(overrides: ['duration_value' => 1, 'duration_unit' => 'months']);

        $meta = $this->metaLines();

        $this->assertNotEmpty($meta, 'The section rendered no course cards, so this proves nothing.');
        $this->assertStringContainsString('1 month', $meta[0]);
        $this->assertStringNotContainsString(
            '1 months',
            $meta[0],
            'The card pluralises a single month — the raw enum value is being printed again.',
        );
    }

    #[Test]
    public function a_longer_course_is_still_plural(): void
    {
        $this->publishedCourse(overrides: ['duration_value' => 4, 'duration_unit' => 'months']);

        $meta = $this->metaLines();

        $this->assertNotEmpty($meta);
        $this->assertStringContainsString('4 months', $meta[0]);
    }

    /**
     * The `meta` line of every card the section would render.
     *
     * Through the provider's own `build()`, reached by reflection because it is protected and the public
     * `resolve()` needs a whole published `WebsiteSection` around it. What is under test is the line of
     * text, not the section plumbing — and the plumbing has tests of its own.
     *
     * @return list<string>
     */
    private function metaLines(): array
    {
        $build = new ReflectionMethod(CoursesSectionProvider::class, 'build');
        $build->setAccessible(true);

        $result = $build->invoke(app(CoursesSectionProvider::class), [
            'featured_only' => false,
            'category' => null,
            'limit' => 12,
        ]);

        $meta = [];

        array_walk_recursive($result, static function (mixed $value, mixed $key) use (&$meta): void {
            if ($key === 'meta' && is_string($value)) {
                $meta[] = $value;
            }
        });

        return $meta;
    }
}
