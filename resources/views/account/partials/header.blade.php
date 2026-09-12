{{--
    Shared header band for the /account screens: page heading plus the tab strip.

        @section('header')
            @include('account.partials.header', [
                'pageTitle' => 'My profile',
                'pageSubtitle' => 'How you appear across the system.',
            ])
        @endsection

    $accountTabs comes from App\Http\Controllers\Account\AccountController::tabs(), which drops
    any tab whose route is not registered.
--}}

<x-ui.page-header
    :title="$pageTitle ?? 'My account'"
    :subtitle="$pageSubtitle ?? null"
    :icon="$pageIcon ?? 'user-circle'"
/>

@if (! empty($accountTabs))
    <x-ui.tabs :tabs="$accountTabs" class="mt-5 -mb-5 sm:-mb-6" />
@endif
