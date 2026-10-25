<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ImplementationTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_owner_cannot_read_or_write_another_tenant(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $s = $this->store($b);
        $o = $this->order($b, $s);
        $this->actingAs($this->owner($a))->get('/dashboard')->assertOk()->assertDontSee('Rua do Cliente');
        $this->post('/products', ['store_id' => $s, 'name' => 'Produto', 'category' => 'Teste', 'price_cents' => 100])->assertNotFound();
        $this->post('/orders/'.$o.'/status', ['status' => 'completed'])->assertNotFound();
        $this->post('/orders/'.$o.'/paid')->assertNotFound();
        $this->get('/admin')->assertForbidden();
    }

    public function test_checkout_recalculates_prices_and_is_idempotent(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $p = DB::table('products')->insertGetId(['tenant_id' => $t, 'store_id' => $s, 'name' => 'Burger', 'category' => 'Lanches', 'price_cents' => 2990]);
        $zone = DB::table('delivery_zones')->insertGetId(['store_id' => $s, 'name' => 'Centro', 'city' => 'São Paulo', 'state_code' => 'SP', 'fee_cents' => 500]);
        $body = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'delivery', 'address' => 'Rua A, 10', 'delivery_zone_id' => $zone, 'items' => [$p => 2], 'idempotency_key' => (string) Str::uuid(), 'total_cents' => 1];
        $body['delivery_token'] = $this->deliveryToken($s, $body['address']);
        $this->post('/cardapio/loja/review', $body)->assertRedirect();
        $this->assertDatabaseCount('orders', 0);
        $first = $this->post('/cardapio/loja', ['draft_id' => $body['idempotency_key']])->assertRedirect();
        $second = $this->post('/cardapio/loja', ['draft_id' => $body['idempotency_key']])->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 5980, 'total_cents' => 6480]);
        DB::table('products')->where('id', $p)->update(['price_cents' => 10000]);
        $this->get($first->headers->get('Location'))->assertOk()->assertSee('64,80');
    }

    public function test_checkout_rejects_product_from_other_store(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $this->store($a, 'a');
        $s = $this->store($b, 'b');
        $p = DB::table('products')->insertGetId(['tenant_id' => $b, 'store_id' => $s, 'name' => 'Outro', 'category' => 'X', 'price_cents' => 100]);
        $this->postJson('/cardapio/a/review', ['customer_name' => 'A', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$p => 1], 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_disabled_module_cannot_dispatch_direct_request(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $o = $this->order($t, $s);
        DB::table('tenants')->where('id', $t)->update(['courier_module' => 'disabled']);
        $this->actingAs($this->owner($t))->post('/orders/'.$o.'/dispatch')->assertForbidden();
        $this->assertDatabaseCount('rides', 0);
    }

    public function test_disable_preserves_assigned_rides_and_revokes_open_offers(): void
    {
        $t = $this->tenant();
        $s = $this->store($t);
        $ride = $this->ride($t, $s);
        $d = $this->driver($s);
        DB::table('rides')->where('id', $ride)->update(['status' => 'assigned', 'driver_id' => $d]);
        $other = $this->ride($t, $s);
        DB::table('offers')->insert(['ride_id' => $other, 'driver_id' => $d, 'status' => 'open', 'expires_at' => now()->addMinute()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/tenants/'.$t.'/module', ['state' => 'disabled'])->assertRedirect();
        $this->assertDatabaseHas('tenants', ['id' => $t, 'courier_module' => 'closing']);
        $this->assertDatabaseHas('rides', ['id' => $ride, 'status' => 'assigned']);
        $this->assertDatabaseHas('offers', ['ride_id' => $other, 'status' => 'revoked']);
    }

    public function test_global_registration_is_pending_and_role_pages_render(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/entregadores/cadastro')->assertOk();
        $this->post('/entregadores/cadastro', ['name' => 'Motoboy', 'email' => 'moto@example.test', 'password' => 'senha-segura-123', 'password_confirmation' => 'senha-segura-123', 'phone' => '11999999999', 'vehicle' => 'Moto'])->assertRedirect('/login');
        $u = User::where('email', 'moto@example.test')->firstOrFail();
        $this->assertNull($u->tenant_id);
        $this->assertSame('courier', $u->role);
        $this->actingAs($u)->get('/courier')->assertOk();
        $this->post('/courier/presence', ['latitude' => -23.55, 'longitude' => -46.63, 'accuracy' => 10])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin')->assertOk();
    }
}
