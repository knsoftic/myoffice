{{--
    The import wizard's step rail (phase-05 §8.6): x-ui.tabs used as a step rail with a disabled-forward rule — a step
    already reached is a link, the current step is marked, and a step not reached yet is inert.

    @include('admin.leads.import.partials.steps', ['current' => 2, 'reached' => 3, 'import' => $import])

      current   int  1..4  the step on screen
      reached   int  1..4  the furthest step this import may open (a later step never renders as a link)
      import    ?LeadImport  null on the upload screen
--}}

@php
    $stepCurrent = (int) ($current ?? 1);
    $stepReached = max($stepCurrent, (int) ($reached ?? $stepCurrent));
    $stepImport = $import ?? null;
    $stepLabels = [1 => 'Upload', 2 => 'Map columns', 3 => 'Options', 4 => 'Validate and run'];
    $stepTabs = [];

    foreach ($stepLabels as $number => $stepLabel) {
        $tab = ['label' => $number.'. '.$stepLabel, 'active' => $number === $stepCurrent, 'icon' => $number < $stepReached && $number !== $stepCurrent ? 'check-circle' : null];

        if ($number !== $stepCurrent && $number <= $stepReached) {
            $tab['url'] = $number === 1 || $stepImport === null
                ? route('admin.leads.import.index')
                : route('admin.leads.import.show', ['import' => $stepImport, 'step' => $number]);
        }

        $stepTabs[] = $tab;
    }
@endphp

<nav aria-label="Import steps" class="space-y-1">
    <x-ui.tabs :tabs="$stepTabs" />
    <p class="text-xs text-slate-500 dark:text-slate-400">Step {{ $stepCurrent }} of 4. Steps unlock in order: a later step opens once the one before it is saved.</p>
</nav>
