@extends('layouts.admin')

@section('title', 'New role')

@section('header')
    <x-ui.page-header
        title="New role"
        subtitle="Name it, place it on a panel, then tick exactly what it may do."
        icon="shield-check"
        :back="route('admin.roles.index')"
    />
@endsection

@section('content')
    @include('admin.roles.partials.form', [
        'role' => $role,
        'permissionGroups' => $permissionGroups,
        'allPermissionNames' => $allPermissionNames,
        'selected' => $selected,
        'panelOptions' => $panelOptions,
        'action' => route('admin.roles.store'),
        'method' => 'POST',
        'submitLabel' => 'Create role',
    ])
@endsection
