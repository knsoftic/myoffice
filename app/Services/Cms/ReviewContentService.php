<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\Moderatable;
use App\Enums\ApprovalStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Enums\ContentSource;
use App\Enums\TestimonialType;
use App\Events\Cms\TestimonialSubmitted;
use App\Models\Cms\StudentReview;
use App\Models\Cms\Testimonial;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use App\Support\Cms\VideoUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Writing testimonials (§14) and student reviews (§91) — the half of phase-04 §6.5 that is not moderation.
 *
 * The contract names no class for it, yet three of its rules need a single home:
 *
 *   · `website.testimonial_auto_approve` (§5): a **staff-entered** (`admin` / `import`) record is created
 *     `approved` when the switch is on; a public-form or panel submission is **always** `pending`.
 *   · `TestimonialSubmitted` (§10.1) fires for every source that needs moderation.
 *   · "Review text edited" (§10.5): a change to the body is written as one `review_edited` entry with the
 *     old and new values of every changed field, so a moderator silently rewriting a testimonial is
 *     traceable. Editing never changes the moderation state.
 *
 * Plain-text body (tags stripped, max 2,000 characters), rating null or 1-5, photos through `MediaService`
 * (`general` / `Thumbnail`), video links YouTube/Vimeo only, IP captured only for non-admin sources, and
 * the deferred `client_id` / `student_id` / `course_id` links stored beside their snapshots (§2.1).
 */
final class ReviewContentService
{
    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeTestimonial(array $data, ?UploadedFile $photo = null, ContentSource $source = ContentSource::Admin, ?Request $request = null): Testimonial
    {
        /** @var Testimonial */
        return $this->store(new Testimonial, $data, $photo, $source, $request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTestimonial(Testimonial $testimonial, array $data, ?UploadedFile $photo = null): Testimonial
    {
        /** @var Testimonial */
        return $this->update($testimonial, $data, $photo);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeStudentReview(array $data, ?UploadedFile $photo = null, ContentSource $source = ContentSource::Admin, ?Request $request = null): StudentReview
    {
        /** @var StudentReview */
        return $this->store(new StudentReview, $data, $photo, $source, $request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStudentReview(StudentReview $review, array $data, ?UploadedFile $photo = null): StudentReview
    {
        /** @var StudentReview */
        return $this->update($review, $data, $photo);
    }

    public function delete(Moderatable&Model $record): void
    {
        $this->content->transaction(function () use ($record): void {
            $wasPublic = $record->isPubliclyVisible();

            $record->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache($record->moderationLabel().' deleted');
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(Testimonial|StudentReview $record, array $data, ?UploadedFile $photo, ContentSource $source, ?Request $request): Model
    {
        return $this->content->transaction(function () use ($record, $data, $photo, $source, $request): Model {
            $record->fill($this->attributes($record, $data, true));
            $record->setAttribute($this->photoColumn($record), $this->content->resolveMediaColumn(
                $data, $this->photoColumn($record), $photo, MediaCollection::General, ImageProfile::Thumbnail, $this->photoField($record), null, 'remove_'.$this->photoField($record),
            ));
            $record->setAttribute('source', $source);

            if (! array_key_exists('sort_order', $data)) {
                $record->setAttribute('sort_order', (int) $record->newQuery()->withTrashed()->max('sort_order') + 1);
            }

            if ($source !== ContentSource::Admin) {
                $record->setAttribute('submitted_by_user_id', $this->content->actorId());
                $record->setAttribute('ip_address', $request === null ? null : mb_substr((string) $request->ip(), 0, 45));
            }

            $autoApprove = ! $source->requiresModeration()
                && $this->content->bool($this->content->settings()->get('website.testimonial_auto_approve', false), false);

            $record->forceFill($autoApprove
                ? ['status' => ApprovalStatus::Approved, 'approved_by' => $this->content->actorId(), 'approved_at' => Carbon::now()]
                : ['status' => ApprovalStatus::Pending, 'approved_by' => null, 'approved_at' => null]);

            $record->save();

            $this->content->recountMediaAfterCommit([$record->getAttribute($this->photoColumn($record))]);

            if ($source->requiresModeration()) {
                event(new TestimonialSubmitted($record));
            }

            if ($autoApprove) {
                $this->content->flushPublicCache($record->moderationLabel().' added (auto-approved)');
            }

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(Testimonial|StudentReview $record, array $data, ?UploadedFile $photo): Model
    {
        return $this->content->transaction(function () use ($record, $data, $photo): Model {
            $column = $this->photoColumn($record);
            $oldPhoto = $record->getAttribute($column);
            $wasPublic = $record->isPubliclyVisible();
            $before = $record->only($this->auditedColumns($record));

            $record->fill($this->attributes($record, $data, false));
            $record->setAttribute($column, $this->content->resolveMediaColumn(
                $data, $column, $photo, MediaCollection::General, ImageProfile::Thumbnail, $this->photoField($record),
                $oldPhoto === null ? null : (int) $oldPhoto, 'remove_'.$this->photoField($record),
            ));

            if ($record->isDirty('review')) {
                // One named entry carrying every changed field — the body included — instead of a generic "updated".
                $this->content->quietly($record, static fn () => $record->save());

                $diff = $this->content->auditor()->diff($before, $record->only($this->auditedColumns($record)));

                $this->content->audit(
                    $record->moduleSlug(),
                    $record->moderationLabel().': review text edited',
                    $record,
                    $diff,
                    null,
                    'review_edited',
                );
            } else {
                $record->save();
            }

            $this->content->recountMediaAfterCommit([$oldPhoto, $record->getAttribute($column)]);

            if ($wasPublic) {
                $this->content->flushPublicCache($record->moderationLabel().' updated');
            }

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Testimonial|StudentReview $record, array $data, bool $creating): array
    {
        $attributes = [];
        $nameColumn = $record instanceof Testimonial ? 'author_name' : 'student_name';

        if ($creating || array_key_exists($nameColumn, $data)) {
            $name = $this->content->plain($data[$nameColumn] ?? null, 150);

            if ($name === null) {
                throw ContentRuleException::refuse($nameColumn, 'A name is required.');
            }

            $attributes[$nameColumn] = $name;
        }

        if ($creating || array_key_exists('review', $data)) {
            $review = $this->content->plain($data['review'] ?? null);

            if ($review === null) {
                throw ContentRuleException::refuse('review', 'The review text is required.');
            }

            if (mb_strlen($review) > Testimonial::MAX_REVIEW_LENGTH) {
                throw ContentRuleException::refuse('review', sprintf('The review may be at most %d characters.', Testimonial::MAX_REVIEW_LENGTH));
            }

            $attributes['review'] = $review;
        }

        if (array_key_exists('rating', $data)) {
            $rating = $data['rating'];

            if ($rating !== null && $rating !== '' && (! is_numeric($rating) || (int) $rating < 1 || (int) $rating > 5 || (string) (int) $rating !== trim((string) $rating))) {
                throw ContentRuleException::refuse('rating', 'The rating must be between 1 and 5.');
            }

            $attributes['rating'] = $this->content->int($rating);
        }

        $attributes['course_name'] = array_key_exists('course_name', $data)
            ? $this->content->plain($data['course_name'], 150)
            : $record->getAttribute('course_name');

        foreach (['student_id', 'course_id', 'client_id'] as $column) {
            if (array_key_exists($column, $data) && in_array($column, $record->getFillable(), true)) {
                $attributes[$column] = $this->content->id($data[$column]);
            }
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        if ($record instanceof Testimonial) {
            $type = $data['type'] ?? ($creating ? TestimonialType::Client : $record->getAttribute('type'));
            $type = $type instanceof TestimonialType ? $type : TestimonialType::tryFrom((string) $type);

            if ($type === null) {
                throw ContentRuleException::refuse('type', 'Choose client, student or other.');
            }

            $attributes['type'] = $type;

            foreach (['author_designation' => 150, 'author_company' => 150] as $column => $limit) {
                if (array_key_exists($column, $data)) {
                    $attributes[$column] = $this->content->plain($data[$column], $limit);
                }
            }

            if (array_key_exists('review_date', $data)) {
                $attributes['review_date'] = $this->date($data['review_date'], 'review_date');
            }

            $company = $attributes['author_company'] ?? $record->getAttribute('author_company');

            if ($type->requiresCompany() && trim((string) $company) === '') {
                throw ContentRuleException::refuse('author_company', 'A company is required for a client testimonial.');
            }

            if ($type->requiresCourse() && trim((string) $attributes['course_name']) === '') {
                throw ContentRuleException::refuse('course_name', 'A course is required for a student testimonial.');
            }
        } else {
            if (array_key_exists('video_url', $data)) {
                $url = $this->content->plain($data['video_url'], 255);

                if ($url !== null && ! VideoUrl::isAllowed($url)) {
                    throw ContentRuleException::videoHostNotAllowed();
                }

                $attributes['video_url'] = $url;
            }
        }

        return $attributes;
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::createFromFormat('!Y-m-d', (string) $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof Carbon) {
            throw ContentRuleException::refuse($field, 'Enter the date as YYYY-MM-DD.');
        }

        return $date->toDateString();
    }

    private function photoColumn(Model $record): string
    {
        return $record instanceof Testimonial ? 'author_photo_media_id' : 'student_photo_media_id';
    }

    /**
     * The upload field the editor posts for the photo (`StoreTestimonialRequest` / `StoreStudentReviewRequest`),
     * so a refused upload lands on the input the admin actually used.
     */
    private function photoField(Model $record): string
    {
        return $record instanceof Testimonial ? 'author_photo' : 'student_photo';
    }

    /**
     * @return list<string>
     */
    private function auditedColumns(Model $record): array
    {
        return array_values(array_diff([...$record->getFillable(), 'is_featured'], ['ip_address', 'submitted_by_user_id', 'source']));
    }
}
