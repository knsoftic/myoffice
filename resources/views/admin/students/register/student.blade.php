@extends('layouts.admin')

@section('title', 'Register a student — step 1')

@section('header')
    <x-ui.page-header title="Register a student"
                      subtitle="Step 1 of 2 — who they are, and the login they will use."
                      icon="user-plus"
                      :back="route('admin.students.index')" />
@endsection

@section('content')
    {{-- Two steps, and the first one commits. A browser closed between them leaves a real student
         with a working login rather than nothing. --}}
    <ol class="mb-4 flex items-center gap-3 text-sm">
        <li class="flex items-center gap-2 font-medium text-brand-600 dark:text-brand-400">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">1</span>
            Student information
        </li>
        <li aria-hidden="true" class="h-px w-8 bg-slate-200 dark:bg-slate-700"></li>
        <li class="flex items-center gap-2 text-slate-400">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-500 dark:bg-slate-700 dark:text-slate-400">2</span>
            Courses &amp; billing
        </li>
    </ol>

    <form method="POST" action="{{ route('admin.students.register.store') }}" class="space-y-4">
        @csrf

        <x-ui.card title="Student">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="name" label="Full name" required :value="old('name')" />
                <x-ui.form.input name="father_name" label="Father's name" :value="old('father_name')" />

                <x-ui.form.select name="gender" label="Gender" placeholder="Not stated">
                    @foreach (\App\Enums\Gender::options() as $value => $label)
                        <option value="{{ $value }}" @selected(old('gender') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="date_of_birth" label="Date of birth" type="date" :value="old('date_of_birth')" />
                <x-ui.form.input name="cnic" label="CNIC" :value="old('cnic')"
                                 help="Digits only — dashes are stripped on save." />
            </div>
        </x-ui.card>

        <x-ui.card title="Contact and login"
                   subtitle="The e-mail is the student's username on the portal.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="phone" label="Phone" required :value="old('phone')" />
                <x-ui.form.input name="whatsapp" label="WhatsApp" :value="old('whatsapp')" />
                <x-ui.form.input name="email" label="Email" type="email" required :value="old('email')"
                                 help="This is what they sign in with." />
                <x-ui.form.input name="city" label="City" :value="old('city')" />
            </div>

            <div class="mt-4">
                <x-ui.form.textarea name="address" label="Address" rows="2" :value="old('address')" />
            </div>

            <div class="mt-4 grid gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-2 dark:border-slate-700">
                <x-ui.form.input name="password" label="Password" type="password" required
                                 autocomplete="new-password" />
                <x-ui.form.input name="password_confirmation" label="Repeat the password" type="password" required
                                 autocomplete="new-password" />

                <p class="text-xs text-slate-500 sm:col-span-2">
                    {{--
                        Said plainly because it is true and because it is what makes an operator-typed
                        password defensible: the copy in their head stops being the password the first
                        time the student signs in.
                    --}}
                    Tell the student these details before they leave. <strong>No e-mail is sent</strong> —
                    you typed the password, so putting it in an inbox as well would add a copy and no
                    safety. They will be asked to choose their own the first time they sign in.
                </p>
            </div>
        </x-ui.card>

        <x-ui.card title="Additional information">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="guardian_name" label="Guardian" :value="old('guardian_name')" />
                <x-ui.form.input name="guardian_phone" label="Guardian's phone" :value="old('guardian_phone')" />
                <x-ui.form.input name="guardian_relation" label="Relation" :value="old('guardian_relation')" />
                <x-ui.form.input name="education" label="Education" :value="old('education')" />
                <x-ui.form.input name="institution_name" label="School or college" :value="old('institution_name')" />
                <x-ui.form.input name="joining_date" label="Joining date" type="date"
                                 :value="old('joining_date', now()->toDateString())" />
            </div>

            <div class="mt-4">
                <x-ui.form.textarea name="notes" label="Notes" rows="2" :value="old('notes')" />
            </div>
        </x-ui.card>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="arrow-right" icon-trailing="arrow-right">
                Next step — courses &amp; billing
            </x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.students.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
