@extends('layouts.admin')

@section('title', 'Edit '.$expense->expense_no)

@section('header')
    <x-ui.page-header :title="'Edit '.$expense->expense_no"
                      :subtitle="$expense->title"
                      icon="banknotes"
                      :badge="$expense->status->label()"
                      :badge-color="$expense->status->color()"
                      :back="route('admin.expenses.show', $expense)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.expenses.update', $expense) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('admin.expenses._form')
    </form>
@endsection
