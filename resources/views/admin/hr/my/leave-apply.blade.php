@extends('layouts.admin')

@section('title', 'Apply for leave')

@section('header')
    <x-ui.page-header title="Apply for leave" subtitle="Weekends and holidays inside the range are kept, marked not counted." icon="calendar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.my.leave.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.my.leave.store') }}" class="max-w-2xl space-y-4">
        @csrf

        <x-ui.card title="What you have">
            @if ($balances->isEmpty())
                <p class="text-sm text-slate-500 dark:text-slate-400">No quota granted yet — ask HR.</p>
            @else
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($types as $type)
                        @php $balance = $balances->get($type->id); @endphp
                        <li class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                            <span class="text-sm text-slate-700 dark:text-slate-200">{{ $type->name }}</span>
                            <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ $balance ? app_number((float) $balance->available_days, 2) : '0.00' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="The request">
            <div class="space-y-3">
                <x-ui.form.select name="leave_type_id" label="Leave type" required placeholder="Choose a type">
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}" @selected((int) old('leave_type_id') === $type->id)>{{ $type->name }}</option>
                    @endforeach
                </x-ui.form.select>
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.form.input type="date" name="from_date" label="From" required :value="old('from_date')" />
                    <x-ui.form.input type="date" name="to_date" label="To" required :value="old('to_date')" />
                </div>
                <x-ui.form.select name="day_portion" label="Portion" :options="$portions" :selected="old('day_portion', 'full_day')" required />
                <x-ui.form.textarea name="reason" label="Reason" rows="3" required :value="old('reason')"
                    help="Your approval chain and HR see this." />
                <x-ui.form.input name="contact_during_leave" label="Contact while away" maxlength="64" :value="old('contact_during_leave')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('admin.my.leave.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Send the request</x-ui.button>
        </div>
    </form>
@endsection
