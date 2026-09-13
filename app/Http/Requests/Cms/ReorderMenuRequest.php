<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use Illuminate\Validation\Validator;

/**
 * Rebuild a menu's two-level tree (`admin.website.menus.reorder`, `can:menus.edit`, §6.3 `reorder()`).
 *
 *   { "tree": [ { "id": 4, "children": [ { "id": 9 }, { "id": 11 } ] }, { "id": 5 } ] }
 *
 * The shape is fixed at two levels: a child carries no `children` of its own, so a grandchild is a 422
 * here before `MenuService` (and then CHECK `chk_mi_depth`) would refuse it (INV-6, FT-19). Every id may
 * appear once in the whole tree. That the flattened set equals the menu's current set is the service's
 * check, under its lock.
 */
final class ReorderMenuRequest extends CmsFormRequest
{
    private const MAX_NODES = 500;

    protected function permission(): string
    {
        return 'menus.edit';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'tree' => ['bail', 'required', 'array', 'min:1', 'max:'.self::MAX_NODES],
            'tree.*' => ['bail', 'required', 'array:id,children'],
            'tree.*.id' => ['bail', 'required', 'integer', 'min:1'],
            'tree.*.children' => ['bail', 'sometimes', 'nullable', 'array', 'max:'.self::MAX_NODES],
            'tree.*.children.*' => ['bail', 'required', 'array:id,children'],
            'tree.*.children.*.id' => ['bail', 'required', 'integer', 'min:1'],
            // The builder serialises every child with an empty `children`; a non-empty one is a third level.
            'tree.*.children.*.children' => ['bail', 'sometimes', 'nullable', 'array', 'max:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tree.*.children.*.children.max' => 'A menu is at most two levels deep: a child item cannot have children of its own.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ids = [];

                foreach ($this->tree() as $node) {
                    $ids[] = $node['id'];

                    foreach ($node['children'] as $child) {
                        $ids[] = $child['id'];
                    }
                }

                if (count($ids) > self::MAX_NODES) {
                    $validator->errors()->add('tree', sprintf('A menu holds at most %d items.', self::MAX_NODES));
                }

                if (count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add('tree', 'An item appears more than once in the new order.');
                }
            },
        ];
    }

    /**
     * The tree normalised to integers: `[['id' => 4, 'children' => [['id' => 9]]]]`.
     *
     * @return list<array{id: int, children: list<array{id: int}>}>
     */
    public function tree(): array
    {
        $tree = [];

        foreach ((array) $this->input('tree', []) as $node) {
            if (! is_array($node) || ! is_numeric($node['id'] ?? null)) {
                continue;
            }

            $children = [];

            foreach ((array) ($node['children'] ?? []) as $child) {
                if (is_array($child) && is_numeric($child['id'] ?? null)) {
                    $children[] = ['id' => (int) $child['id']];
                }
            }

            $tree[] = ['id' => (int) $node['id'], 'children' => $children];
        }

        return $tree;
    }
}
