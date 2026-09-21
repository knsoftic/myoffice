@extends('layouts.admin')

@section('title', 'Add inquiry')

@section('header')
    <x-ui.page-header title="Add inquiry"
                      subtitle="A walk-in, a phone call, or a card somebody left at the desk."
                      icon="question-mark-circle"
                      :back="route('admin.course-inquiries.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.course-inquiries.store') }}" class="space-y-4">
        @csrf

        <x-ui.card title="Who is asking">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.form.input name="name" label="Name" required :value="old('name')" />
                <x-ui.form.input name="phone" label="Phone" required :value="old('phone')"
                                 help="The only way anybody follows this up." />
                <x-ui.form.input name="whatsapp" label="WhatsApp" :value="old('whatsapp')" />
                <x-ui.form.input name="email" label="Email" type="email" :value="old('email')" />
                <x-ui.form.input name="city" label="City" :value="old('city')" />
                <x-ui.form.input name="education" label="Education" :value="old('education')"
                                 help="As they word it — this is not a dropdown on purpose." />
            </div>
        </x-ui.card>

        <x-ui.card title="What they are asking about">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.form.select name="course_id" label="Course" placeholder="Not sure yet">
                    @foreach ($courses as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('course_id') === (int) $id)>{{ $name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="preferred_timing" label="Preferred timing" placeholder="Not stated">
                    @foreach (\App\Enums\PreferredTiming::options() as $value => $label)
                        <option value="{{ $value }}" @selected(old('preferred_timing') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="preferred_delivery_mode" label="How they want to study" placeholder="Not stated">
                    @foreach (\App\Enums\DeliveryMode::options() as $value => $label)
                        <option value="{{ $value }}" @selected(old('preferred_delivery_mode') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="source" label="Where it came from" required
                                  help="The conversion report is counted by source.">
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}" @selected(old('source', 'walk_in') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="assigned_to" label="Assign to" placeholder="Share it out automatically"
                                  help="Left blank, it goes to whoever has the fewest open inquiries.">
                    @foreach ($assignees as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('assigned_to') === (int) $id)>{{ $name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="follow_up_date" label="Next follow-up" type="date" :value="old('follow_up_date')"
                                 help="Left blank, it is set from the institute default." />
            </div>

            <div class="mt-4 grid gap-4">
                <x-ui.form.textarea name="message" label="What they said" rows="3" :value="old('message')" />
                <x-ui.form.textarea name="notes" label="Internal notes" rows="2" :value="old('notes')" />
            </div>
        </x-ui.card>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Save inquiry</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.course-inquiries.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
