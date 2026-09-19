{{--
    The client portal chip (phase-05 §8.7, §8.8): Enabled / Invited / Off.

    @include('admin.clients.partials.portal-chip', ['client' => $client, 'size' => 'sm'])

      Off      portal_enabled is false (or no portal login is bound)
      Invited  portal_enabled, and the bound login has never signed in
      Enabled  portal_enabled, and the bound login has signed in at least once
    `portalUser` should be eager-loaded with last_login_at; without it an enabled portal reads as Enabled.
--}}

@php
    $chipClient = $client;
    $chipSize = $size ?? 'sm';
    $chipUser = $chipClient->relationLoaded('portalUser') ? $chipClient->portalUser : null;

    if (! $chipClient->portal_enabled || blank($chipClient->user_id)) {
        [$chipLabel, $chipColor, $chipIcon] = ['Off', 'slate', 'lock-closed'];
    } elseif ($chipUser !== null && blank($chipUser->last_login_at ?? null)) {
        [$chipLabel, $chipColor, $chipIcon] = ['Invited', 'amber', 'envelope'];
    } else {
        [$chipLabel, $chipColor, $chipIcon] = ['Enabled', 'emerald', 'check-circle'];
    }
@endphp

<x-ui.badge :color="$chipColor" :size="$chipSize" :icon="$chipIcon" :title="$chipClient->portal_invited_at ? 'Invited '.app_datetime($chipClient->portal_invited_at) : null">Portal {{ strtolower($chipLabel) }}</x-ui.badge>
