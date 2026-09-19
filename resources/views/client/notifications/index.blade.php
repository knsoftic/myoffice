@extends('layouts.panel')

@section('title', 'Notifications')

{{--
    Client panel notifications — client.notifications.index (phase-05 §8.10 "Notifications", §9.2). Only the signed-in
    user's own rows: notifiable_type = User AND notifiable_id = auth()->id(). Mark-read re-asserts ownership (404 otherwise).

    Controller variables (Client\NotificationController@index → ServesClientPortal::sectionList('notifications'); this is
    App\Support\Portal\Sections\NotificationsSection::view()):
      $client          App\Models\Crm\Client
      $section         App\Contracts\Portal\ClientPortalSection (NotificationsSection)
      $items           LengthAwarePaginator<Illuminate\Notifications\DatabaseNotification>  newest first; an empty paginator
                       while the notifications table does not exist ($notifications is accepted too)
      $filters         array<string, mixed>
      $unreadCount     optional int  (the section's badgeCount); without it the tab shows no number
      $clientName, $portalSections   the shared portal data
    Query: unread (1), page.
    Each notification's `data` is read as: title (else the notification type as words), body (else message), url (only a
    same-site URL becomes a link).
    Writes: POST client.notifications.read {notification}; POST client.notifications.read-all.
--}}

@php
    $notifications = $notifications ?? ($items ?? null);
    $available = (bool) ($available ?? ($notifications !== null));
    $unreadCount = isset($unreadCount) ? (int) $unreadCount : null;
    $pageHasUnread = $notifications !== null && collect($notifications->items())->contains(static fn ($notification): bool => $notification->read_at === null);
    $unreadOnly = request()->boolean('unread');
    $appRoot = rtrim(url('/'), '/');
    $safeUrl = static function ($candidate) use ($appRoot): ?string {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            return $candidate;
        }

        return str_starts_with($candidate, $appRoot.'/') || $candidate === $appRoot ? $candidate : null;
    };
    $tabs = [
        ['label' => 'All', 'url' => route('client.notifications.index'), 'active' => ! $unreadOnly],
        ['label' => 'Unread', 'url' => route('client.notifications.index', ['unread' => 1]), 'active' => $unreadOnly, 'count' => $unreadCount !== null ? app_number($unreadCount) : null],
    ];
@endphp

@section('header')
    @include('client.partials.header', ['client' => $client, 'title' => 'Notifications', 'subtitle' => 'What has changed on your account.', 'icon' => 'bell'])
@endsection

@section('content')
    <div class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-ui.tabs :tabs="$tabs" variant="pill" class="w-fit" />
            @if ($available && (($unreadCount ?? 0) > 0 || $pageHasUnread) && \Illuminate\Support\Facades\Route::has('client.notifications.read-all'))
                <form method="POST" action="{{ route('client.notifications.read-all') }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary" icon="check">Mark all read</x-ui.button>
                </form>
            @endif
        </div>

        <x-ui.card :padded="false">
            @if (! $available || $notifications === null || $notifications->isEmpty())
                <x-ui.empty-state icon="bell" :title="$unreadOnly ? 'Nothing unread' : 'Nothing new'" :message="$unreadOnly ? 'You are all caught up.' : 'We will let you know here when something changes.'" />
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($notifications as $notification)
                        @php
                            $data = (array) ($notification->data ?? []);
                            $unread = $notification->read_at === null;
                            $title = (string) (data_get($data, 'title') ?: \Illuminate\Support\Str::headline(class_basename((string) $notification->type)));
                            $body = data_get($data, 'body') ?? data_get($data, 'message');
                            $link = $safeUrl(data_get($data, 'url'));
                        @endphp
                        <li @class(['flex gap-3 px-4 py-4 sm:px-5', 'bg-brand-50/40 dark:bg-brand-500/5' => $unread])>
                            <span @class([
                                'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                'bg-brand-500' => $unread,
                                'bg-transparent' => ! $unread,
                            ]) aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <p @class(['text-sm', 'font-semibold text-slate-900 dark:text-white' => $unread, 'font-medium text-slate-700 dark:text-slate-200' => ! $unread])>
                                        @if ($unread)<span class="sr-only">Unread: </span>@endif
                                        @if ($link)
                                            <a href="{{ $link }}" class="hover:text-brand-700 hover:underline dark:hover:text-brand-300">{{ $title }}</a>
                                        @else
                                            {{ $title }}
                                        @endif
                                    </p>
                                    <span class="text-xs text-slate-500 dark:text-slate-400" title="{{ app_datetime($notification->created_at) }}">{{ \App\Support\Format::forHumans($notification->created_at) }}</span>
                                </div>
                                @if (filled($body) && is_scalar($body))
                                    <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ $body }}</p>
                                @endif
                                @if ($unread && \Illuminate\Support\Facades\Route::has('client.notifications.read'))
                                    <form method="POST" action="{{ route('client.notifications.read', $notification->getKey()) }}" class="mt-2">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">Mark read</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="border-t border-slate-200 bg-slate-50/70 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/60">
                    <x-ui.pagination-summary :paginator="$notifications" label="notifications" />
                </div>
            @endif
        </x-ui.card>
    </div>
@endsection
