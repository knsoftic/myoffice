@props([
    'mail' => [],
])

{{--
    x-settings.mail-test — "Send test email" with the real result underneath (phase-02 §5).

    What makes this panel worth having is that it does not lie:

      · it states which transport and host the **saved** settings point at, so an operator can see
        that the test is not quietly using the environment file;
      · a failure prints the transport's own message and the exception class — "Connection could not
        be established with host smtp.example.com" is the sentence that fixes the problem, and
        hiding it behind "Something went wrong" wastes an afternoon;
      · the password appears nowhere. Only "set" or "not set". The controller redacts any stored
        secret from the message before it is flashed, so even a chatty exception cannot leak it.

    The panel renders **outside** the settings form — its own <form> cannot be nested in another —
    and the route carries `throttle:3,1` on top of the service's own per-user rate limit.
--}}

@php
    $m = $mail;

    $mailer = (string) ($m['mailer'] ?? 'log');
    $result = $m['result'] ?? null;
    $canSend = (bool) ($m['can_send'] ?? false);

    $transportLabel = match ($mailer) {
        'smtp' => 'SMTP',
        'log' => 'Log file (nothing is sent)',
        'sendmail' => 'Local sendmail',
        default => $mailer,
    };

    $target = $mailer === 'smtp'
        ? trim((string) ($m['host'] ?? '')).(filled($m['port'] ?? null) ? ':'.$m['port'] : '')
        : null;

    $facts = array_filter([
        'Transport' => $transportLabel,
        'Server' => $target === '' ? null : $target,
        'Encryption' => filled($m['encryption'] ?? null) ? mb_strtoupper((string) $m['encryption']) : null,
        'From' => filled($m['from'] ?? null) ? $m['from'] : null,
        'Password' => ($m['has_password'] ?? false) ? 'Set' : 'Not set',
    ]);
@endphp

<x-ui.card title="Send a test email" subtitle="Uses the settings saved above — never the environment file." icon="envelope">
    <dl class="flex flex-wrap gap-x-6 gap-y-2 border-b border-slate-200/80 pb-4 dark:border-slate-800">
        @foreach ($facts as $label => $value)
            <div class="min-w-0">
                <dt class="text-2xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $label }}</dt>
                <dd class="truncate text-sm font-medium text-slate-700 dark:text-slate-200">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($mailer === 'log')
        <div class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
            <x-ui.icon name="information-circle" class="mt-px h-4 w-4 shrink-0" />
            <span>
                The transport is set to the log file, so a test will succeed without any mail leaving the
                server. Switch it to SMTP above and save before testing a real delivery.
            </span>
        </div>
    @endif

    @if ($canSend)
        <form method="POST" action="{{ route('admin.settings.mail.test') }}" class="mt-4">
            @csrf

            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <x-ui.form.input
                    name="email"
                    type="email"
                    label="Send to"
                    :value="$m['recipient'] ?? null"
                    placeholder="you@example.com"
                    icon="envelope"
                    class="sm:max-w-sm"
                    required
                />

                <x-ui.button type="submit" icon="arrow-up-tray" class="shrink-0">Send test email</x-ui.button>
            </div>

            <x-ui.form.help>At most three tests a minute. Nothing else on this page is saved by sending one.</x-ui.form.help>
        </form>
    @else
        <p class="mt-4 flex items-start gap-2 text-sm text-slate-500 dark:text-slate-400">
            <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
            <span>Sending a test needs permission to change the mail settings.</span>
        </p>
    @endif

    {{-- The result of the last attempt ------------------------------------------------- --}}
    @if ($result !== null)
        @php
            $ok = (bool) ($result['ok'] ?? false);
        @endphp

        <div
            @class([
                'mt-4 rounded-lg px-3 py-3 text-sm ring-1',
                'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/25' => $ok,
                'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25' => ! $ok,
            ])
            role="status"
        >
            <div class="flex items-start gap-2">
                <x-ui.icon :name="$ok ? 'check-circle' : 'x-circle'" class="mt-0.5 h-4 w-4 shrink-0" />

                <div class="min-w-0 flex-1">
                    <p class="font-semibold">
                        {{ $ok ? 'Sent' : 'Failed' }}
                        @if (filled($result['recipient'] ?? null))
                            — {{ $result['recipient'] }}
                        @endif
                    </p>

                    <p class="mt-1 break-words">{{ $result['message'] ?? '' }}</p>

                    @if (filled($result['exception'] ?? null))
                        <p class="mt-1.5 font-mono text-2xs opacity-80">{{ $result['exception'] }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif
</x-ui.card>
