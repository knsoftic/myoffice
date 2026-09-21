@extends('layouts.admin')

@section('title', 'New invoice')

@section('header')
    <x-ui.page-header title="New invoice"
                      subtitle="Saved as a draft. It carries no number until it is issued, so an abandoned draft leaves no gap in the series."
                      icon="document-text"
                      :back="route('admin.invoices.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.invoices.store') }}">
        @csrf
        @include('admin.invoices._form')
    </form>
@endsection
