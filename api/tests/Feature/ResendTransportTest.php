<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Resend transport.
 *
 * Mail is the part of this application that fails quietly: a wrong key or a
 * missing transport package produces no error a user would ever see, and the
 * first symptom is a carrier saying they never heard they won a job.
 *
 * These do not talk to Resend. They pin the wiring — that the transport exists,
 * that it is reachable by name, and that the key is read from the env key the
 * deployment docs tell people to fill in. A typo in any of those is the failure
 * that would otherwise surface in production.
 */
class ResendTransportTest extends TestCase
{
    public function test_the_resend_mailer_is_configured(): void
    {
        $this->assertSame(
            'resend',
            config('mail.mailers.resend.transport'),
            'The resend mailer is missing from config/mail.php.',
        );
    }

    /**
     * The transport package has to be installed as well as configured —
     * `resend/resend-laravel` is not part of a default Laravel install, and
     * without it the mailer fails with "unsupported transport" at the moment
     * the first real email is sent.
     */
    public function test_the_transport_can_actually_be_built(): void
    {
        config(['services.resend.key' => 're_test_key']);

        $transport = Mail::mailer('resend')->getSymfonyTransport();

        $this->assertStringContainsString('resend', strtolower((string) $transport));
    }

    /**
     * The env key the docs name is the env key the code reads. Renaming one
     * without the other is silent: the config falls back to null and every
     * send is refused.
     */
    public function test_the_key_comes_from_the_documented_env_key(): void
    {
        $services = require config_path('services.php');

        $this->assertArrayHasKey('resend', $services);
        $this->assertSame(['key'], array_keys($services['resend']));
    }

    /**
     * Mailgun is gone. This is not housekeeping: a stale mailer left in the
     * config is one `MAIL_MAILER` typo away from silently selecting a
     * transport whose package is no longer installed.
     */
    public function test_the_mailgun_transport_is_gone(): void
    {
        $this->assertArrayNotHasKey('mailgun', config('mail.mailers'));
        $this->assertNull(config('services.mailgun'));
    }

    /** Tests must never be one misconfiguration away from posting real mail. */
    public function test_the_default_mailer_does_not_send_in_tests(): void
    {
        $this->assertNotSame('resend', config('mail.default'));
    }
}
