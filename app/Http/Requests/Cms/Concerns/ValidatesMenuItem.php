<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\MenuItemLinkType;
use App\Enums\Cms\MenuVisibility;
use App\Models\Cms\Menu;
use App\Models\Cms\MenuItem;
use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One menu link (`menu_items`, phase-03 §2.6, §6.3, §102).
 *
 * The link is **typed** so an internal link is never a hardcoded string: exactly one target column is
 * required for the chosen `link_type`, and the others are cleared. URLs are scheme-allowlisted
 * (`http`, `https`, `mailto`, `tel` or a site path — never `javascript:`, FT-29); a route must exist
 * (`Route::has()`); a parent must belong to the same menu and be top level. Depth and cycles are
 * `MenuService`'s to refuse (INV-6, FT-19, FT-20) — it holds the lock.
 *
 * The using class must extend `CmsFormRequest` and implement `menu()` and `currentItem()`.
 */
trait ValidatesMenuItem
{
    /** Scheme allowlist for a `url` link — a leading `/` but never a protocol-relative `//host`. */
    private const URL_PATTERN = '/^(https?:\/\/|mailto:|tel:|\/(?![\/\\\\]))/i';

    abstract public function menu(): ?Menu;

    abstract public function currentItem(): ?MenuItem;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function menuItemRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $menuId = $this->menu()?->getKey();
        $type = MenuItemLinkType::tryFrom(is_string($this->input('link_type')) ? $this->input('link_type') : '')
            ?? $this->currentItem()?->link_type;

        $targetRule = static fn (MenuItemLinkType $wanted): array => $type === $wanted
            ? ['bail', 'required']
            : ['bail', 'nullable'];

        return [
            'label' => array_merge($required, ['bail', 'string', 'max:100']),
            'link_type' => array_merge($required, ['bail', 'string', Rule::enum(MenuItemLinkType::class)]),

            'page_id' => array_merge($targetRule(MenuItemLinkType::Page), [
                'integer', 'min:1', Rule::exists('pages', 'id')->whereNull('deleted_at'),
            ]),
            'route_name' => array_merge($targetRule(MenuItemLinkType::Route), [
                'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-]+$/', $this->routeExistsRule(),
            ]),
            'route_params' => ['bail', 'nullable', 'array', 'max:10'],
            'route_params.*' => ['bail', 'nullable', 'string', 'max:100'],
            'url' => array_merge($targetRule(MenuItemLinkType::Url), [
                'string', 'max:500', 'regex:'.self::URL_PATTERN, $this->safeHrefRule(),
            ]),
            'anchor' => array_merge($targetRule(MenuItemLinkType::SectionAnchor), [
                'string', 'max:65', 'regex:/^#?[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/',
            ]),

            'parent_id' => ['bail', 'nullable', 'integer', 'min:1', Rule::exists('menu_items', 'id')
                ->where('menu_id', $menuId ?? 0)
                ->whereNull('parent_id')
                ->whereNull('deleted_at'),
            ],
            'icon' => ['bail', 'nullable', 'string', 'max:64', $this->iconRule()],
            'open_new_tab' => ['sometimes', 'boolean'],
            'rel_nofollow' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'bail', 'required', 'string', Rule::enum(MenuVisibility::class)],
            'is_enabled' => ['sometimes', 'boolean'],

            // Denormalised and system-owned: never posted.
            'depth' => ['prohibited'],
            'menu_id' => ['prohibited'],
            'sort_order' => ['prohibited'],
            'linkable_type' => ['prohibited'],
            'linkable_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function menuItemMessages(): array
    {
        return [
            'url.regex' => 'Use https://, mailto:, tel: or a path starting with /.',
            'parent_id.exists' => 'The parent must be a top-level item of this menu.',
            'page_id.required' => 'Choose the page this item links to.',
            'route_name.required' => 'Choose the route this item links to.',
            'url.required' => 'Enter the address this item links to.',
            'anchor.required' => 'Choose the section this item scrolls to.',
        ];
    }

    protected function menuItemAfter(Validator $validator): void
    {
        $item = $this->currentItem();
        $parent = $this->input('parent_id');

        if ($item !== null && is_numeric($parent) && (int) $parent === (int) $item->getKey()) {
            $validator->errors()->add('parent_id', 'An item cannot be its own parent.');
        }
    }

    /**
     * The payload for `MenuService`: typed, with booleans cast and the unused target columns cleared
     * so a link never carries two targets.
     *
     * @return array<string, mixed>
     */
    public function menuItemPayload(): array
    {
        $data = $this->validated();

        foreach (['open_new_tab', 'rel_nofollow', 'is_enabled'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->boolean($flag);
            }
        }

        if (isset($data['anchor']) && is_string($data['anchor'])) {
            $data['anchor'] = ltrim($data['anchor'], '#');
        }

        $type = MenuItemLinkType::tryFrom((string) ($data['link_type'] ?? ''));

        if ($type !== null) {
            $targets = ['page_id', 'route_name', 'url', 'anchor'];
            $keep = $type->targetColumn();

            foreach ($targets as $column) {
                if ($column !== $keep) {
                    $data[$column] = null;
                }
            }

            if ($type !== MenuItemLinkType::Route) {
                $data['route_params'] = null;
            }
        }

        return $data;
    }

    private function routeExistsRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ! Route::has($value)) {
                $fail('That route does not exist.');
            }
        };
    }
}
