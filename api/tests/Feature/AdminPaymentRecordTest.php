<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin payments record — the previous site's `myadmin/payment_transaction`.
 *
 * The cases that matter are the ones that decide whether the money figure can
 * be trusted: that unpaid rows never count toward it, that the imported
 * history is included, and that only an admin can see any of it.
 */
class AdminPaymentRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    private function carrier(string $name = 'Bruno Katsav', string $email = 'bruno@example.test'): User
    {
        $user = User::factory()->create([
            'role' => UserRole::Carrier,
            'status' => UserStatus::Active,
            'name' => $name,
            'email' => $email,
        ]);
        UserProfile::factory()->create(['user_id' => $user->id, 'company_name' => "{$name} Freight"]);

        return $user;
    }

    private function payment(User $carrier, array $attributes = []): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'user_id' => $carrier->id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'monthly')->value('id'),
            'gateway' => 'paypal',
            'gateway_reference' => '5O190127TN364715T',
            'payer_name' => $carrier->name,
            'payer_email' => $carrier->email,
            'amount' => 64.99,
            'currency' => 'AUD',
            'status' => 'completed',
            'paid_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_an_admin_sees_who_paid_what_and_when(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments')
            ->assertOk();

        $response->assertJsonPath('data.items.0.carrier.name', 'Bruno Katsav Freight');
        $response->assertJsonPath('data.items.0.amount', 64.99);
        $response->assertJsonPath('data.items.0.currency', 'AUD');
        $response->assertJsonPath('data.items.0.reference', '5O190127TN364715T');
        $response->assertJsonPath('data.summary.collected', 64.99);
    }

    /**
     * A pending row is a plan someone reserved and has not paid for. Counting
     * it would report income that does not exist — the one number on this page
     * that has to be right.
     */
    public function test_unpaid_rows_never_count_toward_money_collected(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier);
        $this->payment($carrier, ['status' => 'pending', 'amount' => 699.90, 'paid_at' => null]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments')
            ->assertOk();

        $response->assertJsonPath('data.summary.collected', 64.99);
        $response->assertJsonPath('data.summary.payments', 1);
        $response->assertJsonPath('data.summary.awaiting', 1);
    }

    public function test_it_splits_the_total_by_gateway(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier);
        $this->payment($carrier, ['gateway' => 'manual', 'amount' => 184.99]);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments')
            ->assertOk();

        $response->assertJsonPath('data.summary.collected', 249.98);
        $response->assertJsonPath('data.summary.collected_paypal', 64.99);
        $response->assertJsonPath('data.summary.collected_manual', 184.99);
    }

    /**
     * PayPal reports `COMPLETED`; this application writes `completed`. The
     * model lower-cases on write so one spelling reaches the database and the
     * client's `status === 'completed'` holds for imported rows too.
     */
    public function test_a_shouting_status_from_paypal_is_normalised(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier, ['status' => 'COMPLETED']);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'completed');
    }

    /** History imported from the old site is marked as such. */
    public function test_imported_history_is_distinguishable(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier, ['legacy_id' => '1839472625']);
        $this->payment($carrier, ['gateway_reference' => 'NEW-ORDER-1']);

        $items = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([false, true], array_column($items, 'is_legacy'));
    }

    public function test_it_filters_by_gateway_and_status(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier);
        $this->payment($carrier, ['gateway' => 'manual', 'status' => 'pending', 'paid_at' => null]);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments?gateway=paypal')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/payments?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    /**
     * PayPal reports whatever address the payer used, which is routinely not
     * the one on the account — so the payer has to be searchable in their own
     * right, not only through the carrier.
     */
    public function test_it_searches_the_payer_the_carrier_and_the_reference(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier, [
            'payer_email' => 'accounts@katsavhaulage.test',
            'gateway_reference' => 'ORDER-ABC-123',
        ]);
        $this->payment($this->carrier('Ana Reyes', 'ana@example.test'));

        foreach (['katsavhaulage', 'ORDER-ABC', 'Bruno'] as $term) {
            $items = $this->actingAs($this->admin())
                ->getJson("/api/v1/admin/payments?q={$term}")
                ->assertOk()
                ->json('data.items');

            $this->assertCount(1, $items, "Searching '{$term}' should find exactly the one payment.");
        }
    }

    public function test_a_carrier_cannot_read_the_payment_record(): void
    {
        $carrier = $this->carrier();
        $this->payment($carrier);

        $this->actingAs($carrier)->getJson('/api/v1/admin/payments')->assertForbidden();
    }

    public function test_a_signed_out_visitor_cannot_read_the_payment_record(): void
    {
        $this->getJson('/api/v1/admin/payments')->assertUnauthorized();
    }

    /** Read-only: editing a payment here would only make it disagree with PayPal. */
    public function test_the_record_cannot_be_written_to(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/payments')
            ->assertStatus(405);
    }
}
