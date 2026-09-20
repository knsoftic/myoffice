<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Cms\BlogPostPublished;
use App\Events\Cms\ContactInquiryRouted;
use App\Events\Cms\ContactInquirySubmitted;
use App\Events\Cms\JobApplicationReceived;
use App\Events\Cms\JobApplicationStatusChanged;
use App\Events\Cms\StudentReviewApproved;
use App\Events\Cms\TestimonialApproved;
use App\Events\Cms\TestimonialSubmitted;
use App\Events\Crm\ClientCreated;
use App\Events\Crm\ClientDocumentSharedWithClient;
use App\Events\Crm\LeadActivityLogged;
use App\Events\Crm\LeadAssigned;
use App\Events\Crm\LeadConverted;
use App\Events\Crm\LeadCreated;
use App\Events\Crm\LeadFollowUpMissed;
use App\Events\Crm\LeadImportCompleted;
use App\Events\Finance\PaymentReversalApproved;
use App\Events\Finance\PaymentReversalRecorded;
use App\Events\Finance\StudentFeePaymentRecorded;
use App\Listeners\Cms\FlushPublicContentCache;
use App\Listeners\Cms\LogApplicationStage;
use App\Listeners\Cms\LogInquiryRouting;
use App\Listeners\Cms\NotifyAuthorOfPublication;
use App\Listeners\Cms\NotifyHrOfApplication;
use App\Listeners\Cms\NotifyStaffOfInquiry;
use App\Listeners\Cms\NotifyStaffOfPendingModeration;
use App\Listeners\Cms\PingSitemap;
use App\Listeners\Cms\RouteContactInquiry;
use App\Listeners\Collaborator\QueueCommissionReversal;
use App\Listeners\Collaborator\QueueStudentFeeCommission;
use App\Listeners\Crm\NotifyAssigneeOfMissedFollowUp;
use App\Listeners\Crm\NotifyClientOfSharedDocument;
use App\Listeners\Crm\NotifyImporterOfCompletion;
use App\Listeners\Crm\NotifyLeadAssignee;
use App\Listeners\Crm\NotifyOfLeadConversion;
use App\Listeners\Crm\NotifyStaffOfWebsiteLead;
use App\Listeners\Crm\RecordCapturedReferral;
use App\Listeners\Crm\TouchLeadActivityCaches;
use App\Listeners\RecordFailedLogin;
use App\Listeners\RecordLogout;
use App\Listeners\RecordSuccessfulLogin;
use App\Models\User;
use App\Services\Auth\LoginHistoryRecorder;
use App\Services\Auth\LoginRedirector;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as FoundationEventServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Event wiring for the application, plus the one guest-redirect rule the auth area owns
 * (phase-01 §7).
 *
 * Laravel 12 has no `app/Providers/EventServiceProvider.php` and no `$listen` array, so the
 * listeners are declared here — one map, registered through `bootstrap/providers.php`.
 *
 * **Framework auto-discovery is switched off on purpose.** `Application::configure()` calls
 * `withEvents()`, which makes the framework scan `app/Listeners` and register every public
 * `handle*()` / `__invoke()` method it finds. Combined with the explicit map below that wires each
 * listener **twice**, and a listener that writes an audit row would then write two. Discovery also
 * boots after this provider, so there is no way to detect and skip it from here.
 *
 * The consequence is a rule worth knowing: **every listener in `app/Listeners` must be listed in
 * the map below**, or it will never fire.
 */
final class EventListenerServiceProvider extends ServiceProvider
{
    /**
     * Event class => listener classes, in the order they run.
     *
     * @var array<class-string, list<class-string>>
     */
    private const LISTENERS = [
        Login::class => [RecordSuccessfulLogin::class],
        Failed::class => [RecordFailedLogin::class],
        Logout::class => [RecordLogout::class],

        // phase-04 §10.1. LogInquiryRouting and LogApplicationStage write the only "routed" / "stage changed"
        // activity entries (§11 tests 38, 44) — without this map both tests fail.
        ContactInquirySubmitted::class => [RouteContactInquiry::class, NotifyStaffOfInquiry::class],
        ContactInquiryRouted::class => [LogInquiryRouting::class],
        JobApplicationReceived::class => [NotifyHrOfApplication::class],
        JobApplicationStatusChanged::class => [LogApplicationStage::class],
        TestimonialApproved::class => [FlushPublicContentCache::class],
        StudentReviewApproved::class => [FlushPublicContentCache::class],
        TestimonialSubmitted::class => [NotifyStaffOfPendingModeration::class],
        BlogPostPublished::class => [NotifyAuthorOfPublication::class, FlushPublicContentCache::class, PingSitemap::class],
        // phase-05 §10.2 / §10.3. No listener on ContactInquirySubmitted: CrmLeadInquiryTarget is the only inquiry path (F-2.1).
        LeadCreated::class => [RecordCapturedReferral::class, NotifyStaffOfWebsiteLead::class],
        ClientCreated::class => [RecordCapturedReferral::class],
        LeadAssigned::class => [NotifyLeadAssignee::class],
        LeadActivityLogged::class => [TouchLeadActivityCaches::class],
        LeadFollowUpMissed::class => [NotifyAssigneeOfMissedFollowUp::class],
        LeadConverted::class => [NotifyOfLeadConversion::class],
        LeadImportCompleted::class => [NotifyImporterOfCompletion::class],
        ClientDocumentSharedWithClient::class => [NotifyClientOfSharedDocument::class],

        // phase-10-12 §6.1 / §10.1. The whole link between money arriving and the commission engine,
        // and it is deliberately one line: the listener dispatches a job and nothing else, so a
        // cashier never waits on the engine and a rolled-back receipt never earns anybody anything
        // (INV-20).
        StudentFeePaymentRecorded::class => [QueueStudentFeeCommission::class],
        // Both reversal events reach one listener, which fires the job only for a reversal that is
        // actually authorised — so a refund awaiting approval undoes nothing yet.
        PaymentReversalRecorded::class => [QueueCommissionReversal::class],
        PaymentReversalApproved::class => [QueueCommissionReversal::class],
    ];

    public function register(): void
    {
        /*
         * Must happen in register(): the framework's event provider reads this flag in its own
         * boot(), which runs after every provider registered in bootstrap/providers.php.
         */
        FoundationEventServiceProvider::disableEventDiscovery();

        /*
         * One recorder per request. It remembers the history row written for this sign-in so
         * AuthenticatedSessionController can re-stamp the regenerated session id onto it — with a
         * fresh instance per resolution that link would be lost.
         */
        $this->app->singleton(LoginHistoryRecorder::class);
    }

    public function boot(): void
    {
        foreach (self::LISTENERS as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        $this->registerGuestRedirect();
    }

    /**
     * Where an already-signed-in visitor goes when they open /login (the `guest` middleware).
     *
     * Without this the framework sends them to the public holding page, whose only button links
     * back to /login — a small loop anyone who presses Back after signing in falls into. Same
     * destination as after a sign-in: `primaryPanel()->homeRoute()` via LoginRedirector.
     *
     * It lives in this provider because the auth area owns no other one (AppServiceProvider is
     * outside this part of the codebase) and because the rule it sets belongs with the sign-in
     * flow it mirrors.
     */
    private function registerGuestRedirect(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            $user = $request->user();

            return $user instanceof User
                ? $this->app->make(LoginRedirector::class)->intendedUrl($user)
                : '/';
        });
    }
}
