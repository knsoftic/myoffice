{{--
    The project form, shared by create and edit (phase-06 §8.2).

    `project_value` appears on **create only**: §6.1 records the opening value as revision 1, and every
    later change goes through the value tab, which demands a reason (INV-P1). An edit form with a value
    box would be a way to move money quietly.
--}}
@php
    $project = $project ?? null;
    $value = static fn (string $key, $fallback = null) => old($key, $project?->{$key} ?? $fallback);
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <x-ui.form.input name="name" label="Project name" :value="$value('name')" required maxlength="200" />
    </div>

    <x-ui.form.select name="client_id" label="Client" :options="$clients" :selected="$value('client_id')" placeholder="Choose a client" required />
    <x-ui.form.select name="project_manager_id" label="Project manager" :options="$managers" :selected="$value('project_manager_id')" placeholder="Unassigned" />

    <x-ui.form.select name="project_type" label="Engagement type" :options="$types" :selected="$value('project_type', 'fixed_price')" required />
    <x-ui.form.select name="priority" label="Priority" :options="$priorities" :selected="$value('priority', 'medium')" required />

    <x-ui.form.input type="date" name="start_date" label="Start date" :value="$project?->start_date?->toDateString() ?? old('start_date')" />
    <x-ui.form.input type="date" name="deadline" label="Deadline" :value="$project?->deadline?->toDateString() ?? old('deadline')" />

    @if ($canSetValue)
        <x-ui.form.input type="number" step="0.01" min="0" name="budget_amount" label="Internal budget" :value="$value('budget_amount', '0.00')" help="What the business allocates to deliver the work." />

        @if ($project === null)
            <x-ui.form.input type="number" step="0.01" min="0" name="project_value" label="Contract value" :value="old('project_value', '0.00')" help="Recorded as revision 1. Every later change needs a reason." />
        @endif
    @endif

    <x-ui.form.select name="progress_basis" label="Derive progress from" :options="$bases" :selected="$value('progress_basis', 'milestones')" help="Milestones, or the tasks directly on the project." />

    <div class="sm:col-span-2">
        <x-ui.form.textarea name="description" label="Description" :value="$value('description')" rows="4" maxlength="5000" />
    </div>
</div>
