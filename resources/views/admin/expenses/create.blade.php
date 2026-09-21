@extends('layouts.admin')

@section('title', 'Record an expense')

@section('header')
    <x-ui.page-header title="Record an expense"
                      subtitle="A cost the business has actually incurred. It reaches the reports when it is approved, not when it is typed."
                      icon="banknotes"
                      :back="route('admin.expenses.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.expenses._form')
    </form>
@endsection
