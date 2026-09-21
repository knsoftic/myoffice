@extends('layouts.admin')

@section('title', 'Add a payment method')

@section('header')
    <x-ui.page-header title="Add a payment method"
                      subtitle="How money moves, and which forms may offer it."
                      icon="credit-card"
                      :back="route('admin.payment-methods.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.payment-methods.store') }}">
        @csrf
        @include('admin.payment-methods._form')
    </form>
@endsection
