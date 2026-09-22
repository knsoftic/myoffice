@extends('layouts.panel')

@section('title', 'Share material')

@section('header')
    <x-ui.page-header title="Share material"
                      subtitle="Saved as a draft. Your batch sees nothing until you publish it."
                      icon="folder-open">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('teacher.materials.index')">Cancel</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="POST" action="{{ route('teacher.materials.store') }}" enctype="multipart/form-data"
          x-data="{ kind: @js(old('type', 'pdf')) }" class="grid gap-6">
        @csrf

        <x-ui.card>
            <x-ui.section-heading title="What is it?" />

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Only batches this teacher actually reaches. The controller checks the posted id
                     against the same scope rather than trusting the picker. --}}
                <x-ui.form.select name="targets[0][id]" label="Batch" required>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" data-course="{{ $batch->course_id }}">
                            {{ $batch->code }} — {{ $batch->course?->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>
                <input type="hidden" name="targets[0][type]" value="batch">

                <x-ui.form.select name="type" label="Kind" required x-model="kind">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </x-ui.form.select>

                {{-- A material and its audience always share one course, so the course follows the
                     batch rather than being a second thing to choose and get wrong. --}}
                <input type="hidden" name="course_id" value="{{ $batches->first()?->course_id }}">

                <div class="sm:col-span-2">
                    <x-ui.form.input name="title" label="Title" required :value="old('title')" />
                </div>

                <div class="sm:col-span-2">
                    <x-ui.form.textarea name="description" label="Description" rows="3" :value="old('description')" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card x-show="kind !== 'link'" x-cloak>
            <x-ui.section-heading title="The file" />

            <x-ui.form.file name="file" label="File"
                            :hint="App\DataObjects\Files\FileRules::courseMaterial(App\Enums\CourseResourceType::Pdf)->describe()" />

            <div class="mt-4">
                <x-ui.form.toggle name="is_downloadable" label="Let students take a copy"
                                  description="Off serves it for viewing instead. It is deterrence, not protection — anyone determined still has the bytes."
                                  :checked="(bool) old('is_downloadable', true)" />
            </div>
        </x-ui.card>

        <x-ui.card x-show="kind === 'link'" x-cloak>
            <x-ui.section-heading title="The link" />

            <x-ui.form.input name="external_url" label="Web address" type="url" :value="old('external_url')"
                             placeholder="https://..." help="http:// or https:// only." />
        </x-ui.card>

        <x-ui.card>
            <x-ui.section-heading title="When can they see it?"
                                  subtitle="Leave both empty to make it available the moment it is published." />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="available_from" label="Available from" type="datetime-local" :value="old('available_from')" />
                <x-ui.form.input name="available_until" label="Available until" type="datetime-local" :value="old('available_until')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('teacher.materials.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="check">Save as draft</x-ui.button>
        </div>
    </form>
@endsection
