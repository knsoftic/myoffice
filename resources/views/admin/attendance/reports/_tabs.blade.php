{{--
    The four reports of §8.16 as one tab strip, with print and export for whichever is showing.

    Expects: $active — 'daily' | 'monthly' | 'percentage' | 'batch'.
--}}

<div class="flex flex-wrap gap-1 border-b border-slate-200/80 px-3 py-2 dark:border-slate-800">
    @foreach ([
        'daily' => 'Daily',
        'monthly' => 'Monthly matrix',
        'percentage' => 'By student',
        'batch' => 'By batch',
    ] as $key => $label)
        <a href="{{ route('admin.student-attendance.reports.'.$key, request()->only(['batch_id', 'course_id', 'teacher_id', 'date', 'from', 'to'])) }}"
           class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                  {{ $active === $key
                      ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                      : 'text-slate-500 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800' }}">
            {{ $label }}
        </a>
    @endforeach

    <div class="ml-auto flex gap-1">
        @can('student_attendance.print')
            <x-ui.button variant="ghost" size="sm" icon="printer"
                         :href="route('admin.student-attendance.print', array_merge(['report' => $active], request()->query()))">Print</x-ui.button>
        @endcan
        @can('student_attendance.export')
            <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray"
                         :href="route('admin.student-attendance.export', array_merge(['report' => $active, 'format' => 'csv'], request()->query()))">CSV</x-ui.button>
        @endcan
    </div>
</div>
