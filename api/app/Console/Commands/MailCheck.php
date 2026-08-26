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
 * never heard they won a job. Worse, a message accepted by Mailgun and then
 * filed as spam looks identical to success from the server's side.
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

        if ($mailer === 'mailgun') {
            $domain = config('services.mailgun.domain');
            $secret = config('services.mailgun.secret');

            $this->line('  domain    : '.($domain ?: '(not set)'));
            $this->line('  endpoint  : '.config('services.mailgun.endpoint'));
            $this->line('  key       : '.($secret ? 'set ('.strlen($secret).' chars)' : '(not set)'));

            if (! $domain || ! $secret) {
                $this->error('  MAILGUN_DOMAIN and MAILGUN_SECRET must both be set.');

                return self::FAILURE;
            }

            // Mailgun rejects a From address outside the sending domain, and
            // the rejection reads as a generic 400 — worth catching here where
            // the cause is obvious.
            if ($from && ! str_ends_with((string) $from, '@'.$domain) && ! str_contains((string) $from, $domain)) {
                $this->warn("  MAIL_FROM_ADDRESS is not on {$domain} — Mailgun will refuse this.");
            }
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
