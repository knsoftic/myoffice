{{--
    The breadcrumb bar under the topbar.

    Three ways to fill it, in order of precedence:
      1. @section('breadcrumbs') … @endsection   — full control over the markup
      2. $breadcrumbs passed from the controller  — [['label' => 'Users', 'url' => …], ['label' => 'Edit']]
      3. derived from the current route name      — admin.users.edit → Dashboard / Users / Edit

    The bar hides itself on a panel's landing page, where a single crumb adds nothing.
--}}

@php
    $crumbs = [];

    if (! empty($breadcrumbs ?? null) && is_array($breadcrumbs)) {
        $crumbs = $breadcrumbs;
    } else {
        $routeName = \Illuminate\Support\Facades\Route::currentRouteName();

        if (filled($routeName)) {
            $segments = explode('.', $routeName);
            $panel = array_shift($segments);

            // A trailing "index" is implied by its resource crumb.
            if (end($segments) === 'index') {
                array_pop($segments);
            }

            $safeUrl = static function (string $name): ?string {
                if (! \Illuminate\Support\Facades\Route::has($name)) {
                    return null;
                }

                try {
                    return route($name);
                } catch (\Throwable) {
                    return null; // route needs parameters we do not have here
                }
            };

            $dashboard = $panel.'.dashboard';

            if ($routeName !== $dashboard && $url = $safeUrl($dashboard)) {
                $crumbs[] = ['label' => 'Dashboard', 'url' => $url, 'icon' => null];
            }

            $path = $panel;
            $last = count($segments) - 1;

            foreach ($segments as $index => $segment) {
                $path .= '.'.$segment;

                $label = \Illuminate\Support\Str::headline(str_replace(['-', '_'], ' ', $segment));
                $url = null;

                if ($index !== $last) {
                    $url = $safeUrl($path.'.index') ?? $safeUrl($path);
                }

                $crumbs[] = ['label' => $label, 'url' => $url, 'icon' => null];
            }
        }
    }
@endphp

@if (\Illuminate\Support\Facades\View::hasSection('breadcrumbs'))
    <div class="border-b border-slate-200 bg-white/60 dark:border-slate-800 dark:bg-slate-900/40">
        <div class="mx-auto max-w-screen-2xl px-4 py-2.5 sm:px-6 lg:px-8">
            @yield('breadcrumbs')
        </div>
    </div>
@elseif (count($crumbs) > 1)
    <div class="border-b border-slate-200 bg-white/60 dark:border-slate-800 dark:bg-slate-900/40">
        <div class="mx-auto max-w-screen-2xl px-4 py-2.5 sm:px-6 lg:px-8">
            <x-ui.breadcrumbs :items="$crumbs" />
        </div>
    </div>
@endif
