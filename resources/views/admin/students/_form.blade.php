{{--
    The §66 student form, shared by create and edit.

    `student_code`, `registration_number` and the status are absent on purpose: the two numbers are
    issued by the numbering service and the status moves through its own transition table, so a field
    for either would be a second writer for a fact that already has one.
--}}

<x-ui.card title="Who they are">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.input name="name" label="Name" required :value="old('name', $student->name)" />
        <x-ui.form.input name="father_name" label="Father's name" :value="old('father_name', $student->father_name)" />

        <x-ui.form.select name="gender" label="Gender" placeholder="Not stated">
            @foreach ($genders as $value => $label)
                <option value="{{ $value }}" @selected(old('gender', $student->gender?->value) === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.input name="date_of_birth" label="Date of birth" type="date"
                         :value="old('date_of_birth', $student->date_of_birth?->toDateString())" />
        <x-ui.form.input name="cnic" label="CNIC / B-Form" :value="old('cnic', $student->formattedCnic())"
                         help="Dashes are fine — they are stripped before it is stored." />
        <x-ui.form.select name="branch_id" label="Branch" placeholder="Every branch">
            @foreach ($branches as $id => $name)
                <option value="{{ $id }}" @selected((int) old('branch_id', $student->branch_id) === (int) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.form.select>
    </div>
</x-ui.card>

<x-ui.card title="How to reach them" class="mt-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.input name="phone" label="Phone" required :value="old('phone', $student->phone)" />
        <x-ui.form.input name="whatsapp" label="WhatsApp" :value="old('whatsapp', $student->whatsapp)" />
        <x-ui.form.input name="email" label="Email" type="email" :value="old('email', $student->email)"
                         help="Needed for a panel login. A student without one simply has no login." />
        <x-ui.form.input name="city" label="City" :value="old('city', $student->city)" />
        <div class="sm:col-span-2">
            <x-ui.form.input name="address" label="Address" :value="old('address', $student->address)" />
        </div>
    </div>
</x-ui.card>

<x-ui.card title="Guardian and background" class="mt-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.input name="guardian_name" label="Guardian" :value="old('guardian_name', $student->guardian_name)" />
        <x-ui.form.input name="guardian_phone" label="Guardian phone" :value="old('guardian_phone', $student->guardian_phone)" />
        <x-ui.form.input name="guardian_relation" label="Relation" :value="old('guardian_relation', $student->guardian_relation)" />
        <x-ui.form.input name="education" label="Education" :value="old('education', $student->education)" />
        <x-ui.form.input name="institution_name" label="School or college" :value="old('institution_name', $student->institution_name)" />
        <x-ui.form.input name="joining_date" label="Joining date" type="date"
                         :value="old('joining_date', $student->joining_date?->toDateString())" />
    </div>

    <div class="mt-4">
        <x-ui.form.textarea name="notes" label="Notes" rows="3" :value="old('notes', $student->notes)" />
    </div>
</x-ui.card>
