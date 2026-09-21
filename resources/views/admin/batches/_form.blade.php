{{--
    The §70 batch form, shared by create and edit.

    `status`, `current_students` and the session counters are absent on purpose: the status moves
    through its own guarded transition and the other three are caches that only a recount writes
    (INV-I7). A field for any of them would be a second writer for a fact that already has one.
--}}

<x-ui.card title="What it is">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.input name="code" label="Code" required :value="old('code', $batch->code)" placeholder="PHP-EVE-01" />
        <x-ui.form.input name="name" label="Name" required :value="old('name', $batch->name)" placeholder="PHP evenings, March" />

        <x-ui.form.select name="course_id" label="Course" required placeholder="Pick a course">
            @foreach ($courses as $id => $name)
                <option value="{{ $id }}" @selected((int) old('course_id', $batch->course_id) === (int) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Not assigned yet">
            @foreach ($teachers as $id => $name)
                <option value="{{ $id }}" @selected((int) old('teacher_id', $batch->teacher_id) === (int) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.select name="branch_id" label="Branch" placeholder="Every branch">
            @foreach ($branches as $id => $name)
                <option value="{{ $id }}" @selected((int) old('branch_id', $batch->branch_id) === (int) $id)>{{ $name }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.input name="student_capacity" label="Seats" type="number" min="1" required
                         :value="old('student_capacity', $batch->student_capacity)"
                         help="Lowering this below the number already enrolled is refused." />
    </div>
</x-ui.card>

<x-ui.card title="When it meets" class="mt-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.form.input name="start_date" label="Starts" type="date" required
                         :value="old('start_date', $batch->start_date?->toDateString())" />
        <x-ui.form.input name="end_date" label="Ends" type="date"
                         :value="old('end_date', $batch->end_date?->toDateString())"
                         help="Leave empty for open-ended." />
        <x-ui.form.input name="start_time" label="From" type="time"
                         :value="old('start_time', $batch->start_time ? app_clock($batch->start_time, 'H:i') : null)" />
        <x-ui.form.input name="end_time" label="To" type="time"
                         :value="old('end_time', $batch->end_time ? app_clock($batch->end_time, 'H:i') : null)" />
    </div>

    <div class="mt-4">
        <x-ui.form.label>Days</x-ui.form.label>
        <div class="mt-2 flex flex-wrap gap-3">
            @foreach ($weekdays as $day)
                @php($disabled = $workingDays !== [] && ! in_array($day->value, $workingDays, true))
                <label class="flex items-center gap-2 text-sm {{ $disabled ? 'opacity-40' : '' }}">
                    <input type="checkbox" name="days[]" value="{{ $day->value }}" @disabled($disabled)
                           @checked(in_array($day->value, old('days', $batch->days ?? []), true))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
                    <span class="text-slate-600 dark:text-slate-300">{{ $day->short() }}</span>
                </label>
            @endforeach
        </div>
        <x-ui.form.help>
            These prefill the timetable. Once the slots exist, the slots are the truth — a batch that
            meets at a different hour on Saturdays says so there, which these boxes cannot express.
        </x-ui.form.help>
        <x-ui.form.error for="days" />
    </div>
</x-ui.card>

<x-ui.card title="Where it meets" class="mt-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.form.select name="delivery_mode" label="Mode" required>
            @foreach ($modes as $value => $label)
                <option value="{{ $value }}" @selected(old('delivery_mode', $batch->delivery_mode?->value ?? 'physical') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.select name="classroom_id" label="Room" placeholder="No room">
            @foreach ($classrooms as $room)
                <option value="{{ $room->id }}" @selected((int) old('classroom_id', $batch->classroom_id) === (int) $room->id)>
                    {{ $room->label() }}{{ $room->capacityLimits() ? ' ('.$room->capacity.' seats)' : '' }}
                </option>
            @endforeach
        </x-ui.form.select>

        <x-ui.form.input name="meeting_url" label="Meeting link" type="url" :value="old('meeting_url', $batch->meeting_url)"
                         placeholder="https://…" help="Used for online and hybrid batches." />
    </div>

    <div class="mt-4">
        <x-ui.form.textarea name="notes" label="Notes" rows="3" :value="old('notes', $batch->notes)" />
    </div>
</x-ui.card>
