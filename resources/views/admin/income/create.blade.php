@extends('layouts.admin')

@section('title', 'Record income')

@section('header')
    <x-ui.page-header title="Record income"
                      subtitle="Money the business received that belongs to no other register."
                      icon="arrow-trending-up"
                      :back="route('admin.income.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.income.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.income._form')
    </form>
@endsection
