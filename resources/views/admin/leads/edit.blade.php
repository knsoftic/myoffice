@extends('layouts.admin')

@section('title', 'Edit '.$lead->name)

{{--
    Edit a lead — admin.leads.edit → PUT admin.leads.update (phase-05 §8.4). LeadPolicy::update has passed.

    Controller variables (Admin\LeadController@edit): $lead (App\Models\Crm\Lead with `assignee`), $serviceOptions,
    $sourceOptions, $duplicateCheckEnabled, $duplicateBlockOnExact (see admin/leads/partials/form). The update never
    touches lead_no, status, assigned_to, client_id or converted_at.
--}}

@section('header')
    <x-ui.page-header :title="'Edit '.$lead->name" :subtitle="$lead->lead_no" icon="pencil" :back="route('admin.leads.show', $lead)">
        <div class="mt-2">@include('admin.crm.partials.enum-badge', ['value' => $lead->status])</div>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.leads.partials.form', ['lead' => $lead])
@endsection
