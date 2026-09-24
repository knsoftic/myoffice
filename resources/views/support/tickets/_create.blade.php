{{--
    Raising a ticket from a portal (phase-19-23 §6.16, §8).

    **Three fields, and no priority.** A requester who could declare "urgent" would, every time, and
    within a month the word would mean nothing (§12.2 Q6) — a portal ticket starts at the
    institute's default and staff move it if it needs moving.

    The desk list is the controller's, filtered on `allowed_panels` — the same column the service
    reads, so every option here is one the service will accept. A select offering a desk that then
    refuses the submission is a form failing for a reason the person cannot see.
--}}

@section('content')
    <form method="POST" action="{{ route($panel.'.tickets.store') }}" class="mx-auto max-w-2xl space-y-4">
        @csrf

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.form.select name="ticket_department_id" label="What is this about?" required>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected((int) old('ticket_department_id') === (int) $department->id)>
                            {{ $department->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                @foreach ($departments as $department)
                    @if ($department->description)
                        <p class="text-xs text-slate-400">{{ $department->name }} — {{ $department->description }}</p>
                    @endif
                @endforeach

                <x-ui.form.input name="subject" label="Subject" :value="old('subject')" required
                                 placeholder="In one line, what is wrong?" />

                <x-ui.form.textarea name="description" label="Tell us what happened" :value="old('description')"
                                    rows="8" required
                                    help="Anything you can add — what you were doing, what you expected, what happened instead." />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route($panel.'.tickets.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="paper-airplane">Send it</x-ui.button>
        </div>
    </form>
@endsection
