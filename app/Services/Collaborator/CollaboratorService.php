<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Enums\CollaboratorStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorSkill;
use App\Models\User;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Support\Collaborator\CollaboratorData;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creating and editing the partner record itself (phase-08-09 §6.2).
 *
 * **This class writes no money.** It creates the *subject* of money and nothing else: no ledger row, no
 * wallet balance, no entitlement, no payout (INV-C7). It does not even compute a balance to show.
 *
 * **Four columns are unreachable from here on purpose.** `collaborator_code` is issued once by
 * `CollaboratorCodeService` (INV-C1); `referral_code` moves only through that service's own method
 * (INV-C2); `status` belongs to `CollaboratorOnboardingService`, which owns §6.2.2's transition table;
 * and `user_id` is set when a login is provisioned. Each has its own permission and its own audit row,
 * and a general `update()` that could reach any of them would be a form that rewrites history.
 */
final class CollaboratorService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorCodeService $codes,
    ) {}

    /**
     * Register a partner.
     *
     * One transaction: the code counter is locked, the record is written, the skills and services are
     * synced. A record starts `pending` unless the caller may approve **and** asked for `active` —
     * §6.2.2's "created directly by an approver" edge, which needs both `collaborators.create` and
     * `collaborators.approve`.
     */
    public function create(CollaboratorData $data, ?User $actor = null, bool $active = false): Collaborator
    {
        $status = $active && $actor?->can('collaborators.approve')
            ? CollaboratorStatus::Active
            : CollaboratorStatus::Pending;

        if ($data->referralCode !== null) {
            $code = $this->codes->normalizeReferralCode($data->referralCode);

            if (! $this->codes->isWellFormed($code)) {
                throw CollaboratorRuleException::refuse('referral_code',
                    'A referral code starts with a letter or a digit and is 4 to 32 characters of A-Z, '
                    .'0-9 and hyphens.');
            }

            $this->codes->assertAvailable($code);
        }

        return $this->db->transaction(function () use ($data, $status, $actor): Collaborator {
            $collaboratorCode = $this->codes->nextCollaboratorCode();

            // The referral code starts equal to the collaborator code (§2.1): one fewer thing to
            // choose at registration, and a partner who wants `ACME` asks for it afterwards.
            $referralCode = $data->referralCode !== null
                ? $this->codes->normalizeReferralCode($data->referralCode)
                : $collaboratorCode;

            $collaborator = new Collaborator;
            $collaborator->forceFill(array_merge($data->columns(), [
                'collaborator_code' => $collaboratorCode,
                'referral_code' => $referralCode,
                'status' => $status->value,
                'applied_at' => now(),
                'status_changed_at' => now(),
                'status_changed_by' => $actor?->getKey(),
                'approved_at' => $status === CollaboratorStatus::Active ? now() : null,
                'approved_by' => $status === CollaboratorStatus::Active ? $actor?->getKey() : null,
            ]))->save();

            $this->syncSkills($collaborator, $data->skills);
            $this->syncServices($collaborator, $data->serviceIds);

            return $collaborator->fresh();
        });
    }

    /**
     * Edit the profile, and only the profile.
     *
     * A replaced photo is deleted **after** the row is saved, never before: a failed save that had
     * already removed the old file would leave the record pointing at nothing.
     */
    public function update(Collaborator $collaborator, CollaboratorData $data, ?string $reason = null): Collaborator
    {
        return $this->db->transaction(function () use ($collaborator, $data, $reason): Collaborator {
            $previousPhoto = $collaborator->photo_path;

            $columns = $data->columns();

            // A form that did not include the photo field must not blank the photo.
            if ($data->photoPath === null) {
                unset($columns['photo_path']);
            }

            $collaborator->withReason($reason ?? 'Profile updated')->forceFill($columns)->save();

            $this->syncSkills($collaborator, $data->skills);
            $this->syncServices($collaborator, $data->serviceIds);

            if ($data->photoPath !== null && $previousPhoto !== null && $previousPhoto !== $data->photoPath) {
                $this->forgetPhoto($previousPhoto);
            }

            return $collaborator->fresh();
        });
    }

    /**
     * Replace the skill set.
     *
     * Idempotent through `uq_cskill`: a double-submitted profile form produces the same set rather than
     * twice as many rows. Removals are **hard** deletes, because the table carries no `deleted_at` —
     * a removed skill has no audit or financial value, and a soft-deleted row would collide with the
     * unique index the moment somebody added the same skill back (D19).
     *
     * @param  list<string>  $names
     */
    public function syncSkills(Collaborator $collaborator, array $names): void
    {
        $wanted = [];

        foreach ($names as $index => $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $slug = Str::slug($name);

            if ($slug === '' || isset($wanted[$slug])) {
                continue;
            }

            $wanted[$slug] = ['name' => $name, 'sort_order' => $index];
        }

        $existing = $collaborator->skills()->get()->keyBy('slug');

        foreach ($existing as $slug => $skill) {
            if (! isset($wanted[$slug])) {
                $skill->delete();
            }
        }

        foreach ($wanted as $slug => $attributes) {
            /** @var CollaboratorSkill|null $skill */
            $skill = $existing->get($slug);

            if ($skill === null) {
                $skill = new CollaboratorSkill;
                $skill->forceFill(['collaborator_id' => $collaborator->getKey()]);
            }

            // `slug` is derived by the model's saving hook, so it is never assigned here.
            $skill->forceFill($attributes)->save();
        }
    }

    /**
     * Replace the offered-services set.
     *
     * `sync()` on a pivot whose composite primary key *is* the uniqueness guarantee, so a re-submitted
     * form cannot duplicate a service. The `created_at` is supplied here rather than by
     * `withTimestamps()`, because the pivot deliberately has no `updated_at` for that to write.
     *
     * @param  list<int>  $serviceIds
     */
    public function syncServices(Collaborator $collaborator, array $serviceIds): void
    {
        // The pivot is created by a guarded migration and is absent when Phase 4 has not run (§2.3).
        if (! Schema::hasTable('collaborator_service')) {
            return;
        }

        $now = now();

        $collaborator->services()->sync(
            collect($serviceIds)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->mapWithKeys(static fn (int $id): array => [$id => ['created_at' => $now]])
                ->all()
        );
    }

    /**
     * Soft delete. The policy decides whether it is allowed — a partner who is owed money is not
     * deleted, because a debt does not disappear with the relationship — and this refuses the one thing
     * a policy cannot see: a missing reason (§6.2.2's last row).
     */
    public function delete(Collaborator $collaborator, string $reason): void
    {
        if (trim($reason) === '') {
            throw CollaboratorRuleException::reasonRequired(
                'reason',
                'Say why this collaborator is being removed. The record stays, and the reason is what '
                .'explains it to whoever reads the audit trail later.',
            );
        }

        $this->db->transaction(static function () use ($collaborator, $reason): void {
            $collaborator->withReason($reason)->delete();
        });
    }

    public function restore(Collaborator $collaborator): Collaborator
    {
        $this->db->transaction(static function () use ($collaborator): void {
            $collaborator->withReason('Restored')->restore();
        });

        return $collaborator->fresh();
    }

    /**
     * Remove a photo file that nothing points at any more. A missing file is not an error: the record
     * is already correct, and throwing here would fail a save that has committed.
     */
    private function forgetPhoto(string $path): void
    {
        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            logger()->warning('phase-08 §6.2: a replaced collaborator photo could not be deleted.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
