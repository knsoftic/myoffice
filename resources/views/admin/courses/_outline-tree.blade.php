{{--
    The three-level outline tree, shared by the course detail screen's Outline tab and the standalone
    builder at /admin/courses/{course}/outline (phase-14-17 §8.4).

    **Drag rules are enforced twice, and only the second one counts.** A lecture only drops into a
    topic and a topic only into a module of the same course — the client refuses the rest as a
    courtesy, and the server re-verifies every id against its parent and the parent against the course
    (§6.3). A payload carrying one foreign id reorders nothing at all.

    **A node that has been taught from cannot be deleted** (INV-I13): its delete button is disabled
    with the reason in the tooltip, and Deactivate is offered instead. That is the policy's answer
    rendered, not a second rule invented here.

    Expects: $course (with modules.topics.lectures/resources/assignmentBlueprints loaded),
             $lectureTypes, $resourceTypes, and the five can* flags.
--}}

@php
    $lectureTypes = $lectureTypes ?? \App\Enums\LectureType::cases();
    $resourceTypes = $resourceTypes ?? \App\Enums\CourseResourceType::cases();

    // Default every flag to "no". A missing variable should hide a control, never raise a warning and
    // render an unguarded button — the route and the policy would still refuse it, but the person
    // would have been shown something that does not work.
    $canCreate = $canCreate ?? false;
    $canEdit = $canEdit ?? false;
    $canDelete = $canDelete ?? false;
    $canToggle = $canToggle ?? false;
    $canUpload = $canUpload ?? false;
@endphp

<div x-data="courseOutline()" class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <x-ui.badge color="slate" size="xs">{{ $course->modules_count }} modules</x-ui.badge>
            <x-ui.badge color="slate" size="xs">{{ $course->topics_count }} topics</x-ui.badge>
            <x-ui.badge color="slate" size="xs">{{ $course->lectures_count }} lectures</x-ui.badge>
            @if ($course->outline_minutes > 0)
                <x-ui.badge color="sky" size="xs">~{{ (int) round($course->outline_minutes / 60) }} hours</x-ui.badge>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button size="sm" variant="ghost" x-on:click="collapseAll()">Collapse all</x-ui.button>

            @if ($canCreate)
                <x-ui.button size="sm" variant="primary" icon="plus"
                             x-on:click.prevent="$dispatch('open-modal', 'add-module')">Add module</x-ui.button>
            @endif
        </div>
    </div>

    @forelse ($course->modules as $module)
        <div class="rounded-lg border border-slate-200 dark:border-slate-800"
             data-module-row data-module-id="{{ $module->id }}"
             draggable="{{ $canEdit ? 'true' : 'false' }}"
             x-on:dragstart="pick($event, 'module')"
             x-on:dragover.prevent="over($event, 'module')"
             x-on:drop.prevent="drop($event)">

            <div class="flex items-start justify-between gap-3 bg-slate-50 px-4 py-3 dark:bg-slate-900/60">
                <div class="min-w-0 flex-1">
                    <button type="button" class="flex items-center gap-2 text-left"
                            x-on:click="toggle('m{{ $module->id }}')">
                        <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                                   x-bind:class="open('m{{ $module->id }}') && 'rotate-90'" />
                        <span class="font-medium text-slate-900 dark:text-white">{{ $module->title }}</span>
                    </button>

                    <div class="mt-1 flex flex-wrap items-center gap-2 pl-6 text-xs text-slate-500 dark:text-slate-400">
                        <span>{{ $module->topics_count }} {{ \Illuminate\Support\Str::plural('topic', $module->topics_count) }}</span>
                        <span>·</span>
                        <span>{{ $module->lectures_count }} {{ \Illuminate\Support\Str::plural('lecture', $module->lectures_count) }}</span>
                        @unless ($module->is_active)
                            <x-ui.badge color="slate" size="xs">switched off</x-ui.badge>
                        @endunless
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    @if ($canCreate)
                        <x-ui.icon-button icon="plus" label="Add a topic to {{ $module->title }}"
                                          x-on:click="$dispatch('open-modal', 'add-topic-{{ $module->id }}')" />
                        <x-ui.icon-button icon="document-duplicate" label="Duplicate {{ $module->title }}"
                                          x-on:click="submitForm('duplicate-module-{{ $module->id }}')" />
                    @endif

                    @if ($canToggle)
                        <form method="POST" action="{{ route('admin.course-outline.toggle', [$course, 'module', $module->id]) }}">
                            @csrf
                            <input type="hidden" name="active" value="{{ $module->is_active ? 0 : 1 }}">
                            <x-ui.icon-button type="submit" :icon="$module->is_active ? 'eye-slash' : 'eye'"
                                              :label="($module->is_active ? 'Switch off ' : 'Switch on ').$module->title" />
                        </form>
                    @endif

                    @if ($canDelete)
                        @can('delete', $module)
                            <x-ui.confirm :action="route('admin.course-modules.destroy', $module)"
                                          title="Remove {{ $module->title }}?"
                                          message="Its topics and lectures go with it. Nothing has been taught from it yet."
                                          confirm-label="Remove it">
                                <x-slot:trigger>
                                    <x-ui.icon-button icon="trash" label="Remove {{ $module->title }}" variant="danger" />
                                </x-slot:trigger>
                            </x-ui.confirm>
                        @else
                            <span title="This module has been taught from — switch it off instead, so the history and every percentage stay intact">
                                <x-ui.icon-button icon="trash" label="Cannot remove {{ $module->title }}" :disabled="true" />
                            </span>
                        @endcan
                    @endif
                </div>
            </div>

            <div x-show="open('m{{ $module->id }}')" x-cloak class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($module->topics as $topic)
                    <div class="px-4 py-3"
                         data-topic-row data-topic-id="{{ $topic->id }}" data-parent-id="{{ $module->id }}"
                         draggable="{{ $canEdit ? 'true' : 'false' }}"
                         x-on:dragstart.stop="pick($event, 'topic')"
                         x-on:dragover.prevent.stop="over($event, 'topic')"
                         x-on:drop.prevent.stop="drop($event)">

                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <button type="button" class="flex items-center gap-2 text-left"
                                        x-on:click="toggle('t{{ $topic->id }}')">
                                    <x-ui.icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform"
                                               x-bind:class="open('t{{ $topic->id }}') && 'rotate-90'" />
                                    <span class="text-sm text-slate-900 dark:text-white">{{ $topic->title }}</span>
                                </button>

                                <div class="mt-1 flex flex-wrap items-center gap-2 pl-6 text-xs text-slate-500 dark:text-slate-400">
                                    <span>{{ $topic->lectures_count }} {{ \Illuminate\Support\Str::plural('lecture', $topic->lectures_count) }}</span>
                                    @if ($topic->resources_count > 0)
                                        <span>· {{ $topic->resources_count }} {{ \Illuminate\Support\Str::plural('resource', $topic->resources_count) }}</span>
                                    @endif
                                    @if ($topic->assignments_count > 0)
                                        <span>· {{ $topic->assignments_count }} {{ \Illuminate\Support\Str::plural('assignment', $topic->assignments_count) }}</span>
                                    @endif
                                    @if ($topic->weight > 1)
                                        <x-ui.badge color="violet" size="xs">weight {{ $topic->weight }}</x-ui.badge>
                                    @endif
                                    @unless ($topic->is_active)
                                        <x-ui.badge color="slate" size="xs">switched off</x-ui.badge>
                                    @endunless
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                @if ($canCreate)
                                    <x-ui.icon-button icon="plus" label="Add a lecture to {{ $topic->title }}"
                                                      x-on:click="$dispatch('open-modal', 'add-lecture-{{ $topic->id }}')" />
                                @endif

                                @if ($canUpload)
                                    <x-ui.icon-button icon="paper-clip" label="Add a resource to {{ $topic->title }}"
                                                      x-on:click="$dispatch('open-modal', 'add-resource-{{ $topic->id }}')" />
                                @endif

                                @if ($canCreate)
                                    <x-ui.icon-button icon="clipboard-document-check" label="Add an assignment blueprint to {{ $topic->title }}"
                                                      x-on:click="$dispatch('open-modal', 'add-blueprint-{{ $topic->id }}')" />
                                @endif

                                @if ($canToggle)
                                    <form method="POST" action="{{ route('admin.course-outline.toggle', [$course, 'topic', $topic->id]) }}">
                                        @csrf
                                        <input type="hidden" name="active" value="{{ $topic->is_active ? 0 : 1 }}">
                                        <x-ui.icon-button type="submit" :icon="$topic->is_active ? 'eye-slash' : 'eye'"
                                                          :label="($topic->is_active ? 'Switch off ' : 'Switch on ').$topic->title" />
                                    </form>
                                @endif

                                @if ($canDelete)
                                    @can('delete', $topic)
                                        <x-ui.confirm :action="route('admin.course-topics.destroy', $topic)"
                                                      title="Remove {{ $topic->title }}?"
                                                      message="Its lectures, resources and blueprints go with it."
                                                      confirm-label="Remove it">
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" label="Remove {{ $topic->title }}" variant="danger" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @else
                                        <span title="This topic has been covered or marked against — switch it off instead, which keeps the history and takes it out of every denominator">
                                            <x-ui.icon-button icon="trash" label="Cannot remove {{ $topic->title }}" :disabled="true" />
                                        </span>
                                    @endcan
                                @endif
                            </div>
                        </div>

                        <div x-show="open('t{{ $topic->id }}')" x-cloak class="mt-3 space-y-2 pl-6">
                            @forelse ($topic->lectures as $lecture)
                                <div class="flex items-center justify-between gap-3 rounded border border-slate-100 px-3 py-2 dark:border-slate-800"
                                     data-lecture-row data-lecture-id="{{ $lecture->id }}" data-parent-id="{{ $topic->id }}"
                                     draggable="{{ $canEdit ? 'true' : 'false' }}"
                                     x-on:dragstart.stop="pick($event, 'lecture')"
                                     x-on:dragover.prevent.stop="over($event, 'lecture')"
                                     x-on:drop.prevent.stop="drop($event)">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <x-ui.icon :name="$lecture->lecture_type->icon()" class="h-4 w-4 shrink-0 text-slate-400" />
                                        <span class="truncate text-sm text-slate-700 dark:text-slate-200">{{ $lecture->title }}</span>
                                        @if ($lecture->is_preview)
                                            <x-ui.badge color="emerald" size="xs">free preview</x-ui.badge>
                                        @endif
                                        @unless ($lecture->is_active)
                                            <x-ui.badge color="slate" size="xs">off</x-ui.badge>
                                        @endunless
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($lecture->duration_minutes)
                                            <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">
                                                {{ $lecture->duration_minutes }}m
                                            </span>
                                        @endif

                                        @if ($canDelete)
                                            @can('delete', $lecture)
                                                <x-ui.confirm :action="route('admin.course-lectures.destroy', $lecture)"
                                                              title="Remove {{ $lecture->title }}?"
                                                              message="Nothing has been taught from it."
                                                              confirm-label="Remove it">
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="trash" size="sm" label="Remove {{ $lecture->title }}" variant="danger" />
                                                    </x-slot:trigger>
                                                </x-ui.confirm>
                                            @else
                                                <span title="A class session taught this lecture — it stays">
                                                    <x-ui.icon-button icon="trash" size="sm" label="Cannot remove" :disabled="true" />
                                                </span>
                                            @endcan
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-slate-400">No lectures under this topic yet.</p>
                            @endforelse

                            @if ($topic->resources->isNotEmpty())
                                <div class="pt-2">
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Resources</p>
                                    @foreach ($topic->resources as $resource)
                                        <div class="flex items-center justify-between gap-3 py-1">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <x-ui.icon :name="$resource->type->icon()" class="h-4 w-4 shrink-0 text-slate-400" />
                                                @if ($resource->isLink() && $resource->external_url)
                                                    <a href="{{ $resource->external_url }}" target="_blank" rel="noopener noreferrer"
                                                       class="truncate text-sm text-sky-700 hover:underline dark:text-sky-400">{{ $resource->title }}</a>
                                                @elseif ($resource->file_path)
                                                    <a href="{{ route('admin.course-resources.download', $resource) }}"
                                                       class="truncate text-sm text-sky-700 hover:underline dark:text-sky-400">{{ $resource->title }}</a>
                                                @else
                                                    <span class="truncate text-sm text-slate-600 dark:text-slate-300">{{ $resource->title }}</span>
                                                @endif
                                                @if ($resource->is_public)
                                                    <x-ui.badge color="sky" size="xs">public</x-ui.badge>
                                                @endif
                                                @if ($resource->humanSize())
                                                    <span class="text-xs text-slate-400">{{ $resource->humanSize() }}</span>
                                                @endif
                                            </div>

                                            @if ($canDelete)
                                                <x-ui.confirm :action="route('admin.course-resources.destroy', $resource)"
                                                              title="Remove {{ $resource->title }}?"
                                                              message="It disappears from the syllabus and, if it was public, from the course page."
                                                              confirm-label="Remove it">
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="trash" size="sm" label="Remove {{ $resource->title }}" variant="danger" />
                                                    </x-slot:trigger>
                                                </x-ui.confirm>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($topic->assignmentBlueprints->isNotEmpty())
                                <div class="pt-2">
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Assignment blueprints</p>
                                    @foreach ($topic->assignmentBlueprints as $blueprint)
                                        <div class="flex items-center justify-between gap-3 py-1">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <x-ui.icon name="clipboard-document-check" class="h-4 w-4 shrink-0 text-slate-400" />
                                                <span class="truncate text-sm text-slate-600 dark:text-slate-300">{{ $blueprint->title }}</span>
                                                @if ($blueprint->estimated_marks !== null)
                                                    <span class="text-xs tabular-nums text-slate-400">{{ $blueprint->estimated_marks }} marks</span>
                                                @endif
                                            </div>

                                            @if ($canDelete)
                                                <x-ui.confirm :action="route('admin.course-topic-assignments.destroy', $blueprint)"
                                                              title="Remove {{ $blueprint->title }}?"
                                                              message="A blueprint is curriculum — no submission or mark is attached to it."
                                                              confirm-label="Remove it">
                                                    <x-slot:trigger>
                                                        <x-ui.icon-button icon="trash" size="sm" label="Remove {{ $blueprint->title }}" variant="danger" />
                                                    </x-slot:trigger>
                                                </x-ui.confirm>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-slate-400">No topics in this module yet.</p>
                @endforelse
            </div>
        </div>
    @empty
        <x-ui.empty-state icon="list-bullet"
                          title="This course has no outline yet"
                          message="A course cannot be published without at least one module — the outline is what a visitor reads before applying." />
    @endforelse

    @if ($canEdit && $course->modules->isNotEmpty())
        <form method="POST" action="{{ route('admin.course-outline.reorder', $course) }}"
              class="flex items-center justify-end gap-3"
              x-ref="reorderForm" x-on:submit.prevent="submitOrder($event)">
            @csrf
            <input type="hidden" name="level" x-ref="level">
            <input type="hidden" name="parent_id" x-ref="parent">
            <p class="text-xs text-slate-500 dark:text-slate-400" x-show="dirty" x-cloak>
                The order on screen has changed.
            </p>
            <x-ui.button type="submit" size="sm" variant="secondary" icon="bars-3" x-show="dirty" x-cloak>
                Save the order
            </x-ui.button>
        </form>
    @endif

    {{-- Duplicate-module posts, kept out of the row so a nested form is never rendered. --}}
    @if ($canCreate)
        @foreach ($course->modules as $module)
            <form method="POST" action="{{ route('admin.course-modules.duplicate', $module) }}"
                  id="duplicate-module-{{ $module->id }}" class="hidden">
                @csrf
            </form>
        @endforeach
    @endif
</div>

@include('admin.courses._outline-modals')

@push('scripts')
    <script>
        /*
         * The outline tree's drag, collapse and reorder behaviour.
         *
         * Dragging rearranges the DOM and nothing else; the order is read off it when Save is pressed.
         * A drop is refused on the client when it would cross levels or parents — and the server
         * re-verifies every id anyway (§6.3), because the client's word is never the control.
         */
        function courseOutline() {
            return {
                dirty: false,
                dragged: null,
                draggedLevel: null,
                collapsed: {},

                open(key) {
                    return this.collapsed[key] !== true;
                },

                toggle(key) {
                    this.collapsed[key] = ! (this.collapsed[key] === true);
                },

                collapseAll() {
                    this.$el.querySelectorAll('[data-module-row]').forEach((row) => {
                        this.collapsed['m' + row.dataset.moduleId] = true;
                    });
                    this.$el.querySelectorAll('[data-topic-row]').forEach((row) => {
                        this.collapsed['t' + row.dataset.topicId] = true;
                    });
                },

                pick(event, level) {
                    this.dragged = event.target.closest('[data-' + level + '-row]');
                    this.draggedLevel = level;
                    event.dataTransfer.effectAllowed = 'move';
                },

                over(event, level) {
                    // Same level, same parent, or nothing happens: a lecture never becomes a module.
                    if (! this.dragged || level !== this.draggedLevel) return;

                    const row = event.target.closest('[data-' + level + '-row]');
                    if (! row || row === this.dragged) return;
                    if (row.dataset.parentId !== this.dragged.dataset.parentId) return;

                    const before = row.compareDocumentPosition(this.dragged) & Node.DOCUMENT_POSITION_FOLLOWING;
                    row.parentNode.insertBefore(this.dragged, before ? row : row.nextSibling);

                    this.dirty = true;
                    this.pendingLevel = level;
                    this.pendingParent = row.dataset.parentId ?? row.dataset.moduleId;
                },

                drop() {
                    this.dragged = null;
                    this.draggedLevel = null;
                },

                submitOrder(event) {
                    const form = event.target;
                    const level = this.pendingLevel ?? 'module';

                    form.querySelectorAll('input[name="order[]"]').forEach((node) => node.remove());

                    this.$refs.level.value = level;
                    this.$refs.parent.value = level === 'module'
                        ? '{{ $course->id }}'
                        : (this.pendingParent ?? '');

                    this.$el.querySelectorAll('[data-' + level + '-row]').forEach((row) => {
                        const id = row.dataset[level + 'Id'];
                        const parent = row.dataset.parentId;

                        if (level !== 'module' && parent !== this.$refs.parent.value) return;

                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'order[]';
                        input.value = id;
                        form.appendChild(input);
                    });

                    form.submit();
                },

                submitForm(id) {
                    document.getElementById(id)?.submit();
                },
            };
        }
    </script>
@endpush
