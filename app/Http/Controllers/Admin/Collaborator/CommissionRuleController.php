<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\DataObjects\Collaborator\BaseResolution;
use App\DataObjects\Collaborator\RuleResolution;
use App\Enums\CommissionBase;
use App\Enums\CommissionCalculationType;
use App\Enums\CommissionRuleSource;
use App\Enums\CommissionScope;
use App\Enums\EntitlementDocumentType;
use App\Enums\FixedCommissionRelease;
use App\Enums\StudentFeeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Collaborator\StoreCommissionRuleRequest;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionSetting;
use App\Services\Collaborator\CommissionCalculator;
use App\Services\Collaborator\CommissionEntitlementService;
use App\Services\Collaborator\CommissionRuleService;
use App\Support\Collaborator\CommissionSettings;
use App\Support\Format;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The commission rule timeline — `admin.commission-rules.*` (phase-10-12 §7.3, §8.4).
 *
 * **Every version is immutable and the screen says so.** The current version renders locked, with only
 * "Close" offered; changing a rate means adding a version with its own effective date. That is not a
 * UI preference — `CollaboratorCommissionSetting` refuses the write at the model, and the reason is
 * that "what was this partner's rate on the day that payment arrived" has to stay answerable.
 *
 * The preview endpoint is what makes the difference legible **before** it is saved: "a 10,000 receipt
 * today earns 1,000.00 under the current rule and 1,500.00 under this one". It writes nothing.
 */
final class CommissionRuleController extends Controller
{
    public function __construct(
        private readonly CommissionRuleService $rules,
        private readonly CommissionCalculator $calculator,
        private readonly CommissionEntitlementService $entitlements,
    ) {}

    public function index(Collaborator $collaborator): View
    {
        return view('admin.commission-rules.index', [
            'collaborator' => $collaborator,
            'timelines' => [
                CommissionScope::Student->value => $this->rules->timeline($collaborator, CommissionScope::Student),
                CommissionScope::Project->value => $this->rules->timeline($collaborator, CommissionScope::Project),
            ],
            'current' => [
                CommissionScope::Student->value => $this->rules->currentVersion($collaborator, CommissionScope::Student),
                CommissionScope::Project->value => $this->rules->currentVersion($collaborator, CommissionScope::Project),
            ],
            'scopes' => CommissionScope::cases(),
            'calculationTypes' => [CommissionCalculationType::Percentage, CommissionCalculationType::Fixed],
            'releases' => FixedCommissionRelease::cases(),
            'bases' => CommissionBase::cases(),
            'feeTypes' => StudentFeeType::cases(),
            'settings' => CommissionSettings::capture(),
        ]);
    }

    public function store(StoreCommissionRuleRequest $request, Collaborator $collaborator): RedirectResponse
    {
        $rule = $this->rules->createVersion(
            $collaborator,
            $request->scope(),
            $request->toRuleData(),
            $request->reason(),
        );

        return redirect()
            ->route('admin.commission-rules.index', $collaborator)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('Version %d is in force from %s. Nothing already posted has changed.',
                    (int) $rule->version, $rule->effective_from->toDateString()),
            ]);
    }

    public function close(Request $request, CollaboratorCommissionSetting $rule): RedirectResponse
    {
        $validated = $request->validate([
            'effective_to' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->rules->close($rule, Carbon::parse((string) $validated['effective_to']), (string) $validated['reason']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Version %d now ends on %s. This partner earns nothing after that until a new version starts.',
                (int) $rule->version, (string) $validated['effective_to']),
        ]);
    }

    /**
     * Wizard step 4: the same receipt, priced under the rule in force today and under the one being
     * proposed.
     *
     * Read-only, and it uses the **same pure calculator the engine uses** — a preview computed by a
     * second implementation is a preview that is occasionally wrong, and the whole point of the screen
     * is to be trusted before somebody commits a rate.
     */
    public function preview(Request $request, Collaborator $collaborator): JsonResponse
    {
        $validated = $request->validate([
            'commission_for' => ['required', 'string'],
            'calculation_type' => ['required', 'string'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'base_override' => ['nullable', 'string'],
            'fixed_release' => ['nullable', 'string'],
            'max_commission_amount' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'document_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $scope = CommissionScope::from((string) $validated['commission_for']);
        $settings = CommissionSettings::capture();
        $receipt = Money::of((string) $validated['amount']);
        $documentAmount = Money::of((string) ($validated['document_amount'] ?? $validated['amount']));
        $today = Carbon::now(Format::timezone())->startOfDay();

        $proposed = $this->proposedResolution($validated, $scope, $settings);
        $current = $this->rules->resolve($collaborator, $scope, $today);

        return response()->json([
            'receipt' => $receipt,
            'proposed' => $this->priceUnder($proposed, $scope, $settings, $receipt, $documentAmount),
            'current' => $current === null
                ? null
                : $this->priceUnder($current, $scope, $settings, $receipt, $documentAmount),
            'note' => 'A new version applies from its start date onwards. Nothing already posted changes — '
                .'every entry keeps the rule it quoted.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $input
     */
    private function proposedResolution(array $input, CommissionScope $scope, CommissionSettings $settings): RuleResolution
    {
        // `validate()` returns only the keys that were actually sent, so every optional field is read
        // with `??` — a wizard that has not reached the fixed-amount step yet simply omits it.
        $type = CommissionCalculationType::from((string) $input['calculation_type']);
        $override = $input['base_override'] ?? null;
        $base = $override === null ? $settings->baseFor($scope) : CommissionBase::from((string) $override);

        return new RuleResolution(
            source: CommissionRuleSource::CollaboratorRule,
            scope: $scope,
            calculationType: $type,
            rate: ($input['rate'] ?? null) === null ? null : (string) $input['rate'],
            fixedAmount: ($input['fixed_amount'] ?? null) === null ? null : (string) $input['fixed_amount'],
            release: ($input['fixed_release'] ?? null) === null
                ? $settings->fixedRelease
                : FixedCommissionRelease::from((string) $input['fixed_release']),
            base: $base->appliesTo($scope) ? $base : CommissionBase::Paid,
            isEnabled: true,
            maxCommissionAmount: ($input['max_commission_amount'] ?? null) === null ? null : (string) $input['max_commission_amount'],
        );
    }

    /**
     * What one receipt earns under one rule, against a fresh document — no entitlement, nothing
     * collected yet, which is the comparison the wizard is actually making.
     *
     * @return array<string, mixed>
     */
    private function priceUnder(
        RuleResolution $rule,
        CommissionScope $scope,
        CommissionSettings $settings,
        string $receipt,
        string $documentAmount,
    ): array {
        if (! $rule->isUsable()) {
            return ['usable' => false, 'amount' => null, 'detail' => 'That rule has no rate or amount to apply.'];
        }

        $document = new BaseResolution(
            documentType: $scope === CommissionScope::Student
                ? EntitlementDocumentType::StudentFee
                : EntitlementDocumentType::Project,
            documentId: 0,
            documentBaseAmount: $documentAmount,
            collectibleAmount: $documentAmount,
            base: $rule->base,
        );

        $promise = $this->entitlements->promiseFor(
            calculationType: $rule->calculationType,
            base: $rule->base,
            release: $rule->release,
            rate: $rule->rate,
            fixedAmount: $rule->fixedAmount,
            documentBaseAmount: $documentAmount,
            maxCommissionAmount: $rule->maxCommissionAmount,
        );

        $calculation = $this->calculator->calculate(
            rule: $rule,
            document: $document,
            settings: $settings,
            paymentAmount: $receipt,
            collectedBefore: Money::ZERO,
            releasedBefore: Money::ZERO,
            promise: $promise,
        );

        return [
            'usable' => true,
            'amount' => $calculation->earns() ? $calculation->release : null,
            'formatted' => $calculation->earns() ? Money::format($calculation->release) : null,
            'branch' => $calculation->branch,
            'base' => $rule->base->label(),
            'detail' => $calculation->earns() ? null : $calculation->detail,
        ];
    }
}
