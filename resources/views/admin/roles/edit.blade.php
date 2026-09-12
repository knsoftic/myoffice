@extends('layouts.admin')

@section('title', 'Edit '.$role->displayName())

@section('header')
    <x-ui.page-header
        :title="$role->displayName()"
        subtitle="Permission matrix. Nothing is saved until you submit."
        :icon="$role->is_system ? 'lock-closed' : 'shield-check'"
        :back="route('admin.roles.index')"
        :badge="$role->panelType()->label().' panel · level '.$role->level"
        :badge-color="$role->panelType()->color()"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="eye" :href="route('admin.roles.show', $role)">
                Overview
            </x-ui.button>

            @can('delete', $role)
                <x-ui.confirm
                    :action="route('admin.roles.destroy', $role)"
                    method="DELETE"
                    :title="'Delete the '.$role->displayName().' role?'"
                    message="The role and all of its permission grants are removed. If anyone still holds it, the delete is refused — reassign them first."
                    confirm-label="Delete role"
                    :require-text="$role->name"
                >
                    <x-slot:trigger>
                        <x-ui.button variant="secondary" icon="trash">Delete</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.roles.partials.form', [
        'role' => $role,
        'permissionGroups' => $permissionGroups,
        'allPermissionNames' => $allPermissionNames,
        'selected' => $selected,
        'panelOptions' => $panelOptions,
        'action' => route('admin.roles.update', $role),
        'method' => 'PUT',
        'submitLabel' => 'Save role',
    ])
@endsection
