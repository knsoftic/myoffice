@extends('layouts.admin')

@section('title', 'Edit '.$course->name)

@section('header')
    <x-ui.page-header :title="$course->name"
                      :subtitle="$course->code.' · '.$course->category?->name"
                      icon="academic-cap"
                      :badge="$course->status->label()"
                      :badge-color="$course->status->color()"
                      :back="route('admin.courses.show', $course)" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.courses.update', $course) }}">
        @csrf
        @method('PUT')
        @include('admin.courses._form')
    </form>
@endsection
