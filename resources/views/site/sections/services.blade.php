{{--
    Section type `services` — declared by Phase 4 (phase-03 §6.1 "Section types declared by later
    phases"; default home sort 30). Phase 3 ships the partial so the type renders the moment Phase 4
    registers it; until then SectionRegistry does not know the key and the renderer never reaches
    this file.

    Receives the standard section contract ($section, $content, …); cards come from Phase 4's
    SectionDataProvider as `$section['provider']` (see site/sections/partials/teaser). With no
    published service yet it shows an honest empty state with the company's real contact details.
--}}

@include('site.sections.partials.teaser', [
    'typeKey' => 'services',
    'emptyIcon' => 'briefcase',
    'emptyText' => 'Our service catalogue is being published here. Until it is, tell us about your project and we will talk you through what we build.',
])
