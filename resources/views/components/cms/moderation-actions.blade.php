@props([
    'record' => null,
    'module' => 'testimonials',
    'routePrefix' => 'admin.testimonials',
    'label' => null,
    'size' => 'sm',
    'withText' => false,
])

{{--
    <x-cms.moderation-actions> — Approve / Reject / Feature for one moderatable row, shared by the
    testimonial and student-review queues (phase-04 §8.13, §8.5, §6.5).

        <x-cms.moderation-actions :record="$testimonial" module="testimonials" route-prefix="admin.testimonials" :label="$testimonial->author_name" />
        <x-cms.moderation-actions :record="$review" module="student_reviews" route-prefix="admin.student-reviews" :label="$review->student_name" :with-text="true" />

    Routes (phase-04 §7.2): POST {prefix}.approve, POST {prefix}.reject (`reason` required), POST
    {prefix}.featured. Each button renders only for a user holding `{module}.approve`, `{module}.reject` or
    `{module}.change_status`, and the route re-checks the same permission — a hidden button is not the guard.

      · Approve is one click (it never changes the review text, §6.5 invariant 4).
      · Reject opens ONE shared dialog (pushed once per page to the `modals` stack) that REQUIRES a reason;
        the server rejects an empty one with 422 as well.
      · Feature is disabled with a tooltip on anything not Approved — the service refuses it too (§6.5.5).
--}}

@php
    use Illuminate\Support\Facades\Route;

    $status = $record?->status;
    $statusValue = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    $isApproved = $statusValue === 'approved';
    $isRejected = $statusValue === 'rejected';
    $isFeatured = (bool) ($record?->is_featured ?? false);
    $name = (string) ($label ?? ('#'.$record?->getKey()));
    $trashed = $record !== null && method_exists($record, 'trashed') && $record->trashed();

    $canApprove = ! $trashed && ! $isApproved && Route::has($routePrefix.'.approve') && auth()->user()?->can($module.'.approve');
    $canReject = ! $trashed && ! $isRejected && Route::has($routePrefix.'.reject') && auth()->user()?->can($module.'.reject');
    $canFeature = ! $trashed && Route::has($routePrefix.'.featured') && auth()->user()?->can($module.'.change_status');
@endphp

@if ($record !== null)
    <div {{ $attributes->class('flex items-center justify-end gap-1') }}>
        @if ($canApprove)
            <form method="POST" action="{{ route($routePrefix.'.approve', $record) }}">
                @csrf
                @if ($withText)
                    <x-ui.button type="submit" variant="success" :size="$size" icon="check">Approve</x-ui.button>
                @else
                    <x-ui.icon-button type="submit" icon="check-circle" :size="$size" label="Approve {{ $name }}" class="text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10" />
                @endif
            </form>
        @endif

        @if ($canReject)
            @if ($withText)
                <x-ui.button
                    variant="secondary"
                    :size="$size"
                    icon="x-circle"
                    x-on:click="$dispatch('open-modal', { name: 'moderation-reject', url: @js(route($routePrefix.'.reject', $record)), label: @js($name) })"
                >Reject</x-ui.button>
            @else
                <x-ui.icon-button
                    icon="x-circle"
                    :size="$size"
                    variant="danger"
                    label="Reject {{ $name }}"
                    x-on:click="$dispatch('open-modal', { name: 'moderation-reject', url: @js(route($routePrefix.'.reject', $record)), label: @js($name) })"
                />
            @endif
        @endif

        @if ($canFeature)
            @if ($isApproved)
                <form method="POST" action="{{ route($routePrefix.'.featured', $record) }}">
                    @csrf
                    <x-ui.icon-button
                        type="submit"
                        icon="star"
                        :size="$size"
                        :label="$isFeatured ? 'Remove '.$name.' from featured' : 'Feature '.$name"
                        @class(['text-amber-500 dark:text-amber-300' => $isFeatured])
                    />
                </form>
            @else
                <span title="Only an approved review can be featured.">
                    <x-ui.icon-button icon="star" :size="$size" label="Only an approved review can be featured" :disabled="true" />
                </span>
            @endif
        @endif
    </div>

    @once
        @push('modals')
            <x-ui.modal name="moderation-reject" title="Reject this review?" icon="x-circle">
                <form
                    id="moderation-reject-form"
                    method="POST"
                    x-data="{ url: '', label: '' }"
                    x-on:open-modal.window="if ($event.detail?.name === 'moderation-reject') { url = $event.detail.url; label = $event.detail.label; $nextTick(() => $refs.reason?.focus()); }"
                    x-bind:action="url"
                    class="space-y-3"
                >
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>
                        is kept out of every public page. The review text is not changed, and the reason is stored and recorded in the activity log.
                    </p>
                    <div>
                        <label for="moderation-reject-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                            Reason <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            id="moderation-reject-reason"
                            x-ref="reason"
                            name="reason"
                            rows="3"
                            required
                            maxlength="255"
                            class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                        >{{ old('reason') }}</textarea>
                        <x-ui.form.error for="reason" />
                    </div>
                </form>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'moderation-reject')">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="moderation-reject-form" variant="danger" icon="x-circle">Reject</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endpush
    @endonce
@endif
