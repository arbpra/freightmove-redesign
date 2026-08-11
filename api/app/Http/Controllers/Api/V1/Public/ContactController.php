<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreContactMessageRequest;
use App\Mail\ContactEnquiry;
use App\Models\ContactMessage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The public contact form.
 *
 * Unauthenticated and it sends email, which makes it the most abusable endpoint
 * on the site. Three things hold it down: a `contact` rate limiter keyed to the
 * caller, a honeypot field, and a minimum message length that most scripted
 * submissions fail.
 */
class ContactController extends Controller
{
    /**
     * POST /api/v1/contact
     */
    public function __invoke(StoreContactMessageRequest $request): JsonResponse
    {
        // A filled honeypot is a bot. Answer exactly as if it had worked:
        // telling a script it was caught only teaches whoever wrote it.
        if ($request->looksAutomated()) {
            return $this->accepted();
        }

        $data = $request->validated();

        $enquiry = ContactMessage::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'] ?? null,
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'],
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'user_id' => $request->user()?->id,
        ]);

        $this->notifyTeam($enquiry);

        return $this->accepted();
    }

    /**
     * Emails the team.
     *
     * Deliberately not allowed to fail the request. The enquiry is already
     * saved, so a mail outage must not tell the customer their message did not
     * arrive — it did. `notified_at` records whether the email actually went,
     * so anything unsent is still findable afterwards.
     */
    private function notifyTeam(ContactMessage $enquiry): void
    {
        $recipient = config('freightmove.contact.recipient');

        if (! $recipient) {
            Log::warning('Contact enquiry stored but not emailed: no recipient configured.', [
                'contact_message_id' => $enquiry->id,
            ]);

            return;
        }

        try {
            Mail::to($recipient)->send(new ContactEnquiry($enquiry));
            $enquiry->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $e) {
            Log::error('Contact enquiry stored but the notification failed to send.', [
                'contact_message_id' => $enquiry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function accepted(): JsonResponse
    {
        return ApiResponse::success(
            null,
            'Thanks — your enquiry is with our team and we will be in touch shortly.',
            201,
        );
    }
}
