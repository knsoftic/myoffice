@extends('layouts.admin')

@section('title', 'New admission')

@section('header')
    <x-ui.page-header title="New admission"
                      subtitle="Pick the student and the course; the fees are prefilled from the course and are negotiable until the first charge."
                      icon="user-plus"
                      :back="route('admin.admissions.index')" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.admissions.store') }}" class="space-y-4"
          x-data="{
              courses: {{ Illuminate\Support\Js::from($courses->keyBy('id')) }},
              courseId: '{{ old('course_id') }}',
              discount: {{ (float) old('discount_amount', 0) }},
              scholarship: {{ (float) old('scholarship_amount', 0) }},
              fees() {
                  const c = this.courses[this.courseId];
                  return c ? [Number(c.course_fee), Number(c.admission_fee), Number(c.registration_fee)] : [0, 0, 0];
              },
              total() { return this.fees().reduce((a, b) => a + b, 0); },
              net() { return Math.max(0, this.total() - Number(this.discount || 0) - Number(this.scholarship || 0)); },
              pick() {
                  const c = this.courses[this.courseId];
                  if (! c) return;
                  this.$refs.courseFee.value = c.course_fee;
                  this.$refs.admissionFee.value = c.admission_fee;
                  this.$refs.registrationFee.value = c.registration_fee;
              },
          }">
        @csrf

        <x-ui.card title="Student and course">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.select name="student_id" label="Student" required>
                    @foreach ($students as $student)
                        <option value="{{ $student->id }}"
                                @selected((int) old('student_id', $selectedStudent?->id) === (int) $student->id)>
                            {{ $student->name }} ({{ $student->student_code }}) — {{ $student->phone }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="course_id" label="Course" required
                                  x-model="courseId" x-on:change="pick()">
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}" @selected((int) old('course_id') === (int) $course->id)>{{ $course->name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.input name="admission_date" label="Admission date" type="date"
                                 :value="old('admission_date', now()->toDateString())" />

                <x-ui.form.select name="counselor_id" label="Counsellor" placeholder="Me">
                    @foreach ($counselors as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('counselor_id') === (int) $id)>{{ $name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="delivery_mode" label="Agreed mode" placeholder="As the course is taught">
                    @foreach ($modes as $value => $label)
                        <option value="{{ $value }}" @selected(old('delivery_mode') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="preferred_timing" label="Preferred timing" placeholder="Not stated">
                    @foreach ($timings as $value => $label)
                        <option value="{{ $value }}" @selected(old('preferred_timing') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.form.select>
            </div>
        </x-ui.card>

        <x-ui.card title="Agreed figures"
                   subtitle="Snapshotted here. Changing the catalogue price later moves what the next student is quoted, and nothing already sold.">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.form.input name="course_fee" label="Course fee" type="number" step="0.01"
                                 x-ref="courseFee" :value="old('course_fee', 0)" />
                <x-ui.form.input name="admission_fee" label="Admission fee" type="number" step="0.01"
                                 x-ref="admissionFee" :value="old('admission_fee', 0)" />
                <x-ui.form.input name="registration_fee" label="Registration fee" type="number" step="0.01"
                                 x-ref="registrationFee" :value="old('registration_fee', 0)" />
                <x-ui.form.input name="discount_amount" label="Discount" type="number" step="0.01"
                                 x-model="discount" :value="old('discount_amount', 0)" />
                <x-ui.form.input name="scholarship_amount" label="Scholarship" type="number" step="0.01"
                                 x-model="scholarship" :value="old('scholarship_amount', 0)" />
                <x-ui.form.input name="monthly_fee" label="Monthly fee" type="number" step="0.01"
                                 :value="old('monthly_fee')" />
            </div>

            <div class="mt-4">
                <x-ui.form.input name="discount_reason" label="Reason for the discount" :value="old('discount_reason')"
                                 help="The answer to «why is this student paying less than that one»." />
            </div>

            <div class="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-slate-500">Net payable (live preview)</span>
                    <span class="text-lg font-semibold tabular-nums text-slate-800 dark:text-slate-100"
                          x-text="net().toLocaleString()"></span>
                </div>
                <p class="mt-1 text-xs text-slate-400">
                    The figure that is stored is computed on the server through the money helper — this is
                    the same sum, shown before you submit.
                </p>
            </div>
        </x-ui.card>

        <x-ui.card title="Notes">
            <x-ui.form.textarea name="notes" label="Notes" rows="3" :value="old('notes')" />
        </x-ui.card>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">Start the admission</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.admissions.index')">Cancel</x-ui.button>
        </div>
    </form>
@endsection
