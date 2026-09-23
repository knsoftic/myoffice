{{--
    One eligibility report, rendered the same way everywhere it appears (phase-19-23 §6.14).

    Three screens show this — the eligibility list, the single-enrolment report and the certificate's
    own page — and §84's requirement is that each rule carries the number behind it. One partial is
    what stops the three drifting into three different ideas of "not eligible".

    Variables:
      $report  ?EligibilityReport
--}}

@if ($report === null)
    <p class="text-sm text-slate-500 dark:text-slate-400">
        There is no enrolment behind this, so the rules cannot be run.
    </p>
@else
    <ul class="grid gap-1.5 sm:grid-cols-2">
        @foreach ($report->rules as $rule)
            <li class="flex items-start gap-2 text-xs">
                <x-ui.icon :name="$rule->skipped ? 'minus-circle' : ($rule->passed ? 'check-circle' : 'x-circle')"
                           @class([
                               'mt-px h-4 w-4 shrink-0',
                               'text-slate-300 dark:text-slate-600' => $rule->skipped,
                               'text-emerald-500' => $rule->passed && ! $rule->skipped,
                               'text-rose-500' => ! $rule->passed,
                           ]) />
                <span class="text-slate-600 dark:text-slate-300">
                    {{ $rule->message }}
                    @if ($rule->required !== null && $rule->actual !== null)
                        <span class="text-slate-400">({{ $rule->actual }} of {{ $rule->required }})</span>
                    @endif
                    @if ($rule->skipped)
                        <span class="text-slate-400">— not checked by this institute</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
