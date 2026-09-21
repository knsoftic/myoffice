{{--
    The category fields, shared by the create modal and the edit screen.

    `sort_order` is deliberately absent: a new category is appended by the service and the order is
    changed by dragging, which posts the whole arrangement at once. A field here would be a second way
    to set the same thing, and the two would disagree the first time somebody used both.
--}}

@php $category = $category ?? null; @endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <x-ui.form.input name="name" label="Name" required maxlength="150"
                         :value="old('name', $category?->name)"
                         placeholder="Web Development" />
    </div>

    <x-ui.form.input name="slug" label="Web address" maxlength="170"
                     :value="old('slug', $category?->slug)"
                     prefix="/courses/category/"
                     help="Left blank it is made from the name. Changing it on a category with published courses takes a reason." />

    <x-ui.form.input name="icon" label="Icon" maxlength="64"
                     :value="old('icon', $category?->icon)"
                     placeholder="code-bracket"
                     help="A name from the icon set — not markup." />

    <div class="sm:col-span-2">
        <x-ui.form.textarea name="description" label="Description" rows="2" maxlength="500"
                            :value="old('description', $category?->description)" />
    </div>

    @if ($category !== null)
        <div class="sm:col-span-2">
            <x-ui.form.input name="slug_change_reason" label="Reason for a changed address" maxlength="255"
                             help="Only needed when the address moves and the category has published courses." />
        </div>
    @endif
</div>

<x-ui.section-heading title="Search engines" class="mt-6" />

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.form.input name="seo_title" label="SEO title" maxlength="180"
                     :value="old('seo_title', $category?->seo_title)" />
    <x-ui.form.input name="seo_description" label="SEO description" maxlength="500"
                     :value="old('seo_description', $category?->seo_description)" />
</div>
