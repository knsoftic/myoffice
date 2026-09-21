@extends('layouts.admin')

@section('title', 'Edit ' . $invoice->draft_reference)

@section('header')
    <x-ui.page-header :title="'Edit ' . $invoice->draft_reference"
                      subtitle="Lines are replaced wholesale and the totals recomputed. An invoice with money against it is corrected by cancelling and replacing it, not in place."
                      icon="document-text"
                      :back="route('admin.invoices.show', $invoice)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.invoices.update', $invoice) }}">
        @csrf
        @method('PUT')
        @include('admin.invoices._form')
    </form>
@endsection
