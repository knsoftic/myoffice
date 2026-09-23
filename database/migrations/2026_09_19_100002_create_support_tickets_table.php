<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 2 — `support_tickets` (phase-19-23 §2.18, requirement §93).
 *
 * **Eight nullable subject foreign keys, and not one of them is ever accepted from a form.**
 * `TicketService::create()` derives `client_id` from `ClientContext` and the rest from the
 * requester's own profiles, because a column a form could set is a column somebody can file a ticket
 * *as somebody else* with. They are nullable because a ticket has at most one or two subjects — a
 * client asking about a project fills `client_id` and `project_id` and nothing else — and a
 * polymorphic pair would have cost every §9 scope query its index.
 *
 * **Every SLA column is nullable or defaults to zero, on purpose** (audit F-13.5). With
 * `support.sla_enabled` off, `first_response_due_at`, `first_response_at`, `resolution_due_at`,
 * `resolved_at` and `waiting_since` simply stay null and the two `*_breached` booleans stay false —
 * no migration is involved in turning the feature on or off, which is what makes it a settings flip
 * rather than a deployment.
 *
 * **`total_waiting_minutes` is what makes the SLA pause honest.** A clock that stopped while waiting
 * on the requester and never recorded how long would be a target nobody could audit; `resume()` adds
 * the elapsed minutes here *and* pushes both due dates forward by the same amount, so the pause is
 * visible from either direction.
 *
 * **There is no `restrictOnDelete` anywhere pointing at this table, and that is deliberate**: the
 * policy refuses `delete` and `forceDelete` for every role (INV-22-1), so a ticket is never deleted
 * and nothing needs protecting from its deletion. A wrong ticket is closed.
 *
 * **Every enum-backed column is string(32)** — `in_progress` is eleven characters and the contract
 * says 16, which fits; D126 is the phase that learned what "fits" is worth when somebody later adds
 * a case. CLAUDE.md §3 is a width nobody has to measure.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'support_tickets';

    /** The eight subject foreign keys, and the table each points at. */
    private const SUBJECTS = [
        'client_id' => 'clients',
        'student_id' => 'students',
        'teacher_id' => 'teachers',
        'collaborator_id' => 'collaborators',
        'employee_id' => 'employees',
        'project_id' => 'projects',
        'course_id' => 'courses',
        'batch_id' => 'batches',
    ];

    /** Every column that holds a user id and empties rather than blocking when that user goes. */
    private const ACTORS = [
        'assigned_to', 'assigned_by', 'first_response_by', 'resolved_by', 'closed_by',
        'last_reply_by', 'created_by', 'updated_by',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('ticket_number', 32);
            $table->unsignedBigInteger('ticket_department_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            // The requester. `restrictOnDelete`, because a ticket with no author is a conversation
            // with nobody — and §93 names the user as part of what a ticket *is*.
            $table->unsignedBigInteger('user_id');
            $table->string('requester_panel', 32);

            foreach (array_keys(self::SUBJECTS) as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }

            $table->string('subject', 255);
            $table->longText('description');

            // The shared four-case `Priority` of phase-06 §3. There is no `TicketPriority`: one
            // enum, one label, one colour, so a task badge and a ticket badge cannot drift (F-5.7).
            $table->string('priority', 32)->default('medium');
            $table->string('status', 32)->default('open');

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();

            // ---------------------------------------------------------------- SLA. All inert when off.
            $table->dateTime('first_response_due_at')->nullable();
            // The first **public staff** reply only (INV-22-2). An internal note is not a response to
            // anybody, and stamping it would let a queue hit its target by talking to itself.
            $table->timestamp('first_response_at')->nullable();
            $table->unsignedBigInteger('first_response_by')->nullable();
            $table->boolean('first_response_breached')->default(false);

            $table->dateTime('resolution_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->boolean('resolution_breached')->default(false);

            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestamp('waiting_since')->nullable();
            $table->unsignedInteger('total_waiting_minutes')->default(0);

            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamp('last_reopened_at')->nullable();

            $table->timestamp('last_reply_at')->nullable();
            $table->unsignedBigInteger('last_reply_by')->nullable();
            // Drives the "awaiting us / awaiting them" chip without a join.
            $table->string('last_reply_panel', 32)->nullable();

            // CACHES. Recounted under a row lock, never incremented in place.
            $table->unsignedSmallInteger('replies_count')->default(0);
            $table->unsignedSmallInteger('staff_replies_count')->default(0);
            $table->unsignedSmallInteger('requester_replies_count')->default(0);
            $table->unsignedSmallInteger('attachments_count')->default(0);

            // phase-05 §12 Q3's escape hatch: a ticket a client's colleagues cannot read.
            $table->boolean('is_private_to_creator')->default(false);
            $table->json('tags')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('ticket_number', 'uq_tk_number');

            // The queue, and the eight scoped lists §9.4 names. Each leads with the column the scope
            // filters on, so a client's ticket list is an index seek rather than a table scan.
            $table->index(['status', 'priority', 'created_at'], 'idx_tk_queue');
            $table->index(['assigned_to', 'status'], 'idx_tk_assignee');
            $table->index(['user_id', 'status'], 'idx_tk_requester');
            $table->index(['client_id', 'status'], 'idx_tk_client');
            $table->index(['student_id', 'status'], 'idx_tk_student');
            $table->index(['collaborator_id', 'status'], 'idx_tk_collaborator');
            $table->index(['teacher_id', 'status'], 'idx_tk_teacher');
            $table->index(['ticket_department_id', 'status'], 'idx_tk_department');
            $table->index(['branch_id', 'status'], 'idx_tk_branch');
            $table->index('first_response_due_at', 'idx_tk_first_due');
            $table->index('resolution_due_at', 'idx_tk_resolution_due');
            $table->index('project_id', 'idx_tk_project');
            $table->index('last_reply_at', 'idx_tk_last_reply');

            // D125: a foreign key whose only cover is a composite leading with a different column is
            // a foreign key with no index of its own. These four are not led by any composite above.
            $table->index('employee_id', 'idx_tk_employee');
            $table->index('course_id', 'idx_tk_course');
            $table->index('batch_id', 'idx_tk_batch');
            $table->index('assigned_by', 'idx_tk_assigner');
            $table->index('first_response_by', 'idx_tk_responder');
            $table->index('resolved_by', 'idx_tk_resolver');
            $table->index('closed_by', 'idx_tk_closer');
            $table->index('last_reply_by', 'idx_tk_last_replier');

            // A department with tickets in it is deactivated, never deleted.
            $table->foreign('ticket_department_id', RawSchema::foreignKeyName(self::TABLE, 'ticket_department_id'))
                ->references('id')->on('ticket_departments')->restrictOnDelete();

            $table->foreign('user_id', RawSchema::foreignKeyName(self::TABLE, 'user_id'))
                ->references('id')->on('users')->restrictOnDelete();

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();

            foreach (self::SUBJECTS as $column => $references) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on($references)->nullOnDelete();
            }

            foreach (self::ACTORS as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    private function constraints(): void
    {
        // A negative cache is a recount that went wrong, and it is better to refuse the write than
        // to show a queue "-1 replies".
        $this->ensure(
            'chk_tk_counts',
            '`replies_count` >= 0 AND `staff_replies_count` >= 0 AND `requester_replies_count` >= 0'
            .' AND `attachments_count` >= 0 AND `reopened_count` >= 0 AND `total_waiting_minutes` >= 0',
        );

        // A resolved ticket knows when it was resolved. Without this, an SLA report cannot tell a
        // ticket answered in an hour from one answered in a week.
        $this->ensure(
            'chk_tk_resolved',
            "`status` NOT IN ('resolved','closed') OR `resolved_at` IS NOT NULL",
        );

        // `waiting_since` belongs to `waiting` and to nothing else — a stale one left behind on a
        // reopened ticket would pause a clock that is supposed to be running.
        $this->ensure('chk_tk_waiting', "`status` = 'waiting' OR `waiting_since` IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
