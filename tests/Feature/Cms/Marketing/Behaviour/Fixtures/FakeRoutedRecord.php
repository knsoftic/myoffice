<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The "working record" a fake inquiry target creates (phase-04 §6.10.3: Phase 4 ships no target, its tests
 * register a fake one to prove the pipeline).
 *
 * The `leads` and `course_inquiries` tables belong to later phases and DDL auto-commits on MariaDB (a test
 * cannot create a table inside the RefreshDatabase transaction), so the fake record borrows an existing,
 * behaviour-free table: `blog_tags` (name + unique slug). It is a plain model — no activity log, no
 * Blameable, no soft-delete scope — so a row it writes never shows up as a blog tag (`is_active = false`)
 * and never adds an audit entry of its own. `routed_type` stores this class name, exactly as a real target's
 * model class would be stored.
 *
 * Not named `*Test.php`, so PHPUnit does not try to run it.
 */
final class FakeRoutedRecord extends Model
{
    protected $table = 'blog_tags';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
