<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\CtaVariant;
use App\Models\Cms\CtaBlock;
use Illuminate\Validation\Rule;

/**
 * A reusable call-to-action block (`cta_blocks`, phase-03 §2.8, §6.13 `CtaBlockService::save()`).
 *
 * Button URLs are scheme-allowlisted (§6.6); `description` is plain text (never rich text); the
 * background colour is a `#rrggbb` hex. "Background image or colour, the later-set one wins" and "the
 * key is immutable once referenced" are the service's rules. `status` is not writable here: a CTA goes
 * live through the toggle route (`website_cta_blocks.change_status`), and `usage_count` is a cache.
 *
 * The using class must extend `CmsFormRequest` and implement `ctaBlock()`.
 */
trait ValidatesCtaBlock
{
    abstract public function ctaBlock(): ?CtaBlock;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function ctaRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $unique = Rule::unique('cta_blocks', 'key');

        if ($this->ctaBlock() !== null) {
            $unique = $unique->ignore($this->ctaBlock()->getKey());
        }

        $rules = [
            // Unique against trashed rows too: `uq_cta_key` is a plain unique index.
            'key' => array_merge($required, ['bail', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $unique]),
            'name' => array_merge($required, ['bail', 'string', 'max:150']),
            'variant' => array_merge($required, ['bail', 'string', Rule::enum(CtaVariant::class)]),
            'heading' => array_merge($required, ['bail', 'string', 'max:200']),
            'subheading' => ['sometimes', 'bail', 'nullable', 'string', 'max:300'],
            'description' => ['sometimes', 'bail', 'nullable', 'string', 'max:5000'],
            'background_media_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('media_assets', 'id')->whereNull('deleted_at')],
            'background_color' => ['sometimes', 'bail', 'nullable', 'string', 'regex:/^#[0-9a-f]{6}$/i'],

            'status' => ['prohibited'],
            'usage_count' => ['prohibited'],
        ];

        foreach (['primary', 'secondary'] as $button) {
            $rules[$button.'_label'] = ['sometimes', 'bail', 'nullable', 'string', 'max:60'];
            $rules[$button.'_url'] = ['sometimes', 'bail', 'nullable', 'string', 'max:500', $this->safeHrefRule()];
            $rules[$button.'_style'] = ['sometimes', 'bail', 'nullable', 'string', Rule::enum(ButtonStyle::class)];
            $rules[$button.'_new_tab'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function ctaMessages(): array
    {
        return [
            'key.regex' => 'Use lowercase letters, digits and underscores (for example free_consultation).',
            'key.unique' => 'Another CTA block (possibly one in the trash) already uses this key.',
            'background_color.regex' => 'Use a hex colour such as #1d4ed8.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ctaPayload(): array
    {
        $data = $this->validated();

        foreach (['primary_new_tab', 'secondary_new_tab'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->boolean($flag);
            }
        }

        return $data;
    }

    protected function prepareCta(): void
    {
        $this->trimStrings([
            'key', 'name', 'heading', 'subheading', 'description', 'background_color',
            'primary_label', 'primary_url', 'secondary_label', 'secondary_url',
        ]);
    }
}
