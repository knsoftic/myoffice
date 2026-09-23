<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Enums\ConversationScope;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Support\Conversation;
use App\Models\User;
use App\Services\Support\ConversationService;
use App\Support\MessagingMatrix;
use App\Support\RichText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Internal messaging — `admin.messages.*` (phase-19-23 §7.6, §8.13, §9.4).
 *
 * **The thread list is always the viewer's own participant rows**, never a client id and never a
 * branch. `ConversationService::threadsFor()` is the only query, and it is the same one every panel
 * uses — phase-05 §9.2's rule, restated: a conversation is personal, not corporate.
 *
 * **The recipient picker asks the matrix, not the user table.** `recipients()` returns only people
 * this viewer may actually start a thread with, because a picker that listed everybody would teach
 * people to try and be refused — and because the list itself would leak who exists.
 */
final class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $threads,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Conversation::class);

        // `threadsFor()` is the scope and nothing else — the filters belong to the screen, not to
        // the rule, and putting them in the service would give every panel's filter set a vote on
        // what "my threads" means.
        $conversations = $this->threads->threadsFor($request->user())
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('q')->toString()).'%';
                $query->where('subject', 'like', $term);
            })
            ->when($request->string('scope')->toString() !== '', fn ($q) => $q->where('pair_scope', $request->string('scope')->toString()))
            ->when($request->boolean('unread'), function ($query) use ($request): void {
                $query->whereHas('participants', fn ($p) => $p
                    ->where('user_id', $request->user()->getKey())
                    ->whereNull('left_at')
                    ->where('unread_count', '>', 0));
            })
            ->with(['lastMessage:id,conversation_id,body,created_at,user_id,is_system', 'participants.user:id,name'])
            ->paginate(20)
            ->withQueryString();

        return view('admin.messages.index', [
            'conversations' => $conversations,
            'scopes' => ConversationScope::cases(),
            'unread' => $this->threads->unreadCountFor($request->user()),
            'canCreate' => (bool) $request->user()?->can('create', Conversation::class),
            'messagingEnabled' => (bool) setting('support.messaging_enabled', true),
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $user = $request->user();

        $messages = $conversation->messages()
            ->with('author:id,name')
            ->chronological()
            ->paginate(50);

        // Opening a thread is reading it. Done after the page is built so the badge the layout
        // renders is the state *after* this visit, not a count that clears on the next click.
        $this->threads->markRead($conversation, $user);

        return view('admin.messages.show', [
            'conversation' => $conversation->load(['participants.user:id,name']),
            'messages' => $messages,
            'canSend' => (bool) $user?->can('send', $conversation),
            'canAddParticipant' => (bool) $user?->can('addParticipant', $conversation),
            'canLeave' => (bool) $user?->can('leave', $conversation),
            'canClose' => (bool) $user?->can('close', $conversation),
            'attachmentsEnabled' => (bool) setting('support.messaging_attachments_enabled', true),
        ]);
    }

    /** Starting a thread — direct with one person, or a group with several. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Conversation::class);

        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $request->input('user_ids', []),
        )));

        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            throw ValidationException::withMessages(['user_ids' => 'Choose somebody to write to.']);
        }

        $first = ['body' => RichText::sanitize($request->string('body')->toString(), 'cms')];
        $user = $request->user();

        if (count($ids) === 1) {
            $target = User::query()->findOrFail($ids[0]);
            $conversation = $this->threads->startDirect($user, $target, $first);
        } else {
            $subject = $request->string('subject')->toString();

            if (trim($subject) === '') {
                throw ValidationException::withMessages(['subject' => 'A group needs a subject, or nobody knows what it is for.']);
            }

            $conversation = $this->threads->startGroup($ids, $subject, $user, [], $first);
        }

        return redirect()
            ->route('admin.messages.show', $conversation)
            ->with('toast', ['type' => 'success', 'message' => 'Message sent.']);
    }

    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('send', $conversation);

        $this->threads->send($conversation, $request->user(), [
            'body' => RichText::sanitize($request->string('body')->toString(), 'cms'),
            'attachments_count' => $request->integer('attachments_count'),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sent.']);
    }

    public function read(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('view', $conversation);

        $this->threads->markRead($conversation, $request->user());

        return back();
    }

    public function addParticipant(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('addParticipant', $conversation);

        $target = User::query()->findOrFail($request->integer('user_id'));

        $this->threads->addParticipant($conversation, $target, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $target->name.' has been added.']);
    }

    public function leave(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('leave', $conversation);

        $this->threads->leave($conversation, $request->user());

        return redirect()
            ->route('admin.messages.index')
            ->with('toast', ['type' => 'success', 'message' => 'You have left the thread. Its history keeps your messages.']);
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('close', $conversation);

        $this->threads->close($conversation, $request->string('reason')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Thread closed. It stays readable for ever.']);
    }

    /**
     * The matrix-filtered recipient picker (§8.13).
     *
     * **Every candidate is asked of `MessagingMatrix::mayStart()`**, so the list is exactly who this
     * person may write to and nobody else. A picker built from the user table with a search box
     * would offer a student every client in the system and refuse each one on submit — which is
     * both a worse experience and a directory of everybody's name.
     */
    public function recipients(Request $request): JsonResponse
    {
        Gate::authorize('create', Conversation::class);

        $user = $request->user();
        $term = trim($request->string('q')->toString());

        $candidates = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($user->getKey())
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            // Bounded before the matrix runs: `mayStart()` resolves roles per candidate, so an
            // unbounded list would be a query per user in the system for one keystroke.
            ->limit(50)
            ->get(['id', 'name', 'email']);

        $allowed = $candidates
            ->map(static function (User $candidate) use ($user): ?array {
                $decision = MessagingMatrix::mayStart($user, $candidate);

                return $decision->allowed
                    ? [
                        'id' => (int) $candidate->getKey(),
                        'name' => (string) $candidate->name,
                        'email' => (string) $candidate->email,
                        'scope' => $decision->scope?->value,
                        'scope_label' => $decision->scope?->label(),
                    ]
                    : null;
            })
            ->filter()
            ->values();

        return response()->json(['recipients' => $allowed]);
    }
}
