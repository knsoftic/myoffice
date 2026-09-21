@extends('layouts.admin')

@section('title', 'Classrooms')

@section('header')
    <x-ui.page-header title="Classrooms"
                      subtitle="Rooms the timetable books. A virtual room holds as many classes at once as you care to schedule."
                      icon="building-office-2">
        <x-slot:actions>
            @can('create', \App\Models\Institute\Classroom::class)
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'add-classroom')">Add room</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <x-ui.stat-card label="Rooms" :value="app_number($counts['all'])" icon="building-office-2" color="slate" />
        <x-ui.stat-card label="Open" :value="app_number($counts['active'])" icon="check" color="emerald" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Code, name or where it is" />

            <x-ui.form.select name="type" label="Type" placeholder="Any type">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="active" label="Open?" placeholder="Either">
                <option value="1" @selected(request('active') === '1')>Open</option>
                <option value="0" @selected(request('active') === '0')>Closed</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.classrooms.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-ui.table :is-empty="$rooms->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Room</th>
                <th class="px-4 py-3 text-left font-semibold">Type</th>
                <th class="px-4 py-3 text-left font-semibold">Seats</th>
                <th class="px-4 py-3 text-left font-semibold">Where</th>
                <th class="px-4 py-3 text-left font-semibold">In use</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($rooms as $room)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ $room->code }}</div>
                        <div class="text-xs text-slate-400">{{ $room->name }}</div>
                    </td>
                    <td class="px-4 py-3"><x-ui.badge :color="$room->type->color()" size="xs">{{ $room->type->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $room->capacityLimits() ? app_number($room->capacity) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $room->location ?: '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_number($room->batches_count) }} {{ \Illuminate\Support\Str::plural('batch', $room->batches_count) }},
                        {{ app_number($room->timetable_entries_count) }} {{ \Illuminate\Support\Str::plural('slot', $room->timetable_entries_count) }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$room->is_active ? 'emerald' : 'slate'">{{ $room->is_active ? 'Open' : 'Closed' }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex justify-end gap-1">
                            @can('update', $room)
                                <x-ui.button variant="ghost" size="sm" icon="pencil"
                                             x-on:click="$dispatch('open-modal', 'edit-classroom-{{ $room->id }}')">Edit</x-ui.button>
                            @endcan
                            @can('changeStatus', $room)
                                <form method="POST" action="{{ route('admin.classrooms.toggle', $room) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="ghost" size="sm"
                                                 :icon="$room->is_active ? 'lock-closed' : 'lock-open'">
                                        {{ $room->is_active ? 'Close' : 'Open' }}
                                    </x-ui.button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="building-office-2" title="No rooms yet"
                                  description="Add the rooms classes are held in, so the timetable can book them." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$rooms" label="rooms" />
    </x-ui.card>

    @can('create', \App\Models\Institute\Classroom::class)
        <x-ui.modal name="add-classroom" title="Add a room" icon="building-office-2">
            <form method="POST" action="{{ route('admin.classrooms.store') }}" class="space-y-4">
                @csrf
                @include('admin.classrooms._fields', ['room' => new \App\Models\Institute\Classroom(['capacity' => 20]), 'types' => $types, 'branches' => $branches])

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-classroom')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Add the room</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan

    @foreach ($rooms as $room)
        @can('update', $room)
            <x-ui.modal name="edit-classroom-{{ $room->id }}" :title="'Edit '.$room->code" icon="pencil">
                <form method="POST" action="{{ route('admin.classrooms.update', $room) }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    @include('admin.classrooms._fields', ['room' => $room, 'types' => $types, 'branches' => $branches])

                    <div class="flex justify-between gap-2">
                        @can('delete', $room)
                            <x-ui.button variant="ghost" size="sm"
                                         x-on:click="$dispatch('open-modal', 'delete-classroom-{{ $room->id }}')">Remove</x-ui.button>
                        @else
                            <span></span>
                        @endcan

                        <div class="flex gap-2">
                            <x-ui.button type="button" variant="ghost"
                                         x-on:click="$dispatch('close-modal', 'edit-classroom-{{ $room->id }}')">Cancel</x-ui.button>
                            <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                        </div>
                    </div>
                </form>
            </x-ui.modal>
        @endcan

        @can('delete', $room)
            <x-ui.modal name="delete-classroom-{{ $room->id }}" :title="'Remove '.$room->code.'?'" icon="trash">
                <form method="POST" action="{{ route('admin.classrooms.destroy', $room) }}" class="space-y-4">
                    @csrf
                    @method('DELETE')
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        A room that has ever held a class is not removed — it is closed, so the classes
                        taught there can still say where they were. This is refused if that is the case.
                    </p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'delete-classroom-{{ $room->id }}')">Keep it</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Remove the room</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan
    @endforeach
@endsection
