@php
    $employee = $employee ?? null;
@endphp

<div class="grid gap-4 lg:grid-cols-2">
    <x-ui.card title="Who they are">
        <div class="space-y-3">
            <x-ui.form.input name="name" label="Full name" required maxlength="150" :value="old('name', $employee?->name)" />
            <x-ui.form.input type="email" name="email" label="Work email" maxlength="150" :value="old('email', $employee?->email)" />
            <div class="grid grid-cols-2 gap-3">
                <x-ui.form.input name="phone" label="Phone" maxlength="32" :value="old('phone', $employee?->phone)" />
                <x-ui.form.input name="whatsapp" label="WhatsApp" maxlength="32" :value="old('whatsapp', $employee?->whatsapp)" />
            </div>
            <x-ui.form.input name="address" label="Address" maxlength="255" :value="old('address', $employee?->address)" />
            <x-ui.form.input name="city" label="City" maxlength="100" :value="old('city', $employee?->city)" />
        </div>
    </x-ui.card>

    <x-ui.card title="Where they work">
        <div class="space-y-3">
            <x-ui.form.select name="department_id" label="Department" required placeholder="Choose a department">
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected((int) old('department_id', $employee?->department_id) === $department->id)>{{ $department->name }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.form.select name="designation_id" label="Designation" placeholder="No title">
                @foreach ($designations as $designation)
                    <option value="{{ $designation->id }}" @selected((int) old('designation_id', $employee?->designation_id) === $designation->id)>{{ $designation->title }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.form.select name="work_shift_id" label="Work shift" placeholder="Branch default"
                help="Without a shift, late minutes are meaningless and the day is judged on hours worked alone.">
                @foreach ($shifts as $shift)
                    <option value="{{ $shift->id }}" @selected((int) old('work_shift_id', $employee?->work_shift_id) === $shift->id)>{{ $shift->name }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.form.select name="reports_to_id" label="Reports to" placeholder="Nobody">
                @foreach ($managers as $manager)
                    @continue($employee !== null && $manager->id === $employee->id)
                    <option value="{{ $manager->id }}" @selected((int) old('reports_to_id', $employee?->reports_to_id) === $manager->id)>
                        {{ $manager->name }} ({{ $manager->employee_code }})
                    </option>
                @endforeach
            </x-ui.form.select>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.form.input type="date" name="joining_date" label="Joined on" required
                    :value="old('joining_date', $employee?->joining_date?->toDateString())" />
                <x-ui.form.select name="employment_type" label="Employment" :options="$employmentTypes"
                    :selected="old('employment_type', $employee?->employment_type?->value ?? 'full_time')" required />
            </div>
            <x-ui.form.checkbox name="is_attendance_exempt" label="Exempt from attendance"
                :checked="(bool) old('is_attendance_exempt', $employee?->is_attendance_exempt)"
                description="Never marked absent or late, and always paid a full day. This is a pay decision, so it is shown on every screen."
                with-hidden />
        </div>
    </x-ui.card>

    <x-ui.card title="In an emergency">
        <div class="space-y-3">
            <x-ui.form.input name="emergency_contact_name" label="Contact name" maxlength="150"
                :value="old('emergency_contact_name', $employee?->emergency_contact_name)" />
            <div class="grid grid-cols-2 gap-3">
                <x-ui.form.input name="emergency_contact_relation" label="Relationship" maxlength="64"
                    :value="old('emergency_contact_relation', $employee?->emergency_contact_relation)" />
                <x-ui.form.input name="emergency_contact_phone" label="Phone" maxlength="32"
                    :value="old('emergency_contact_phone', $employee?->emergency_contact_phone)" />
            </div>
            <x-ui.form.textarea name="notes" label="Internal notes" rows="3" :value="old('notes', $employee?->notes)" />
        </div>
    </x-ui.card>

    @if ($employee === null)
        <x-ui.card title="Give them a login" subtitle="Optional. An employee record exists perfectly well without one.">
            <div x-data="{ withLogin: {{ old('create_login') ? 'true' : 'false' }} }" class="space-y-3">
                <x-ui.form.checkbox name="create_login" label="Create a login for this employee"
                    x-model="withLogin" :checked="(bool) old('create_login')" />

                <div x-show="withLogin" x-cloak class="space-y-3">
                    <x-ui.form.input type="email" name="account_email" label="Login email" maxlength="150"
                        :value="old('account_email')" />
                    <x-ui.form.input type="password" name="account_password" label="Temporary password"
                        help="They are asked to change it at first sign-in." />
                    <x-ui.form.select name="account_role" label="Role" placeholder="Choose a role">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected(old('account_role') === $role)>{{ $role }}</option>
                        @endforeach
                    </x-ui.form.select>
                </div>
            </div>
        </x-ui.card>
    @endif
</div>
