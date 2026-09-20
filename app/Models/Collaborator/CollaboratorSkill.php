<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Models\Concerns\Blameable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One skill a collaborator claims (phase-08-09 §2.2, requirement §34).
 *
 * A **child list, not a shared taxonomy** ([D-P8-3]). Phases 7, 13 and 16 all carry skills of their own;
 * a cross-domain `skills` table invented here would have collided with whatever Phase 7 built first. If
 * one ever appears, this table migrates into it additively.
 *
 * `slug` is what the filter matches on, so "React", "react" and " React " are one skill when somebody
 * asks who can do React.
 *
 * **No `deleted_at`** (D19): a removed skill has no audit or financial value, and a soft-deleted row
 * would collide with `uq_cskill` the moment the same skill was added back.
 */
class CollaboratorSkill extends Model
{
    use Blameable;

    protected $table = 'collaborator_skills';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collaborator_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The slug is derived, never posted: two rows whose name differs only in case are one skill, and
        // the unique index is what makes the "replace the set" sync idempotent.
        static::saving(static function (CollaboratorSkill $skill): void {
            $skill->slug = Str::slug((string) $skill->name);
        });
    }

    public function moduleSlug(): string
    {
        return 'collaborators';
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }
}
