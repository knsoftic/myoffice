<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\DataObjects\Crm\ImportOptions;
use App\Http\Requests\Crm\Concerns\ValidatesImportOptions;
use App\Models\Crm\LeadImport;
use App\Services\Crm\LeadImportService;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Save the column map and options of a staged import — `admin.leads.import.mapping`, `can:leads.import`
 * (phase-05 §6.6, §8.6 steps 2 and 3).
 *
 * `column_map` is `csv header => lead field` (blank = "ignored"). The wizard posts it by column **position**
 * (`column_map[0] = name`); positions are translated to the staged file's own header names before validation, so the
 * service always receives the header-keyed map it stores. A target must be one of `LeadImportService::TARGET_FIELDS`,
 * `name` must be mapped, and no field may be mapped twice. The wizard's `defaults[source|assigned_to|status]` are
 * accepted as `default_source` / `default_assigned_to` / `default_status`. The import must be one the actor may see.
 */
final class UpdateLeadImportMappingRequest extends CrmFormRequest
{
    use ValidatesImportOptions;

    public function authorize(): bool
    {
        $import = $this->boundModel('import', LeadImport::class);

        return $import instanceof LeadImport && $this->actorCan('leads.import') && $this->actorCan('view', $import);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'column_map' => ['required', 'array', 'min:1', 'max:200'],
            'column_map.*' => ['nullable', 'string', 'in:'.implode(',', self::targets())],
            'defaults' => ['nullable', 'array:source,assigned_to,status'],
        ], $this->importOptionRules());
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $map = $this->input('column_map');

                if (! is_array($map)) {
                    return;
                }

                $seen = [];

                foreach ($map as $header => $field) {
                    if (mb_strlen((string) $header) > 255) {
                        $validator->errors()->add('column_map', 'A column header is longer than 255 characters.');

                        return;
                    }

                    if (! is_string($field) || $field === '') {
                        continue;
                    }

                    if (isset($seen[$field])) {
                        $validator->errors()->add('column_map', sprintf('"%s" is mapped from more than one column.', $field));

                        return;
                    }

                    $seen[$field] = true;
                }

                if (! isset($seen['name'])) {
                    $validator->errors()->add('column_map', 'Map a column to the lead name.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $defaults = $this->input('defaults');

        if (is_array($defaults)) {
            foreach (['source' => 'default_source', 'assigned_to' => 'default_assigned_to', 'status' => 'default_status'] as $from => $to) {
                if (! $this->has($to) && array_key_exists($from, $defaults)) {
                    $this->merge([$to => $defaults[$from]]);
                }
            }
        }

        $this->trimStrings($this->importOptionNames());
        $this->translatePositions();
    }

    /**
     * Every lead field a CSV column may populate — the service's list, declared once.
     *
     * @return list<string>
     */
    public static function targets(): array
    {
        return array_keys(LeadImportService::TARGET_FIELDS);
    }

    public function toOptions(): ImportOptions
    {
        $data = $this->validated();
        unset($data['defaults']);

        return ImportOptions::fromArray($this->resolvedImportOptions($data));
    }

    /**
     * `column_map[<position>]` → `column_map[<header>]`, using the staged file's header row. A map already keyed by
     * header (or a file that cannot be read) is left as posted, and the service validates the names.
     */
    private function translatePositions(): void
    {
        $map = $this->input('column_map');
        $import = $this->boundModel('import', LeadImport::class);

        if (! is_array($map) || $map === [] || ! $import instanceof LeadImport) {
            return;
        }

        foreach (array_keys($map) as $key) {
            if (! is_int($key) && ! (is_string($key) && ctype_digit($key))) {
                return;
            }
        }

        try {
            $headers = app(LeadImportService::class)->headers($import);
        } catch (Throwable) {
            return;
        }

        $translated = [];

        foreach ($map as $position => $field) {
            $header = $headers[(int) $position] ?? null;

            if ($header === null) {
                return;
            }

            $translated[$header] = $field;
        }

        $this->merge(['column_map' => $translated]);
    }
}
