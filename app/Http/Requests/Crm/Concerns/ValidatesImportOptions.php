<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm\Concerns;

use App\Enums\InquirySource;
use App\Enums\LeadImportDuplicateStrategy;
use App\Enums\LeadStatus;
use App\Http\Requests\Crm\CrmFormRequest;
use Illuminate\Validation\Rule;

/**
 * The option half of the import wizard (phase-05 §6.6, §8.6 steps 1 and 3) — the `ImportOptions` keys:
 * `delimiter`, `encoding`, `default_source`, `default_assigned_to`, `default_status`, `duplicate_strategy`,
 * `stop_after_errors`.
 *
 * @mixin CrmFormRequest
 */
trait ValidatesImportOptions
{
    /** The delimiters the sniffer may be overridden with (`tab` is a TAB character, `pipe` is `|`; `auto` sniffs). */
    public const DELIMITERS = [',', ';', '|', 'tab', 'pipe', 'auto'];

    /** `auto` sniffs the encoding. */
    public const ENCODINGS = ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1', 'auto'];

    /**
     * @return array<string, list<mixed>>
     */
    protected function importOptionRules(): array
    {
        return [
            'delimiter' => ['nullable', 'string', Rule::in(self::DELIMITERS)],
            'encoding' => ['nullable', 'string', Rule::in(self::ENCODINGS)],
            'default_source' => ['nullable', 'string', Rule::enum(InquirySource::class)],
            'default_assigned_to' => [
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('leads.view', 'Choose an active user who can work on leads.'),
            ],
            // An import creates open work: a default status may not pre-close it.
            'default_status' => ['nullable', 'string', Rule::enum(LeadStatus::class)->except([LeadStatus::Won, LeadStatus::Lost])],
            'duplicate_strategy' => ['nullable', 'string', Rule::enum(LeadImportDuplicateStrategy::class)],
            'stop_after_errors' => ['nullable', 'integer', 'min:0', 'max:'.$this->crmInt('import_max_rows', 5000)],
        ];
    }

    /**
     * @return list<string>
     */
    protected function importOptionNames(): array
    {
        return ['delimiter', 'encoding', 'default_source', 'default_assigned_to', 'default_status', 'duplicate_strategy', 'stop_after_errors'];
    }

    /**
     * The validated options with the wizard's words resolved: `auto` means "let the service sniff" (null) and `pipe`
     * is the `|` character.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolvedImportOptions(array $data): array
    {
        if (($data['delimiter'] ?? null) === 'auto') {
            $data['delimiter'] = null;
        } elseif (($data['delimiter'] ?? null) === 'pipe') {
            $data['delimiter'] = '|';
        }

        if (($data['encoding'] ?? null) === 'auto') {
            $data['encoding'] = null;
        }

        return $data;
    }
}
