@extends('layouts.admin')

@section('title', 'Edit '.$admission->admission_number)

@section('header')
    <x-ui.page-header :title="'Edit ' . $admission->admission_number"
                      :subtitle="($admission->student?->name ?? '') . ' · ' . ($admission->course?->name ?? '')"
                      icon="user-plus"
                      :back="route('admin.admissions.show', $admission)" />
@endsection

@section('content')
    @php
        $currentCounselor = $admission->counselor_id === null ? null : (int) $admission->counselor_id;
        $selectedCounselor = (int) old('counselor_id', $currentCounselor);
        $selectedMode = old('delivery_mode', $admission->delivery_mode?->value);
        $selectedTiming = old('preferred_timing', $admission->preferred_timing?->value);
    @endphp

    <form method="POST" action="{{ route('admin.admissions.update', $admission) }}" class="space-y-4">
        @csrf
        @method('PUT')

        <x-ui.card title="Admission details"
                   subtitle="The fees have their own panel on the admission, and the stage moves only through its steps.">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.input name="admission_date" label="Admission date" type="date" required
                                 :value="old('admission_date', $admission->admission_date?->toDateString())" />

                <x-ui.form.select name="counselor_id" label="Counsellor" placeholder="Nobody">
                    @foreach ($counselors as $id => $name)
                        <option value="{{ $id }}" @selected($selectedCounselor === (int) $id)>{{ $name }}</option>
                    @endforeach
                    {{-- The counsellor on record may no longer hold admissions.create; keep them selectable. --}}
                    @if ($currentCounselor !== null && ! $counselors->has($currentCounselor) && $admission->counselor)
                        <option value="{{ $currentCounselor }}" @selected($selectedCounselor === $currentCounselor)>{{ $admission->counselor->name }}</option>
                    @endif
                </x-ui.form.select>

                <x-ui.form.select name="delivery_mode" label="Agreed mode" placeholder="As the course is taught">
                    @foreach ($modes as $value => $label)
                        <option value="{{ $value }}" @selected($selectedMode === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="preferred_timing" label="Preferred timing" placeholder="Not stated">
                    @foreach ($timings as $value => $label)
                        <option value="{{ $value }}" @selected($selectedTiming === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>
            </div>

            <div class="mt-3">
                <x-ui.form.textarea name="notes" label="Notes" rows="4" :value="old('notes', $admission->notes)" />
            </div>
        </x-ui.card>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save changes</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.admissions.show', $admission)">Cancel</x-ui.button>
        </div>
    </form>
@endsection
