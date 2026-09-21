{{--
    The expense filter form, shared by the register and the approval queue.

    Expects: $route (the route name to submit to) · $statuses · $contexts · $categories · $range ·
             optionally $lockStatus (the queue is already narrowed to pending, so it hides the control
             rather than offering one that does nothing).
--}}

@php
    $lockStatus = $lockStatus ?? false;
@endphp

<x-ui.card class="mb-4">
    <form method="GET" action="{{ route($route) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.form.input type="date" name="from" label="From" :value="request('from', app_date($range->start(), 'Y-m-d'))" />
        <x-ui.form.input type="date" name="to" label="To" :value="request('to', app_date($range->end(), 'Y-m-d'))" />
        <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Number, title or supplier" />

        <x-ui.form.select name="category" label="Category" placeholder="Any category">
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.select name="context" label="Business" placeholder="Both businesses">
            @foreach ($contexts as $context)
                <option value="{{ $context->value }}" @selected(request('context') === $context->value)>{{ $context->label() }}</option>
            @endforeach
        </x-ui.form.select>

        @unless ($lockStatus)
            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>
        @endunless

        <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" name="mine" value="1" @checked(request()->boolean('mine'))
                   class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
            Only mine
        </label>

        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route($route)">Clear</x-ui.button>
        </div>
    </form>
</x-ui.card>
