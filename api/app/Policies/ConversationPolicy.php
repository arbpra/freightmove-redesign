<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\FreightJob;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may talk to whom, and about what.
 *
 * A thread is always about one load, between that load's shipper and one
 * carrier. Two rules do the work:
 *
 * **Only participants.** A conversation is private to the two people in it;
 * admins are excluded too. Support reading customer messages is a decision with
 * privacy consequences, and it should be a deliberate, audited feature rather
 * than a side effect of the `before()` hook every other policy here uses.
 *
 * **The carrier must have quoted.** This is the disintermediation guard. The
 * platform deliberately withholds a carrier's phone and email until a quote is
 * accepted (see JobQuoteResource); an open message channel from any carrier to
 * any shipper would route straight around that — "what's your number?" — and
 * the subscription is the product. Quoting is a small commitment, but it is a
 * real one, and it keeps the conversation attached to an actual offer.
 *
 * If that proves too strict in practice — a carrier wanting to ask about
 * loading access before pricing — the place to loosen it is here, and the
 * trade-off is on the record.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->includes($user->id);
    }

    public function send(User $user, Conversation $conversation): Response
    {
        if (! $conversation->includes($user->id)) {
            return Response::deny('This conversation is not yours.');
        }

        $job = $conversation->job;

        if ($job && $job->status->isTerminal()) {
            return Response::deny('This load is closed, so the conversation is read-only.');
        }

        return Response::allow();
    }

    /**
     * Opening a thread about a load.
     *
     * `$counterpart` is the other person: for a carrier that is the shipper who
     * posted it, for a shipper it is one of the carriers who quoted.
     */
    public function open(User $user, FreightJob $job, User $counterpart): Response
    {
        if ($user->id === $counterpart->id) {
            return Response::deny('You cannot message yourself.');
        }

        $shipperId = $job->shipper_id;
        $carrierId = $user->id === $shipperId ? $counterpart->id : $user->id;

        // One of the two has to own the load, and it has to be the right one.
        if ($shipperId !== $user->id && $shipperId !== $counterpart->id) {
            return Response::deny('Neither of you posted this load.');
        }

        if (! $job->quotes()->where('carrier_id', $carrierId)->exists()) {
            return Response::deny(
                $user->id === $shipperId
                    ? 'You can message a carrier once they have quoted on this load.'
                    : 'Quote on this load first, then you can message the shipper about it.'
            );
        }

        return Response::allow();
    }
}
