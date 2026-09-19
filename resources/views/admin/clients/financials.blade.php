@extends('layouts.admin')

@section('title', 'Financials · '.($client->display_name ?? $client->name))

{{--
    Client financials — admin.clients.financials {client} (phase-05 §7, §8.8). ClientPolicy::viewFinancial has passed;
    without clients.view_financial the route is a 403 and no figure is ever computed (test 58). With
    `Accept: application/json` the controller answers {client_id, summary} instead.

    Controller variables (Admin\ClientController@financials):
      $client            App\Models\Crm\Client
      $financialSummary  App\DataObjects\Crm\ClientFinancialSummary — ClientService::financialSummary(); see
                         admin/clients/partials/financials
--}}

@section('header')
    @include('admin.clients.partials.header', ['client' => $client, 'compact' => true])
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-ui.section-heading title="Financial summary" subtitle="Invoiced, paid, outstanding and overdue, each read from the module that owns the figure." icon="banknotes" />
            <x-ui.button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.clients.show', ['client' => $client, 'tab' => 'financials'])">Back to the client</x-ui.button>
        </div>

        @include('admin.clients.partials.financials', ['summary' => $financialSummary ?? null])
    </div>
@endsection
