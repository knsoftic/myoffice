@extends('layouts.admin')

@section('title', 'New ticket')

{{--
    Raising a ticket from the desk — admin.tickets.create (phase-19-23 §8).

    Staff may file one *on behalf of* somebody: a client phones, and the agent records it under their
    name so it appears in their portal and the replies reach them. That is what `user_id` is for, and
    it is the one place in the system where a requester is chosen rather than derived.

    There is no client or student field. Those are derived from the requester's own profiles by the
    service, because a form that could set them could file a ticket *against* another company — and
    §9.4 reads exactly those columns to decide who may see it.
--}}

@section('header')
    <x-ui.page-header title="New ticket" subtitle="Raise one yourself, or record one somebody has phoned in." icon="lifebuoy">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.tickets.index')">Back to the queue</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.tickets.store') }}" class="space-y-4">
        @csrf

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="ticket_department_id" label="Desk" required>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected((int) old('ticket_department_id') === (int) $department->id)>
                            {{ $department->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="priority" label="Priority">
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority->value }}" @selected(old('priority') === $priority->value)>{{ $priority->label() }}</option>
                    @endforeach
                </x-ui.form.select>

                <div class="sm:col-span-2">
                    <x-ui.form.input name="subject" label="Subject" :value="old('subject')" required
                                     placeholder="What is the problem, in one line?" />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="description" label="Description" :value="old('description')" rows="8" required />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.input type="number" name="user_id" label="Raise on behalf of (user id)" :value="old('user_id')"
                                     help="Leave blank to raise it as yourself. Filled in, the ticket appears in that person's portal and the replies reach them." />
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2 dark:text-slate-300">
                    <input type="checkbox" name="is_private_to_creator" value="1" @checked(old('is_private_to_creator'))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                    Private to the person who raised it — colleagues at the same company will not see it
                </label>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.tickets.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="paper-airplane">Raise ticket</x-ui.button>
        </div>
    </form>
@endsection
