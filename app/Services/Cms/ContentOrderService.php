<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Cms\BlogCategory;
use App\Models\Cms\JobOpening;
use App\Models\Cms\PortfolioCategory;
use App\Models\Cms\PortfolioItem;
use App\Models\Cms\Service;
use App\Models\Cms\ServiceCategory;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\TeamMember;
use App\Models\Cms\Technology;
use App\Models\Cms\Testimonial;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Drag-and-drop ordering for every phase-04 list that carries `sort_order` (phase-04 §6.2).
 *
 * Invariants: every id must belong to `$modelClass` (a live row) or the whole reorder is refused with a
 * 422 and nothing changes; the listed rows get `sort_order` 1..n in the given order, in one transaction and
 * one statement per 500 ids; the act writes **one** activity entry (not one per row) naming the ids.
 */
final class ContentOrderService
{
    /** @var list<class-string<Model>> */
    private const SORTABLE = [
        ServiceCategory::class,
        Service::class,
        Technology::class,
        PortfolioCategory::class,
        PortfolioItem::class,
        TeamMember::class,
        Testimonial::class,
        StudentReview::class,
        SuccessStory::class,
        BlogCategory::class,
        JobOpening::class,
    ];

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(string $modelClass, array $orderedIds): void
    {
        if (! in_array($modelClass, self::SORTABLE, true)) {
            throw new InvalidArgumentException(sprintf('[%s] cannot be reordered.', $modelClass));
        }

        $ids = [];

        foreach ($orderedIds as $id) {
            if (! is_numeric($id) || (int) $id < 1 || (string) (int) $id !== trim((string) $id)) {
                throw ContentRuleException::foreignIds();
            }

            $ids[] = (int) $id;
        }

        if ($ids === []) {
            return;
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw ContentRuleException::refuse('ids', 'Each entry may appear only once.');
        }

        /** @var Model $prototype */
        $prototype = new $modelClass;
        $table = $prototype->getTable();
        $module = method_exists($prototype, 'moduleSlug') ? (string) $prototype->moduleSlug() : $table;

        $this->content->transaction(function () use ($modelClass, $ids, $table, $module): void {
            $found = $modelClass::query()->whereIn('id', $ids)->lockForUpdate()->count();

            if ($found !== count($ids)) {
                throw ContentRuleException::foreignIds();
            }

            $connection = $this->content->connection();
            $position = 0;

            foreach (array_chunk($ids, 500) as $chunk) {
                $cases = [];
                $bindings = [];

                foreach ($chunk as $id) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = ++$position;
                }

                // One bound statement per chunk: `sort_order` = CASE id WHEN ? THEN ? … END. The table name
                // comes from the model, never from input.
                $connection->update(
                    sprintf(
                        'UPDATE `%s` SET `sort_order` = CASE `id` %s END WHERE `id` IN (%s)',
                        str_replace('`', '', $table),
                        implode(' ', $cases),
                        implode(',', array_fill(0, count($chunk), '?')),
                    ),
                    [...$bindings, ...$chunk],
                );
            }

            $label = Str::of(class_basename($modelClass))->headline()->lower()->plural(count($ids));

            $this->content->audit(
                $module,
                sprintf('Reordered %d %s', count($ids), $label),
                null,
                ['ids' => $ids, 'count' => count($ids), 'model' => $modelClass],
                null,
                'reordered',
            );

            $this->content->flushPublicCache(sprintf('Reordered %d %s', count($ids), $label));
        });
    }
}
