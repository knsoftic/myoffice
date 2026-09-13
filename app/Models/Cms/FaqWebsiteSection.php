<?php

declare(strict_types=1);

namespace App\Models\Cms;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One FAQ hand-picked into a FAQ section — the `faq_website_section` pivot (phase-03 §2.11).
 *
 * Primary key (`faq_id`, `website_section_id`); both sides `cascadeOnDelete`; timestamps only. Used
 * only when the section's `content.source = selected`; the `category` and `featured` sources leave it
 * empty. Folded into the parent section's snapshot at publish time (§2.15) — never read publicly.
 *
 * @property int $faq_id
 * @property int $website_section_id
 * @property int $sort_order
 */
class FaqWebsiteSection extends Pivot
{
    protected $table = 'faq_website_section';

    public $incrementing = false;

    public $timestamps = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'faq_id',
        'website_section_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'faq_id' => 'integer',
            'website_section_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Faq, $this>
     */
    public function faq(): BelongsTo
    {
        return $this->belongsTo(Faq::class, 'faq_id');
    }

    /**
     * @return BelongsTo<WebsiteSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(WebsiteSection::class, 'website_section_id');
    }
}
