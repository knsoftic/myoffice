@extends('layouts.admin')

@section('title', 'Outline — '.$course->name)

@section('header')
    <x-ui.page-header :title="'Outline — '.$course->name"
                      subtitle="Three levels: module, topic, lecture. Resources and assignment blueprints hang off a topic."
                      icon="list-bullet"
                      :badge="$course->status->label()"
                      :badge-color="$course->status->color()"
                      :back="route('admin.courses.show', $course)">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="academic-cap" :href="route('admin.courses.show', $course)">
                Course
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card>
        @include('admin.courses._outline-tree')
    </x-ui.card>
@endsection
