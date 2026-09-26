<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Institute\Student;
use App\Models\User;
use App\Notifications\Institute\PortalLoginCreatedNotification;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * Whether the last `createLogin()` call created the account but failed to deliver the password.
     *
     * The account is real either way, so the mail failure must not throw — but the operator standing
     * at the desk has to be told, because the password is now gone and only they can start a reset.
     * A success toast over a failed send is the exact shape of the bug this whole change fixes.
     */
    private bool $credentialsMailFailed = false;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StudentNumberService $numbers,
    ) {}

    /** True when the login was created but its credentials email did not go out. */
    public function credentialsMailFailed(): bool
    {
        return $this->credentialsMailFailed;
    }

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

            $this->syncLoginTo($student->refresh(), $to, $actor);

            return $student;
        }, 3);
    }

    /**
     * The panel account, created at activation and never before.
     *
     * Returns the existing user when there already is one — being called twice must not produce a
     * second account for one student, and `uq_st_user` would refuse it anyway.
     *
     * The generated password leaves here only by `PortalLoginCreatedNotification`. Check
     * `credentialsMailFailed()` afterwards: a `true` means the account exists and nobody can sign
     * in to it until somebody resets it.
     */
    public function createLogin(Student $student, ?User $actor = null): ?User
    {
        if ($student->user_id !== null) {
            // `loadMissing`, not `->user`: strict mode turns a lazy read into an exception, and this
            // branch is reached from `changeStatus()` where the relation was never loaded.
            return $student->loadMissing('user')->user;
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

        /*
        | Reset here and not at the top of the method, so that the three early returns above leave
        | the previous result alone. `activate()` calls this twice — once through `changeStatus()`,
        | once directly — and the second call short-circuits; resetting on the way through would
        | erase the first call's failure and hand the operator a success they did not get.
        */
        $this->credentialsMailFailed = false;

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
            /*
            | Held in a local only long enough to mail it. Never returned by this method, never
            | logged, never written anywhere but the hash below — so the message sent after this
            | transaction commits is genuinely the only copy that ever exists.
            */
            $plainPassword = Str::password(16, true, true, false, false);

            $user->forceFill([
                'name' => $student->name,
                'email' => $email,
                'password' => $plainPassword,
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

            /*
            | **After the commit, not inside it.**
            |
            | Sent inside the transaction, a later failure would roll the account back while the
            | student keeps an e-mail holding credentials for a user that no longer exists — and
            | they would spend a morning trying to sign in to nothing. Sent after, the worst case
            | is an account that exists and an e-mail that did not arrive, which a password reset
            | fixes.
            |
            | The send is not allowed to undo the account either: if mail is misconfigured the
            | login is still real, still resettable, and the failure belongs in the log rather than
            | in a rolled-back transaction the operator cannot interpret.
            */
            $this->db->afterCommit(function () use ($user, $student, $plainPassword): void {
                try {
                    $user->notify(new PortalLoginCreatedNotification(
                        $plainPassword,
                        (string) $student->name,
                        'student portal',
                        'see your courses, timetable, attendance, results and fee history',
                    ));
                } catch (Throwable $e) {
                    $this->credentialsMailFailed = true;

                    Log::error('Student login created but the credentials email failed', [
                        'student_id' => $student->getKey(),
                        'user_id' => $user->getKey(),
                        'message' => $e->getMessage(),
                    ]);
                }
            });

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
    private function syncLoginTo(Student $student, StudentStatus $status, ?User $actor = null): void
    {
        $user = $student->loadMissing('user')->user;

        if (! $user instanceof User) {
            /*
            | **The second door into activation.** The class docblock says the login is created at
            | activation, and `AdmissionService::activate()` did that — but a student activated from
            | the Students screen never goes through an admission, so they reached Active with no
            | account and nobody noticed until they tried to sign in. This is the same rule applied
            | at the other door, not a new one: identical guards, identical setting, and
            | `createLogin()` is idempotent, so the admission path's own call still short-circuits.
            */
            if ($status === StudentStatus::Active) {
                $this->createLogin($student, $actor);
            }

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
