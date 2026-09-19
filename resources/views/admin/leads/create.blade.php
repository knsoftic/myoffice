@extends('layouts.admin')

@section('title', 'Add lead')

{{--
    Add a lead — admin.leads.create → POST admin.leads.store (phase-05 §8.4).

    Controller variables (Admin\LeadController@create): $lead (a fresh App\Models\Crm\Lead), and the form's variables —
    $serviceOptions, $sourceOptions, $assigneeOptions, $followUpTypeOptions, $followUpDefaultAt, $defaultSource,
    $autoAssignMode, $duplicateCheckEnabled, $duplicateBlockOnExact, $referralCode (see admin/leads/partials/form).
--}}

@section('header')
    <x-ui.page-header title="Add lead" subtitle="Capture who they are, what they want and when to talk to them next." icon="user-plus" :back="route('admin.leads.index')" />
@endsection

@section('content')
    @include('admin.leads.partials.form', ['lead' => $lead ?? new \App\Models\Crm\Lead()])
@endsection
