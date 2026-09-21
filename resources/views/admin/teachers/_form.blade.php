{{--
    The §72 teacher form, shared by create and edit.

    `teacher_code` and `status` are absent on purpose: the code is issued by the numbering service and
    the status moves through its own guarded transition, so a field for either would be a second
    writer for a fact that already has one.

    When an employee record is linked, name, email, phone and salary come from it and the fields are
    disabled here — the service discards them anyway, so the lock is a courtesy rather than the rule.
--}}

@php($locked = $teacher->exists && $teacher->isLinkedToEmployee())

@if ($locked)
    <x-ui.card class="mb-4 border-sky-200 dark:border-sky-500/30">
        <div class="flex items-start gap-3">
            <x-ui.icon name="link" class="mt-0.5 h-5 w-5 shrink-0 text-sky-500" />
            <p class="text-sm text-slate-600 dark:text-slate-300">
                This teacher is linked to an employee record, so their name, contact details and salary
                are kept there. Unlink them from the profile screen to edit those here.
            </p>
        </div>
    </x-ui.card>
@endif

<x-ui.card title="Who they are">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.input name="name" label="Name" required :value="old('name', $teacher->name)" :disabled="$locked" />

        <x-ui.form.select name="gender" label="Gender" placeholder="Not stated">
            @foreach ($genders as $value => $label)
                <option value="{{ $value }}" @selected(old('gender', $teacher->gender?->value) === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.select name="branch_id" label="Branch" placeholder="Every branch">
            @foreach ($branches as $id => $name)
                <option value="{{ $id }}" @selected((int) old('branch_id', $teacher->branch_id) === (int) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.input name="phone" label="Phone" :value="old('phone', $teacher->phone)" :disabled="$locked" />
        <x-ui.form.input name="whatsapp" label="WhatsApp" :value="old('whatsapp', $teacher->whatsapp)" />
        <x-ui.form.input name="email" label="Email" type="email" :value="old('email', $teacher->email)" :disabled="$locked"
                         help="Needed for a teacher login. Without one, there is simply no login." />
    </div>
</x-ui.card>

<x-ui.card title="What they teach" class="mt-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.input name="specialization" label="Subject" :value="old('specialization', $teacher->specialization)"
                         placeholder="Laravel, graphic design, spoken English…" />
        <x-ui.form.input name="qualification" label="Qualification" :value="old('qualification', $teacher->qualification)" />
        <x-ui.form.input name="experience_years" label="Years of experience" type="number" min="0" max="80"
                         :value="old('experience_years', $teacher->experience_years)" />
        <x-ui.form.input name="joining_date" label="Joined on" type="date"
                         :value="old('joining_date', $teacher->joining_date?->toDateString())" />

        @if ($canSeeSalary)
            <x-ui.form.input name="salary" label="Salary" type="number" step="0.01" min="0"
                             :value="old('salary', $teacher->salary)" :disabled="$locked"
                             help="Only visible to people who hold the teachers financial permission." />
        @endif

        <x-ui.form.input name="sort_order" label="Order" type="number" min="0"
                         :value="old('sort_order', $teacher->sort_order ?? 0)"
                         help="Lower comes first, on the site and in pickers." />
    </div>

    <div class="mt-4">
        <x-ui.form.textarea name="experience_note" label="Experience, in one line" rows="2"
                            :value="old('experience_note', $teacher->experience_note)" />
    </div>
</x-ui.card>

<x-ui.card title="Profile" class="mt-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.form.checkbox name="is_public" label="Show on the website"
                            :checked="old('is_public', $teacher->is_public)"
                            description="A public profile needs a slug — that is the address it lives at." />
        <x-ui.form.input name="slug" label="Slug" :value="old('slug', $teacher->slug)"
                         placeholder="ahmed-raza" help="Lower case, words joined by dashes." />
    </div>

    <div class="mt-4 grid gap-4">
        <x-ui.form.textarea name="public_bio" label="Public bio" rows="3" :value="old('public_bio', $teacher->public_bio)"
                            help="What the website and the student panel show." />
        <x-ui.form.textarea name="bio" label="Internal notes" rows="3" :value="old('bio', $teacher->bio)"
                            help="Staff only. Never rendered on the site or on a student's screen." />
    </div>
</x-ui.card>

@unless ($teacher->exists)
    <x-ui.card title="Access" class="mt-4">
        <x-ui.form.checkbox name="create_login" label="Create a teacher login now"
                            :checked="old('create_login', true)"
                            description="Needs an email address. The password is random, sent by notification, and must be changed on first sign-in." />
    </x-ui.card>
@endunless
