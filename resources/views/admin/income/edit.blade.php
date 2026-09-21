@extends('layouts.admin')

@section('title', 'Edit '.$income->income_no)

@section('header')
    <x-ui.page-header :title="'Edit '.$income->income_no"
                      :subtitle="$income->title"
                      icon="arrow-trending-up"
                      :badge="$income->status->label()"
                      :badge-color="$income->status->color()"
                      :back="route('admin.income.show', $income)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.income.update', $income) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('admin.income._form')
    </form>
@endsection
