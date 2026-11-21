<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ThrottleIsolationTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_owner_live_polling_never_consumes_the_limit_of_their_actions(): void
    {
        $tenant = $this->tenant();
        $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        for ($poll = 0; $poll < 12; $poll++) {
            $this->getJson('/orders/live')->assertOk();
        }
        $this->postJson('/stores/address/postcode', [])->assertUnprocessable();
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->postJson('/stores/address/postcode', [])->assertUnprocessable();
        }
        $this->postJson('/stores/address/postcode', [])->assertTooManyRequests();
        $this->getJson('/orders/live')->assertOk();
        $this->postJson('/admin/ifood/test')->assertForbidden();
    }

    public function test_courier_board_polling_does_not_block_heartbeat(): void
    {
        $driver = $this->driver($this->store($this->tenant()));
        $this->actingAs(User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id')));
        for ($poll = 0; $poll < 12; $poll++) {
            $this->getJson('/courier/live')->assertOk();
        }
        $this->postJson('/courier/heartbeat', ['latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10, 'captured_at' => now()->timestamp])->assertOk();
    }

    public function test_customer_tracking_polling_does_not_block_payment_actions_from_the_same_address(): void
    {
        for ($poll = 0; $poll < 12; $poll++) {
            $this->getJson('/pedido/00000000-0000-0000-0000-000000000000/status')->assertNotFound();
        }
        $this->postJson('/pedido/00000000-0000-0000-0000-000000000000/pagar')->assertNotFound();
        $this->postJson('/pedido/00000000-0000-0000-0000-000000000000/consultar-pagamento')->assertNotFound();
    }
}
