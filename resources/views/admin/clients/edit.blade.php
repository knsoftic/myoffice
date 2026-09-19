@extends('layouts.admin')

@section('title', 'Edit '.($client->display_name ?? $client->name))

{{--
    Edit a client — admin.clients.edit → PUT admin.clients.update (phase-05 §6.7). ClientPolicy::update has passed. The
    update never touches client_code, user_id, status or portal_enabled.

    Controller variables (Admin\ClientController@edit): $client, $clientTypeOptions, $sourceOptions, $defaultCurrency,
    $defaultPaymentTermsDays, $defaultTaxRate (see admin/clients/partials/form).
--}}

@section('header')
    <x-ui.page-header :title="'Edit '.($client->display_name ?? $client->name)" :subtitle="$client->client_code" icon="pencil" :back="route('admin.clients.show', $client)">
        <div class="mt-2">@include('admin.crm.partials.enum-badge', ['value' => $client->status])</div>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.clients.partials.form', ['client' => $client])
@endsection
