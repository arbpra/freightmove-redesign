<?php

namespace App\Policies;

use App\Enums\JobStatus;
use App\Enums\QuoteStatus;
use App\Models\FreightJob;
use App\Models\JobQuote;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may quote, and on what.
 */
class JobQuotePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * Submitting a quote on a load.
     *
     * Denials return a Response with a reason, because "you cannot do that" is
     * useless to a carrier who simply needs to renew — the message is what tells
     * them which of these three situations they are in.
     */
    public function create(User $user, FreightJob $job): Response
    {
        if (! $user->isCarrier()) {
            return Response::deny('Only carriers can quote on loads.');
        }

        // Legacy rule R2: one quote per carrier per load. Also a unique index on
        // job_quotes, so a race cannot slip a second one through.
        if ($job->quotes()->where('carrier_id', $user->id)->exists()) {
            return Response::deny('You have already quoted on this load.');
        }

        // Two separate gates, both off by default — see config/freightmove.php.
        // Reported separately because the fix differs: one is "upload your
        // documents", the other is "renew your subscription".
        if (! $user->meetsVerificationGate()) {
            return Response::deny(
                'Your account needs to be verified before you can quote. '
                    .'Upload your ABN and insurance details to get started.'
            );
        }

        // Legacy rule R3/G4 — see config/freightmove.php for why enforcement is
        // off by default and how the legacy grace period works.
        if (! $user->canQuote()) {
            return Response::deny('An active subscription is needed to quote on loads.');
        }

        return Response::allow();
    }

    public function view(User $user, JobQuote $quote): bool
    {
        // The carrier who submitted it, or the shipper who owns the load.
        return $quote->carrier_id === $user->id
            || $quote->job?->shipper_id === $user->id;
    }

    /**
     * Accepting or declining a quote.
     *
     * Only the shipper who owns the load, only while the quote is still
     * pending, and only while the load is still open — once a job is accepted,
     * completed or cancelled the decision is made and further quotes on it are
     * moot.
     */
    public function decide(User $user, JobQuote $quote): Response
    {
        $job = $quote->job;

        if (! $job || $job->shipper_id !== $user->id) {
            return Response::deny('This quote is not on one of your loads.');
        }

        if ($quote->status !== QuoteStatus::Pending) {
            return Response::deny('That quote has already been dealt with.');
        }

        if (! in_array($job->status, JobStatus::openForQuotes(), true)) {
            return Response::deny('This load is no longer open for quotes.');
        }

        return Response::allow();
    }

    /** A quote can be withdrawn while it is still pending. */
    public function delete(User $user, JobQuote $quote): bool
    {
        return $quote->carrier_id === $user->id && $quote->status->value === 'pending';
    }
}
