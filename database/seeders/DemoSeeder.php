<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Commands\Ops\DemoSeed;
use App\DataObjects\Collaborator\PayoutRequestData;
use App\DataObjects\Collaborator\RuleData;
use App\DataObjects\Crm\ClientData;
use App\DataObjects\Crm\LeadData;
use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Finance\RefundData;
use App\DataObjects\Institute\DiscountData;
use App\DataObjects\Institute\FeeStructureData;
use App\DataObjects\Institute\InstallmentLine;
use App\DataObjects\Project\ProjectData;
use App\DataObjects\Project\TaskData;
use App\DataObjects\Support\MeetingData;
use App\DataObjects\Support\ParticipantInput;
use App\Enums\AdmissionStage;
use App\Enums\BatchStatus;
use App\Enums\ClassroomType;
use App\Enums\ClientType;
use App\Enums\CollaborationType;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionScope;
use App\Enums\ContactInquiryStatus;
use App\Enums\CourseInquiryStatus;
use App\Enums\CourseLevel;
use App\Enums\DeliveryMode;
use App\Enums\EmploymentType;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttendanceStatus;
use App\Enums\ExamType;
use App\Enums\FeeDiscountType;
use App\Enums\InquirySource;
use App\Enums\JobOpeningStatus;
use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\PanelType;
use App\Enums\PaymentMethod;
use App\Enums\PayoutMethod;
use App\Enums\Priority;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ReferralSource;
use App\Enums\ReferralSubject;
use App\Enums\ReversalType;
use App\Enums\StudentAttendanceStatus;
use App\Enums\StudentFeeType;
use App\Enums\TaskStatus;
use App\Enums\ThemePreference;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Enums\WorkMode;
use App\Models\Branch;
use App\Models\Cms\BlogCategory;
use App\Models\Cms\BlogPost;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorPayout;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Finance\ProjectPayment;
use App\Models\Institute\Batch;
use App\Models\Institute\Certificate;
use App\Models\Institute\Classroom;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Models\Institute\CourseInquiry;
use App\Models\Institute\Exam;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentBatchEnrollment;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeePayment;
use App\Models\Institute\Teacher;
use App\Models\Project\Project;
use App\Models\Role;
use App\Models\Support\Meeting;
use App\Models\Support\SupportTicket;
use App\Models\Support\TicketDepartment;
use App\Models\User;
use App\Services\Cms\BlogService;
use App\Services\Cms\JobOpeningService;
use App\Services\Collaborator\CollaboratorService;
use App\Services\Collaborator\CommissionRuleService;
use App\Services\Collaborator\PayoutService;
use App\Services\Collaborator\ReferralService;
use App\Services\Crm\ClientService;
use App\Services\Crm\LeadService;
use App\Services\Finance\PaymentService;
use App\Services\Institute\AdmissionService;
use App\Services\Institute\AttendanceService;
use App\Services\Institute\BatchEnrollmentService;
use App\Services\Institute\BatchService;
use App\Services\Institute\CertificateService;
use App\Services\Institute\ClassSessionService;
use App\Services\Institute\CourseInquiryService;
use App\Services\Institute\CourseOutlineService;
use App\Services\Institute\CourseService;
use App\Services\Institute\ExamResultService;
use App\Services\Institute\ExamService;
use App\Services\Institute\StudentFeeService;
use App\Services\Institute\StudentService;
use App\Services\Institute\TeacherService;
use App\Services\Project\MilestoneService;
use App\Services\Project\ProjectService;
use App\Services\Project\TaskService;
use App\Services\Support\MeetingService;
use App\Services\Support\TicketService;
use App\Support\Collaborator\CollaboratorData;
use App\Support\Money;
use Closure;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The demo dataset of phase-24-25 section 6.7 — a whole business, fictional, and labelled as such.
 *
 * **How anybody tells demo data from real data six months later.** Three marks, deliberately
 * redundant, because the question always gets asked long after whoever ran this has left:
 *
 *   1. **Every e-mail address is on `@demo.myoffice.test`** (`DemoSeeder::EMAIL_DOMAIN`). `.test` is
 *      reserved by RFC 6761 and can never resolve, so no demo row can ever mail a real person, and
 *      `WHERE email LIKE '%@demo.myoffice.test'` finds the people in one query per table.
 *   2. **Every human-readable name starts with `Demo `** (`DemoSeeder::NAME_PREFIX`) — clients,
 *      projects, courses, batches, students, partners, posts, tickets. It shows up on every screen
 *      and in every export without anybody having to know a convention.
 *   3. **Every row with a free-text notes column carries the literal tag `[DEMO]`**
 *      (`DemoSeeder::TAG`), which is what makes a cross-table sweep possible:
 *      `SELECT * FROM <table> WHERE notes LIKE '%[DEMO]%'`.
 *
 * Numbers issued by a service — receipt numbers, admission numbers, fee numbers, collaborator codes
 * — are deliberately *not* marked. They come from the real sequences, because a demo dataset whose
 * receipts do not look like receipts proves nothing about the numbering. The three marks above are
 * the ones to grep for.
 *
 * **It never touches the production baseline** (D65, converge-additively). Roles, permissions,
 * modules and settings belong to `ModuleSeeder`, `PermissionSeeder`, `RoleSeeder` and
 * `SettingSeeder`; this seeder reads them and adds rows beside them. It writes no setting, edits no
 * role and enables no module. Run it on a database that already has the baseline — that is why it is
 * not in `DatabaseSeeder`'s list and is reachable only through `demo:seed`.
 *
 * **Every money row goes through the real service** (section 6.7, FIN-19). Receipts through
 * `PaymentService::recordStudentFeePayment()`, project money through `recordProjectPayment()`,
 * refunds and voids through `refund()` / `void()`, charges through
 * `StudentFeeService::generateStructure()`, commission rules through `CommissionRuleService`,
 * payouts through `PayoutService`. Nothing here inserts into a money table, so the dataset is itself
 * a run of the commission engine: **`demo:seed` followed by `integrity:verify --suite=all` must be
 * clean**, and when it is not, the bug is in the engine and not in this file.
 *
 * **Idempotent, and it deletes nothing.** Every stage looks its rows up by a natural demo key
 * (e-mail, name, referral code) before creating them, and every money call carries a deterministic
 * idempotency key, so a second run adds nothing and changes nothing. It could not clean up after
 * itself even if it wanted to: the money tables are append-only, protected by a model hook and a
 * `BEFORE DELETE` trigger (D19). That is why `demo:seed --fresh` rebuilds the database instead of
 * deleting demo rows — see {@see DemoSeed}.
 *
 * **One stage per domain, each reported, none of them fatal to the others.** A stage that throws is
 * recorded with the exception message and the run continues with the stages that do not depend on
 * it; `demo:seed` prints the table and exits non-zero. The alternative — one transaction over the
 * whole dataset — turns any single drifted signature into "the demo seeder does not work", which is
 * how a fixture stops being maintained. Nothing is swallowed: every failure is named, in the output
 * and in the exit code.
 */
class DemoSeeder extends Seeder
{
    use WritesToConsole;

    /** RFC 6761 reserved TLD: a demo address can never resolve, so it can never reach a person. */
    public const EMAIL_DOMAIN = 'demo.myoffice.test';

    /** The tag written into every `notes` column the dataset touches. */
    public const TAG = '[DEMO]';

    /** Prefix on every human-readable name. */
    public const NAME_PREFIX = 'Demo';

    /** Natural key of the second branch, which exists so D11's branch scoping has something to scope. */
    public const SECOND_BRANCH_CODE = 'DEMO-KHI';

    /**
     * Fixed PRNG seed. The dataset is the same shape on every machine, so "it works on mine" and a
     * screenshot of a bug refer to the same rows.
     */
    private const SEED = 20250925;

    /** The section 6.7 volumes, in one place so a slow machine can be given a smaller dataset. */
    private const VOLUME = [
        'clients' => 20,
        'leads' => 60,
        'partners' => 8,
        'projects' => 15,
        'project_payments' => 40,
        'courses' => 12,
        'batches' => 6,
        'teachers' => 6,
        'students' => 80,
        'admissions' => 120,
        'receipts' => 500,
        'refunds' => 40,
        'voids' => 3,
        'session_days' => 20,
        'exams' => 4,
        'certificates' => 10,
        'course_inquiries' => 30,
        'blog_posts' => 12,
        'jobs' => 6,
        'tickets' => 20,
        'meetings' => 30,
        'payouts' => 6,
    ];

    /** @var array<string, array{rows: int, ok: bool, detail: string}> */
    private array $stages = [];

    private User $actor;

    /** @var array<string, User> role name => the demo account that holds it */
    private array $accounts = [];

    /** @var list<Branch> */
    private array $branches = [];

    /** @var list<Client> */
    private array $clients = [];

    /** @var list<Collaborator> */
    private array $partners = [];

    /** @var list<Project> */
    private array $projects = [];

    /** @var list<Teacher> */
    private array $teachers = [];

    /** @var list<Course> */
    private array $courses = [];

    /** @var list<Batch> */
    private array $batches = [];

    /** @var list<Student> */
    private array $students = [];

    /** @var list<StudentAdmission> */
    private array $admissions = [];

    private int $sequence = 0;

    public function run(): void
    {
        $this->assertNotProduction();

        $password = $this->demoPassword();
        $this->actor = $this->resolveActor();

        mt_srand(self::SEED);

        // **Commissions are queued, so without this the dataset would have no ledger.**
        // `PaymentRecorded` queues `ProcessStudentFeeCommission` / `ProcessProjectPaymentCommission`
        // after the commit. On a demo box nobody has a worker running, so the receipts would exist
        // and the entitlements, ledger entries and wallets would not — and `integrity:verify` would
        // be asked about a dataset that is only half written. Running the queue inline for the length
        // of the seed is the only way the pairing in section 6.7 means anything.
        config(['queue.default' => 'sync']);

        // **And mail goes to the log for the length of the seed.** Three hundred notifications fired
        // inline would otherwise try a real SMTP transport: on an unconfigured box every stage that
        // notifies anybody fails, and on a configured one the demo dataset mails somebody. Neither is
        // acceptable from a fixture. The override is runtime-only and dies with the process.
        config(['mail.default' => 'log']);

        // Signed in for the duration: `Blameable` attributes every demo row to a real account rather
        // than to nobody, the fee and payment services read `auth()->user()` for the back-dating
        // permission (a demo history is months old, and the limit is 30 days), and `Gate::before`
        // short-circuits for Super Admin so no stage fails on an ability the console does not have.
        //
        // **`setUser()` and not `login()`.** `login()` fires `Login`, which the login-history
        // recorder listens for — and a console process has no request behind it, so the audit row it
        // would write is a sign-in that never happened, from an IP address that does not exist. The
        // seeder needs `auth()->user()` to resolve, not a session.
        Auth::setUser($this->actor);

        try {
            $this->stage('branches', fn (): int => $this->seedBranches());
            $this->stage('accounts', fn (): int => $this->seedAccounts($password));
            $this->stage('clients', fn (): int => $this->seedClients(), ['branches']);
            $this->stage('leads', fn (): int => $this->seedLeads(), ['accounts']);
            $this->stage('partners', fn (): int => $this->seedPartners());
            $this->stage('projects', fn (): int => $this->seedProjects(), ['clients', 'partners']);
            $this->stage('project_payments', fn (): int => $this->seedProjectPayments(), ['projects']);
            $this->stage('teachers', fn (): int => $this->seedTeachers(), ['branches']);
            $this->stage('courses', fn (): int => $this->seedCourses(), ['teachers']);
            $this->stage('batches', fn (): int => $this->seedBatches(), ['courses']);
            $this->stage('students', fn (): int => $this->seedStudents(), ['branches', 'partners']);
            $this->stage('admissions', fn (): int => $this->seedAdmissions(), ['students', 'batches']);
            $this->stage('receipts', fn (): int => $this->seedReceipts(), ['admissions']);
            $this->stage('refunds', fn (): int => $this->seedRefundsAndVoids(), ['receipts']);
            $this->stage('attendance', fn (): int => $this->seedAttendance(), ['admissions']);
            $this->stage('exams', fn (): int => $this->seedExams(), ['attendance']);
            $this->stage('certificates', fn (): int => $this->seedCertificates(), ['admissions']);
            $this->stage('payouts', fn (): int => $this->seedPayouts(), ['receipts']);
            $this->stage('course_inquiries', fn (): int => $this->seedCourseInquiries(), ['courses']);
            $this->stage('contact_inquiries', fn (): int => $this->seedContactInquiries());
            $this->stage('blog_posts', fn (): int => $this->seedBlogPosts(), ['accounts']);
            $this->stage('jobs', fn (): int => $this->seedJobs());
            $this->stage('tickets', fn (): int => $this->seedTickets(), ['accounts']);
            $this->stage('meetings', fn (): int => $this->seedMeetings(), ['accounts', 'clients']);
        } finally {
            // `forgetUser()`, the mirror of `setUser()`: there is no session to tear down, and
            // `logout()` would fire a sign-out nobody performed.
            Auth::forgetUser();
        }

        $this->printSummary();
    }

    /**
     * What ran, what it wrote and what failed — read by `demo:seed` for its exit code.
     *
     * @return array<string, array{rows: int, ok: bool, detail: string}>
     */
    public function report(): array
    {
        return $this->stages;
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    /**
     * **Production is a refusal, not a prompt** (section 6.7).
     *
     * A week after the accident nobody can tell which client is fictional: the rows have real
     * receipt numbers, real audit trails and real dates, and the only honest answer becomes "restore
     * the backup you took before it". Both the command and the seeder check, so `db:seed
     * --class=DemoSeeder` cannot get past the command's guard by going around it.
     */
    private function assertNotProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        throw new RuntimeException(
            'DemoSeeder refuses to run with APP_ENV=production. Demo rows in a production database are '
            .'indistinguishable from real data within a week — they carry real receipt numbers, real '
            .'audit rows and real dates. There is no flag that overrides this; use a staging database.'
        );
    }

    /**
     * The demo password — from `DEMO_PASSWORD`, with no default (section 6.7).
     *
     * A default here would be a known password on every demo box on the internet, and the one demo
     * box that is quietly reachable from outside is the one nobody remembers setting up.
     */
    private function demoPassword(): string
    {
        $password = trim((string) env('DEMO_PASSWORD', ''));

        if ($password === '') {
            throw new RuntimeException(
                'DEMO_PASSWORD is not set. The demo accounts deliberately have no default password: a '
                .'default is a known credential on every demo installation. Put one in .env (it is '
                .'already listed in .env.example) and run this again.'
            );
        }

        return $password;
    }

    /**
     * The account every demo row is attributed to.
     *
     * A Super Admin, because the seed writes across every module and back-dates money: `Gate::before`
     * short-circuits for that role, so no stage fails on a permission, and the back-date guard in
     * `PaymentService` accepts a value date older than `finance.backdate_limit_days`.
     */
    private function resolveActor(): User
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        $actor = User::query()
            ->whereHas('roles', fn ($q) => $q
                ->where('name', User::SUPER_ADMIN_ROLE)
                ->where('guard_name', $guard))
            ->orderBy('id')
            ->first();

        if ($actor === null) {
            throw new RuntimeException(
                'No Super Admin account exists, so there is nobody to attribute the demo rows to. Run '
                .'`php artisan user:create-super-admin --name="..." --email="..."` first (section 6.8 '
                .'step 9).'
            );
        }

        return $actor;
    }

    /*
    |--------------------------------------------------------------------------
    | The stage runner
    |--------------------------------------------------------------------------
    */

    /**
     * Run one stage, record what it wrote, and never let it take the rest of the dataset down.
     *
     * Deliberately **not** wrapped in a transaction of its own. The services below open their own —
     * a receipt is one transaction with the ledger row and the wallet movement, which is invariant 6
     * of CLAUDE.md — and a seeder-level transaction around five hundred of them would hold every row
     * lock in the institute for the length of the run and defeat the `afterCommit` dispatch the
     * commission engine relies on.
     *
     * @param  list<string>  $needs  stages whose rows this one builds on
     */
    private function stage(string $name, Closure $work, array $needs = []): void
    {
        foreach ($needs as $need) {
            if (($this->stages[$need]['ok'] ?? false) !== true) {
                $this->stages[$name] = [
                    'rows' => 0,
                    'ok' => false,
                    'detail' => 'skipped — the "'.$need.'" stage did not finish',
                ];

                return;
            }
        }

        $started = microtime(true);

        try {
            $rows = (int) $work();

            $this->stages[$name] = ['rows' => $rows, 'ok' => true, 'detail' => ''];
            $this->seedLine(sprintf(
                '  demo: %-18s %5d row(s)  %5.1fs',
                $name,
                $rows,
                microtime(true) - $started,
            ));
        } catch (Throwable $e) {
            $this->stages[$name] = [
                'rows' => 0,
                'ok' => false,
                'detail' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 300),
            ];

            $this->seedWarning(sprintf('  demo: %-18s FAILED — %s', $name, $e->getMessage()));
        }
    }

    private function printSummary(): void
    {
        $rows = [];

        foreach ($this->stages as $name => $stage) {
            $rows[] = [
                $stage['ok'] ? 'ok' : 'FAIL',
                $name,
                (string) $stage['rows'],
                mb_substr($stage['detail'], 0, 70),
            ];
        }

        $this->seedTable(['', 'stage', 'rows', 'detail'], $rows);

        $this->seedInfo(sprintf(
            'Demo dataset: %d stage(s) ok, %d failed. Every row is marked: e-mail on @%s, name '
            .'prefixed "%s ", notes tagged %s.',
            count(array_filter($this->stages, static fn (array $s): bool => $s['ok'])),
            count(array_filter($this->stages, static fn (array $s): bool => ! $s['ok'])),
            self::EMAIL_DOMAIN,
            self::NAME_PREFIX,
            self::TAG,
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 1 — branches and accounts
    |--------------------------------------------------------------------------
    */

    /**
     * A second branch, so D11's branch scoping has two values to be wrong about.
     *
     * One branch proves nothing: every scoped query passes when there is only one answer. The head
     * office row belongs to `BranchSeeder` and is read, never edited.
     */
    private function seedBranches(): int
    {
        $created = 0;

        $branch = Branch::withTrashed()->where('code', self::SECOND_BRANCH_CODE)->first();

        if ($branch === null) {
            $branch = Branch::query()->create([
                'code' => self::SECOND_BRANCH_CODE,
                'name' => self::NAME_PREFIX.' Karachi Campus',
                'city' => 'Karachi',
                'email' => 'karachi@'.self::EMAIL_DOMAIN,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]);
            $created++;
        } elseif ($branch->trashed()) {
            $branch->restore();
        }

        $this->branches = array_values(array_filter([Branch::default(), $branch->refresh()]));

        return $created;
    }

    /**
     * One demo account per seeded role (section 6.7: the eighteen accounts).
     *
     * `must_change_password` is true on every one of them, which is the difference between a demo
     * account and a back door: the shared `DEMO_PASSWORD` gets somebody in once and then has to be
     * replaced.
     *
     * **Super Admin is one of the eighteen**, as section 6.7 specifies — a demonstration of a system
     * with five panels needs the account that can see all of them. Three things keep that from being
     * a hole: this seeder cannot run in production at all, the password is whatever the operator put
     * in `DEMO_PASSWORD` rather than anything shipped, and the account is pinned to the
     * change-password screen until somebody replaces it. It does mean a demo box has two Super
     * Admins, so `user:create-super-admin` will (correctly) ask for `--force` there.
     */
    private function seedAccounts(string $password): int
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $created = 0;

        $roles = Role::query()->where('guard_name', $guard)->ordered()->get();

        foreach ($roles as $role) {
            $slug = Str::slug((string) $role->name);
            $email = $slug.'@'.self::EMAIL_DOMAIN;

            $user = User::withTrashed()->where('email', $email)->first();

            if ($user === null) {
                $user = new User;
                $user->email = $email;
                $user->password = $password;          // hashed by the model cast
                $user->password_changed_at = null;
                $user->locale = 'en';
                $created++;
            }

            if ($user->trashed()) {
                $user->restore();
            }

            $user->name = self::NAME_PREFIX.' '.$role->displayName();
            $user->status = UserStatus::Active;
            $user->status_reason = null;
            // Set, never filled — it is a security flag (User::class, SEC-12).
            $user->must_change_password = true;

            $user->status_changed_at ??= now();
            $user->theme ??= ThemePreference::System;
            $user->email_verified_at ??= now();
            // `?? null` and not `$this->branches[0]` bare: the accounts stage deliberately declares no
            // dependency on `branches` (an account is worth having even when the branch stage failed),
            // so on that path the list is empty and a bare index would emit an undefined-key warning
            // on every one of the eighteen rows.
            $user->branch_id ??= ($this->branches[0] ?? null)?->getKey() ?? Branch::default()?->getKey();

            $user->save();

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            $this->accounts[(string) $role->name] = $user->refresh();
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 2 — CRM
    |--------------------------------------------------------------------------
    */

    /**
     * Twenty clients, one of them wired to the demo Client-panel account.
     *
     * The portal link is a direct write rather than `ClientService::enablePortal()` on purpose: that
     * method queues an invitation mail, and a fixture that mails somebody is a fixture nobody runs
     * twice. The columns it sets here are exactly the two the portal reads.
     */
    private function seedClients(): int
    {
        $service = app(ClientService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['clients']; $i++) {
            $email = sprintf('client-%02d@%s', $i, self::EMAIL_DOMAIN);
            $existing = Client::withTrashed()->where('email', $email)->first();

            if ($existing !== null) {
                $this->clients[] = $existing;

                continue;
            }

            $company = $this->pick(self::COMPANIES).' '.$this->pick(['Pvt Ltd', 'Solutions', 'Traders', 'Group']);

            $this->clients[] = $service->create(new ClientData(
                name: sprintf('%s Client %02d — %s', self::NAME_PREFIX, $i, $company),
                clientType: $i % 4 === 0 ? ClientType::Individual : ClientType::Company,
                companyName: $i % 4 === 0 ? null : $company,
                email: $email,
                phone: $this->phone(3000000 + $i),
                city: $this->pick(self::CITIES),
                country: 'Pakistan',
                source: $this->pick(InquirySource::cases()),
                notes: self::TAG.' fictional client, safe to delete.',
            ));

            $created++;
        }

        $this->linkClientPortalAccount();

        return $created;
    }

    private function linkClientPortalAccount(): void
    {
        $account = $this->accounts['Client'] ?? null;
        $client = $this->clients[0] ?? null;

        if ($account === null || $client === null || $client->user_id !== null) {
            return;
        }

        $client->forceFill(['user_id' => $account->getKey(), 'portal_enabled' => true])->save();
    }

    /**
     * Sixty leads spread across every status, so the board has something in each column.
     */
    private function seedLeads(): int
    {
        $service = app(LeadService::class);
        $statuses = LeadStatus::cases();
        $sales = $this->accounts['Sales Executive'] ?? $this->actor;
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['leads']; $i++) {
            $email = sprintf('lead-%02d@%s', $i, self::EMAIL_DOMAIN);

            if (Lead::withTrashed()->where('email', $email)->exists()) {
                continue;
            }

            $service->create(new LeadData(
                name: sprintf('%s Lead %02d %s', self::NAME_PREFIX, $i, $this->pick(self::SURNAMES)),
                company: $this->pick(self::COMPANIES),
                email: $email,
                phone: $this->phone(3100000 + $i),
                country: 'Pakistan',
                budgetAmount: Money::of((string) (50000 + ($i * 2500))),
                source: $this->pick(InquirySource::cases()),
                notes: self::TAG.' fictional lead.',
                assignedTo: $i % 3 === 0 ? (int) $sales->getKey() : null,
                status: $statuses[($i - 1) % count($statuses)],
                // A demo dataset has near-duplicates on purpose — that is what the detector is for —
                // so the guard is answered up front rather than throwing halfway through the stage.
                confirmDuplicate: true,
            ));

            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 3 — collaborators and commission rules
    |--------------------------------------------------------------------------
    */

    /**
     * Eight partners, each with a student rule and a project rule in force.
     *
     * Rule versions come from `CommissionRuleService::createVersion()` and never from an insert:
     * `collaborator_commission_settings` is append-only and versioned, and a rule written by hand is
     * a rule with no effective date anybody can defend (D19, phase-10-12 section 5).
     *
     * The first partner is created with a fixed amount per student rather than a percentage, because
     * the fixed/percentage split is where the calculator has two code paths and a dataset with only
     * one of them exercises half the engine.
     */
    private function seedPartners(): int
    {
        $collaborators = app(CollaboratorService::class);
        $rules = app(CommissionRuleService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['partners']; $i++) {
            // Upper case because `CollaboratorCodeService::normalizeReferralCode()` normalises to it;
            // looking the row up by the same spelling it will be stored under is what keeps this
            // stage idempotent instead of creating eight more partners on the second run.
            $code = sprintf('DEMO%02d', $i);
            $existing = Collaborator::withTrashed()->where('referral_code', $code)->first();

            if ($existing !== null) {
                $this->partners[] = $existing;

                continue;
            }

            $partner = $collaborators->create(
                new CollaboratorData(
                    name: sprintf('%s Partner %02d %s', self::NAME_PREFIX, $i, $this->pick(self::SURNAMES)),
                    collaborationType: $this->pick([
                        CollaborationType::ReferralPartner,
                        CollaborationType::MarketingPartner,
                        CollaborationType::SalesPartner,
                        CollaborationType::Agency,
                    ]),
                    email: sprintf('partner-%02d@%s', $i, self::EMAIL_DOMAIN),
                    phone: $this->phone(3200000 + $i),
                    country: 'Pakistan',
                    joiningDate: Carbon::now()->subMonths(18),
                    notes: self::TAG.' fictional partner.',
                    referralCode: $code,
                ),
                $this->actor,
                active: true,
            );

            $rules->createVersion(
                $partner,
                CommissionScope::Student,
                new RuleData(
                    scope: CommissionScope::Student,
                    calculationType: $i === 1 ? CommissionCalculationType::Fixed : CommissionCalculationType::Percentage,
                    effectiveFrom: Carbon::now()->subMonths(18)->startOfMonth(),
                    rate: $i === 1 ? null : Money::of((string) (5 + $i)),
                    fixedAmount: $i === 1 ? '1500.00' : null,
                ),
                self::TAG.' opening student rule.',
            );

            $rules->createVersion(
                $partner,
                CommissionScope::Project,
                new RuleData(
                    scope: CommissionScope::Project,
                    calculationType: CommissionCalculationType::Percentage,
                    effectiveFrom: Carbon::now()->subMonths(18)->startOfMonth(),
                    rate: Money::of((string) (3 + $i)),
                ),
                self::TAG.' opening project rule.',
            );

            $this->partners[] = $partner->refresh();
            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 4 — projects and project money
    |--------------------------------------------------------------------------
    */

    /**
     * Fifteen projects with milestones and tasks; every third one credited to a partner.
     *
     * The referral is attached before any money arrives, which is the only order that means
     * anything: attribution is resolved on the payment date, so a partner attached after the first
     * receipt earns nothing on it — correctly, and that is a case worth having in the dataset too.
     */
    private function seedProjects(): int
    {
        $projects = app(ProjectService::class);
        $milestones = app(MilestoneService::class);
        $tasks = app(TaskService::class);
        $referrals = app(ReferralService::class);

        $manager = $this->accounts['Project Manager'] ?? $this->actor;
        $developer = $this->accounts['Developer'] ?? $this->actor;
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['projects']; $i++) {
            $name = sprintf('%s Project %02d — %s', self::NAME_PREFIX, $i, $this->pick(self::PROJECT_KINDS));

            $existing = Project::withTrashed()->where('name', $name)->first();

            if ($existing !== null) {
                $this->projects[] = $existing;

                continue;
            }

            $client = $this->clients[($i - 1) % max(1, count($this->clients))] ?? null;

            if ($client === null) {
                break;
            }

            $value = Money::of((string) (150000 + ($i * 45000)));

            $project = $projects->create(new ProjectData(
                name: $name,
                clientId: (int) $client->getKey(),
                projectManagerId: (int) $manager->getKey(),
                description: self::TAG.' fictional project, generated by DemoSeeder.',
                projectType: $i % 5 === 0 ? ProjectType::Retainer : ProjectType::FixedPrice,
                priority: $this->pick(Priority::cases()),
                startDate: Carbon::now()->subMonths(12 - ($i % 10))->toDateString(),
                deadline: Carbon::now()->addMonths(1 + ($i % 6))->toDateString(),
                budgetAmount: $value,
                projectValue: $value,
            ), $this->actor);

            if ($i % 3 === 0 && $this->partners !== []) {
                $referrals->attachSubject(
                    ReferralSubject::Project,
                    (int) $project->getKey(),
                    $this->partners[$i % count($this->partners)],
                    ReferralSource::ManualSelection,
                    null,
                    Carbon::parse((string) $project->start_date),
                );
            }

            foreach (['Discovery', 'Build', 'Launch'] as $index => $title) {
                $milestone = $milestones->create($project, [
                    'title' => self::NAME_PREFIX.' '.$title,
                    'description' => self::TAG.' milestone.',
                    'due_date' => Carbon::now()->addWeeks(2 + ($index * 4))->toDateString(),
                ], $this->actor);

                if ($index === 0) {
                    $milestones->changeStatus($milestone, MilestoneStatus::Completed, null, $this->actor);
                }

                for ($t = 1; $t <= 3; $t++) {
                    $task = $tasks->create(new TaskData(
                        projectId: (int) $project->getKey(),
                        projectMilestoneId: (int) $milestone->getKey(),
                        title: sprintf('%s %s task %d', self::NAME_PREFIX, $title, $t),
                        description: self::TAG.' task.',
                        priority: $this->pick(Priority::cases()),
                        dueDate: Carbon::now()->addWeeks($index + $t)->toDateString(),
                        estimatedMinutes: 120 * $t,
                    ), $this->actor);

                    // (task, user, collaboratorId, note, actor) — a task has one assignee, so the
                    // collaborator slot stays null.
                    $tasks->assign($task, $developer, null, null, $this->actor);

                    if ($index === 0) {
                        $tasks->changeStatus($task, TaskStatus::Completed, null, $this->actor);
                    }
                }
            }

            if ($i % 4 === 0) {
                $projects->changeStatus($project, ProjectStatus::InProgress, null, $this->actor);
            }

            $this->projects[] = $project->refresh();
            $created++;
        }

        return $created;
    }

    /**
     * Forty received project payments — through `PaymentService`, which is what fires the project
     * commission engine.
     *
     * Value dates walk backwards a few weeks at a time so the dataset has money in several months
     * and the finance reports have something to group by. Back-dating past
     * `finance.backdate_limit_days` is allowed here only because the seed is signed in as a Super
     * Admin; that is the same check a real screen runs, not a bypass.
     */
    private function seedProjectPayments(): int
    {
        $payments = app(PaymentService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['project_payments']; $i++) {
            $project = $this->projects[($i - 1) % max(1, count($this->projects))] ?? null;

            if ($project === null) {
                break;
            }

            $key = sprintf('demo:project-payment:%d:%d', (int) $project->getKey(), $i);

            if (ProjectPayment::query()->where('idempotency_key', $key)->exists()) {
                continue;
            }

            $result = $payments->recordProjectPayment($project, new RecordPaymentData(
                amount: Money::of((string) (25000 + (($i % 6) * 15000))),
                method: $this->pick([
                    PaymentMethod::BankTransfer,
                    PaymentMethod::Cash,
                    PaymentMethod::Cheque,
                    PaymentMethod::OnlineGateway,
                ]),
                paidOn: Carbon::now()->subDays(7 * ($i % 40)),
                notes: self::TAG.' fictional receipt.',
                receivedBy: (int) $this->actor->getKey(),
                idempotencyKey: $key,
                confirmDuplicate: true,
            ));

            if ($result->created) {
                $created++;
            }
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 5 — the institute catalogue
    |--------------------------------------------------------------------------
    */

    private function seedTeachers(): int
    {
        $service = app(TeacherService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['teachers']; $i++) {
            $email = sprintf('teacher-%02d@%s', $i, self::EMAIL_DOMAIN);
            $existing = Teacher::withTrashed()->where('email', $email)->first();

            if ($existing !== null) {
                $this->teachers[] = $existing;

                continue;
            }

            $this->teachers[] = $service->create([
                'name' => sprintf('%s Teacher %02d %s', self::NAME_PREFIX, $i, $this->pick(self::SURNAMES)),
                'email' => $email,
                'phone' => $this->phone(3300000 + $i),
                'branch_id' => $this->branchId($i),
                'qualification' => $this->pick(['MSc Computer Science', 'BSCS', 'MBA IT', 'MS Data Science']),
                'experience_years' => 2 + ($i % 8),
                'specialization' => $this->pick(self::COURSE_SUBJECTS),
                'joining_date' => Carbon::now()->subYears(2)->toDateString(),
                'is_public' => true,
                'notes' => self::TAG.' fictional teacher.',
                // No login: the account stage already owns the demo Teacher-panel account, and a
                // second teacher login per row would be eighteen more passwords nobody set.
                'create_login' => false,
            ], $this->actor);

            $created++;
        }

        return $created;
    }

    /**
     * Twelve published courses, each with an outline.
     *
     * A course cannot be published without a category, a fee and at least one module
     * (`Course::publishingGaps()`), so the outline is built before the publish rather than after —
     * the demo catalogue is meant to render on the public site, which is the whole point of having
     * one.
     */
    private function seedCourses(): int
    {
        $courses = app(CourseService::class);
        $outline = app(CourseOutlineService::class);
        $created = 0;

        $category = $this->reviveOrCreate(
            CourseCategory::class,
            ['slug' => 'demo-programmes'],
            [
                'name' => self::NAME_PREFIX.' Programmes',
                'description' => self::TAG.' fictional course category.',
                'is_active' => true,
                'sort_order' => 90,
            ],
        );

        for ($i = 1; $i <= self::VOLUME['courses']; $i++) {
            $subject = self::COURSE_SUBJECTS[($i - 1) % count(self::COURSE_SUBJECTS)];
            $name = sprintf('%s %s', self::NAME_PREFIX, $subject);

            $existing = Course::withTrashed()->where('name', $name)->first();

            if ($existing !== null) {
                $this->courses[] = $existing;

                continue;
            }

            $course = $courses->create([
                'name' => $name,
                'course_category_id' => $category->getKey(),
                'branch_id' => $this->branchId($i),
                'short_description' => self::TAG.' fictional course.',
                'full_description' => self::TAG.' Generated by DemoSeeder for demonstration only.',
                'duration_value' => 3 + ($i % 4),
                'duration_unit' => 'months',
                'total_classes' => 36,
                'class_duration_minutes' => 90,
                'course_fee' => Money::of((string) (30000 + ($i * 2500))),
                'admission_fee' => Money::of('3000'),
                'registration_fee' => Money::of('1000'),
                'monthly_fee' => Money::of('0'),
                'installment_available' => true,
                'max_installments' => 4,
                'level' => $this->pick(CourseLevel::cases())->value,
                'delivery_mode' => $this->pick(DeliveryMode::cases())->value,
                'default_teacher_id' => $this->teacherId($i),
                'certificate_available' => true,
                'admission_open' => true,
                'notes' => self::TAG.' fictional course.',
            ], $this->actor);

            foreach (['Foundations', 'Core', 'Project Work'] as $moduleIndex => $moduleTitle) {
                $module = $outline->addModule($course, [
                    'title' => $moduleTitle,
                    'description' => self::TAG.' module.',
                    'duration_minutes' => 600,
                ], $this->actor);

                for ($t = 1; $t <= 3; $t++) {
                    $outline->addTopic($module, [
                        'title' => sprintf('%s topic %d.%d', $moduleTitle, $moduleIndex + 1, $t),
                        'description' => self::TAG.' topic.',
                        'weight' => 1,
                        'estimated_minutes' => 120,
                    ], $this->actor);
                }
            }

            $courses->publish($course->refresh(), $this->actor);

            $this->courses[] = $course->refresh();
            $created++;
        }

        return $created;
    }

    /**
     * Six batches, each on its own weekday slot.
     *
     * The slots are deliberately distinct per batch: `ClassSessionService` runs clash detection over
     * teacher, classroom and batch, and two demo batches sharing a teacher at the same hour would
     * make the attendance stage fail on a conflict that is the detector working correctly.
     */
    private function seedBatches(): int
    {
        $batches = app(BatchService::class);
        $created = 0;

        // **The working days come from the settings, not from a list in this file.** `BatchService`
        // refuses a day the institute does not teach on, and which days those are is an
        // administrator's decision — a hard-coded Saturday fails on an installation that does not
        // teach on one, which is a seeder bug reported as an institute rule.
        $working = array_values(array_filter(
            (array) setting('institute.timetable_working_days', []),
            static fn (mixed $day): bool => is_string($day) && $day !== '',
        ));

        if ($working === []) {
            $working = self::WEEKDAYS;
        }

        for ($i = 1; $i <= self::VOLUME['batches']; $i++) {
            $name = sprintf('%s Batch %02d', self::NAME_PREFIX, $i);
            $existing = Batch::withTrashed()->where('name', $name)->first();

            if ($existing !== null) {
                $this->batches[] = $existing;

                continue;
            }

            $course = $this->courses[($i - 1) % max(1, count($this->courses))] ?? null;

            if ($course === null) {
                break;
            }

            // **One room per batch.** Every demo batch used to share a single lab, and because the
            // attendance stage holds a session for every batch on the same dates, two overlapping
            // sessions in one room made the clash detector refuse the whole stage — the detector
            // being right, and the fixture being wrong.
            $classroom = $this->reviveOrCreate(
                Classroom::class,
                ['code' => sprintf('DEMO-R%d', $i)],
                [
                    'name' => sprintf('%s Lab %d', self::NAME_PREFIX, $i),
                    'branch_id' => (int) $course->branch_id,
                    'type' => ClassroomType::Lab->value,
                    'capacity' => 30,
                    'is_active' => true,
                    'notes' => self::TAG.' fictional classroom.',
                ],
            );

            $batch = $batches->create([
                'name' => $name,
                'course_id' => (int) $course->getKey(),
                'branch_id' => (int) $course->branch_id,
                'teacher_id' => $this->teacherId($i),
                'classroom_id' => $classroom->getKey(),
                'start_date' => Carbon::now()->subMonths(3)->startOfMonth()->toDateString(),
                'end_date' => Carbon::now()->addMonths(3)->endOfMonth()->toDateString(),
                'days' => [$working[($i - 1) % count($working)]],
                'start_time' => sprintf('%02d:00', 9 + $i),
                'end_time' => sprintf('%02d:30', 10 + $i),
                'delivery_mode' => DeliveryMode::Physical->value,
                'student_capacity' => 30,
                'notes' => self::TAG.' fictional batch.',
            ], $this->actor);

            // Enrolling, not Planned: a planned batch refuses enrolment, and the admission stage
            // needs a seat to hand out.
            $batches->changeStatus($batch, BatchStatus::Enrolling, null, $this->actor);

            $this->batches[] = $batch->refresh();
            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 6 — students, admissions, charges
    |--------------------------------------------------------------------------
    */

    /**
     * Eighty students; every fourth one credited to a partner from the day they arrived.
     */
    private function seedStudents(): int
    {
        $students = app(StudentService::class);
        $referrals = app(ReferralService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['students']; $i++) {
            $email = sprintf('student-%02d@%s', $i, self::EMAIL_DOMAIN);
            $existing = Student::withTrashed()->where('email', $email)->first();

            if ($existing !== null) {
                $this->students[] = $existing;

                continue;
            }

            $student = $students->create([
                'name' => sprintf('%s Student %02d %s', self::NAME_PREFIX, $i, $this->pick(self::SURNAMES)),
                'father_name' => self::NAME_PREFIX.' '.$this->pick(self::SURNAMES),
                'email' => $email,
                'phone' => $this->phone(3400000 + $i),
                'city' => $this->pick(self::CITIES),
                'branch_id' => $this->branchId($i),
                'joining_date' => Carbon::now()->subMonths(6)->toDateString(),
                'education' => $this->pick(['Intermediate', 'BSCS', 'BCom', 'Matric']),
                'notes' => self::TAG.' fictional student.',
            ], $this->actor);

            if ($i % 4 === 0 && $this->partners !== []) {
                $referrals->attachSubject(
                    ReferralSubject::Student,
                    (int) $student->getKey(),
                    $this->partners[$i % count($this->partners)],
                    ReferralSource::ReferralLink,
                    null,
                    Carbon::now()->subMonths(6),
                );
            }

            $this->students[] = $student->refresh();
            $created++;
        }

        return $created;
    }

    /**
     * A hundred and twenty admissions, each walked through the pipeline that actually exists.
     *
     * **The pipeline is driven step by step rather than through `AdmissionService::requestFees()` and
     * `::assignBatch()`, because neither of those can currently be called**: their implemented
     * signatures take `array $plan` and `int $batchId` and then hand those straight to
     * `StudentFeeService::generateStructure(StudentAdmission, FeeStructureData)` and
     * `BatchEnrollmentService::enroll(Student, Batch, …)`, so both raise a `TypeError` before they do
     * anything. phase-14-17 section 13.1 specifies them as `requestFees(StudentAdmission,
     * FeeStructureData)` and `assignBatch(StudentAdmission, Batch, array)`; the fix belongs to that
     * phase's file, not to a seeder. Until it lands, this stage calls the two owning services
     * directly — which is what the delegating methods are documented to do anyway (INV-I1) — and
     * advances `stage` itself, writing exactly the two columns `assignBatch()` writes.
     *
     * Every charge is `generateStructure()`'s, so `SUM(charges.net_amount) = admission.net_payable`
     * is asserted by the service on every one of the hundred and twenty.
     */
    private function seedAdmissions(): int
    {
        $admissions = app(AdmissionService::class);
        $fees = app(StudentFeeService::class);
        $enrollments = app(BatchEnrollmentService::class);
        $payments = app(PaymentService::class);
        $created = 0;

        if ($this->students === [] || $this->batches === []) {
            return 0;
        }

        for ($i = 1; $i <= self::VOLUME['admissions']; $i++) {
            $student = $this->students[($i - 1) % count($this->students)];
            $batch = $this->batches[($i - 1) % count($this->batches)];
            $course = Course::query()->find($batch->course_id);

            if ($course === null) {
                continue;
            }

            $live = StudentAdmission::query()
                ->where('student_id', $student->getKey())
                ->where('course_id', $course->getKey())
                ->first();

            if ($live !== null) {
                $this->admissions[] = $live;

                continue;
            }

            // A quarter of the intake negotiated something off, and every twelfth got a scholarship:
            // the discount rows this produces are written by `copyAdmissionReductions()`, which is
            // the only writer of a fee discount that the ceiling check can defend.
            $discount = $i % 4 === 0 ? Money::of('2500') : Money::of('0');
            $scholarship = $i % 12 === 0 ? Money::of('5000') : Money::of('0');

            $admission = $admissions->create($student, $course, [
                'branch_id' => $student->branch_id,
                'admission_date' => Carbon::now()->subMonths(3)->addDays($i % 60)->toDateString(),
                'discount_amount' => $discount,
                'scholarship_amount' => $scholarship,
                'discount_reason' => Money::isPositive($discount) ? self::TAG.' negotiated discount.' : null,
                'notes' => self::TAG.' fictional admission.',
                'counselor_id' => $this->actor->getKey(),
            ], null, $this->actor);

            $admission = $admissions->register($admission, $this->actor);

            $structure = $fees->generateStructure($admission, new FeeStructureData(
                amounts: [
                    StudentFeeType::AdmissionFee->value => (string) $admission->admission_fee,
                    StudentFeeType::RegistrationFee->value => (string) $admission->registration_fee,
                    StudentFeeType::CourseFee->value => (string) $admission->course_fee,
                ],
                copyAdmissionDiscount: true,
                approvedBy: (int) $this->actor->getKey(),
            ), $this->actor);

            // An installment plan on a third of the course-fee charges — and always before the first
            // receipt, because `buildInstallmentPlan()` refuses a plan on a charge that already holds
            // money (and is right to: the lines would have to be built around it).
            $courseFee = $structure->charges
                ->first(fn (StudentFee $c): bool => $c->fee_type === StudentFeeType::CourseFee);

            // A scholarship granted after the charges were raised, on one admission in fifteen.
            // `addDiscount()` rather than a second `discount_amount` on the admission, because this
            // is the other shape entirely: a reduction agreed later, by a named approver, on a
            // charge that already exists — and it is the one that has to redistribute a plan.
            if ($courseFee !== null && $i % 15 === 0) {
                $fees->addDiscount($courseFee, new DiscountData(
                    type: FeeDiscountType::Scholarship,
                    reason: self::TAG.' merit scholarship agreed after admission.',
                    amount: Money::of('2000'),
                    approvedBy: (int) $this->actor->getKey(),
                    effectiveOn: Carbon::now(),
                ), $this->actor);

                $courseFee = $courseFee->refresh();
            }

            if ($courseFee !== null && $i % 3 === 0) {
                $this->buildPlan($fees, $courseFee, 4);
            }

            $enrollments->enroll($student, $batch, $admission, [
                'enrolled_on' => Carbon::parse((string) $admission->admission_date)->toDateString(),
                'notes' => self::TAG.' fictional enrolment.',
            ], $this->actor);

            // The two columns `AdmissionService::assignBatch()` writes, written here for the reason
            // in the method docblock. Nothing financial moves with a batch (phase-18 section 2.1).
            $admission->forceFill([
                'stage' => AdmissionStage::BatchAssignment->value,
                'batch_id' => $batch->getKey(),
                'updated_by' => $this->actor->getKey(),
            ])->save();

            // The first receipt. `institute.require_fee_before_activation` defaults to `any_payment`,
            // so without this the activation below would correctly refuse.
            $first = $structure->charges->first();

            if ($first instanceof StudentFee) {
                $this->receiveFirst($payments, $first, $i);
            }

            $fresh = $admission->refresh();

            // Only when the service says it is ready. Asking it rather than assuming keeps the
            // dataset honest: an admission that is not activatable stays visible at
            // `batch_assignment`, which is a state the screens have to handle anyway.
            if ($admissions->activationGaps($fresh) === []) {
                $fresh = $admissions->activate($fresh, $this->actor);

                if ($i % 9 === 0) {
                    $fresh = $admissions->complete($fresh, $this->actor);
                }
            }

            $this->admissions[] = $fresh;
            $created++;
        }

        return $created;
    }

    /**
     * Split a charge into `$count` installments that sum exactly to its net amount.
     *
     * `Money::distribute()` is the only function in the system allowed to split a money value, and
     * it splits in integer paisa so the lines add up by construction — which is what
     * `assertPlanIntegrity()` then re-proves.
     */
    private function buildPlan(StudentFeeService $fees, StudentFee $charge, int $count): void
    {
        $shares = Money::distribute((string) $charge->net_amount, $count);
        $lines = [];

        foreach ($shares as $index => $share) {
            $lines[] = new InstallmentLine(
                number: $index + 1,
                amount: $share,
                dueDate: Carbon::now()->subMonths(2)->addMonths($index)->toImmutable(),
            );
        }

        $fees->buildInstallmentPlan($charge, $lines, $this->actor);
    }

    /**
     * The opening receipt against a charge — the first installment when there is a plan, otherwise
     * the whole balance for a small head and half of it for a large one.
     *
     * When a plan exists the line id goes with the money. A receipt against a charge that has
     * installments but names no line is legal and is *not* what this is demonstrating: the demo
     * dataset should read like an office that allocates its receipts.
     */
    private function receiveFirst(PaymentService $payments, StudentFee $charge, int $salt): bool
    {
        $line = $charge->installments()->orderBy('installment_no')->first();

        if ($line !== null) {
            return $this->receive(
                $payments,
                $charge,
                $line->remaining(),
                $salt,
                (int) $line->installment_no,
                (int) $line->getKey(),
            );
        }

        $balance = Money::of((string) $charge->balance_amount);

        $amount = Money::compare($balance, '10000.00') === 1
            ? Money::distribute($balance, 2)[0]
            : $balance;

        return $this->receive($payments, $charge, $amount, $salt, 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 7 — receipts, refunds, voids
    |--------------------------------------------------------------------------
    */

    /**
     * Receipts up to the section 6.7 volume, in a deliberate mix.
     *
     * Full settlements, partial payments, and installment lines paid one at a time — the three
     * shapes the fee status machine has to derive `pending` / `partial` / `paid` from, and the three
     * the commission engine has to produce one entry per received payment for.
     */
    private function seedReceipts(): int
    {
        $payments = app(PaymentService::class);
        $created = 0;
        $budget = self::VOLUME['receipts'];

        $charges = StudentFee::query()
            ->whereIn('student_admission_id', array_map(
                static fn (StudentAdmission $a): int => (int) $a->getKey(),
                $this->admissions,
            ))
            ->orderBy('id')
            ->get();

        foreach ($charges as $index => $charge) {
            if ($created >= $budget) {
                break;
            }

            $charge = $charge->refresh();
            $lines = $charge->installments()->orderBy('installment_no')->get();

            if ($lines->isNotEmpty()) {
                foreach ($lines as $line) {
                    if ($created >= $budget) {
                        break;
                    }

                    // `remaining()`, not a `balance_amount` column — installment lines do not have
                    // one. What is owed is `amount - paid - waived`, and the model is the only place
                    // that subtraction is allowed to live.
                    $owed = $line->remaining();

                    if (Money::compare($owed, Money::ZERO) <= 0) {
                        continue;
                    }

                    if ($this->receive($payments, $charge, $owed, $index, (int) $line->installment_no, (int) $line->getKey())) {
                        $created++;
                    }
                }

                continue;
            }

            $balance = Money::of((string) $charge->balance_amount);

            if (Money::compare($balance, Money::ZERO) <= 0) {
                continue;
            }

            // Every fifth charge is left part-paid on purpose: a dataset in which everything is
            // settled never shows an ageing bucket, a reminder or an overdue row.
            $amount = $index % 5 === 0 ? Money::distribute($balance, 3)[0] : $balance;

            if ($this->receive($payments, $charge, $amount, $index, 90)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * One receipt, keyed so a re-run recognises it instead of taking the money twice.
     */
    private function receive(
        PaymentService $payments,
        StudentFee $charge,
        string $amount,
        int $salt,
        int $slot,
        ?int $installmentId = null,
    ): bool {
        if (Money::compare(Money::of($amount), Money::ZERO) <= 0) {
            return false;
        }

        $key = sprintf('demo:receipt:%d:%d:%d', (int) $charge->getKey(), $slot, $salt);

        if (StudentFeePayment::query()->where('idempotency_key', $key)->exists()) {
            return false;
        }

        $result = $payments->recordStudentFeePayment($charge, new RecordPaymentData(
            amount: Money::of($amount),
            method: $this->pick([
                PaymentMethod::Cash,
                PaymentMethod::BankTransfer,
                PaymentMethod::Easypaisa,
                PaymentMethod::Jazzcash,
            ]),
            paidOn: Carbon::now()->subDays(3 + (($salt + $slot) % 80)),
            installmentId: $installmentId,
            notes: self::TAG.' fictional receipt.',
            receivedBy: (int) $this->actor->getKey(),
            idempotencyKey: $key,
            confirmDuplicate: true,
        ));

        return $result->created;
    }

    /**
     * Forty refunds and three voids — the reversal side of the ledger.
     *
     * Nothing is updated and nothing is deleted: a reversal is a new row that references the
     * original, and the commission it earned is released by a negative ledger entry (CLAUDE.md
     * section 5). A dataset without them cannot demonstrate that, and `integrity:verify` would never
     * be asked the only question that is hard.
     */
    private function seedRefundsAndVoids(): int
    {
        $payments = app(PaymentService::class);
        $created = 0;

        $receipts = StudentFeePayment::query()
            ->where('idempotency_key', 'like', 'demo:receipt:%')
            ->orderBy('id')
            ->limit(self::VOLUME['refunds'] + self::VOLUME['voids'] + 40)
            ->get();

        foreach ($receipts as $index => $receipt) {
            if ($created >= self::VOLUME['refunds'] + self::VOLUME['voids']) {
                break;
            }

            $remaining = Money::of((string) $receipt->refundableRemaining());

            if (Money::compare($remaining, '100.00') < 0) {
                continue;
            }

            $isVoid = $created >= self::VOLUME['refunds'];

            try {
                if ($isVoid) {
                    $payments->void($receipt, self::TAG.' cheque returned unpaid.');
                } else {
                    $payments->refund($receipt, new RefundData(
                        amount: Money::distribute($remaining, 4)[0],
                        reason: self::TAG.' fictional partial refund.',
                        type: ReversalType::PartialRefund,
                        method: PaymentMethod::BankTransfer->value,
                        idempotencyKey: sprintf('demo:refund:%d', (int) $receipt->getKey()),
                        refundedOn: Carbon::now()->subDays(1 + ($index % 20)),
                    ));
                }
            } catch (Throwable) {
                // A receipt the engine will not reverse (already fully returned, or cancelled by an
                // earlier run) is not a seeding failure — move to the next one. The stage still
                // reports how many it managed, so a count far below the volume is visible.
                continue;
            }

            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 8 — a month of attendance, exams and certificates
    |--------------------------------------------------------------------------
    */

    /**
     * A month of held sessions with a marked register per batch.
     *
     * Sessions are created one-off rather than generated from a timetable: the timetable generator
     * needs entries this seeder does not own, and a one-off carries the same clash detection. Each
     * session is marked and then marked held, in that order — `markHeld()` fills whatever is left as
     * absent, which is the real-world order and the one that leaves the counters agreeing.
     */
    private function seedAttendance(): int
    {
        $sessions = app(ClassSessionService::class);
        $attendance = app(AttendanceService::class);
        $created = 0;

        foreach ($this->batches as $batch) {
            $enrolled = StudentBatchEnrollment::query()
                ->where('batch_id', $batch->getKey())
                ->where('status', EnrollmentStatus::Active->value)
                ->exists();

            if (! $enrolled) {
                continue;
            }

            for ($day = self::VOLUME['session_days']; $day >= 1; $day--) {
                $date = Carbon::now()->subDays($day * 2)->toDateString();

                $exists = ClassSession::query()
                    ->where('batch_id', $batch->getKey())
                    ->whereDate('session_date', $date)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $session = $sessions->createOneOff($batch, [
                    'session_date' => $date,
                    'start_time' => (string) $batch->start_time,
                    'end_time' => (string) $batch->end_time,
                    'title' => self::NAME_PREFIX.' session '.$day,
                    'notes' => self::TAG.' fictional session.',
                ], $this->actor);

                // **The register is built from the roster as of this session's own date**, never
                // from "everybody enrolled now". `AttendanceService::mark()` throws, by name, for a
                // student who was not on the batch that day — which is INV-I9 doing its job, and
                // what a fixture that marked its whole intake on a session predating half the
                // enrolments would run into on the first batch.
                $marks = [];

                foreach ($attendance->roster($session) as $position => $enrollment) {
                    // Roughly one absence in nine and one leave in seventeen, so the attendance
                    // percentage a certificate is judged on is neither 100 nor obviously fabricated.
                    $status = match (true) {
                        ($position + $day) % 9 === 0 => StudentAttendanceStatus::Absent,
                        ($position + $day) % 17 === 0 => StudentAttendanceStatus::Leave,
                        default => StudentAttendanceStatus::Present,
                    };

                    $marks[(int) $enrollment->student_id] = ['status' => $status->value];
                }

                if ($marks === []) {
                    // Nobody was on the roster that far back. The session stays scheduled and empty
                    // rather than being marked held with a register of absences nobody earned.
                    continue;
                }

                $attendance->mark($session, $marks, ['marked_via' => 'manual'], $this->actor);
                $sessions->markHeld($session->refresh(), $this->actor);

                $created++;
            }
        }

        return $created;
    }

    /**
     * Four exams with marks entered, verified and published.
     *
     * The sheet goes through `ExamResultService`, so the grade comes from the scale rather than from
     * this file — a demo result row carrying a grade nothing derived would be the one number in the
     * dataset that cannot be reproduced.
     */
    private function seedExams(): int
    {
        $exams = app(ExamService::class);
        $results = app(ExamResultService::class);
        $created = 0;

        foreach (array_slice($this->batches, 0, self::VOLUME['exams']) as $index => $batch) {
            $name = sprintf('%s Exam %02d', self::NAME_PREFIX, $index + 1);

            if (Exam::query()->where('batch_id', $batch->getKey())->where('name', $name)->exists()) {
                continue;
            }

            $hasRoster = StudentBatchEnrollment::query()
                ->where('batch_id', $batch->getKey())
                ->where('status', EnrollmentStatus::Active->value)
                ->exists();

            if (! $hasRoster) {
                continue;
            }

            $exam = $exams->create([
                'batch_id' => (int) $batch->getKey(),
                'name' => $name,
                'exam_type' => ExamType::Midterm->value,
                'scheduled_date' => Carbon::now()->subDays(10)->toDateString(),
                'start_time' => '14:00',
                'end_time' => '16:00',
                'total_marks' => '100.00',
                'passing_marks' => '40.00',
                'delivery_mode' => DeliveryMode::Physical->value,
                'notes' => self::TAG.' fictional exam.',
            ], $this->actor);

            $exam = $exams->schedule($exam, $this->actor);
            $exam = $exams->markConducted($exam, $this->actor);

            // **The sheet comes from `openSheet()`, which is the roster as it stood on the exam
            // date.** A sheet built from "everybody enrolled now" would include a student who joined
            // after the paper was sat, and `saveSheet()` rejects the entire sheet naming them —
            // correctly (INV-20-6), because marks for somebody who was not there are not a rounding
            // error.
            $sheet = $results->openSheet($exam->refresh());
            $rows = [];

            foreach ($sheet['rows'] as $position => $row) {
                $absent = ($position + $index) % 13 === 0;

                $rows[] = [
                    'student_id' => (int) $row['student_id'],
                    'attendance_status' => $absent
                        ? ExamAttendanceStatus::Absent->value
                        : ExamAttendanceStatus::Appeared->value,
                    'obtained_marks' => $absent ? null : (string) (35 + (($position * 7) % 60)),
                    'remarks' => self::TAG,
                ];
            }

            if ($rows === []) {
                continue;
            }

            $results->saveSheet($exam->refresh(), $rows, $this->actor);
            $results->verify($exam->refresh(), $this->actor);
            $results->publish($exam->refresh(), $this->actor);

            $created++;
        }

        return $created;
    }

    /**
     * Ten certificates, issued with a recorded override.
     *
     * The override is the point, not a shortcut: a demo enrolment three months old has not finished
     * its course, so `CertificateEligibilityService` refuses — and the documented way an institute
     * issues anyway is an override with a reason, which is exactly the row a later reader needs to
     * see in the dataset.
     */
    private function seedCertificates(): int
    {
        $certificates = app(CertificateService::class);
        $created = 0;

        $enrollments = StudentBatchEnrollment::query()
            ->whereIn('batch_id', array_map(static fn (Batch $b): int => (int) $b->getKey(), $this->batches))
            ->where('status', EnrollmentStatus::Active->value)
            ->orderBy('id')
            ->limit(self::VOLUME['certificates'] * 3)
            ->get();

        foreach ($enrollments as $enrollment) {
            if ($created >= self::VOLUME['certificates']) {
                break;
            }

            $held = Certificate::query()
                ->where('student_batch_enrollment_id', $enrollment->getKey())
                ->exists();

            if ($held) {
                continue;
            }

            try {
                $certificate = $certificates->draft($enrollment, [
                    'completion_date' => Carbon::now()->subDays(5)->toDateString(),
                    'notes' => self::TAG.' fictional certificate.',
                ], $this->actor);

                $certificates->issue(
                    $certificate,
                    $this->actor,
                    self::TAG.' issued by the demo seeder despite the eligibility gaps, so the '
                    .'override trail has an example in it.',
                );
            } catch (Throwable) {
                continue;
            }

            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 9 — payouts
    |--------------------------------------------------------------------------
    */

    /**
     * Six payouts across the statuses that matter: requested, approved, paid.
     *
     * Requested against whatever the wallet actually holds, never a made-up figure — a payout for
     * more than the available balance is refused by the service, and rightly, so the seeder asks the
     * wallet what there is rather than deciding.
     */
    private function seedPayouts(): int
    {
        $payouts = app(PayoutService::class);
        $created = 0;

        foreach ($this->partners as $index => $partner) {
            if ($created >= self::VOLUME['payouts']) {
                break;
            }

            $wallet = $partner->refresh()->wallet;
            $available = Money::of((string) ($wallet?->available_balance ?? '0.00'));

            // The floor is the institute's own `collaborator.minimum_payout`, not a number invented
            // here: `assertPayable()` refuses anything under it, and a fixture that ignored the
            // setting would fail on an installation that had raised it.
            $floor = Money::max(
                Money::of((string) setting('collaborator.minimum_payout', '0.00')),
                '500.00',
            );

            if (Money::compare($available, $floor) < 0) {
                continue;
            }

            $key = sprintf('demo:payout:%d', (int) $partner->getKey());

            if (CollaboratorPayout::query()->where('idempotency_key', $key)->exists()) {
                continue;
            }

            // The whole available balance, because a payout is allocated FIFO against ledger entries
            // and the transaction is abandoned if the allocation cannot reach the figure asked for.
            $payout = $payouts->createFor($partner, new PayoutRequestData(
                requestedAmount: $available,
                method: PayoutMethod::BankTransfer,
                notes: self::TAG.' fictional payout request.',
                idempotencyKey: $key,
            ), $this->actor);

            // One partner in three is left at `requested` so the approval queue is never empty, and
            // none of them is marked paid — an approved-but-unpaid payout is the state the finance
            // screens actually have to render, and `markPaid()` would need a transfer reference that
            // no fictional bank issued.
            if ($index % 3 !== 0) {
                $payouts->approve($payout, $this->actor, self::TAG.' approved by the demo seeder.');
            }

            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 10 — website, support and calendar
    |--------------------------------------------------------------------------
    */

    /**
     * Thirty course enquiries, moved through the funnel by the state machine that owns it.
     *
     * `inquiry_number` is issued by `StudentNumberService` inside the service, so an enquiry written
     * by hand would have no number — and the spread of statuses is walked with `changeStatus()`
     * rather than stamped, because `CourseInquiryService::TRANSITIONS` is the thing a demo funnel is
     * supposed to demonstrate. `not_interested` and `contacted` both require a reason; the calls
     * below always pass one, so none of them lands on `chk_ci_lost_reason`.
     */
    private function seedCourseInquiries(): int
    {
        $service = app(CourseInquiryService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['course_inquiries']; $i++) {
            $email = sprintf('inquiry-%02d@%s', $i, self::EMAIL_DOMAIN);

            if (CourseInquiry::query()->where('email', $email)->exists()) {
                continue;
            }

            $course = $this->courses[($i - 1) % max(1, count($this->courses))] ?? null;

            $inquiry = $service->create([
                'branch_id' => $this->branchId($i),
                'name' => sprintf('%s Inquiry %02d %s', self::NAME_PREFIX, $i, $this->pick(self::SURNAMES)),
                'phone' => $this->phone(3500000 + $i),
                'email' => $email,
                'city' => $this->pick(self::CITIES),
                'course_id' => $course?->getKey(),
                'source' => $this->pick(InquirySource::cases())->value,
                'message' => self::TAG.' fictional course inquiry.',
                'notes' => self::TAG,
                'assigned_to' => ($this->accounts['Course Coordinator'] ?? $this->actor)->getKey(),
            ], $this->actor);

            match ($i % 5) {
                0 => $service->changeStatus($inquiry, CourseInquiryStatus::NotInterested, self::TAG.' chose another institute.', $this->actor),
                1 => $service->changeStatus($inquiry, CourseInquiryStatus::DemoScheduled, self::TAG.' demo booked.', $this->actor),
                2 => $service->changeStatus($inquiry, CourseInquiryStatus::AdmissionConfirmed, self::TAG.' admitted.', $this->actor),
                3 => $service->changeStatus($inquiry, CourseInquiryStatus::Contacted, self::TAG.' first call made.', $this->actor),
                // One in five is left at `new`, because an empty "to be called" queue is the one
                // state the follow-up screens are never seen in.
                default => null,
            };

            $created++;
        }

        return $created;
    }

    /**
     * Website contact inquiries, written directly.
     *
     * `ContactInquiryService::submit()` needs an HTTP request for its spam guard, rate limiter and
     * source URL, and faking one in a seeder would test the fake. The rows it would have written are
     * these.
     */
    private function seedContactInquiries(): int
    {
        $statuses = ContactInquiryStatus::cases();
        $created = 0;

        for ($i = 1; $i <= 15; $i++) {
            $email = sprintf('contact-%02d@%s', $i, self::EMAIL_DOMAIN);

            if (ContactInquiry::query()->where('email', $email)->exists()) {
                continue;
            }

            $row = new ContactInquiry;
            $row->forceFill([
                'name' => sprintf('%s Visitor %02d', self::NAME_PREFIX, $i),
                'email' => $email,
                'phone' => $this->phone(3600000 + $i),
                'subject' => self::NAME_PREFIX.' website enquiry '.$i,
                'message' => self::TAG.' fictional website enquiry, generated by DemoSeeder.',
                'status' => $statuses[($i - 1) % count($statuses)]->value,
                'created_by' => $this->actor->getKey(),
                'updated_by' => $this->actor->getKey(),
            ])->save();

            $created++;
        }

        return $created;
    }

    private function seedBlogPosts(): int
    {
        $blog = app(BlogService::class);
        $created = 0;

        $category = $this->reviveOrCreate(
            BlogCategory::class,
            ['slug' => 'demo-notes'],
            [
                'name' => self::NAME_PREFIX.' Notes',
                'description' => self::TAG.' fictional blog category.',
                'is_active' => true,
                'sort_order' => 90,
            ],
        );

        $author = $this->accounts['SEO Expert'] ?? $this->actor;

        for ($i = 1; $i <= self::VOLUME['blog_posts']; $i++) {
            $slug = sprintf('demo-post-%02d', $i);

            if (BlogPost::withTrashed()->where('slug', $slug)->exists()) {
                continue;
            }

            $post = $blog->store([
                'blog_category_id' => $category->getKey(),
                'author_id' => $author->getKey(),
                'title' => sprintf('%s Post %02d — %s', self::NAME_PREFIX, $i, $this->pick(self::PROJECT_KINDS)),
                'slug' => $slug,
                'excerpt' => self::TAG.' fictional excerpt.',
                'content' => '<p>'.self::TAG.' Fictional article body written by DemoSeeder. '
                    .'It exists so the blog index, the reading-time estimate and the related-posts '
                    .'block have something to render.</p>',
                'is_featured' => $i <= 2,
            ], null, [self::NAME_PREFIX.' Tag', 'Fixture']);

            // Two left as drafts: an index that shows everything never proves the published scope.
            if ($i > 2) {
                $blog->publish($post);
            }

            $created++;
        }

        return $created;
    }

    private function seedJobs(): int
    {
        $jobs = app(JobOpeningService::class);
        $created = 0;

        for ($i = 1; $i <= self::VOLUME['jobs']; $i++) {
            $slug = sprintf('demo-role-%02d', $i);

            if (JobOpening::withTrashed()->where('slug', $slug)->exists()) {
                continue;
            }

            $job = $jobs->store([
                'title' => sprintf('%s Opening %02d — %s', self::NAME_PREFIX, $i, $this->pick(self::PROJECT_KINDS)),
                'slug' => $slug,
                'department' => $this->pick(['Engineering', 'Design', 'Marketing', 'Support']),
                'location' => $this->pick(self::CITIES),
                'work_mode' => WorkMode::cases()[($i - 1) % count(WorkMode::cases())]->value,
                'employment_type' => EmploymentType::cases()[($i - 1) % count(EmploymentType::cases())]->value,
                'experience_min_years' => $i % 5,
                'openings_count' => 1 + ($i % 3),
                'description' => self::TAG.' fictional job description.',
                'requirements' => self::TAG.' fictional requirements.',
                'responsibilities' => self::TAG.' fictional responsibilities.',
                'deadline' => Carbon::now()->addMonths(2)->toDateString(),
                'salary_visible' => false,
            ]);

            $jobs->changeStatus($job, $i === self::VOLUME['jobs'] ? JobOpeningStatus::Closed : JobOpeningStatus::Open);

            for ($a = 1; $a <= 3; $a++) {
                $email = sprintf('applicant-%02d-%02d@%s', $i, $a, self::EMAIL_DOMAIN);

                // `withTrashed()`: `uq_job_application_per_job` is `(job_opening_id, email)` and
                // does not exclude `deleted_at`, so a soft-deleted demo application would slip past
                // a default-scoped check and then collide on the insert.
                if (JobApplication::withTrashed()->where('email', $email)->exists()) {
                    continue;
                }

                $application = new JobApplication;
                $application->fill([
                    'job_opening_id' => $job->getKey(),
                    'applicant_name' => sprintf('%s Applicant %02d-%02d', self::NAME_PREFIX, $i, $a),
                    'email' => $email,
                    'phone' => $this->phone(3700000 + ($i * 10) + $a),
                    'city' => $this->pick(self::CITIES),
                    'experience_years' => $a,
                    'cover_letter' => self::TAG.' fictional cover letter.',
                    // `cv_path` is NOT NULL and there is deliberately no file behind it: the seeder
                    // writes nothing to the private disk, so a demo CV download 404s instead of
                    // handing somebody a fabricated document that looks real.
                    'cv_path' => 'job-applications/demo/'.$slug.'-'.$a.'.pdf',
                    'cv_original_name' => 'demo-cv.pdf',
                    'cv_mime' => 'application/pdf',
                    'cv_size' => 1024,
                ]);
                $application->save();
            }

            $created++;
        }

        return $created;
    }

    /**
     * Twenty tickets raised by the panel accounts that would really raise them.
     *
     * The requester decides the department's audience check and the SLA clock, so each ticket is
     * raised *as* a portal or staff account rather than as the seeder — a queue where every ticket
     * came from the same Super Admin would demonstrate nothing about `assertMayRaise()`.
     */
    private function seedTickets(): int
    {
        $tickets = app(TicketService::class);
        $created = 0;

        $department = TicketDepartment::query()->where('is_active', true)->orderBy('sort_order')->first();

        if ($department === null) {
            // `reviveOrCreate` and not `create`: `uq_td_slug` does not exclude `deleted_at`, so a
            // demo department somebody soft-deleted would make this insert fail on the second run
            // with a duplicate key rather than with anything a reader could act on.
            $department = $this->reviveOrCreate(
                TicketDepartment::class,
                ['slug' => 'demo-general-support'],
                [
                    'name' => self::NAME_PREFIX.' General Support',
                    'description' => self::TAG.' fictional ticket department.',
                    'allowed_panels' => array_map(static fn (PanelType $p): string => $p->value, PanelType::cases()),
                    'is_active' => true,
                    'sort_order' => 90,
                ],
            );

            // A revived department can come back inactive, and `assertMayRaise()` refuses a ticket
            // into one. Reactivating it is the whole reason this stage created a department at all.
            if ($department->is_active !== true) {
                $department->forceFill(['is_active' => true])->save();
            }
        }

        $requesters = array_values(array_filter([
            $this->accounts['Client'] ?? null,
            $this->accounts['Student'] ?? null,
            $this->accounts['Teacher'] ?? null,
            $this->accounts['Collaborator'] ?? null,
            $this->accounts['Admin'] ?? null,
        ]));

        if ($requesters === []) {
            return 0;
        }

        $agent = $this->accounts['Support Agent'] ?? $this->actor;

        for ($i = 1; $i <= self::VOLUME['tickets']; $i++) {
            $subject = sprintf('%s Ticket %02d — %s', self::NAME_PREFIX, $i, $this->pick(self::TICKET_SUBJECTS));

            if (SupportTicket::withTrashed()->where('subject', $subject)->exists()) {
                continue;
            }

            $requester = $requesters[($i - 1) % count($requesters)];

            $ticket = $tickets->create([
                'ticket_department_id' => $department->getKey(),
                'subject' => $subject,
                'description' => self::TAG.' fictional ticket raised by DemoSeeder.',
                'priority' => $this->pick(Priority::cases())->value,
            ], $requester, $this->actor);

            if ($i % 3 === 0) {
                $tickets->reply($ticket, ['body' => self::TAG.' fictional agent reply.'], $agent);
                $tickets->changeStatus($ticket->refresh(), TicketStatus::Resolved, self::TAG.' resolved.', $agent);
            } elseif ($i % 4 === 0) {
                $tickets->changeStatus($ticket->refresh(), TicketStatus::InProgress, null, $agent);
            }

            $created++;
        }

        return $created;
    }

    private function seedMeetings(): int
    {
        $meetings = app(MeetingService::class);
        $created = 0;

        $organizer = $this->accounts['Project Manager'] ?? $this->actor;
        $attendees = array_values(array_filter([
            $this->accounts['Developer'] ?? null,
            $this->accounts['Designer'] ?? null,
            $this->accounts['Sales Executive'] ?? null,
        ]));

        for ($i = 1; $i <= self::VOLUME['meetings']; $i++) {
            $title = sprintf('%s Meeting %02d — %s', self::NAME_PREFIX, $i, $this->pick(self::MEETING_TOPICS));

            if (Meeting::withTrashed()->where('title', $title)->exists()) {
                continue;
            }

            $project = $this->projects[($i - 1) % max(1, count($this->projects))] ?? null;
            $client = $this->clients[($i - 1) % max(1, count($this->clients))] ?? null;

            // Half in the past and half ahead, so the calendar has history as well as a next-up list.
            $when = $i % 2 === 0
                ? Carbon::now()->subDays($i)->setTime(11, 0)
                : Carbon::now()->addDays($i)->setTime(15, 30);

            $meetings->create(
                new MeetingData(
                    title: $title,
                    scheduledAt: $when->toImmutable(),
                    durationMinutes: 45,
                    deliveryMode: DeliveryMode::Online,
                    meetingUrl: 'https://meet.'.self::EMAIL_DOMAIN.'/demo-'.$i,
                    agenda: self::TAG.' fictional agenda.',
                    branchId: $this->branchId($i),
                    projectId: $project?->getKey(),
                    clientId: $client?->getKey(),
                ),
                // `ParticipantInput` objects, not arrays: `MeetingService::normalise()` silently
                // drops anything that is not one, so an array here would book a meeting with
                // nobody in it and no error to explain why.
                array_map(
                    static fn (User $u): ParticipantInput => ParticipantInput::user((int) $u->getKey()),
                    $attendees,
                ),
                $organizer,
            );

            $created++;
        }

        return $created;
    }

    /*
    |--------------------------------------------------------------------------
    | Small helpers
    |--------------------------------------------------------------------------
    */

    /**
     * `firstOrCreate` that can see a soft-deleted row — and revives it instead of colliding with it.
     *
     * **A plain `firstOrCreate()` on a soft-deleting model with a unique key is a duplicate-key error
     * waiting for somebody to press Delete.** The default scope hides the trashed row, so the lookup
     * misses, the insert runs, and MariaDB refuses it on `uq_cc_slug` / `uq_cr_code` / the blog
     * category's `slug` — none of which exclude `deleted_at`. A demo box is precisely where somebody
     * deletes a demo row to see what the button does and then re-runs `demo:seed`, and the second run
     * would die on a stage that has nothing wrong with it. Every other stage in this file looks its
     * rows up with `withTrashed()` for the same reason; this is that rule for the lookup tables.
     *
     * Restoring rather than leaving it trashed is deliberate: a trashed category cannot hold the
     * courses the next lines attach to it, so a revived row is the only outcome the stage can use.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $key  the natural demo key, matched with trashed rows visible
     * @param  array<string, mixed>  $attributes  written only when the row is created
     * @return TModel
     */
    private function reviveOrCreate(string $model, array $key, array $attributes): Model
    {
        /** @var TModel|null $existing */
        $existing = $model::withTrashed()->where($key)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        /** @var TModel */
        return $model::query()->create([...$key, ...$attributes]);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** A Pakistani mobile number, unique per caller-supplied seed. */
    private function phone(int $seed): string
    {
        return '03'.str_pad((string) ($seed % 100000000), 9, '0', STR_PAD_LEFT);
    }

    private function branchId(int $index): ?int
    {
        if ($this->branches === []) {
            return Branch::default()?->getKey();
        }

        // Two thirds at head office, one third at the demo campus: an even split hides an
        // off-by-one in a branch filter, and an all-one-branch dataset hides the filter entirely.
        return (int) $this->branches[$index % 3 === 0 ? min(1, count($this->branches) - 1) : 0]->getKey();
    }

    private function teacherId(int $index): ?int
    {
        if ($this->teachers === []) {
            return null;
        }

        return (int) $this->teachers[($index - 1) % count($this->teachers)]->getKey();
    }

    /** @var list<string> */
    private const CITIES = ['Lahore', 'Karachi', 'Islamabad', 'Faisalabad', 'Multan', 'Peshawar'];

    /** @var list<string> */
    private const SURNAMES = ['Qadri', 'Iqbal', 'Hussain', 'Bhatti', 'Raza', 'Farooq', 'Siddiqui', 'Malik'];

    /** @var list<string> */
    private const COMPANIES = ['Northline', 'Bluepeak', 'Orchard', 'Cedarworks', 'Harbour', 'Kite', 'Foundry'];

    /** @var list<string> */
    private const PROJECT_KINDS = [
        'Corporate Website', 'Inventory Portal', 'Mobile App', 'E-commerce Store',
        'Booking System', 'CRM Rollout', 'Brand Refresh',
    ];

    /** @var list<string> */
    private const COURSE_SUBJECTS = [
        'Web Development', 'Graphic Design', 'Digital Marketing', 'Data Analytics',
        'Mobile Development', 'UI/UX Design', 'Cyber Security', 'Cloud Basics',
        'Python Programming', 'Video Editing', 'Office Automation', 'SEO Foundations',
    ];

    /** @var list<string> */
    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /** @var list<string> */
    private const TICKET_SUBJECTS = [
        'cannot download my fee slip', 'invoice query', 'batch timing change',
        'certificate not showing', 'payout not received', 'login problem',
    ];

    /** @var list<string> */
    private const MEETING_TOPICS = ['kick-off', 'sprint review', 'client demo', 'requirements walk-through'];
}
