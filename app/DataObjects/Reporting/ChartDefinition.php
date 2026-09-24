<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Enums\ChartType;
use InvalidArgumentException;

/**
 * The optional chart rendered above a report's table (phase-19-23 §6.20 `chart()`).
 *
 * **The chart reads the same `ReportResult` the table does.** It names a label key and one or more
 * value keys, and both must be columns the report already returns — so a chart can never show a
 * figure the table does not, and a money column withheld from a viewer (INV-23-2) takes its series
 * with it rather than leaving a graph of numbers nobody is allowed to read.
 *
 * That last point is the reason {@see self::forColumns()} exists: the engine calls it with the keys
 * that survived the permission strip and gets back either a narrower chart or null.
 */
final readonly class ChartDefinition
{
    /**
     * @param  string  $labelKey  the row key plotted along the category axis
     * @param  array<string, string>  $series  row key => series label
     * @param  string|null  $secondaryKey  the series drawn on the right-hand axis (combo only)
     */
    public function __construct(
        public ChartType $type,
        public string $title,
        public string $labelKey,
        public array $series,
        public ?string $secondaryKey = null,
        public ?string $description = null,
    ) {
        if ($series === []) {
            throw new InvalidArgumentException(sprintf('Chart [%s] has no series to plot.', $title));
        }

        if ($type->isProportional() && count($series) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Chart [%s] is a %s and was given %d series. A pie with two series is not a chart '
                .'with two series, it is a chart that silently drops one.',
                $title,
                $type->value,
                count($series),
            ));
        }

        if ($secondaryKey !== null && ! $type->hasSecondaryAxis()) {
            throw new InvalidArgumentException(sprintf(
                'Chart [%s] names a secondary series but a %s has only one axis.',
                $title,
                $type->value,
            ));
        }

        if ($secondaryKey !== null && ! array_key_exists($secondaryKey, $series)) {
            throw new InvalidArgumentException(sprintf(
                'Chart [%s] puts [%s] on the right-hand axis, but that key is not one of its series.',
                $title,
                $secondaryKey,
            ));
        }
    }

    /**
     * This chart, narrowed to the columns a viewer actually got.
     *
     * Returns null when nothing is left to plot — a chart of no series is a heading over an empty
     * box, and the screen should render the table alone instead.
     *
     * @param  list<string>  $available  the row keys that survived the permission strip
     */
    public function forColumns(array $available): ?self
    {
        if (! in_array($this->labelKey, $available, true)) {
            return null;
        }

        $series = array_intersect_key($this->series, array_flip($available));

        if ($series === []) {
            return null;
        }

        $secondary = $this->secondaryKey !== null && array_key_exists($this->secondaryKey, $series)
            ? $this->secondaryKey
            : null;

        // A combo that lost its line is an ordinary bar chart, and saying so is more honest than
        // drawing an empty right-hand axis.
        $type = $this->type === ChartType::Combo && $secondary === null ? ChartType::Bar : $this->type;

        // A proportional chart that kept more than one series cannot narrow to itself; take the
        // first, which is the one the report listed first and therefore meant most.
        if ($type->isProportional() && count($series) > 1) {
            $series = array_slice($series, 0, 1, preserve_keys: true);
        }

        return new self($type, $this->title, $this->labelKey, $series, $secondary, $this->description);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_unique([$this->labelKey, ...array_keys($this->series)]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'primitive' => $this->type->primitive(),
            'title' => $this->title,
            'description' => $this->description,
            'label_key' => $this->labelKey,
            'series' => $this->series,
            'secondary_key' => $this->secondaryKey,
            'stacked' => $this->type->isStacked(),
        ];
    }
}
