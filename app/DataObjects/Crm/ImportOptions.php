<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\InquirySource;
use App\Enums\LeadImportDuplicateStrategy;
use App\Enums\LeadStatus;

/**
 * The options of a CSV lead import (phase-05 §2.5, §6.6, §8.6 steps 1-3).
 *
 * `delimiter` / `encoding` null means "sniff it"; `columnMap` is csv header => lead field; the three defaults
 * apply to every row that does not supply its own value; `stopAfterErrors` null means never stop early.
 */
final readonly class ImportOptions
{
    use ReadsInput;

    /**
     * @param  array<string, string>|null  $columnMap
     */
    public function __construct(
        public ?string $delimiter = null,
        public ?string $encoding = null,
        public ?array $columnMap = null,
        public ?InquirySource $defaultSource = null,
        public ?int $defaultAssignedTo = null,
        public ?LeadStatus $defaultStatus = null,
        public ?LeadImportDuplicateStrategy $duplicateStrategy = null,
        public ?int $stopAfterErrors = null,
    ) {}

    /**
     * Keys: `delimiter`, `encoding`, `column_map` (header => field), `default_source`, `default_assigned_to`,
     * `default_status`, `duplicate_strategy`, `stop_after_errors`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $map = null;

        if (is_array($data['column_map'] ?? null)) {
            $map = [];

            foreach ($data['column_map'] as $header => $field) {
                if (is_string($field) && $field !== '' && is_scalar($header)) {
                    $map[(string) $header] = $field;
                }
            }
        }

        $delimiter = $data['delimiter'] ?? null;
        $delimiter = is_string($delimiter) && $delimiter !== '' ? ($delimiter === '\t' || $delimiter === 'tab' ? "\t" : mb_substr($delimiter, 0, 1)) : null;
        $stop = self::int($data, 'stop_after_errors');

        return new self(
            delimiter: $delimiter,
            encoding: self::str($data, 'encoding', 16),
            columnMap: $map,
            defaultSource: self::enum($data, 'default_source', InquirySource::class),
            defaultAssignedTo: self::int($data, 'default_assigned_to'),
            defaultStatus: self::enum($data, 'default_status', LeadStatus::class),
            duplicateStrategy: self::enum($data, 'duplicate_strategy', LeadImportDuplicateStrategy::class),
            stopAfterErrors: $stop === null || $stop < 1 ? null : $stop,
        );
    }

    /**
     * The `lead_imports.defaults` JSON.
     *
     * @return array{source: string|null, assigned_to: int|null, status: string|null, stop_after_errors: int|null}
     */
    public function defaults(): array
    {
        return [
            'source' => $this->defaultSource?->value,
            'assigned_to' => $this->defaultAssignedTo,
            'status' => $this->defaultStatus?->value,
            'stop_after_errors' => $this->stopAfterErrors,
        ];
    }
}
