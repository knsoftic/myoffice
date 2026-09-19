<?php

declare(strict_types=1);

use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\FileController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\MeetingController;
use App\Http\Controllers\Client\MessageController;
use App\Http\Controllers\Client\MilestoneController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\ProjectController;
use App\Http\Controllers\Client\TaskController;
use App\Http\Controllers\Client\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client panel routes (phase-01 §8, phase-05 §7, D31)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group.
|
|   auth                 — a session is required
|   active               — status must be Active
|   panel:client         — one of the user's roles must belong to this panel
|   client.context       — the login resolves to a client whose portal is on and whose status allows it,
|                          re-checked on every request (EnsureClientContext, §6.9)
|   module:<slug>        — the owning phase's module switch
|   can:client_portal.*  — the exact portal permission, per route
|
| Phase 5 owns this file and EVERY `client.*` route name (D31, F-6.2). A later phase never redeclares a name here —
| Laravel's last registration wins silently. It registers a `ClientPortalSection` into ClientPortalRegistry, and the
| controllers below resolve it; an unregistered section 404s and has no nav item ([D-P5-1]). Every row carries
| `client.context`, including the rows a later phase fills in (F-12.3). The client is taken from ClientContext,
| never from the request (CLAUDE.md rule 10).
|
*/

Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'active', 'panel:client', 'client.context'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:client_portal.dashboard')
            ->name('dashboard');

        Route::get('profile', [ProfileController::class, 'edit'])->middleware('can:client_portal.profile')->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->middleware('can:client_portal.profile')->name('profile.update');

        // Phase 6 sections: projects, progress, tasks, milestones, files.
        Route::get('projects', [ProjectController::class, 'index'])->middleware(['module:projects', 'can:client_portal.projects'])->name('projects.index');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->middleware(['module:projects', 'can:viewByClient,project'])->name('projects.show');
        Route::get('projects/{project}/tasks', [TaskController::class, 'index'])->whereNumber('project')->middleware(['module:tasks', 'can:client_portal.tasks'])->name('tasks.index');
        Route::get('projects/{project}/milestones', [MilestoneController::class, 'index'])->whereNumber('project')->middleware(['module:project_milestones', 'can:client_portal.milestones'])->name('milestones.index');
        Route::get('projects/{project}/progress', [ProjectController::class, 'progress'])->whereNumber('project')->middleware(['module:projects', 'can:client_portal.projects'])->name('progress.show');
        Route::get('files', [FileController::class, 'index'])->middleware(['module:files', 'can:client_portal.files'])->name('files.index');
        Route::get('files/{file}/download', [FileController::class, 'download'])->whereNumber('file')->middleware(['module:files', 'can:client_portal.download'])->name('files.download');

        // Phase 5's own documents section (private disk, D21).
        Route::get('documents', [DocumentController::class, 'index'])->middleware(['module:client_documents', 'can:client_portal.documents'])->name('documents.index');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereNumber('document')->middleware(['module:client_documents', 'can:client_portal.download'])->name('documents.download');

        // Phase 13 invoices; the spine's payments.
        Route::get('invoices', [InvoiceController::class, 'index'])->middleware(['module:invoices', 'can:client_portal.invoices'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->middleware(['module:invoices', 'can:client_portal.invoices'])->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice')->middleware(['module:invoices', 'can:client_portal.download'])->name('invoices.pdf');
        Route::get('payments', [PaymentController::class, 'index'])->middleware(['module:project_payments', 'can:client_portal.payments'])->name('payments.index');

        // Phase 22 sections: meetings, tickets, messages, notifications.
        Route::get('meetings', [MeetingController::class, 'index'])->middleware(['module:meetings', 'can:client_portal.meetings'])->name('meetings.index');
        Route::get('tickets', [TicketController::class, 'index'])->middleware(['module:support_tickets', 'can:client_portal.tickets'])->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->middleware(['module:support_tickets', 'can:client_portal.tickets'])->name('tickets.show');
        Route::get('messages', [MessageController::class, 'index'])->middleware(['module:messages', 'can:client_portal.messages'])->name('messages.index');
        Route::get('messages/{conversation}', [MessageController::class, 'show'])->whereNumber('conversation')->middleware(['module:messages', 'can:client_portal.messages'])->name('messages.show');
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('can:client_portal.notifications')->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->middleware('can:client_portal.notifications')->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->middleware('can:client_portal.notifications')->name('notifications.read');

        // Phase 22: client writes
    });
