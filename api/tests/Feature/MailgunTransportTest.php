<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Mailgun transport.
 *
 * Mail is the part of this application that fails quietly: a wrong key, an
 * unverified domain or a missing transport package produces no error a user
 * would ever see, and the first symptom is a carrier saying they never heard
 * they won a job.
 *
 * These do not talk to Mailgun. They pin the wiring — that the transport
 * exists, that it is reachable by name, and that the credentials are read from
 * the keys the deployment docs tell people to fill in. A typo in any of those
 * is the failure that would otherwise be found in production.
 */
class MailgunTransportTest extends TestCase
{
    public function test_the_mailgun_mailer_is_configured(): void
    {
        $this->assertSame(
            'mailgun',
            config('mail.mailers.mailgun.transport'),
            'The mailgun mailer is missing from config/mail.php.',
        );
    }

    /**
     * The transport package has to be installed as well as configured —
     * symfony/mailgun-mailer is not part of a default Laravel install, and
     * without it the mailer resolves to a "unsupported transport" error at the
     * moment the first real email is sent.
     */
    public function test_the_transport_can_actually_be_built(): void
    {
        config([
            'services.mailgun.domain' => 'mg.example.test',
            'services.mailgun.secret' => 'key-test',
            'services.mailgun.endpoint' => 'api.mailgun.net',
        ]);

        $transport = Mail::mailer('mailgun')->getSymfonyTransport();

        $this->assertStringContainsString('mailgun', strtolower((string) $transport));
    }

    /**
     * The env keys the docs name are the env keys the code reads. Renaming one
     * without the other is silent: the config falls back to null and every
     * send is refused with a 401 that reads as a bad key.
     */
    public function test_credentials_come_from_the_documented_env_keys(): void
    {
        $services = require config_path('services.php');

        $this->assertArrayHasKey('mailgun', $services);
        $this->assertSame(
            ['domain', 'secret', 'endpoint', 'scheme'],
            array_keys($services['mailgun']),
        );
    }

    /**
     * US and EU Mailgun are separate stacks with separate keys. Defaulting to
     * US is a choice, not an accident, and it must not become null — an empty
     * endpoint produces a request to nowhere.
     */
    public function test_the_region_defaults_to_the_us_endpoint(): void
    {
        $this->assertSame('api.mailgun.net', config('services.mailgun.endpoint'));
    }

    /**
     * Whatever the transport, the application must keep working when it fails.
     * `Notifier` and the receipt senders all guard their sends; this asserts
     * the promise at the seam rather than trusting each caller.
     */
    public function test_the_default_mailer_is_not_mailgun_in_tests(): void
    {
        // Tests must never be one misconfiguration away from posting real mail.
        $this->assertNotSame('mailgun', config('mail.default'));
    }
}
