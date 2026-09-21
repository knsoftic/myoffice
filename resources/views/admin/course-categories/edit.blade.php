@extends('layouts.admin')

@section('title', 'Edit '.$category->name)

@section('header')
    <x-ui.page-header :title="$category->name"
                      subtitle="A category groups courses on the public site. Hiding it never changes a course's status on its own."
                      icon="rectangle-stack"
                      :badge="$category->is_active ? 'Active' : 'Hidden'"
                      :badge-color="$category->is_active ? 'emerald' : 'slate'"
                      :back="route('admin.course-categories.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.course-categories.update', $category) }}">
        @csrf
        @method('PUT')

        <x-ui.card>
            @include('admin.course-categories._fields')

            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" variant="primary" icon="check">Save changes</x-ui.button>
                    <x-ui.button variant="ghost" :href="route('admin.course-categories.index')">Cancel</x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
@endsection
