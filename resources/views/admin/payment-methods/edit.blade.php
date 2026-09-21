@extends('layouts.admin')

@section('title', 'Edit '.$method->name)

@section('header')
    <x-ui.page-header :title="$method->name"
                      subtitle="Renaming it changes every dropdown and no history."
                      icon="credit-card"
                      :badge="$method->is_active ? 'Active' : 'Off'"
                      :badge-color="$method->is_active ? 'emerald' : 'slate'"
                      :back="route('admin.payment-methods.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.payment-methods.update', $method) }}">
        @csrf
        @method('PUT')
        @include('admin.payment-methods._form')
    </form>
@endsection
