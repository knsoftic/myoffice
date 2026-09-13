<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\FaqSource;
use App\Models\Cms\Faq;
use App\Models\Cms\FaqCategory;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Support\RichText;
use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * FAQs and FAQ categories (phase-03 §2.9-§2.11, §6.13 `FaqService`, §8.11).
 *
 * One public entry point per operation:
 *
 *   save()               create or update a question (the owning phase may also attach it, §90)
 *   toggle()             status only — draft / published / archived
 *   reorder()            the exact, contiguous order of one category or of the uncategorised bucket
 *   delete()             soft-delete a question
 *   saveCategory()       create or update a category (the rail's enable switch included)
 *   reorderCategories()  the exact, contiguous order of the rail
 *   deleteCategory()     soft-delete a category; its questions become uncategorised
 *   forSection()         the questions a `faq` section shows, per its `source`
 *
 * Invariants:
 *
 *   · **INV-13.** An answer is sanitised with `RichText::sanitize()` on write (and again on render).
 *   · **A question's category must be live and enabled** when it is chosen; null is allowed.
 *   · **`faqable_*` is written only for an allowlisted owner type** (§6.13 — today
 *     `App\Models\Institute\Course`); the CMS form never posts it.
 *   · **Categories are referenced by slug** (the `faq` section's `faq_category_ref`, handover decision
 *     2), so `forSection()` resolves by slug, and a slug still referenced by a live FAQ section cannot be
 *     changed out from under it.
 *   · **INV-5.** Reordering asserts the id list is exactly the current set and writes `sort_order` 10,
 *     20, 30 ... in one statement inside one transaction.
 *   · **INV-14 / INV-16.** Deletes are soft; every effective change is audited with old and new values
 *     and bumps the public cache after commit. A save that changes nothing writes nothing.
 */
final class FaqService
{
    /** @var list<string> */
    public const FAQ_WRITABLE = ['question', 'answer', 'faq_category_id', 'is_featured', 'status', 'faqable_type', 'faqable_id'];

    /** @var list<string> */
    public const CATEGORY_WRITABLE = ['name', 'slug', 'description', 'icon', 'is_enabled'];

    /**
     * §6.13: the only owner types a FAQ may be attached to. A later phase that needs another adds it here
     * and nowhere else (F-2.2 — no `course_faqs` table exists).
     *
     * @var list<string>
     */
    public const FAQABLE_TYPES = ['App\\Models\\Institute\\Course'];

    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** The snapshot's own ceiling for one FAQ section (`SnapshotBuilder::faqs()`). */
    public const SECTION_LIMIT = 100;

    private const FAQ_MODULE = 'faqs';

    private const CATEGORY_MODULE = 'faq_categories';

    /** Tables `writeOrder()` may address — never a caller-supplied name. */
    private const ORDERED_TABLES = ['faqs', 'faq_categories'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SectionValidator $validator,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Questions
    |--------------------------------------------------------------------------
    */

    /**
     * Create a question (`$faq` null) or update one. On update only the keys present change.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException
     */
    public function save(array $data, ?Faq $faq = null): Faq
    {
        $this->assertKnownKeys($data, self::FAQ_WRITABLE, 'question');

        $id = $this->connection()->transaction(function () use ($data, $faq): int {
            $row = $faq === null ? null : $this->lockFaq((int) $faq->getKey());

            if ($row !== null && $row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That question is in the trash.', ['faq' => ['Restore it before editing.']]);
            }

            $values = $this->cleanFaq($data, $row);
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            if ($row === null) {
                $values = array_merge([
                    'faq_category_id' => null,
                    'faqable_type' => null,
                    'faqable_id' => null,
                    'status' => ContentStatus::Draft->value,
                    'is_featured' => false,
                ], $values);

                $values['sort_order'] = $this->nextFaqSort($values['faq_category_id']);

                $id = (int) $this->connection()->table('faqs')->insertGetId(array_merge($values, [
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ]));

                $this->auditor->record(
                    module: self::FAQ_MODULE,
                    description: sprintf('Question created: %s', Str::limit((string) $values['question'], 120)),
                    subject: $this->findFaq($id),
                    properties: ['attributes' => array_merge($this->withoutAnswer($values), ['answer_length' => mb_strlen((string) $values['answer'])])],
                    event: 'created',
                );

                $this->cache->bumpAfterCommit(sprintf('Question #%d created', $id));

                return $id;
            }

            $changes = [];

            foreach ($values as $column => $value) {
                if ($this->comparable($row->{$column} ?? null) !== $this->comparable($value)) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                return (int) $row->id;
            }

            if (array_key_exists('faq_category_id', $changes)) {
                $changes['sort_order'] = $this->nextFaqSort($changes['faq_category_id']);
            }

            $this->connection()->table('faqs')->where('id', $row->id)->update(array_merge($changes, [
                'updated_at' => $now,
                'updated_by' => $actor,
            ]));

            $diff = $this->auditor->diff(
                $this->withoutAnswer(array_intersect_key((array) $row, $changes)),
                $this->withoutAnswer($changes)
            );

            if (array_key_exists('answer', $changes)) {
                $diff['old']['answer_length'] = mb_strlen((string) $row->answer);
                $diff['attributes']['answer_length'] = mb_strlen((string) $changes['answer']);
            }

            $this->auditor->record(
                module: self::FAQ_MODULE,
                description: sprintf('Question updated: %s', Str::limit((string) ($changes['question'] ?? $row->question), 120)),
                subject: $this->findFaq((int) $row->id),
                properties: $diff,
                event: 'updated',
            );

            $this->cache->bumpAfterCommit(sprintf('Question #%d updated', $row->id));

            return (int) $row->id;
        });

        return $this->findFaq($id);
    }

    /**
     * Change the status only (§6.13 `toggle()`). `scheduled` is refused: only pages schedule.
     */
    public function toggle(Faq $faq, ContentStatus $status): Faq
    {
        if ($status === ContentStatus::Scheduled) {
            throw InvalidSectionContentException::withErrors('Only pages can be scheduled.', [
                'status' => ['Choose draft, published or archived.'],
            ]);
        }

        $this->connection()->transaction(function () use ($faq, $status): void {
            $row = $this->lockFaq((int) $faq->getKey());

            if ($row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That question is in the trash.', ['faq' => ['Restore it first.']]);
            }

            if ((string) $row->status === $status->value) {
                return;
            }

            $this->connection()->table('faqs')->where('id', $row->id)->update([
                'status' => $status->value,
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->auditor->record(
                module: self::FAQ_MODULE,
                description: sprintf('Question %s: %s', mb_strtolower($status->label()), Str::limit((string) $row->question, 120)),
                subject: $faq,
                properties: ['old' => ['status' => (string) $row->status], 'attributes' => ['status' => $status->value]],
                event: 'status_changed',
            );

            $this->cache->bumpAfterCommit(sprintf('Question #%d status changed', $row->id));
        });

        return $this->findFaq((int) $faq->getKey());
    }

    /**
     * Reorder the live questions of one category, or of the uncategorised bucket when `$category` is
     * null (§6.13, INV-5).
     *
     * @param  array<int, int|string>  $orderedIds
     *
     * @throws InvalidSectionContentException when the list is not exactly the current set
     */
    public function reorder(?FaqCategory $category, array $orderedIds): void
    {
        $given = $this->ids($orderedIds);

        $this->connection()->transaction(function () use ($category, $given): void {
            $categoryId = null;

            if ($category !== null) {
                $categoryRow = $this->lockCategory((int) $category->getKey());

                if ($categoryRow->deleted_at !== null) {
                    throw InvalidSectionContentException::withErrors('That category is in the trash.', ['faq_category_id' => ['Restore it first.']]);
                }

                $categoryId = (int) $categoryRow->id;
            }

            $current = $this->connection()->table('faqs')
                ->where('faq_category_id', $categoryId)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $this->assertExactSet($current, $given);

            if ($current === $given) {
                return;
            }

            $this->writeOrder('faqs', $given);

            $this->auditor->record(
                module: self::FAQ_MODULE,
                description: $category === null ? 'Uncategorised questions reordered' : sprintf('Questions reordered: %s', $category->name),
                subject: $category,
                properties: ['old' => ['order' => $current], 'attributes' => ['order' => $given], 'faq_category_id' => $categoryId],
                event: 'reordered',
            );

            $this->cache->bumpAfterCommit('Questions reordered');
        });
    }

    /**
     * Soft-delete a question (INV-14). Its hand-picked places keep their pivot rows, so a restore is
     * lossless; the snapshot builder never shows a trashed question.
     */
    public function delete(Faq $faq): void
    {
        $this->connection()->transaction(function () use ($faq): void {
            $row = $this->lockFaq((int) $faq->getKey());

            if ($row->deleted_at !== null) {
                return;
            }

            $now = Carbon::now();

            $this->connection()->table('faqs')->where('id', $row->id)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->auditor->record(
                module: self::FAQ_MODULE,
                description: sprintf('Question deleted: %s', Str::limit((string) $row->question, 120)),
                subject: $faq,
                properties: ['old' => ['deleted_at' => null, 'status' => (string) $row->status], 'attributes' => ['deleted_at' => $now->toDateTimeString()]],
                event: 'deleted',
            );

            $this->cache->bumpAfterCommit(sprintf('Question #%d deleted', $row->id));
        });
    }

    /**
     * The questions a `faq` section shows (§6.13 `forSection()`): `category` — the published questions of
     * the enabled category named **by slug**; `selected` — the hand-picked pivot, in pivot order;
     * `featured` — published and featured. At most `SECTION_LIMIT`.
     *
     * @return Collection<int, Faq>
     */
    public function forSection(WebsiteSection $section): Collection
    {
        $content = $section->getAttribute('content');
        $content = is_array($content) ? $content : (json_decode((string) $content, true) ?: []);
        $source = FaqSource::tryFrom($this->scalar($content['source'] ?? null)) ?? FaqSource::Category;

        switch ($source) {
            case FaqSource::Category:
                $slug = trim($this->scalar($content['faq_category_ref'] ?? null));

                if ($slug === '') {
                    return new Collection;
                }

                return Faq::query()
                    ->published()
                    ->whereHas('category', static function ($query) use ($slug): void {
                        $query->where('slug', $slug)->where('is_enabled', true);
                    })
                    ->ordered()
                    ->limit(self::SECTION_LIMIT)
                    ->get()
                    ->toBase();

            case FaqSource::Selected:
                return Faq::query()
                    ->published()
                    ->select('faqs.*')
                    ->join('faq_website_section as fw', 'fw.faq_id', '=', 'faqs.id')
                    ->where('fw.website_section_id', $section->getKey())
                    ->orderBy('fw.sort_order')
                    ->orderBy('faqs.id')
                    ->limit(self::SECTION_LIMIT)
                    ->get()
                    ->toBase();

            case FaqSource::Featured:
            default:
                return Faq::query()
                    ->published()
                    ->featured()
                    ->ordered()
                    ->limit(self::SECTION_LIMIT)
                    ->get()
                    ->toBase();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    /**
     * Create a category (`$category` null) or update one. A blank slug on create is derived from the
     * name; an explicit slug that is taken is refused, never silently renamed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException
     * @throws ContentActionNotAllowedException for a slug change while a live FAQ section references it
     */
    public function saveCategory(array $data, ?FaqCategory $category = null): FaqCategory
    {
        $this->assertKnownKeys($data, self::CATEGORY_WRITABLE, 'category');

        $id = $this->connection()->transaction(function () use ($data, $category): int {
            $row = $category === null ? null : $this->lockCategory((int) $category->getKey());

            if ($row !== null && $row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That category is in the trash.', ['faq_category' => ['Restore it before editing.']]);
            }

            $values = $this->cleanCategory($data, $row === null);
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            if ($row === null) {
                $values['slug'] ??= $this->categorySlugFor((string) $values['name']);
                $this->assertCategorySlugAvailable((string) $values['slug'], null);

                $values = array_merge(['description' => null, 'icon' => null, 'is_enabled' => true], $values, [
                    'sort_order' => (int) $this->connection()->table('faq_categories')->max('sort_order') + 10,
                ]);

                try {
                    $id = (int) $this->connection()->table('faq_categories')->insertGetId(array_merge($values, [
                        'created_at' => $now,
                        'updated_at' => $now,
                        'created_by' => $actor,
                        'updated_by' => $actor,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    throw $this->categorySlugTaken();
                }

                $this->auditor->record(
                    module: self::CATEGORY_MODULE,
                    description: sprintf('FAQ category created: %s', $values['name']),
                    subject: $this->findCategory($id),
                    properties: ['attributes' => $values],
                    event: 'created',
                );

                $this->cache->bumpAfterCommit(sprintf('FAQ category #%d created', $id));

                return $id;
            }

            if (array_key_exists('slug', $values) && $values['slug'] === null) {
                unset($values['slug']); // a blank slug on update keeps the stored one
            }

            $changes = [];

            foreach ($values as $column => $value) {
                if ($this->comparable($row->{$column} ?? null) !== $this->comparable($value)) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                return (int) $row->id;
            }

            if (array_key_exists('slug', $changes)) {
                $this->assertCategorySlugAvailable((string) $changes['slug'], (int) $row->id);

                $references = $this->sectionsReferencingSlug((string) $row->slug);

                if ($references > 0) {
                    throw new ContentActionNotAllowedException(sprintf(
                        'The slug "%s" is used by %d FAQ %s. Choose another category there first, or keep the slug.',
                        $row->slug,
                        $references,
                        $references === 1 ? 'section' : 'sections'
                    ));
                }
            }

            try {
                $this->connection()->table('faq_categories')->where('id', $row->id)->update(array_merge($changes, [
                    'updated_at' => $now,
                    'updated_by' => $actor,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw $this->categorySlugTaken();
            }

            $this->auditor->record(
                module: self::CATEGORY_MODULE,
                description: sprintf('FAQ category updated: %s', $changes['name'] ?? $row->name),
                subject: $this->findCategory((int) $row->id),
                properties: $this->auditor->diff(array_intersect_key((array) $row, $changes), $changes),
                event: array_keys($changes) === ['is_enabled'] ? ((bool) $changes['is_enabled'] ? 'enabled' : 'disabled') : 'updated',
            );

            $this->cache->bumpAfterCommit(sprintf('FAQ category #%d updated', $row->id));

            return (int) $row->id;
        });

        return $this->findCategory($id);
    }

    /**
     * Reorder every live category (§6.13, INV-5).
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorderCategories(array $orderedIds): void
    {
        $given = $this->ids($orderedIds);

        $this->connection()->transaction(function () use ($given): void {
            $current = $this->connection()->table('faq_categories')
                ->whereNull('deleted_at')
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            $this->assertExactSet($current, $given);

            if ($current === $given) {
                return;
            }

            $this->writeOrder('faq_categories', $given);

            $this->auditor->record(
                module: self::CATEGORY_MODULE,
                description: 'FAQ categories reordered',
                properties: ['old' => ['order' => $current], 'attributes' => ['order' => $given]],
                event: 'reordered',
            );

            $this->cache->bumpAfterCommit('FAQ categories reordered');
        });
    }

    /**
     * Soft-delete a category. Its live questions are moved to the uncategorised bucket (appended in their
     * current order), never deleted with it.
     */
    public function deleteCategory(FaqCategory $category): void
    {
        $this->connection()->transaction(function () use ($category): void {
            $row = $this->lockCategory((int) $category->getKey());

            if ($row->deleted_at !== null) {
                return;
            }

            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            $moved = $this->connection()->table('faqs')
                ->where('faq_category_id', $row->id)->whereNull('deleted_at')
                ->orderBy('sort_order')->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            if ($moved !== []) {
                $sort = (int) $this->connection()->table('faqs')
                    ->whereNull('faq_category_id')->whereNull('deleted_at')->max('sort_order');

                foreach ($moved as $faqId) {
                    $this->connection()->table('faqs')->where('id', $faqId)->update([
                        'faq_category_id' => null,
                        'sort_order' => $sort += 10,
                        'updated_at' => $now,
                        'updated_by' => $actor,
                    ]);
                }
            }

            $this->connection()->table('faq_categories')->where('id', $row->id)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'updated_by' => $actor,
            ]);

            $this->auditor->record(
                module: self::CATEGORY_MODULE,
                description: sprintf('FAQ category deleted: %s', $row->name),
                subject: $category,
                properties: [
                    'old' => ['deleted_at' => null, 'slug' => (string) $row->slug],
                    'attributes' => ['deleted_at' => $now->toDateTimeString()],
                    'uncategorised_faqs' => $moved,
                ],
                event: 'deleted',
            );

            $this->cache->bumpAfterCommit(sprintf('FAQ category #%d deleted', $row->id));
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanFaq(array $data, ?object $row): array
    {
        $errors = [];
        $values = [];

        foreach (['question', 'answer'] as $required) {
            if ($row === null && ! array_key_exists($required, $data)) {
                $errors[$required][] = 'This field is required.';
            }
        }

        foreach ($data as $column => $value) {
            switch ($column) {
                case 'question':
                    $text = $this->text($value);

                    if ($text === null) {
                        $errors[$column][] = 'Enter the question.';
                    } elseif (mb_strlen($text) > 300) {
                        $errors[$column][] = 'At most 300 characters.';
                    } else {
                        $values[$column] = $text;
                    }

                    break;

                case 'answer':
                    if (! is_string($value) || mb_strlen($value) > 100_000) {
                        $errors[$column][] = 'Enter the answer (at most 100,000 characters).';

                        break;
                    }

                    $clean = trim(RichText::sanitize($value));

                    if ($clean === '') {
                        $errors[$column][] = 'Enter the answer.';
                    } else {
                        $values[$column] = $clean;
                    }

                    break;

                case 'faq_category_id':
                    if ($value === null || $value === '') {
                        $values[$column] = null;

                        break;
                    }

                    $categoryId = $this->positiveInt($value);

                    if ($categoryId === null) {
                        $errors[$column][] = 'Choose a category from the list.';

                        break;
                    }

                    if ($row !== null && (string) $row->faq_category_id === (string) $categoryId) {
                        $values[$column] = $categoryId; // unchanged: a since-disabled category does not block editing

                        break;
                    }

                    $enabled = $this->connection()->table('faq_categories')
                        ->where('id', $categoryId)->whereNull('deleted_at')->value('is_enabled');

                    if ($enabled === null) {
                        $errors[$column][] = 'That category no longer exists.';
                    } elseif (! (bool) $enabled) {
                        $errors[$column][] = 'That category is disabled. Enable it first, or choose another.';
                    } else {
                        $values[$column] = $categoryId;
                    }

                    break;

                case 'is_featured':
                    $flag = $this->bool($value);

                    if ($flag === null) {
                        $errors[$column][] = 'This must be true or false.';
                    } else {
                        $values[$column] = $flag;
                    }

                    break;

                case 'status':
                    $status = $value instanceof ContentStatus ? $value : ContentStatus::tryFrom($this->scalar($value));

                    if ($status === null || $status === ContentStatus::Scheduled) {
                        $errors[$column][] = 'Choose draft, published or archived.';
                    } else {
                        $values[$column] = $status->value;
                    }

                    break;
            }
        }

        if (array_key_exists('faqable_type', $data) || array_key_exists('faqable_id', $data)) {
            $type = $this->text($data['faqable_type'] ?? null);
            $ownerId = ($data['faqable_id'] ?? null) === null ? null : $this->positiveInt($data['faqable_id']);

            if ($type === null && ($data['faqable_id'] ?? null) === null) {
                $values['faqable_type'] = null;
                $values['faqable_id'] = null;
            } elseif ($type === null || $ownerId === null) {
                $errors['faqable_type'][] = 'An attached question needs both the owner type and the owner id.';
            } elseif (! in_array(Relation::getMorphedModel($type) ?? $type, self::FAQABLE_TYPES, true)) {
                $errors['faqable_type'][] = sprintf('A question cannot be attached to [%s].', $type);
            } else {
                $values['faqable_type'] = $type;
                $values['faqable_id'] = $ownerId;
            }
        }

        if ($errors !== []) {
            throw InvalidSectionContentException::withErrors('The question could not be saved.', $errors);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanCategory(array $data, bool $creating): array
    {
        $errors = [];
        $values = [];

        if ($creating && ! array_key_exists('name', $data)) {
            $errors['name'][] = 'This field is required.';
        }

        foreach ($data as $column => $value) {
            switch ($column) {
                case 'name':
                    $text = $this->text($value);

                    if ($text === null) {
                        $errors[$column][] = 'Enter a name.';
                    } elseif (mb_strlen($text) > 150) {
                        $errors[$column][] = 'At most 150 characters.';
                    } else {
                        $values[$column] = $text;
                    }

                    break;

                case 'slug':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'Use lowercase letters, digits and hyphens.';

                        break;
                    }

                    $slug = $this->text($value);

                    if ($slug !== null && (mb_strlen($slug) > 150 || preg_match(self::SLUG_PATTERN, $slug) !== 1)) {
                        $errors[$column][] = 'Use lowercase letters, digits and hyphens (at most 150 characters).';
                    } else {
                        $values[$column] = $slug;
                    }

                    break;

                case 'description':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'This must be text.';

                        break;
                    }

                    $text = $this->text($value);

                    if ($text !== null && mb_strlen($text) > 300) {
                        $errors[$column][] = 'At most 300 characters.';
                    } else {
                        $values[$column] = $text;
                    }

                    break;

                case 'icon':
                    $icon = is_string($value) || $value === null ? $this->text($value) : false;

                    if ($icon === false || ($icon !== null && ! $this->iconAllowed($icon))) {
                        $errors[$column][] = 'Choose an icon from the list.';
                    } else {
                        $values[$column] = $icon;
                    }

                    break;

                case 'is_enabled':
                    $flag = $this->bool($value);

                    if ($flag === null) {
                        $errors[$column][] = 'This must be true or false.';
                    } else {
                        $values[$column] = $flag;
                    }

                    break;
            }
        }

        if ($errors !== []) {
            throw InvalidSectionContentException::withErrors('The category could not be saved.', $errors);
        }

        return $values;
    }

    private function categorySlugFor(string $name): string
    {
        $base = trim(mb_substr(Str::slug($name), 0, 140), '-');

        if ($base === '' || preg_match(self::SLUG_PATTERN, $base) !== 1) {
            $base = 'faq-category';
        }

        $candidate = $base;

        for ($suffix = 2; $this->connection()->table('faq_categories')->where('slug', $candidate)->exists(); $suffix++) {
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    private function assertCategorySlugAvailable(string $slug, ?int $exceptId): void
    {
        $taken = $this->connection()->table('faq_categories')
            ->where('slug', $slug)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw $this->categorySlugTaken();
        }
    }

    private function categorySlugTaken(): InvalidSectionContentException
    {
        return InvalidSectionContentException::withErrors('That slug is already used.', [
            'slug' => ['Another category (possibly one in the trash) already uses this slug.'],
        ]);
    }

    /**
     * Live `faq` sections whose draft or live copy names this category slug.
     */
    private function sectionsReferencingSlug(string $slug): int
    {
        return $this->connection()->table('website_sections')
            ->where('section_key', 'faq')
            ->whereNull('deleted_at')
            ->where(static function ($query) use ($slug): void {
                $query->where('content->faq_category_ref', $slug)
                    ->orWhere('published_content->fields->faq_category_ref', $slug);
            })
            ->count();
    }

    private function nextFaqSort(?int $categoryId): int
    {
        return (int) $this->connection()->table('faqs')
            ->where('faq_category_id', $categoryId)
            ->whereNull('deleted_at')
            ->max('sort_order') + 10;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutAnswer(array $values): array
    {
        unset($values['answer']);

        return $values;
    }

    private function iconAllowed(string $icon): bool
    {
        if (mb_strlen($icon) > 64) {
            return false;
        }

        $icons = $this->validator->icons();

        return $icons === null
            ? preg_match(SectionValidator::ICON_PATTERN, $icon) === 1
            : in_array($icon, $icons, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $writable
     */
    private function assertKnownKeys(array $data, array $writable, string $noun): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), $writable));

        if ($unknown !== []) {
            throw InvalidSectionContentException::withErrors(
                sprintf('Unknown %s fields: %s.', $noun, implode(', ', $unknown)),
                [$noun => [sprintf('These fields cannot be written here: %s.', implode(', ', $unknown))]]
            );
        }
    }

    /**
     * @param  list<int>  $current
     * @param  list<int>  $given
     */
    private function assertExactSet(array $current, array $given): void
    {
        $sortedCurrent = $current;
        $sortedGiven = $given;
        sort($sortedCurrent);
        sort($sortedGiven);

        if ($sortedCurrent !== $sortedGiven) {
            throw InvalidSectionContentException::staleOrder($current, $given);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $clean = [];

        foreach (array_values($ids) as $id) {
            $int = $this->positiveInt($id);

            if ($int === null) {
                throw InvalidSectionContentException::staleOrder([], []);
            }

            $clean[] = $int;
        }

        if (count($clean) !== count(array_unique($clean))) {
            throw InvalidSectionContentException::staleOrder(array_values(array_unique($clean)), $clean);
        }

        return $clean;
    }

    /**
     * `sort_order` = 10, 20, 30 ... for the given ids, in one statement (INV-5).
     *
     * @param  list<int>  $orderedIds
     */
    private function writeOrder(string $table, array $orderedIds): void
    {
        if ($orderedIds === [] || ! in_array($table, self::ORDERED_TABLES, true)) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($orderedIds as $index => $id) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = ($index + 1) * 10;
        }

        $bindings[] = Carbon::now();
        $bindings[] = $this->auditor->actorId();

        $this->connection()->update(
            sprintf(
                'UPDATE `%s` SET `sort_order` = CASE `id` %s END, `updated_at` = ?, `updated_by` = ? WHERE `id` IN (%s)',
                $table,
                implode(' ', $cases),
                implode(', ', array_fill(0, count($orderedIds), '?'))
            ),
            array_merge($bindings, $orderedIds)
        );
    }

    private function lockFaq(int $id): object
    {
        $row = $this->connection()->table('faqs')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That question no longer exists.', ['faq' => ['It may have been deleted in another tab.']]);
        }

        return $row;
    }

    private function lockCategory(int $id): object
    {
        $row = $this->connection()->table('faq_categories')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That category no longer exists.', ['faq_category' => ['It may have been deleted in another tab.']]);
        }

        return $row;
    }

    private function findFaq(int $id): Faq
    {
        /** @var Faq */
        return Faq::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function findCategory(int $id): FaqCategory
    {
        /** @var FaqCategory */
        return FaqCategory::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace("\r\n", "\n", trim($value));

        return $value === '' ? null : $value;
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function comparable(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
