<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /public/config` — the keys the browser is allowed to hold.
 *
 * Both values here are public by design: a Maps key and a PayPal client id
 * identify the account to a browser SDK and authorise nothing on their own.
 * They are served rather than compiled in because `deploy/web` is committed to
 * the repository, so anything baked into a build is baked into git history and
 * rotating it would mean a rebuild instead of an env edit.
 */
class ClientConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_the_paypal_client_id_when_paypal_is_the_gateway(): void
    {
        config([
            'freightmove.subscriptions.gateway' => 'paypal',
            'services.paypal.client_id' => 'AXqJtest',
            'services.paypal.mode' => 'sandbox',
        ]);

        $this->getJson('/api/v1/public/config')
            ->assertOk()
            ->assertJsonPath('data.paypal_client_id', 'AXqJtest')
            ->assertJsonPath('data.paypal_mode', 'sandbox');
    }

    /**
     * Pay Later messaging must not advertise instalments the checkout cannot
     * honour. On the manual gateway there is no PayPal checkout at all.
     */
    public function test_it_withholds_the_client_id_when_paypal_is_not_the_gateway(): void
    {
        config([
            'freightmove.subscriptions.gateway' => 'manual',
            'services.paypal.client_id' => 'AXqJtest',
        ]);

        $this->getJson('/api/v1/public/config')
            ->assertOk()
            ->assertJsonPath('data.paypal_client_id', null);
    }

    /** Null, not '' — "not configured" is different from "configured blank". */
    public function test_an_unset_key_is_null_rather_than_empty(): void
    {
        config([
            'freightmove.subscriptions.gateway' => 'paypal',
            'services.paypal.client_id' => '',
            'freightmove.google_maps_key' => '',
        ]);

        $data = $this->getJson('/api/v1/public/config')->assertOk()->json('data');

        $this->assertNull($data['paypal_client_id']);
        $this->assertNull($data['google_maps_key']);
    }

    /** The secret must never be within reach of this endpoint. */
    public function test_the_paypal_secret_is_never_served(): void
    {
        config([
            'freightmove.subscriptions.gateway' => 'paypal',
            'services.paypal.client_id' => 'AXqJtest',
            'services.paypal.client_secret' => 'EOOsecret-must-not-leak',
        ]);

        $body = $this->getJson('/api/v1/public/config')->assertOk()->getContent();

        $this->assertStringNotContainsString('EOOsecret-must-not-leak', $body);
        $this->assertStringNotContainsString('secret', strtolower($body));
    }

    public function test_it_needs_no_account(): void
    {
        $this->getJson('/api/v1/public/config')->assertOk();
    }
}
