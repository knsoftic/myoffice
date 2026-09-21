<?php

declare(strict_types=1);

use App\Http\Controllers\Collaborator\CommissionController;
use App\Http\Controllers\Collaborator\DashboardController;
use App\Http\Controllers\Collaborator\PayoutAccountController as CollaboratorPayoutAccountController;
use App\Http\Controllers\Collaborator\PayoutController as CollaboratorPayoutController;
use App\Http\Controllers\Collaborator\ProjectController;
use App\Http\Controllers\Collaborator\StatementController;
use App\Http\Controllers\Collaborator\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Collaborator panel routes (phase-01 §8)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group, so the prefix,
| the route-name prefix and the panel middleware are declared here.
|
|   auth                   — a session is required
|   active                 — status must be Active
|   panel:collaborator     — one of the user's roles must belong to this panel
|   module:collaborators   — the whole panel closes when the owning business
|                            module is disabled (phase-01 §8)
|   can:collaborator_portal.* — the exact portal permission, per route
|
| Phase 1 registers the dashboard only, so panel isolation is testable from day
| one. Phases 8–12 add students, projects, tasks, wallet, commissions, payouts
| and statements here; every row they expose is scoped to the authenticated
| collaborator (CLAUDE.md rule 10).
|
*/

Route::prefix('collaborator')
    ->name('collaborator.')
    ->middleware(['auth', 'active', 'panel:collaborator', 'module:collaborators'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:collaborator_portal.dashboard')
            ->name('dashboard');

        /*
        |----------------------------------------------------------------------
        | Money - phase-10-12 sec 7.5
        |----------------------------------------------------------------------
        |
        | Every row on these screens is scoped through the **session**, resolved
        | by `ResolvesOwnCollaborator`, and never through a field in the
        | request: a hidden collaborator_id is a number the browser can change.
        | A row belonging to somebody else answers **404, not 403** - telling a
        | partner "that payout exists but is not yours" leaks that it exists,
        | and an id walk would reveal how many payouts the business makes.
        |
        | There is deliberately **no approve route here**. A partner asks;
        | `collaborator.payout_auto_approve_below` decides whether the ask is
        | granted without ceremony. Approving one's own withdrawal is not a
        | button that exists in this panel.
        |
        */
        Route::get('wallet', [WalletController::class, 'index'])
            ->middleware('can:collaborator_portal.wallet')
            ->name('wallet.index');

        // Either commission permission opens the list; the query is then narrowed to the purposes
        // actually held, so the other type never enters the result set.
        Route::middleware('role_or_permission:collaborator_portal.student_commission|collaborator_portal.project_commission')
            ->group(static function (): void {
                Route::get('commissions', [CommissionController::class, 'index'])->name('commissions.index');
                Route::get('commissions/{entry}', [CommissionController::class, 'show'])->whereNumber('entry')->name('commissions.show');
            });

        Route::middleware('can:collaborator_portal.statement_download')->group(static function (): void {
            Route::get('statement', [StatementController::class, 'index'])->name('statement.index');
            Route::get('statement/export/{format}', [StatementController::class, 'export'])->name('statement.export');
        });

        Route::middleware('can:collaborator_portal.payouts')->group(static function (): void {
            Route::get('payouts', [CollaboratorPayoutController::class, 'index'])->name('payouts.index');
        });

        Route::middleware('can:collaborator_portal.payout_request')->group(static function (): void {
            // Declared before `{payout}` so the literal wins, and throttled hard: a payout request is
            // a thing somebody retries when a page feels slow.
            Route::get('payouts/create', [CollaboratorPayoutController::class, 'create'])->name('payouts.create');
            Route::post('payouts', [CollaboratorPayoutController::class, 'store'])->middleware('throttle:3,60')->name('payouts.store');
            Route::post('payouts/{payout}/cancel', [CollaboratorPayoutController::class, 'cancel'])->whereNumber('payout')->name('payouts.cancel');

            Route::get('payout-accounts', [CollaboratorPayoutAccountController::class, 'index'])->name('payout-accounts.index');
            Route::post('payout-accounts', [CollaboratorPayoutAccountController::class, 'store'])->name('payout-accounts.store');
        });

        Route::get('payouts/{payout}', [CollaboratorPayoutController::class, 'show'])
            ->whereNumber('payout')
            ->middleware('can:collaborator_portal.payouts')
            ->name('payouts.show');

        Route::get('projects', [ProjectController::class, 'index'])
            ->middleware('can:collaborator_portal.projects')
            ->name('projects.index');
    });
