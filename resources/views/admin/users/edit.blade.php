@extends('layouts.admin')

@section('title', 'Edit '.$user->name)

@section('header')
    <x-ui.page-header
        :title="'Edit '.$user->name"
        subtitle="Profile, sign-in and role assignments."
        icon="pencil"
        :back="route('admin.users.show', $user)"
        :badge="$user->status->label()"
        :badge-color="$user->status->color()"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="eye" :href="route('admin.users.show', $user)">
                View profile
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.users.partials.form', [
        'user' => $user,
        'roles' => $roles,
        'branches' => $branches,
        'statusOptions' => $statusOptions,
        'action' => route('admin.users.update', $user),
        'method' => 'PUT',
        'submitLabel' => 'Save changes',
    ])
@endsection
