<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Institute\Student;
use App\Models\User;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The student record itself (§66, phase-14-17 §6.6).
 *
 * **`student_code` and `registration_number` are never writable through this class.** The code is
 * issued once by `StudentNumberService` inside the transaction that creates the row; the registration
 * number is issued by `AdmissionService::register()`. A service method that could set either would be
 * a second way to produce a document number, which is how two students come to share one.
 *
 * **A status change is a business event, not a column edit.** §2.30.4 says which moves exist and which
 * of them take a reason, and `changeStatus()` is the only door. Suspending a student also puts their
 * login out of action — leaving an account live for somebody the institute has suspended is the kind
 * of gap nobody notices until it matters.
 *
 * **The login is created late, and never silently.** `createLogin()` runs at activation, only when
 * `institute.auto_create_student_login` is on and there is somewhere to send the password. The
 * password is random and returned to nobody: it goes out by notification, and never into a response,
 * a log line or an activity row.
 */
final class StudentService
{
    /** §2.30.4, verbatim. */
    private const TRANSITIONS = [
        'inquiry' => ['applied', 'dropped'],
        'applied' => ['registered', 'dropped', 'suspended'],
        'registered' => ['active', 'dropped', 'suspended'],
        'active' => ['completed', 'dropped', 'suspended'],
        'completed' => ['active'],
        'dropped' => ['active'],
        'suspended' => ['active'],
    ];

    /** Moves somebody has to answer for. */
    private const REASON_REQUIRED = ['dropped', 'suspended'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentNumberService $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Student
    {
        return $this->db->transaction(function () use ($data, $actor): Student {
            $student = new Student;

            $student->fill($this->columns($data));

            $branchId = $data['branch_id']
                ?? $actor?->branch_id
                ?? setting('institute.default_branch_id');

            $status = $data['status'] ?? StudentStatus::Applied->value;
            $status = $status instanceof StudentStatus ? $status : StudentStatus::from((string) $status);

            $student->forceFill([
                'student_code' => $this->numbers->nextStudentCode(
                    $branchId === null ? null : Branch::find($branchId),
                ),
                'branch_id' => $branchId,
                'phone' => $this->normalisePhone((string) ($data['phone'] ?? '')),
                'whatsapp' => $this->optionalPhone($data['whatsapp'] ?? null),
                'guardian_phone' => $this->optionalPhone($data['guardian_phone'] ?? null),
                'cnic' => $this->normaliseCnic($data['cnic'] ?? null),
                'status' => $status->value,
                'status_changed_at' => Carbon::now(),
                'joining_date' => $data['joining_date'] ?? null,
                'created_by' => $actor?->getKey(),
            ])->save();

            return $student->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data, ?User $actor = null): Student
    {
        return $this->db->transaction(function () use ($student, $data, $actor): Student {
            $student->fill($this->columns($data));

            $forced = ['updated_by' => $actor?->getKey()];

            foreach (['phone' => 'phone', 'whatsapp' => 'whatsapp', 'guardian_phone' => 'guardian_phone'] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $forced[$column] = $key === 'phone'
                        ? $this->normalisePhone((string) $data[$key])
                        : $this->optionalPhone($data[$key]);
                }
            }

            if (array_key_exists('cnic', $data)) {
                $forced['cnic'] = $this->normaliseCnic($data['cnic']);
            }

            $student->forceFill($forced)->save();

            return $student->refresh();
        }, 3);
    }

    /**
     * §2.30.4. The one door a student's status moves through.
     */
    public function changeStatus(
        Student $student,
        StudentStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): Student {
        $from = $student->status;

        if ($from === $to) {
            return $student;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw CourseRuleException::refuse('status', sprintf(
                'A student cannot go from %s to %s. %s',
                $from->label(),
                $to->label(),
                $allowed === []
                    ? sprintf('%s is where this one ends.', $from->label())
                    : 'From here: '.implode(', ', $allowed).'.',
            ));
        }

        $reason = trim((string) $reason);

        if (in_array($to->value, self::REASON_REQUIRED, true) && $reason === '') {
            throw CourseRuleException::reasonRequired('status', sprintf(
                'Moving a student to %s takes a reason. It goes on the record, and it is the first '
                .'thing anybody asks about afterwards.',
                $to->label(),
            ));
        }

        if ($from === StudentStatus::Suspended && $to === StudentStatus::Active && $reason === '') {
            throw CourseRuleException::reasonRequired('status',
                'Reinstating a suspended student takes a reason, for the same cause the suspension did.');
        }

        return $this->db->transaction(function () use ($student, $to, $reason, $actor): Student {
            if ($reason !== '') {
                $student->withReason($reason);
            }

            $student->forceFill([
                'status' => $to->value,
                'status_changed_at' => Carbon::now(),
                // `chk_st_status_reason` demands one for suspended and dropped and refuses a stale one
                // to linger on a live student, so it is cleared on the way back.
                'status_reason' => in_array($to->value, self::REASON_REQUIRED, true) ? $reason : null,
                'updated_by' => $actor?->getKey(),
            ])->save();

            $this->syncLoginTo($student->refresh(), $to);

            return $student;
        }, 3);
    }

    /**
     * The panel account, created at activation and never before.
     *
     * Returns the existing user when there already is one — being called twice must not produce a
     * second account for one student, and `uq_st_user` would refuse it anyway.
     */
    public function createLogin(Student $student, ?User $actor = null): ?User
    {
        if ($student->user_id !== null) {
            return $student->user;
        }

        if (! (bool) setting('institute.auto_create_student_login', true)) {
            return null;
        }

        $email = trim((string) $student->email);

        // Somewhere to send it, or there is no point creating it: a login nobody can be told about is
        // an account with a random password and no way in.
        if ($email === '') {
            return null;
        }

        return $this->db->transaction(function () use ($student, $email, $actor): ?User {
            if (User::query()->where('email', $email)->exists()) {
                throw CourseRuleException::refuse('email', sprintf(
                    'There is already an account for %s. Link it to this student instead of creating a '
                    .'second one — two logins for one person is how somebody ends up locked out of '
                    .'their own record.',
                    $email,
                ));
            }

            $user = new User;
            $user->forceFill([
                'name' => $student->name,
                'email' => $email,
                // Never returned, never logged: the notification is the only thing that sees it.
                'password' => Str::password(16, true, true, false, false),
                'status' => UserStatus::Active->value,
                'must_change_password' => true,
                'branch_id' => $student->branch_id,
                'email_verified_at' => null,
                'created_by' => $actor?->getKey(),
            ])->save();

            $user->assignRole('Student');

            $student->forceFill([
                'user_id' => $user->getKey(),
                'updated_by' => $actor?->getKey(),
            ])->save();

            return $user;
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A student who is suspended or dropped must not be able to sign in, and one reinstated must.
     * Phase 1's `active` middleware reads `users.status`, so this is where the two agree.
     */
    private function syncLoginTo(Student $student, StudentStatus $status): void
    {
        $user = $student->user;

        if (! $user instanceof User) {
            return;
        }

        $wanted = $status->canLogin() ? UserStatus::Active : UserStatus::Inactive;

        if ($user->status === $wanted) {
            return;
        }

        $user->forceFill(['status' => $wanted->value])->save();
    }

    private function normalisePhone(string $phone): string
    {
        $trimmed = trim($phone);
        $plus = str_starts_with($trimmed, '+') ? '+' : '';

        return $plus.(preg_replace('/\D/', '', $trimmed) ?? '');
    }

    private function optionalPhone(mixed $phone): ?string
    {
        $value = trim((string) ($phone ?? ''));

        return $value === '' ? null : $this->normalisePhone($value);
    }

    /**
     * Digits only. `chk_st_cnic_digits` enforces it in the table; this is what makes a form that types
     * 31102-1234567-1 land as the same value as one that types 3110212345671.
     */
    private function normaliseCnic(mixed $cnic): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($cnic ?? '')) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $fields = [
            'name', 'father_name', 'gender', 'date_of_birth', 'email', 'address', 'city',
            'photo_path', 'guardian_name', 'guardian_relation', 'education', 'institution_name',
            'joining_date', 'notes',
        ];

        return array_filter(
            array_intersect_key($data, array_flip($fields)),
            static fn (string $key): bool => in_array($key, $fields, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
