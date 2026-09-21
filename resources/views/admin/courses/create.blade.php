@extends('layouts.admin')

@section('title', 'Add a course')

@section('header')
    <x-ui.page-header title="Add a course"
                      subtitle="Created as a draft. It goes on the public site only when it has an outline and somebody publishes it."
                      icon="academic-cap"
                      :back="route('admin.courses.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.courses.store') }}">
        @csrf
        @include('admin.courses._form')
    </form>
@endsection
