{{--
    The routing cell — "the heart of the screen" (phase-04 §8.10, §6.10.2, §6.10.3). Exactly one of:
      · "Lead #123" / "Course inquiry #45" (a link when a later phase supplies one)
      · an amber "Awaiting CRM" / "Awaiting Institute" / "Module disabled" chip with the reason on hover
      · a rose "Routing failed (2 tries)" chip with the stored error
      · a slate "—" for a general inquiry (and for spam)
    plus, when `withActions` is true, the Route now / Retry button — disabled with a tooltip while the target is unavailable.

    @include('admin.contact-inquiries.partials.routing-cell', [
        'inquiry' => $inquiry,
        'targets' => $targets,        // array<string, array{label, registered, available}> — the controller's targets()
        'rowRouting' => $routing[$inquiry->id] ?? null,   // ?array{canRoute: bool, waitingReason: ?string} — InquiryRouter
        'routed' => null,             // ?array{label, url, missing} — optional link to the created record
        'withActions' => true,
    ])

    When `rowRouting` is present its answers are used verbatim (InquiryRouter::canRoute() / waitingReason() are the
    authority); otherwise the cell derives them from `targets` and the stored routing_error.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $targets = (array) ($targets ?? []);
    $rowRouting = is_array($rowRouting ?? null) ? $rowRouting : null;
    $routingStatus = $inquiry->routing_status instanceof \BackedEnum ? $inquiry->routing_status->value : (string) $inquiry->routing_status;
    $targetKey = (string) ($inquiry->routing_target ?? '');
    $error = (string) ($inquiry->routing_error ?? '');
    $attempts = (int) ($inquiry->routing_attempts ?? 0);
    $isSpam = (bool) $inquiry->is_spam;
    $routed = $routed ?? null;
    $withActions = (bool) ($withActions ?? false);

    $names = match ($targetKey) {
        'crm_lead' => ['awaiting' => 'Awaiting CRM', 'record' => 'Lead', 'install' => 'Lead module not installed yet', 'disabled' => 'Leads module is disabled'],
        'course_inquiry' => ['awaiting' => 'Awaiting Institute', 'record' => 'Course inquiry', 'install' => 'Course inquiry module not installed yet', 'disabled' => 'Course inquiries module is disabled'],
        default => ['awaiting' => 'Awaiting routing', 'record' => 'Record', 'install' => 'Target not installed yet', 'disabled' => 'Target module is disabled'],
    };

    $registered = $targetKey !== '' && (bool) ($targets[$targetKey]['registered'] ?? array_key_exists($targetKey, $targets));
    $available = $registered && (bool) ($targets[$targetKey]['available'] ?? false);

    $canRouteNow = $rowRouting !== null ? (bool) ($rowRouting['canRoute'] ?? false) : $available;
    $waitingReason = $rowRouting['waitingReason'] ?? match ($error) {
        'target_unregistered' => $names['install'],
        'module_disabled' => $names['disabled'],
        '' => null,
        default => $error,
    };
    $unavailableReason = $waitingReason ?? (! $registered ? $names['install'] : ($available ? null : $names['disabled']));

    $showButton = $withActions
        && ! $isSpam
        && in_array($routingStatus, ['pending', 'failed'], true)
        && (bool) auth()->user()?->can('contact_inquiries.change_status')
        && Route::has('admin.contact-inquiries.route');
@endphp

<div class="flex flex-col items-start gap-1.5">
    @if ($isSpam || $routingStatus === 'not_applicable' || $routingStatus === '')
        <span class="text-sm text-slate-400 dark:text-slate-500" title="{{ $isSpam ? 'Spam is never routed' : 'Handled in this queue' }}">—</span>
    @elseif ($routingStatus === 'routed')
        @php
            $label = $routed['label'] ?? ($names['record'].' #'.$inquiry->routed_id);
            $missing = (bool) ($routed['missing'] ?? false);
        @endphp
        @if ($missing)
            <x-ui.badge color="slate" size="sm" icon="exclamation-circle" title="The record was removed or its module is not installed.">{{ $label }} (unavailable)</x-ui.badge>
        @elseif (filled($routed['url'] ?? null))
            <a href="{{ $routed['url'] }}" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:underline dark:text-brand-300">
                <x-ui.icon name="check-circle" class="h-4 w-4 text-emerald-500" /> {{ $label }}
            </a>
        @else
            <x-ui.badge color="emerald" size="sm" icon="check-circle">{{ $label }}</x-ui.badge>
        @endif
        @if ($inquiry->routed_at)
            <span class="text-2xs text-slate-400 dark:text-slate-500">{{ app_datetime($inquiry->routed_at) }}</span>
        @endif
    @elseif ($routingStatus === 'failed')
        <x-ui.badge color="rose" size="sm" icon="exclamation-triangle" title="{{ $error !== '' ? $error : 'The target refused the inquiry.' }}" class="cursor-help">
            Routing failed ({{ app_number($attempts) }} {{ $attempts === 1 ? 'try' : 'tries' }})
        </x-ui.badge>
        @if ($error !== '')
            <span class="line-clamp-1 max-w-[14rem] text-2xs text-rose-600 dark:text-rose-400">{{ $error }}</span>
        @endif
    @else
        @php $chip = $error === 'module_disabled' ? 'Module disabled' : $names['awaiting']; @endphp
        <x-ui.badge color="amber" size="sm" icon="clock" title="{{ $waitingReason ?? 'Waiting for Route now' }}" class="cursor-help">{{ $chip }}</x-ui.badge>
        @if ($attempts > 0 && ! in_array($error, ['target_unregistered', 'module_disabled'], true))
            <span class="text-2xs text-amber-700 dark:text-amber-400">{{ app_number($attempts) }} {{ $attempts === 1 ? 'try' : 'tries' }} so far</span>
        @endif
    @endif

    @if ($showButton)
        @if ($canRouteNow)
            <form method="POST" action="{{ route('admin.contact-inquiries.route', $inquiry) }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="secondary" :icon="$routingStatus === 'failed' ? 'arrow-path' : 'arrow-right'">
                    {{ $routingStatus === 'failed' ? 'Retry' : 'Route now' }}
                </x-ui.button>
            </form>
        @else
            <span title="{{ $unavailableReason ?? 'The target is not available' }}">
                <x-ui.button size="sm" variant="secondary" icon="arrow-right" :disabled="true">Route now</x-ui.button>
            </span>
        @endif
    @endif
</div>
