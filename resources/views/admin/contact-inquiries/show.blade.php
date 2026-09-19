@extends('layouts.admin')

@section('title', 'Inquiry')

{{--
    One contact inquiry — admin.contact-inquiries.show (phase-04 §8.10 "Detail screen", §6.10, §9.1.2, F-12.4).
    ContactInquiryPolicy::view() has passed; opening it marks a `new` inquiry read (ContactInquiryService::markRead).

    Controller variables (Admin\ContactInquiryController@show):
      $inquiry           App\Models\Cms\ContactInquiry with service, assignee, reader. The technical block
                         (ContactInquiry::TECHNICAL_COLUMNS — ip_address, user_agent, utm_*, referrer_url,
                         filled_in_seconds, spam_reason) is SELECTED ONLY for a user holding contact_inquiries.view_logs;
                         for everyone else those columns are absent from the model and therefore from this page (and the
                         metadata panel is not rendered). Viewing it writes the §10.5 sensitive-access activity entry.
      $showTechnical     bool
      $routedRecord      ?Model   ContactInquiry::routedRecord() — null when nothing was created or the class is absent
      $canRoute          bool     InquiryRouter::canRoute()
      $waitingReason     ?string  InquiryRouter::waitingReason()
      $targets           array<string, array{label: string, registered: bool, available: bool}>
      $history           list<array{id: int, event: ?string, description: string, causer: ?string, at: Carbon}>  newest first
      $statusOptions     array<string, string>
      $assignees         array<int, string>   (empty without contact_inquiries.assign)
      $can               array<string, bool>

    Writes: PUT admin.contact-inquiries.update {inquiry} response_notes + status; POST .route / .status / .assign /
    .spam / .not-spam; DELETE .destroy.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $canViewLogs = (bool) ($showTechnical ?? $user?->can('contact_inquiries.view_logs'));

    // The created lead / course inquiry, when the controller resolved one (ContactInquiry::routedRecord()).
    $routed = $routed ?? null;
    if ($routed === null && isset($routedRecord) && $routedRecord instanceof \Illuminate\Database\Eloquent\Model) {
        $routed = [
            'label' => \Illuminate\Support\Str::headline(class_basename($routedRecord)).' #'.$routedRecord->getKey(),
            'url' => null,
            'missing' => method_exists($routedRecord, 'trashed') && $routedRecord->trashed(),
        ];
    } elseif ($routed === null && filled($inquiry->routed_id)) {
        $routed = ['label' => 'Record #'.$inquiry->routed_id, 'url' => null, 'missing' => true];
    }
    $canEdit = (bool) $user?->can('contact_inquiries.edit') && Route::has('admin.contact-inquiries.update');
    $canStatus = (bool) $user?->can('contact_inquiries.change_status');
    $canAssign = (bool) $user?->can('contact_inquiries.assign') && Route::has('admin.contact-inquiries.assign');
    $canDelete = (bool) $user?->can('contact_inquiries.delete');

    $inquiryAttributes = $inquiry->getAttributes();
    $service = $inquiry->relationLoaded('service') ? $inquiry->service : null;
    $assignee = null;
    foreach (['assignee', 'assignedTo'] as $relationName) {
        if ($inquiry->relationLoaded($relationName)) {
            $assignee = $inquiry->getRelation($relationName);
            break;
        }
    }
    $readBy = null;
    foreach (['reader', 'readBy'] as $relationName) {
        if ($inquiry->relationLoaded($relationName)) {
            $readBy = $inquiry->getRelation($relationName);
            break;
        }
    }
    $typeValue = $inquiry->inquiry_type instanceof \BackedEnum ? $inquiry->inquiry_type->value : (string) $inquiry->inquiry_type;
    $statusValue = $inquiry->status instanceof \BackedEnum ? $inquiry->status->value : (string) $inquiry->status;
    $history = collect($history ?? []);
    $digits = static fn (?string $phone): string => preg_replace('/[^\d+]/', '', (string) $phone);
    $safeUrl = static fn (?string $url): ?string => is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;

    $technical = [
        'IP address' => $inquiryAttributes['ip_address'] ?? null,
        'Device' => $inquiryAttributes['user_agent'] ?? null,
        'Page' => $inquiryAttributes['page_url'] ?? null,
        'Referrer' => $inquiryAttributes['referrer_url'] ?? null,
        'UTM source' => $inquiryAttributes['utm_source'] ?? null,
        'UTM medium' => $inquiryAttributes['utm_medium'] ?? null,
        'UTM campaign' => $inquiryAttributes['utm_campaign'] ?? null,
        'Filled in' => array_key_exists('filled_in_seconds', $inquiryAttributes) && $inquiryAttributes['filled_in_seconds'] !== null ? app_number((int) $inquiryAttributes['filled_in_seconds']).((int) $inquiryAttributes['filled_in_seconds'] === 1 ? ' second' : ' seconds') : null,
    ];
    // Present only when the controller selected the technical columns, which it does only for view_logs holders.
    $hasTechnical = $canViewLogs && collect(['ip_address', 'user_agent', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'filled_in_seconds'])
        ->contains(static fn (string $column): bool => array_key_exists($column, $inquiryAttributes));
@endphp

@section('header')
    <x-ui.page-header :title="$inquiry->name" :subtitle="$inquiry->subject ?: 'Contact inquiry'" icon="inbox-stack" :back="route('admin.contact-inquiries.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $inquiry->inquiry_type, 'dot' => false])
            @include('admin.marketing.partials.enum-badge', ['value' => $inquiry->status])
            @include('admin.marketing.partials.enum-badge', ['value' => $inquiry->source, 'dot' => false, 'variant' => 'outline'])
            @if ($inquiry->is_spam)
                <x-ui.badge color="rose" size="sm" icon="exclamation-triangle">Spam</x-ui.badge>
            @endif
            <span class="text-xs text-slate-500 dark:text-slate-400">Received {{ app_datetime($inquiry->created_at) }}</span>
        </div>

        <x-slot:actions>
            @if ($canStatus && $inquiry->is_spam && Route::has('admin.contact-inquiries.not-spam'))
                <form method="POST" action="{{ route('admin.contact-inquiries.not-spam', $inquiry) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="check-circle">Not spam</x-ui.button>
                </form>
            @elseif ($canStatus && Route::has('admin.contact-inquiries.spam'))
                <x-ui.button variant="secondary" icon="exclamation-triangle" x-on:click="$dispatch('open-modal', { name: 'inquiry-spam', url: @js(route('admin.contact-inquiries.spam', $inquiry)), label: @js($inquiry->name) })">Mark spam</x-ui.button>
            @endif

            @if ($canDelete)
                <x-ui.confirm
                    :action="route('admin.contact-inquiries.destroy', $inquiry)"
                    :title="'Delete the inquiry from '.$inquiry->name.'?'"
                    message="It moves to the trash. A lead or course inquiry already created from it is not touched."
                    confirm-label="Delete inquiry"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete inquiry" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        <div class="space-y-6 xl:col-span-3">
            <x-ui.card title="The submission, as received" icon="envelope">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Email</dt>
                        <dd><a href="mailto:{{ $inquiry->email }}" class="break-all font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $inquiry->email }}</a></dd>
                    </div>
                    @if (filled($inquiry->phone))
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Phone</dt>
                            <dd><a href="tel:{{ $digits($inquiry->phone) }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $inquiry->phone }}</a></dd>
                        </div>
                    @endif
                    @if (filled($inquiry->whatsapp))
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">WhatsApp</dt>
                            <dd><a href="https://wa.me/{{ ltrim($digits($inquiry->whatsapp), '+') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 font-medium text-emerald-700 hover:underline dark:text-emerald-400"><x-ui.icon name="whatsapp" class="h-4 w-4" /> {{ $inquiry->whatsapp }}</a></dd>
                        </div>
                    @endif
                    @if (filled($inquiry->company))
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Company</dt>
                            <dd class="text-slate-900 dark:text-white">{{ $inquiry->company }}</dd>
                        </div>
                    @endif
                    @if ($typeValue === 'service')
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Service</dt>
                            <dd class="text-slate-900 dark:text-white">{{ $service?->name ?? 'A service that has since been removed' }}</dd>
                        </div>
                    @elseif ($typeValue === 'course')
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Course</dt>
                            <dd class="text-slate-900 dark:text-white">{{ $inquiry->course_name ?: '—' }}</dd>
                        </div>
                    @endif
                    @if (filled($inquiry->budget))
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Budget</dt>
                            <dd class="text-slate-900 dark:text-white">{{ $inquiry->budget }}</dd>
                        </div>
                    @endif
                    @if (filled($inquiry->referral_code))
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Referral code (as captured)</dt>
                            <dd class="font-mono text-slate-900 dark:text-white" title="A display snapshot only: it grants nobody access and pays no commission.">{{ $inquiry->referral_code }}</dd>
                        </div>
                    @endif
                </dl>

                @if (filled($inquiry->subject))
                    <p class="mt-5 text-sm font-semibold text-slate-900 dark:text-white">{{ $inquiry->subject }}</p>
                @endif
                {{-- Escaped, never rendered as HTML: the message is visitor input (§8.10, test 54). --}}
                <div class="mt-2 whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50/60 p-4 text-sm leading-relaxed text-slate-700 dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-200">{{ $inquiry->message }}</div>
            </x-ui.card>

            @if ($canEdit)
                <x-ui.card title="Response notes" subtitle="Internal. Never sent to the visitor." icon="pencil">
                    <form method="POST" action="{{ route('admin.contact-inquiries.update', $inquiry) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <x-ui.form.textarea name="response_notes" label="Notes" :value="$inquiry->response_notes" :rows="5" maxlength="5000" placeholder="What was agreed, who replied, next steps…" />
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <x-ui.form.select name="status" label="Status" :options="$statusOptions ?? []" :selected="$statusValue" class="sm:max-w-xs" />
                            <x-ui.button type="submit" icon="check">Save</x-ui.button>
                        </div>
                        @if ($inquiry->responded_at)
                            <p class="text-xs text-slate-500 dark:text-slate-400">Responded {{ app_datetime($inquiry->responded_at) }}.</p>
                        @endif
                    </form>
                </x-ui.card>
            @elseif (filled($inquiry->response_notes))
                <x-ui.card title="Response notes" icon="pencil">
                    <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $inquiry->response_notes }}</p>
                </x-ui.card>
            @endif

            @if ($hasTechnical)
                <x-ui.card title="Technical details" subtitle="Visible to you because you hold the inquiry logs permission. This view is recorded." icon="finger-print">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        @foreach ($technical as $label => $value)
                            <div class="min-w-0">
                                <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                <dd class="break-words text-slate-900 dark:text-white">
                                    @if (in_array($label, ['Page', 'Referrer'], true) && $safeUrl($value))
                                        <span class="font-mono text-xs">{{ \Illuminate\Support\Str::limit((string) $value, 120) }}</span>
                                    @else
                                        <span @class(['font-mono text-xs' => $label !== 'Filled in'])>{{ filled($value) ? $value : '—' }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Spam verdict</dt>
                            <dd class="text-slate-900 dark:text-white">
                                @if ($inquiry->is_spam)
                                    Spam — {{ \Illuminate\Support\Str::headline((string) ($inquiryAttributes['spam_reason'] ?? 'flagged')) }}
                                @else
                                    Passed every check
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>
            @elseif (filled($inquiryAttributes['page_url'] ?? null))
                <p class="text-xs text-slate-500 dark:text-slate-400">Sent from {{ \Illuminate\Support\Str::limit((string) $inquiryAttributes['page_url'], 120) }}</p>
            @endif
        </div>

        <div class="space-y-6 xl:col-span-2">
            <x-ui.card title="Handling" icon="user">
                <div class="space-y-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Assigned to</p>
                            @if ($assignee)
                                <div class="mt-1 flex items-center gap-2">
                                    <x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="sm" />
                                    <span class="truncate font-medium text-slate-900 dark:text-white">{{ $assignee->name }}</span>
                                </div>
                            @else
                                <p class="mt-1 text-slate-400">Nobody</p>
                            @endif
                        </div>
                        @if ($canAssign)
                            <x-ui.button variant="secondary" size="sm" icon="user-plus" x-on:click="$dispatch('open-modal', { name: 'inquiry-assign', url: @js(route('admin.contact-inquiries.assign', $inquiry)), label: @js($inquiry->name), current: @js($inquiry->assigned_to) })">{{ $assignee ? 'Reassign' : 'Assign' }}</x-ui.button>
                        @endif
                    </div>

                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Status</p>
                            <div class="mt-1">@include('admin.marketing.partials.enum-badge', ['value' => $inquiry->status])</div>
                            @if ($inquiry->read_at)
                                <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Read {{ app_datetime($inquiry->read_at) }}{{ $readBy ? ' by '.$readBy->name : '' }}</p>
                            @endif
                        </div>
                        @if ($canStatus && Route::has('admin.contact-inquiries.status'))
                            <x-ui.button variant="secondary" size="sm" icon="check-badge" x-on:click="$dispatch('open-modal', { name: 'inquiry-status', url: @js(route('admin.contact-inquiries.status', $inquiry)), label: @js($inquiry->name), current: @js($statusValue) })">Change</x-ui.button>
                        @endif
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Routing" icon="arrow-right">
                <div class="space-y-4">
                    @include('admin.contact-inquiries.partials.routing-cell', [
                        'inquiry' => $inquiry,
                        'targets' => $targets ?? [],
                        'rowRouting' => isset($canRoute) ? ['canRoute' => (bool) $canRoute, 'waitingReason' => $waitingReason ?? null] : null,
                        'routed' => $routed,
                        'withActions' => true,
                    ])

                    @php
                        $routingValue = $inquiry->routing_status instanceof \BackedEnum ? $inquiry->routing_status->value : (string) $inquiry->routing_status;
                    @endphp
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        @if ($inquiry->is_spam)
                            Spam is stored for tuning but never routed. “Not spam” sends it on its way.
                        @elseif ($typeValue === 'general')
                            General inquiries are handled here; they do not create a lead or a course inquiry.
                        @elseif ($routingValue === 'routed')
                            A {{ $typeValue === 'course' ? 'course inquiry' : 'lead' }} was created from this inquiry. The inquiry itself is kept unchanged.
                        @else
                            A {{ $typeValue === 'course' ? 'course inquiry is' : 'lead is' }} created automatically once the {{ $typeValue === 'course' ? 'institute' : 'CRM' }} module exists and is enabled. Nothing is lost while it waits.
                        @endif
                    </p>
                </div>
            </x-ui.card>

            <x-ui.card title="History" icon="clock">
                @if ($history->isEmpty())
                    <x-ui.empty-state icon="clock" title="Nothing has happened yet" message="Routing attempts, status changes and assignments are recorded here." :compact="true" />
                @else
                    <ol class="relative space-y-5 border-l border-slate-200 pl-5 dark:border-slate-800">
                        @foreach ($history as $entry)
                            @php
                                // {id, event, description, causer (name), at} arrays from the controller, or Activity models.
                                $causerName = $entry instanceof \Illuminate\Database\Eloquent\Model
                                    ? ($entry->relationLoaded('causer') ? $entry->causer?->getAttribute('name') : null)
                                    : data_get($entry, 'causer');
                                $entryAt = data_get($entry, 'at') ?? data_get($entry, 'created_at');
                                $entryReason = data_get($entry, 'reason');
                            @endphp
                            <li class="relative">
                                <span class="absolute -left-[1.6rem] top-1 flex h-2.5 w-2.5 rounded-full bg-brand-500 ring-4 ring-white dark:bg-brand-400 dark:ring-slate-900" aria-hidden="true"></span>
                                <p class="text-sm text-slate-900 dark:text-white">{{ \Illuminate\Support\Str::ucfirst((string) (data_get($entry, 'description') ?: \Illuminate\Support\Str::headline((string) data_get($entry, 'event')))) }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ filled($causerName) ? $causerName : 'System' }} · {{ app_datetime($entryAt) }}</p>
                                @if (filled($entryReason))
                                    <p class="mt-1 text-xs italic text-slate-600 dark:text-slate-300">“{{ $entryReason }}”</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>

    @include('admin.contact-inquiries.partials.dialogs', ['assigneeOptions' => $assignees ?? ($assigneeOptions ?? []), 'statusOptions' => $statusOptions ?? null])
@endsection
