{{--
    A widget class exists but the Blade view its `view()` names does not.

    Shown instead of an exception, because one unfinished card must not take the dashboard down —
    and because a developer reading this message knows immediately what to create.
--}}

<x-ui.empty-state
    icon="exclamation-triangle"
    title="This card has no view yet"
    :compact="true"
>
    <span class="font-mono text-xs">{{ $widget->view }}</span>
    <span class="mt-1 block">
        {{ class_basename($widget->className()) }} is registered but
        <code class="rounded bg-slate-100 px-1 py-0.5 text-2xs dark:bg-slate-800">resources/views/{{ str_replace('.', '/', $widget->view) }}.blade.php</code>
        is missing.
    </span>
</x-ui.empty-state>
