<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves the mail transport works, before it matters.
 *
 * Mail is the one part of this application that fails quietly. A wrong key, an
 * unverified domain, a From address that is not on it — none of them raise
 * anything a user would see, and the first symptom is a carrier saying they
 * never heard they won a job. Worse, a message accepted by the provider and
 * then filed as spam looks identical to success from the server's side.
 *
 * So: send one, deliberately, and read the answer.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {to : Where to send the test message}';

    protected $description = 'Send a test email and report what the transport said';

    public function handle(): int
    {
        $to = $this->argument('to');
        $mailer = config('mail.default');
        $from = config('mail.from.address');

        $this->line('');
        $this->line("  transport : {$mailer}");
        $this->line('  from      : '.($from ?: '(not set)'));

        if ($mailer === 'resend') {
            $key = config('services.resend.key');

            $this->line('  key       : '.($key ? 'set ('.strlen($key).' chars)' : '(not set)'));

            if (! $key) {
                $this->error('  RESEND_KEY is not set.');

                return self::FAILURE;
            }

            // Resend refuses a From address outside a verified domain, and the
            // refusal is a 403 that reads like a bad key. Worth catching here
            // where the cause is obvious.
            if ($from && ! str_contains((string) $from, '@')) {
                $this->warn('  MAIL_FROM_ADDRESS does not look like an address.');
            }

            $this->line('  domain    : verify the sender domain at resend.com/domains');
        }

        if ($mailer === 'log') {
            $this->warn('  MAIL_MAILER is `log`: nothing will be sent. Check storage/logs.');
        }

        $this->line('');

        try {
            Mail::raw(
                "FreightMove mail check.\n\nIf you are reading this, the {$mailer} transport works.",
                fn ($message) => $message->to($to)->subject('FreightMove mail check'),
            );
        } catch (Throwable $e) {
            $this->error('  Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("  Accepted for delivery to {$to}.");
        $this->line('  Accepted is not delivered — check the inbox, and the spam folder.');
        $this->line('');

        return self::SUCCESS;
    }
}
