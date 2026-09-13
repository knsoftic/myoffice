{{--
    `site.holding` — shown with HTTP 503 while `maintenance.public_site_enabled` is off (phase-03 §6.10,
    §8.14): the logo, the company name, a neutral sentence and the contact details.

    Receives: $company, $heading, $message (the names EnsurePublicSiteAvailable already passes). With no
    heading the page says "This website is currently unavailable."; with no message it shows only the
    heading and the contact details. See site/partials/holding-page for the full set of rules.
--}}

@include('site.partials.holding-page', ['variant' => 'holding'])
