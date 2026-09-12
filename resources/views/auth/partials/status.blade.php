{{--
    Inline status panel for the auth screens.

    Controllers in app/Http/Controllers/Auth flash `auth_status` (not Laravel's conventional
    `status`) on purpose: `status` is also picked up by components/ui/toast, which would show the
    same sentence twice — once here and once as a toast. Sentinel values from Breeze are
    translated to real sentences below.

        return back()->with('auth_status', __($status));
--}}

@php
    $authStatus = session('auth_status');

    $authStatusMessages = [
        'verification-link-sent' => 'A new verification link has been sent to your email address.',
    ];

    $authStatusMessage = is_string($authStatus) && trim($authStatus) !== ''
        ? ($authStatusMessages[$authStatus] ?? $authStatus)
        : null;
@endphp

@if ($authStatusMessage)
    <div
        class="mb-5 flex items-start gap-2.5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/25"
        role="status"
    >
        <x-ui.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0" />
        <span>{{ $authStatusMessage }}</span>
    </div>
@endif
