{{--
    The "nothing was saved" banner at the top of a Phase 4 form.

    @include('admin.marketing.partials.form-errors', ['except' => ['reason', 'published_at']])

    Field errors are still printed beside each field; this only makes a refused save impossible to miss on
    a long, tabbed form where the failing field may sit on another tab. `except` lists keys owned by a
    dialog on the same page (a schedule or reject form) so their errors do not claim the main form failed.
--}}

@php
    $except = (array) ($except ?? []);
    $formErrors = collect($errors->getMessages())
        ->reject(static fn ($messages, string $key): bool => in_array(\Illuminate\Support\Str::before($key, '.'), $except, true))
        ->flatten()
        ->values();
@endphp

@if ($formErrors->isNotEmpty())
    <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25" role="alert">
        <p class="font-semibold">Nothing was saved — {{ $formErrors->count() === 1 ? 'one field needs' : $formErrors->count().' fields need' }} attention.</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach ($formErrors->take(6) as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
