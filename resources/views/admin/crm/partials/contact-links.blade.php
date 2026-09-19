{{--
    Call / WhatsApp / email icon links for a lead, a client or a contact (phase-05 §8.1 "Contact" column, §8.3 header).

    @include('admin.crm.partials.contact-links', [
        'phone' => $lead->phone,
        'whatsapp' => $lead->whatsapp,          // falls back to the phone when empty
        'email' => $lead->email,
        'name' => $lead->name,                  // for the accessible labels
        'whatsappTemplate' => $whatsappTemplate, // crm.whatsapp_link_template, passed by the controller
        'size' => 'sm',                          // x-ui.icon-button size
        'variant' => 'ghost',
        'labels' => false,                       // true renders the value beside each icon
    ])

    No URL is hardcoded here (§5, test 85): the WhatsApp link is the template the controller hands over with `{phone}`
    replaced by the number's digits. When the template is missing or is not an http(s)/whatsapp URL, no WhatsApp link
    is rendered at all rather than inventing one.
--}}

@php
    $contactName = (string) ($name ?? 'this contact');
    $contactPhone = trim((string) ($phone ?? ''));
    $contactWhatsapp = trim((string) ($whatsapp ?? '')) ?: $contactPhone;
    $contactEmail = trim((string) ($email ?? ''));
    $contactSize = $size ?? 'sm';
    $contactVariant = $variant ?? 'ghost';
    $withLabels = (bool) ($labels ?? false);

    $telDigits = preg_replace('/[^\d+]/', '', $contactPhone);
    // Digits only, with a leading 00 international prefix dropped; a "+" never reaches the URL. A local number is
    // passed through as typed: no dialling code is guessed here.
    $waDigits = (string) preg_replace('/^00/', '', (string) preg_replace('/\D/', '', $contactWhatsapp));

    $template = is_string($whatsappTemplate ?? null) ? trim($whatsappTemplate) : '';
    $waUrl = null;

    if ($template !== '' && $waDigits !== '' && preg_match('~^(https?://|whatsapp://)~i', $template) === 1) {
        $waUrl = str_replace('{phone}', rawurlencode($waDigits), $template);
    }
@endphp

<div class="flex flex-wrap items-center gap-0.5">
    @if ($contactPhone !== '' && $telDigits !== '')
        @if ($withLabels)
            <a href="tel:{{ $telDigits }}" class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white">
                <x-ui.icon name="phone" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                <span class="sr-only">Call {{ $contactName }} on</span> {{ $contactPhone }}
            </a>
        @else
            <x-ui.icon-button icon="phone" :size="$contactSize" :variant="$contactVariant" :href="'tel:'.$telDigits" :label="'Call '.$contactName.' on '.$contactPhone" />
        @endif
    @endif

    @if ($waUrl !== null)
        @if ($withLabels)
            <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm text-emerald-700 transition-colors hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10">
                <x-ui.icon name="whatsapp" class="h-4 w-4" />
                <span class="sr-only">WhatsApp {{ $contactName }} on</span> {{ $contactWhatsapp }}
            </a>
        @else
            <x-ui.icon-button icon="whatsapp" :size="$contactSize" :variant="$contactVariant" :href="$waUrl" target="_blank" rel="noopener noreferrer" class="text-emerald-600 dark:text-emerald-400" :label="'WhatsApp '.$contactName.' on '.$contactWhatsapp" />
        @endif
    @endif

    @if ($contactEmail !== '')
        @if ($withLabels)
            <a href="mailto:{{ $contactEmail }}" class="inline-flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1 text-sm text-brand-700 transition-colors hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">
                <x-ui.icon name="envelope" class="h-4 w-4" />
                <span class="sr-only">Email {{ $contactName }} at</span> <span class="truncate">{{ $contactEmail }}</span>
            </a>
        @else
            <x-ui.icon-button icon="envelope" :size="$contactSize" :variant="$contactVariant" :href="'mailto:'.$contactEmail" :label="'Email '.$contactName.' at '.$contactEmail" />
        @endif
    @endif

    @if ($contactPhone === '' && $waUrl === null && $contactEmail === '')
        <span class="text-xs text-slate-400 dark:text-slate-500">—</span>
    @endif
</div>
