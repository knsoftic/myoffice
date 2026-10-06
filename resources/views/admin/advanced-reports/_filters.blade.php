{{--
    The Advanced Reports filter form (D177) — included by admin/advanced-reports/index.

    A plain GET form, so every filter lives in the URL: a filtered screen can be bookmarked, shared and
    exported, and the export/print links rebuild exactly this filter set from the same query string.

    The selects apply themselves, as every filter bar in the admin does (`x-ui.filter-bar`): changing
    the joining-date period, the course, the batch, the student status or the payment status submits
    the form. Two things wait for "Apply Filter" (or Enter): the search box, so a page does not reload
    under somebody mid-word, and "Custom Date Range", which shows the From/To pair and waits for the
    dates. "Apply Filter" and "Reset Filters" are always visible.

    Changing the course clears the batch before submitting, and the server renders the batch list for
    the chosen course (and drops a batch that does not fit it), so the batch select never offers — or
    applies — another course's batch. Without JavaScript nothing auto-submits and the same form still
    works with "Apply Filter".

    Expects: $filters (AdvancedReportFilters), $periodOptions, $courseOptions, $batchOptions,
             $statusOptions, $paymentOptions, $total (int — rows matching, for the "Showing" line).
--}}

@php
    $custom = $filters->period === \App\DataObjects\Reporting\AdvancedReportFilters::PERIOD_CUSTOM;
    // The order travels with a new filter, as the filter bar elsewhere keeps it; defaults stay out of
    // the URL.
    $keptOrder = array_intersect_key($filters->toQuery(), array_flip(['sort', 'direction']));
@endphp

<x-ui.card class="mb-4">
    <form method="GET"
          action="{{ route('admin.advanced-reports.index') }}"
          class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
          aria-label="Filter the report"
          x-ref="form"
          x-on:change="changed($event)"
          x-data="{
              period: @js($filters->period),
              submit() {
                  this.$refs.form.requestSubmit ? this.$refs.form.requestSubmit() : this.$refs.form.submit();
              },
              changed(event) {
                  const field = event.target;

                  if (! field || field.tagName !== 'SELECT') {
                      return;
                  }

                  if (field.name === 'period' && field.value === 'custom') {
                      return;
                  }

                  if (field.name === 'course_id' && this.$refs.form.elements.batch_id) {
                      this.$refs.form.elements.batch_id.value = '';
                  }

                  this.submit();
              },
          }">
        @foreach ($keptOrder as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <x-ui.form.select name="period" label="Joining date" :options="$periodOptions" :selected="$filters->period"
                          x-model="period" />

        <div x-show="period === 'custom'" @unless ($custom) x-cloak @endunless>
            <x-ui.form.input type="date" name="from" label="From Date" :value="$custom ? $filters->from : null" />
        </div>

        <div x-show="period === 'custom'" @unless ($custom) x-cloak @endunless>
            <x-ui.form.input type="date" name="to" label="To Date" :value="$custom ? $filters->to : null" />
        </div>

        <x-ui.form.select name="course_id" label="Course" placeholder="All Courses"
                          :options="$courseOptions" :selected="$filters->courseId" />

        <x-ui.form.select name="batch_id" label="Batch" placeholder="All Batches"
                          :options="$batchOptions" :selected="$filters->batchId" />

        <x-ui.form.select name="status" label="Student Status" placeholder="All Students"
                          :options="$statusOptions" :selected="$filters->status?->value" />

        {{-- "Overdue is refreshed nightly" is said once, under the summary cards beside the Overdue card. --}}
        <x-ui.form.select name="payment" label="Payment Status" placeholder="All"
                          :options="$paymentOptions" :selected="$filters->payment?->value" />

        <x-ui.form.input name="search" label="Search" icon="magnifying-glass" class="sm:col-span-2"
                         :value="$filters->search"
                         maxlength="{{ \App\DataObjects\Reporting\AdvancedReportFilters::SEARCH_MAX }}"
                         placeholder="Name, student ID, phone, email, course or batch" />

        <div class="flex flex-wrap items-center justify-end gap-2 sm:col-span-2 xl:col-span-4">
            <div class="mr-auto space-y-0.5 text-xs text-slate-500 dark:text-slate-400" aria-live="polite">
                @if ($filters->isFiltered() || $filters->period !== \App\DataObjects\Reporting\AdvancedReportFilters::PERIOD_ALL)
                    <p>
                        Showing {{ app_number($total) }} {{ \Illuminate\Support\Str::plural('record', $total) }}
                        · Joined: {{ $filters->periodLabel() }}
                    </p>
                @endif
                @if ($custom && $filters->from === null && $filters->to === null)
                    <p class="text-amber-700 dark:text-amber-400" x-show="period === 'custom'">
                        Pick a From or To date — showing all time
                    </p>
                @endif
            </div>

            <x-ui.button variant="secondary" icon="arrow-path" :href="route('admin.advanced-reports.index')">
                Reset Filters
            </x-ui.button>
            <x-ui.button type="submit" icon="funnel">Apply Filter</x-ui.button>
        </div>
    </form>
</x-ui.card>
