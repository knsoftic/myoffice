@extends('layouts.guest')

@section('title', 'Portal unavailable')

{{--
    The explanatory 403 page of EnsureClientContext / `client.context` (phase-05 §6.9). Rendered with status 403 on any
    /client request when the portal cannot be used — re-evaluated on every request, so revoking access takes effect at
    once, not at the next sign-in.

    Variables (App\Http\Middleware\EnsureClientContext):
      $reason    string  portal_off       crm.client_portal_enabled is false
                         no_context       the user is bound to no client and to no contact with portal access
                         portal_disabled  the client's portal_enabled is false
                         status           the client's status does not allow the portal (ClientStatus::canUsePortal())
      $message   ?string an optional sentence replacing the default one (never an internal detail)
--}}

@php
    $reason = in_array($reason ?? null, ['portal_off', 'no_context', 'portal_disabled', 'status'], true) ? $reason : 'no_context';
    $copy = [
        'portal_off' => ['The client portal is closed', 'The client portal is switched off for everyone at the moment. Please try again later.'],
        'no_context' => ['No client account is linked to this login', 'Your login is not connected to a client account yet. Please contact your account manager.'],
        'portal_disabled' => ['Portal access is switched off', 'Access to the portal has been switched off for your company. Please contact your account manager.'],
        'status' => ['Your account is not active', 'Your company\'s account is not active, so the portal cannot be used right now. Please contact your account manager.'],
    ][$reason];
@endphp

@section('heading')
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">{{ $copy[0] }}</h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ filled($message ?? null) ? $message : $copy[1] }}</p>
@endsection

@section('content')
    <div class="space-y-4">
        <div class="flex items-start gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
            <x-ui.icon name="lock-closed" class="mt-0.5 h-5 w-5 shrink-0" />
            <p>Nothing on your account has been deleted. As soon as access is restored, signing in again takes you straight back to your portal.</p>
        </div>

        @if (\Illuminate\Support\Facades\Route::has('logout'))
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="arrow-right-on-rectangle" :block="true">Sign out</x-ui.button>
            </form>
        @endif
    </div>
@endsection
