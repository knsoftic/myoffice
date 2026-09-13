@props([
    'group' => '',
    'fields' => [],
])

{{--
    x-settings.file-forms — one hidden DELETE form per stored file, rendered **after** the settings
    form closes.

    The "Remove" button inside each image field reaches its form through the HTML `form` attribute
    (`<button type="submit" form="settings-file-destroy-branding-logo-light">`). That is what keeps
    a remove button inside the settings form without nesting one <form> inside another, which
    browsers drop silently.

    Only fields that actually have a stored file, and that this user may edit, get a form — so
    there is never a live delete endpoint on the page for something read-only.
--}}

@foreach ($fields as $field)
    @continue(($field['file'] ?? null) === null || ($field['disabled'] ?? false))

    <form
        id="settings-file-destroy-{{ $group }}-{{ str_replace('_', '-', $field['key']) }}"
        method="POST"
        action="{{ route('admin.settings.file.destroy', ['group' => $group, 'key' => $field['key']]) }}"
        class="hidden"
    >
        @csrf
        @method('DELETE')
    </form>
@endforeach
