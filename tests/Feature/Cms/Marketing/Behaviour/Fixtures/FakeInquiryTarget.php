<?php

declare(strict_types=1);

namespace Tests\Feature\Cms\Marketing\Behaviour\Fixtures;

use App\Contracts\Inquiry\InquiryTarget;
use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A stand-in for Phase 5's `CrmLeadInquiryTarget` and Phase 14-17's `CourseInquiryTarget`
 * (phase-04 §6.10.1, §6.10.3 — "its own tests register a fake target to prove the pipeline").
 *
 * It keeps the contract every real target must keep:
 *
 *   · `handle()` creates one record per inquiry and is idempotent on the inquiry id (a second call returns
 *     the same row, the way `uq_leads_inquiry` / `uq_ci_inquiry` make a real target idempotent);
 *   · `isAvailable()` follows a real module switch when a module slug is given (`leads` for the CRM target,
 *     `course_inquiries` for the institute one), so "module disabled" is proven through `modules`, not a flag.
 *
 * Two knobs let a test fabricate the failure modes of §6.10.2: `$throwMessage` makes `handle()` throw (step 7),
 * and `$sticky` makes it hand back one existing record for every inquiry — the race step 8's
 * `uq_contact_inquiry_routed_target` index exists to lose.
 *
 * Not named `*Test.php`, so PHPUnit does not try to run it.
 */
final class FakeInquiryTarget implements InquiryTarget
{
    /** How many times `handle()` was called — "no target attempt" is `0`. */
    public int $handled = 0;

    /** When set, `handle()` throws a RuntimeException with this message. */
    public ?string $throwMessage = null;

    /** When set, `handle()` returns this record for every inquiry. */
    public ?Model $sticky = null;

    public function __construct(
        private readonly string $targetKey = InquiryType::TARGET_CRM_LEAD,
        private readonly ?string $module = null,
    ) {}

    public function key(): string
    {
        return $this->targetKey;
    }

    public function label(): string
    {
        return $this->targetKey === InquiryType::TARGET_COURSE_INQUIRY ? 'Fake course inquiry' : 'Fake CRM lead';
    }

    public function isAvailable(): bool
    {
        return $this->module === null || Modules::enabled($this->module);
    }

    public function handles(InquiryType $type): bool
    {
        return $type->routingTarget() === $this->targetKey;
    }

    public function handle(ContactInquiry $inquiry): Model
    {
        $this->handled++;

        if ($this->throwMessage !== null) {
            throw new RuntimeException($this->throwMessage);
        }

        if ($this->sticky instanceof Model) {
            return $this->sticky;
        }

        $slug = self::slugFor($this->targetKey, (int) $inquiry->getKey());

        $existing = FakeRoutedRecord::query()->where('slug', $slug)->first();

        if ($existing instanceof FakeRoutedRecord) {
            return $existing;
        }

        // A course target carries the free-text course name onward (§6.10.4); a lead carries the visitor's name.
        $label = $this->targetKey === InquiryType::TARGET_COURSE_INQUIRY
            ? (string) ($inquiry->getAttribute('course_name') ?? '')
            : (string) $inquiry->getAttribute('name');

        return FakeRoutedRecord::query()->create([
            'name' => mb_substr($label === '' ? 'Fake record' : $label, 0, 100),
            'slug' => $slug,
            'is_active' => false,
        ]);
    }

    public static function slugFor(string $targetKey, int $inquiryId): string
    {
        return sprintf('fake-%s-inquiry-%d', str_replace('_', '-', $targetKey), $inquiryId);
    }

    /**
     * How many records this kind of target has created so far.
     */
    public static function recordCount(string $targetKey): int
    {
        return FakeRoutedRecord::query()->where('slug', 'like', sprintf('fake-%s-inquiry-%%', str_replace('_', '-', $targetKey)))->count();
    }
}
