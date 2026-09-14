{{--
    `layouts.site` — the name phase-03 §8.14, build-order §3 and phase-19-23 §1.2 give the public website
    layout. The layout itself lives at `site/layouts/public.blade.php` (integration K-9); this file is the
    contract-name alias, so a later phase that writes

        @extends('layouts.site')
        @section('content') … @endsection

    gets exactly the same shell, sections, variables ($site, $page) and behaviour as
    `@extends('site.layouts.public')`. It adds nothing of its own: change the layout there, never here.
--}}
@extends('site.layouts.public')
