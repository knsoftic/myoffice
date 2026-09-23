<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Enums\PrintTemplateType;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * The layout of a printable document (phase-19-23 §2.13, requirements §82, §84, §85).
 *
 * **[D-21-1] One table, three documents.** A `type` distinguishes a certificate from an ID card from a
 * result card, so there is one editor, one sanitiser and one token registry rather than three of each
 * drifting apart.
 *
 * **[D-21-2] `body_html` is never compiled.** It is HTML with `{tokens}`, sanitised through
 * `RichText::sanitize($html, 'material')` on save *and again* on render, then replaced by
 * `str_replace` over a fixed list from `PrintTokenRegistry` (INV-21-5). Storing Blade and rendering it
 * would hand remote code execution to anybody holding `print_templates.edit` — and the second
 * sanitise on render is what stops a row written straight into the database from reaching a page.
 *
 * **A template that printed something is deactivated, never deleted.** `restrictOnDelete` from
 * `certificates` and `student_id_cards` catches a hard delete; the hook below catches the **soft**
 * one, which is an UPDATE no foreign key ever sees. Both are needed, because `Gate::before` waves a
 * Super Admin past every policy — D124, learned in Phase 19 and applied here from the start. A
 * document has to stay re-printable byte-identically, and it cannot be if its layout has vanished.
 *
 * @property string $body_html
 */
class PrintTemplate extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'print_templates';

    /**
     * `is_default` is absent: making a template the default clears the previous one for that
     * (type, branch), which is a transaction rather than a field. `tokens_used` and `preview_path`
     * are derived by the service and never posted.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'code',
        'name',
        'description',
        'branch_id',
        'paper_size',
        'orientation',
        'width_mm',
        'height_mm',
        'margin_mm',
        'background_image_path',
        'logo_path',
        'body_html',
        'custom_css',
        'signatories',
        'show_qr',
        'qr_size_mm',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PrintTemplateType::class,
            'paper_size' => PaperSize::class,
            'orientation' => PageOrientation::class,
            'width_mm' => 'decimal:2',
            'height_mm' => 'decimal:2',
            'margin_mm' => 'decimal:2',
            'qr_size_mm' => 'decimal:2',
            'tokens_used' => 'array',
            'signatories' => 'array',
            'show_qr' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $template): void {
            // A hard delete is caught by restrictOnDelete; this is the soft one, which is an UPDATE
            // that no foreign key sees. See the class note — both are needed.
            if ($template->hasPrintedAnything()) {
                throw new LogicException(sprintf(
                    'Template %s has printed documents against it. Deactivate it instead — a '
                    .'certificate or card that was handed over has to stay re-printable exactly as '
                    .'it was issued.',
                    $template->getAttribute('code'),
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'print_templates';
    }

    /** Has this layout produced a document somebody is holding? */
    public function hasPrintedAnything(): bool
    {
        return $this->certificates()->exists() || $this->idCards()->exists();
    }

    /**
     * The page's dimensions in millimetres, already turned the right way up.
     *
     * The `PaperSize` cases give **portrait** figures, so every caller would otherwise have to
     * remember to swap them for a landscape template — and one that forgot would render a certificate
     * cropped down its long edge.
     *
     * @return array{0: float, 1: float}
     */
    public function dimensionsMm(): array
    {
        $size = $this->paper_size;

        $width = $size->needsExplicitDimensions()
            ? (float) $this->getAttribute('width_mm')
            : (float) $size->widthMm();

        $height = $size->needsExplicitDimensions()
            ? (float) $this->getAttribute('height_mm')
            : (float) $size->heightMm();

        return $this->orientation->orient($width, $height);
    }

    /**
     * What dompdf is given as its paper.
     *
     * A custom size and `cr80` both resolve to a points array, because dompdf has no name for either
     * and millimetres are not its unit.
     *
     * @return string|array{0: float, 1: float, 2: float, 3: float}
     */
    public function dompdfPaper(): string|array
    {
        if ($this->paper_size->needsExplicitDimensions()) {
            [$width, $height] = $this->dimensionsMm();

            return PaperSize::points($width, $height);
        }

        return $this->paper_size->dompdfPaper();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'print_template_id');
    }

    public function idCards(): HasMany
    {
        return $this->hasMany(StudentIdCard::class, 'print_template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, PrintTemplateType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Templates a branch may use: its own, plus every template that belongs to no branch in
     * particular. Null means "everywhere", not "nobody" — [D-IN-5], the same rule Phase 16 set for
     * teachers.
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(static function (Builder $q) use ($branchId): void {
            $q->whereNull('branch_id');

            if ($branchId !== null) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }
}
