{{--
    The add-node dialogs for the outline tree.

    Every one posts to a route bound to its **parent** — a topic to its module, a lecture to its topic —
    so the ids the row is written with were never in the request body. That is INV-I12 in practice, and
    it is why none of these forms carries a hidden `course_id`.
--}}

@php
    $lectureTypes = $lectureTypes ?? \App\Enums\LectureType::cases();
    $resourceTypes = $resourceTypes ?? \App\Enums\CourseResourceType::cases();

    $canCreate = $canCreate ?? false;
    $canUpload = $canUpload ?? false;
@endphp

@if ($canCreate)
    <x-ui.modal name="add-module" title="Add a module" icon="folder-plus">
        <form method="POST" action="{{ route('admin.course-modules.store', $course) }}" class="space-y-4">
            @csrf

            <x-ui.form.input name="title" label="Module title" required maxlength="180"
                             placeholder="Getting started" />
            <x-ui.form.textarea name="description" label="Description" rows="2" maxlength="5000" />
            <x-ui.form.input type="number" min="1" name="duration_minutes" label="Planned minutes"
                             help="Optional. The real figure comes from the lectures inside it." />

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-module')">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="primary">Add it</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    @foreach ($course->modules as $module)
        <x-ui.modal name="add-topic-{{ $module->id }}" :title="'Add a topic to '.$module->title" icon="plus">
            <form method="POST" action="{{ route('admin.course-topics.store', $module) }}" class="space-y-4">
                @csrf

                <x-ui.form.input name="title" label="Topic title" required maxlength="180" />
                <x-ui.form.textarea name="description" label="Description" rows="2" maxlength="5000" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input type="number" min="1" max="100" name="weight" label="Weight" value="1"
                                     help="How much of the course this topic is worth. 1 counts it like any other." />
                    <x-ui.form.input type="number" min="1" name="estimated_minutes" label="Estimated minutes" />
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'add-topic-{{ $module->id }}')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Add it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        @foreach ($module->topics as $topic)
            <x-ui.modal name="add-lecture-{{ $topic->id }}" :title="'Add a lecture to '.$topic->title" icon="plus">
                <form method="POST" action="{{ route('admin.course-lectures.store', $topic) }}" class="space-y-4">
                    @csrf

                    <x-ui.form.input name="title" label="Lecture title" required maxlength="180" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.select name="lecture_type" label="Kind" required>
                            @foreach ($lectureTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.input type="number" min="1" max="1440" name="duration_minutes" label="Minutes" />
                    </div>

                    <x-ui.form.textarea name="description" label="Description" rows="2" maxlength="5000" />
                    <x-ui.form.input type="url" name="video_url" label="Video link" maxlength="255" />

                    <x-ui.form.toggle name="is_preview" label="Free preview"
                                      description="Shown on the public course page before anybody applies." />

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'add-lecture-{{ $topic->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Add it</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>

            <x-ui.modal name="add-blueprint-{{ $topic->id }}" :title="'Assignment blueprint for '.$topic->title"
                        icon="clipboard-document-check">
                <form method="POST" action="{{ route('admin.course-topic-assignments.store', $topic) }}" class="space-y-4">
                    @csrf

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        A blueprint is curriculum: "this topic has a practice assignment worth about this
                        much". It is never graded — a batch turns it into a real assignment with a deadline.
                    </p>

                    <x-ui.form.input name="title" label="Title" required maxlength="180" />
                    <x-ui.form.textarea name="description" label="Description" rows="2" maxlength="5000" />
                    <x-ui.form.textarea name="instructions" label="Instructions" rows="3" maxlength="5000" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.input type="number" step="0.01" min="0" name="estimated_marks" label="Marks" />
                        <x-ui.form.input type="number" step="0.01" min="0" name="estimated_hours" label="Hours" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'add-blueprint-{{ $topic->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Add it</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endforeach
@endif

@if ($canUpload)
    @foreach ($course->modules as $module)
        @foreach ($module->topics as $topic)
            <x-ui.modal name="add-resource-{{ $topic->id }}" :title="'Add a resource to '.$topic->title" icon="paper-clip">
                <form method="POST" action="{{ route('admin.course-resources.store', $topic) }}"
                      enctype="multipart/form-data" class="space-y-4">
                    @csrf

                    <x-ui.form.input name="title" label="Title" required maxlength="180" />

                    <x-ui.form.select name="type" label="Kind" required>
                        @foreach ($resourceTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.file name="file" label="File"
                                    hint="Up to 25 MB. The type is checked on the contents, not the name." />

                    <x-ui.form.input type="url" name="external_url" label="…or a link" maxlength="500"
                                     help="A resource points at one or the other." />

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.form.toggle name="is_public" label="Show on the public page"
                                          description="Visible to anybody, before they apply." />
                        <x-ui.form.toggle name="is_downloadable" label="Downloadable" :checked="true" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'add-resource-{{ $topic->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Add it</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endforeach
    @endforeach
@endif
