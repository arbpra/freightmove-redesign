<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FreightJob;
use App\Models\Message;
use App\Models\User;
use App\Services\Notifier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Messaging between a shipper and a carrier about one load.
 *
 * Shared by both roles, so it sits outside the role-prefixed groups. Access is
 * ConversationPolicy's job — in particular the rule that a carrier must have
 * quoted before a thread can be opened, which is what stops the channel being
 * used to route around the platform.
 */
class ConversationController extends Controller
{
    private const MESSAGES_PER_PAGE = 50;

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * GET /api/v1/conversations
     *
     * My threads, most recently active first.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $conversations = Conversation::forUser($userId)
            ->with(['job:id,title,status,shipper_id,pickup_location,delivery_location'])
            ->withCount([
                // Unread means "sent to me and not yet read" — my own messages
                // are never unread, however recently I sent them.
                'messages as unread_count' => fn ($query) => $query
                    ->whereNull('read_at')
                    ->where('sender_id', '!=', $userId),
            ])
            ->with(['messages' => fn ($query) => $query->latest()->limit(1)])
            // Ordered by real activity, not by when the thread was opened: an
            // empty thread from last week must not sit above a live one.
            ->orderByDesc(
                Message::select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->limit(1)
            )
            ->get();

        // One query for every counterpart, rather than one per row.
        $counterparts = User::with('profile:id,user_id,company_name')
            ->whereIn('id', $conversations->map(fn ($c) => $c->counterpartId($userId))->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        return ApiResponse::success([
            'items' => $conversations->map(function (Conversation $conversation) use ($userId, $counterparts) {
                $other = $counterparts->get($conversation->counterpartId($userId));
                $last = $conversation->messages->first();

                return [
                    'id' => $conversation->id,
                    'job' => [
                        'id' => $conversation->job?->id,
                        'title' => $conversation->job?->title,
                        'status' => $conversation->job?->status->value,
                        'lane' => $conversation->job
                            ? "{$conversation->job->pickup_location} → {$conversation->job->delivery_location}"
                            : null,
                    ],
                    'with' => [
                        'id' => $other?->id,
                        'name' => $other?->profile?->company_name ?: $other?->name,
                    ],
                    'unread_count' => $conversation->unread_count,
                    'last_message' => $last ? [
                        'body' => $last->body,
                        'sent_by_me' => $last->sender_id === $userId,
                        'created_at' => $last->created_at?->toIso8601String(),
                    ] : null,
                ];
            })->all(),
            'unread_total' => $conversations->sum('unread_count'),
        ]);
    }

    /**
     * GET /api/v1/conversations/{conversation}
     *
     * The thread. Opening it marks everything addressed to me as read.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $userId = $request->user()->id;

        // Reading the thread is what "read" means. Scoped to the other side's
        // messages so my own timestamps are never rewritten.
        $conversation->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $userId)
            ->update(['read_at' => now()]);

        // Newest first to take the last page, then flipped so the thread reads
        // top to bottom. `id` breaks the tie: `created_at` has second
        // resolution and a conversation is mostly messages seconds apart, so
        // ordering on the timestamp alone leaves consecutive lines arbitrary.
        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES_PER_PAGE)
            ->get()
            ->reverse()
            ->values();

        $conversation->load('job:id,title,status,shipper_id,pickup_location,delivery_location');
        $other = User::with('profile:id,user_id,company_name')
            ->find($conversation->counterpartId($userId));

        return ApiResponse::success([
            'id' => $conversation->id,
            'job' => [
                'id' => $conversation->job?->id,
                'title' => $conversation->job?->title,
                'status' => $conversation->job?->status->value,
                'lane' => $conversation->job
                    ? "{$conversation->job->pickup_location} → {$conversation->job->delivery_location}"
                    : null,
            ],
            'with' => [
                'id' => $other?->id,
                'name' => $other?->profile?->company_name ?: $other?->name,
            ],
            // A closed load leaves the history readable but the box disabled.
            'can_send' => $request->user()->can('send', $conversation),
            'items' => $messages->map(fn (Message $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'sent_by_me' => $message->sender_id === $userId,
                'sender_name' => $message->sender?->name,
                'read_at' => $message->read_at?->toIso8601String(),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/conversations
     *
     * Opens the thread for a load, or returns the one already there.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'integer', 'exists:freight_jobs,id'],
            // Required when a shipper opens the thread, because a load can have
            // many carriers quoting on it. A carrier never sends it: their
            // counterpart is whoever posted the load.
            'with_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $job = FreightJob::findOrFail($validated['job_id']);

        $counterpartId = $user->id === $job->shipper_id
            ? ($validated['with_user_id'] ?? null)
            : $job->shipper_id;

        if (! $counterpartId) {
            return ApiResponse::error('Tell us which carrier you want to message.', [
                'with_user_id' => ['Required when you posted the load.'],
            ], 422);
        }

        $counterpart = User::findOrFail($counterpartId);

        $this->authorize('open', [Conversation::class, $job, $counterpart]);

        $conversation = Conversation::between($job->id, $user->id, $counterpart->id);

        return ApiResponse::success(['id' => $conversation->id], 'Conversation ready.', 201);
    }

    /**
     * POST /api/v1/conversations/{conversation}/messages
     */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $user = $request->user();

        $message = DB::transaction(function () use ($conversation, $user, $validated) {
            $message = $conversation->messages()->create([
                'sender_id' => $user->id,
                'message_type' => MessageType::Text,
                'body' => $validated['body'],
            ]);

            // Bumps the thread's own timestamp so a plain updated_at sort still
            // reflects activity even outside the index query above.
            $conversation->touch();

            return $message;
        });

        $this->notifier->messageReceived(
            $conversation,
            $conversation->counterpartId($user->id),
            $user,
            $validated['body'],
        );

        return ApiResponse::success([
            'id' => $message->id,
            'body' => $message->body,
            'sent_by_me' => true,
            'sender_name' => $user->name,
            'read_at' => null,
            'created_at' => $message->created_at?->toIso8601String(),
        ], 'Sent.', 201);
    }
}
