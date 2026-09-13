{{--
    One widget's body, and the single place a widget's own view is rendered.

    Both paths go through here — the inline grid and the JSON endpoint's `html` member — so the
    markup for a card exists exactly once and a refreshed card can never look different from a
    freshly loaded one.

    `@includeFirst` is the guard for the one mistake a later phase will make: shipping the widget
    class and forgetting the view. The dashboard then says so, on that card, instead of throwing a
    `View not found` over the whole page.

    Expects: $widget (WidgetDescriptor), $data (array), $range (DateRange).
--}}

@includeFirst(
    [$widget->view, 'admin.dashboard.partials.missing-view'],
    ['widget' => $widget, 'data' => $data, 'range' => $range]
)
