{{--
    The one material form, shared by create and edit (phase-19-23 §8.2).

    The kind drives everything: a file kind wants a file and refuses a URL, a link wants a URL and
    refuses a file. `chk_cm_payload` enforces that at the database, the Form Request says it on the
    field, and this hides the half that does not apply — three layers of the same rule, and only the
    first two are load-bearing.
--}}
@php($editing = isset($material))
@php($rules = App\DataObjects\Files\FileRules::courseMaterial($editing ? $material->type : App\Enums\CourseResourceType::Pdf))

<div x-data="{ kind: '{{ old('type', $editing ? $material->type->value : 'pdf') }}' }" class="grid gap-6">
    <x-ui.card>
        <x-ui.section-heading title="What is it?" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.select name="course_id" label="Course" required
                              :disabled="$editing"
                              help="{{ $editing ? 'A material stays with the course whose students were targeted.' : '' }}">
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}"
                            @selected((int) old('course_id', $editing ? $material->course_id : 0) === (int) $course->id)>
                        {{ $course->name }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="type" label="Kind" required x-model="kind" :disabled="$editing">
                @foreach ($types as $type)
                    <option value="{{ $type->value }}"
                            @selected(old('type', $editing ? $material->type->value : '') === $type->value)>
                        {{ $type->label() }}
                    </option>
                @endforeach
            </x-ui.form.select>

            <div class="sm:col-span-2">
                <x-ui.form.input name="title" label="Title" required
                                 :value="old('title', $editing ? $material->title : '')"
                                 placeholder="Week 1 — handout" />
            </div>

            <div class="sm:col-span-2">
                <x-ui.form.textarea name="description" label="Description" rows="3"
                                    :value="old('description', $editing ? $material->description : '')"
                                    help="What it is for, and anything the class should know before opening it." />
            </div>
        </div>
    </x-ui.card>

    <x-ui.card x-show="kind !== 'link'" x-cloak>
        <x-ui.section-heading title="The file" />

        <x-ui.form.file name="file" label="{{ $editing ? 'Replace the file' : 'File' }}"
                        :accept="$rules->acceptAttribute()"
                        :hint="$rules->describe()"
                        :current="$editing && $material->isFile() ? $material->original_name : null"
                        help="The name on disk is chosen by the server; yours is kept only for the download." />

        <div class="mt-4">
            <x-ui.form.toggle name="is_downloadable"
                              label="Let students take a copy"
                              description="Turning this off serves the file for viewing instead of downloading. It is deterrence, not protection — anyone determined still has the bytes."
                              :checked="(bool) old('is_downloadable', $editing ? $material->is_downloadable : true)" />
        </div>
    </x-ui.card>

    <x-ui.card x-show="kind === 'link'" x-cloak>
        <x-ui.section-heading title="The link" />

        <x-ui.form.input name="external_url" label="Web address" type="url"
                         :value="old('external_url', $editing ? $material->external_url : '')"
                         placeholder="https://..."
                         help="http:// or https:// only. A link is always openable — there is nothing to withhold." />
    </x-ui.card>

    <x-ui.card>
        <x-ui.section-heading title="When can they see it?"
                              subtitle="Leave both empty to make it available the moment it is published." />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="available_from" label="Available from" type="datetime-local"
                             :value="old('available_from', $editing ? app_input_datetime($material->available_from) : '')"
                             help="Upload ahead of the class and let it appear on the day." />

            <x-ui.form.input name="available_until" label="Available until" type="datetime-local"
                             :value="old('available_until', $editing ? app_input_datetime($material->available_until) : '')" />
        </div>
    </x-ui.card>

    @unless ($editing)
        <x-ui.card>
            <x-ui.section-heading title="Who is it for?"
                                  subtitle="A material with no audience reaches nobody, so it cannot be published until this is set. You can change it later." />

            <x-ui.form.select name="targets[0][id]" label="Batch" placeholder="Choose a batch">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->course?->name }}</option>
                @endforeach
            </x-ui.form.select>
            <input type="hidden" name="targets[0][type]" value="batch">

            <x-ui.form.help class="mt-2">
                Targeting the whole course, several batches or one student is done on the material’s own
                page once it exists.
            </x-ui.form.help>
        </x-ui.card>
    @endunless

    <x-ui.card>
        <x-ui.section-heading title="Filing" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.form.input name="sort_order" label="Order" type="number" min="0"
                             :value="old('sort_order', $editing ? $material->sort_order : 0)" />

            <x-ui.form.textarea name="notes" label="Internal notes" rows="2"
                                :value="old('notes', $editing ? $material->notes : '')"
                                help="Never shown to students." />
        </div>
    </x-ui.card>
</div>
