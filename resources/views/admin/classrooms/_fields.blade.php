{{--
    The six fields a room has. Shared by the add and the edit dialog, so the two cannot drift.
--}}

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.form.input name="code" label="Code" required :value="old('code', $room->code)" placeholder="LAB-1" />
    <x-ui.form.input name="name" label="Name" required :value="old('name', $room->name)" placeholder="Computer Lab 1" />

    <x-ui.form.select name="type" label="Type" required>
        @foreach ($types as $value => $label)
            <option value="{{ $value }}" @selected(old('type', $room->type?->value ?? 'classroom') === $value)>{{ $label }}</option>
        @endforeach
    </x-ui.form.select>

    <x-ui.form.input name="capacity" label="Seats" type="number" min="1" required
                     :value="old('capacity', $room->capacity ?? 20)"
                     help="A virtual room is never capped by this — a meeting link seats everybody." />

    <x-ui.form.input name="location" label="Where it is" :value="old('location', $room->location)" placeholder="2nd floor, back" />

    <x-ui.form.select name="branch_id" label="Branch" placeholder="Every branch">
        @foreach ($branches as $id => $name)
            <option value="{{ $id }}" @selected((int) old('branch_id', $room->branch_id) === (int) $id)>{{ $name }}</option>
        @endforeach
    </x-ui.form.select>

    <div class="sm:col-span-2">
        <x-ui.form.input name="notes" label="Notes" :value="old('notes', $room->notes)" />
    </div>
</div>
