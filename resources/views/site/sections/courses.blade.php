{{--
    Section type `courses` — declared by Phase 14 (phase-03 §6.1 "Section types declared by later
    phases"; default home sort 40). Phase 3 ships the partial so the type renders the moment Phase 14
    registers it; until then SectionRegistry does not know the key and the renderer never reaches
    this file.

    Receives the standard section contract ($section, $content, …); cards come from Phase 14's
    SectionDataProvider as `$section['provider']` (see site/sections/partials/teaser). With no active
    course yet it shows an honest empty state with the institute's real contact details.
--}}

@include('site.sections.partials.teaser', [
    'typeKey' => 'courses',
    'emptyIcon' => 'academic-cap',
    'emptyText' => 'Course listings and upcoming batches are being published here. Contact our admissions team for the current schedule.',
])
