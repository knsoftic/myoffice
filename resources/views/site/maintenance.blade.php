{{--
    `site.maintenance` — shown with HTTP 503 while `maintenance.maintenance_mode` is on (phase-03 §6.10,
    §8.14; phase-02 §6 "Maintenance").

    Receives (exactly what EnsurePublicSiteAvailable passes): $company, $heading, $message.
    The admin's `maintenance.maintenance_message` is printed as plain text. No sign-in link, no stack
    trace. See site/partials/holding-page for the full set of rules.
--}}

@include('site.partials.holding-page', ['variant' => 'maintenance'])
