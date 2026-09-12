@extends('layouts.admin')

@section('title', 'New user')

@section('header')
    <x-ui.page-header
        title="New user"
        subtitle="Create an account and give it the roles that decide which panel it reaches."
        icon="user-plus"
        :back="route('admin.users.index')"
    />
@endsection

@section('content')
    @include('admin.users.partials.form', [
        'user' => $user,
        'roles' => $roles,
        'branches' => $branches,
        'statusOptions' => $statusOptions,
        'action' => route('admin.users.store'),
        'method' => 'POST',
        'submitLabel' => 'Create user',
    ])
@endsection
