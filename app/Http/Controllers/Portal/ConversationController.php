<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ServesPortalSupport;
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
 * A portal's own threads — `{panel}.messages.*` (phase-19-23 §6.18, §9.4, requirement §94).
 *
 * **This is where the §94 matrix earns its keep.** A student must not be able to message a client,
 * a client must not be able to message another client, and neither of them can discover the other
 * exists through this screen: `recipients()` asks `MessagingMatrix::mayStart()` for every candidate
 * and returns only the allowed ones, so the picker is both the permission check and the reason the
 * list is short.
 *
 * **No group threads from a portal.** `startGroup()` requires every pair among the members to be
 * allowed (PH22-26), and a portal user choosing three people is a portal user assembling a room the
 * matrix would have to adjudicate pair by pair. The screen offers a direct thread; staff make the
 * groups.
 *
 * **The composer disappears rather than refusing.** `ConversationPolicy::send()` re-asks the matrix
 * on every request (INV-22-4), so removing a pair from the settings closes the box on an existing
 * thread here and now — which is the behaviour the setting promises.
 */
final class ConversationController extends Controller
{
    use ServesPortalSupport;

    public function __construct(
        private readonly ConversationService $threads,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Conversation::class);

        $user = $request->user();

        $conversations = $this->threads->threadsFor($user)
            ->with(['lastMessage:id,conversation_id,body,created_at,user_id,is_system', 'participants.user:id,name'])
            ->paginate(15)
            ->withQueryString();

        return view($this->screen($request, 'messages.index'), [
            'conversations' => $conversations,
            'unread' => $this->threads->unreadCountFor($user),
            'panel' => $this->panel($request),
            'canCreate' => (bool) $user?->can('create', Conversation::class),
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

        $this->threads->markRead($conversation, $user);

        return view($this->screen($request, 'messages.show'), [
            'conversation' => $conversation->load(['participants.user:id,name']),
            'messages' => $messages,
            'panel' => $this->panel($request),
            'canSend' => (bool) $user?->can('send', $conversation),
            'canLeave' => (bool) $user?->can('leave', $conversation),
        ]);
    }

    /** Starting a direct thread. A portal never starts a group — see the class note. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Conversation::class);

        $targetId = $request->integer('user_id');

        if ($targetId < 1) {
            throw ValidationException::withMessages(['user_id' => 'Choose somebody to write to.']);
        }

        $body = RichText::sanitize($request->string('body')->toString(), 'cms');

        if (trim(strip_tags((string) $body)) === '') {
            throw ValidationException::withMessages(['body' => 'Write something before sending.']);
        }

        $target = User::query()->findOrFail($targetId);

        $conversation = $this->threads->startDirect($request->user(), $target, ['body' => $body]);

        return redirect()
            ->to($this->route($request, 'messages.show', $conversation))
            ->with('toast', ['type' => 'success', 'message' => 'Message sent.']);
    }

    public function send(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('send', $conversation);

        $this->threads->send($conversation, $request->user(), [
            'body' => RichText::sanitize($request->string('body')->toString(), 'cms'),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sent.']);
    }

    public function leave(Request $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('leave', $conversation);

        $this->threads->leave($conversation, $request->user());

        return redirect()
            ->to($this->route($request, 'messages.index'))
            ->with('toast', ['type' => 'success', 'message' => 'You have left the thread. Its history keeps your messages.']);
    }

    /**
     * Who this person may write to (§8.13).
     *
     * **Every candidate goes through the matrix.** A student asking for this list gets institute
     * staff and their own teachers, and nothing else — not because the query filtered by role, but
     * because `mayStart()` refused everybody else. That is the same answer `startDirect()` will
     * give on submit, which is what stops the picker offering something it then declines.
     *
     * Bounded at fifty before the matrix runs: `mayStart()` resolves roles per candidate, so an
     * unbounded list would be a query per user in the system for one keystroke.
     */
    public function recipients(Request $request): JsonResponse
    {
        Gate::authorize('create', Conversation::class);

        $user = $request->user();
        $term = trim($request->string('q')->toString());

        $allowed = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereKeyNot($user->getKey())
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where('name', 'like', $like);
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(static function (User $candidate) use ($user): ?array {
                $decision = MessagingMatrix::mayStart($user, $candidate);

                return $decision->allowed
                    ? [
                        'id' => (int) $candidate->getKey(),
                        'name' => (string) $candidate->name,
                        'scope_label' => $decision->scope?->label(),
                    ]
                    : null;
            })
            ->filter()
            ->values();

        // The email is deliberately absent, unlike the admin picker: a portal user does not need
        // a colleague's address to write to them, and a picker that handed one out would be a
        // directory anybody with a login could page through.
        return response()->json(['recipients' => $allowed]);
    }
}
