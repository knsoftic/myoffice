<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * A teacher shown on the website without a slug gets one from their name.
 *
 * `chk_te_public_slug` refuses a public row with no slug, and the service used to check only after the
 * insert — so the operator got a raw constraint violation (a 500) instead of a saved teacher.
 */
final class PublicTeacherSlugTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_public_teacher_created_without_a_slug_gets_one_from_the_name(): void
    {
        $actor = $this->createSuperAdmin();

        $first = $this->teacher(['name' => 'Ayesha Khan', 'is_public' => true], $actor);
        $second = $this->teacher(['name' => 'Ayesha Khan', 'is_public' => true], $actor);

        $this->assertSame('ayesha-khan', $first->slug);
        $this->assertSame('ayesha-khan-2', $second->slug);
    }

    #[Test]
    public function a_teacher_made_public_later_gets_a_slug_and_a_chosen_slug_is_kept(): void
    {
        $actor = $this->createSuperAdmin();

        $teacher = $this->teacher(['name' => 'Bilal Ahmed'], $actor);
        $this->assertNull($teacher->slug);

        $teacher = $this->teacherService()->update($teacher, ['is_public' => true], $actor);
        $this->assertSame('bilal-ahmed', $teacher->slug);

        $chosen = $this->teacher(['name' => 'Sana Iqbal', 'is_public' => true, 'slug' => 'sana'], $actor);
        $this->assertSame('sana', $chosen->slug);
    }
}
