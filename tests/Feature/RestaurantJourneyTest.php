<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class RestaurantJourneyTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_restaurant_can_sell_from_tenant_creation_to_completion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/tenants', ['name' => 'Restaurante Piloto', 'slug' => 'piloto', 'email' => 'piloto@example.com', 'password' => 'SenhaPiloto12345'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/logout');
        $this->post('/login', ['email' => 'piloto@example.com', 'password' => 'SenhaPiloto12345'])->assertRedirect('/app');
        $this->get('/dashboard')->assertOk()->assertSee('Cadastrar primeira loja');
        $this->post('/stores', ['name' => 'Loja Piloto', 'slug' => 'piloto', 'address' => 'Rua A, 10', 'latitude' => -23.55, 'longitude' => -46.63, 'rate_mode' => 'fixed', 'base_fare' => '8,00', 'per_km_fare' => '2,00', 'minimum_fare' => '8,00', 'customer_fee' => '5,00'])->assertRedirect()->assertSessionHasNoErrors();
        $store = DB::table('stores')->where('slug', 'piloto')->first();
        $this->post('/products', ['store_id' => $store->id, 'name' => 'Prato do dia', 'category' => 'Almoço', 'price' => '24,90'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk()->assertSee('✓ Produtos disponíveis')->assertSee('/cardapio/piloto')->assertSee('Retirada disponível');
        $product = DB::table('products')->where('store_id', $store->id)->first();
        $this->post('/logout');
        $this->get('/cardapio/piloto')->assertOk()->assertSee('Prato do dia');
        $draft = (string) Str::uuid();
        $this->post('/cardapio/piloto/review', ['customer_name' => 'Cliente Piloto', 'phone' => '11999999999', 'mode' => 'pickup', 'items' => [$product->id => 2], 'idempotency_key' => $draft])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cardapio/piloto', ['draft_id' => $draft])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/cardapio/piloto', ['draft_id' => $draft])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $order = DB::table('orders')->first();
        $this->assertSame(4980, $order->total_cents);
        $this->post('/login', ['email' => 'piloto@example.com', 'password' => 'SenhaPiloto12345'])->assertRedirect('/app');
        foreach (['accepted', 'preparing', 'ready', 'completed'] as $status) {
            $this->post('/orders/'.$order->id.'/status', ['status' => $status])->assertRedirect()->assertSessionHasNoErrors();
            $this->getJson('/pedido/'.$order->public_id.'/status')->assertOk()->assertJsonPath('status', $status);
        }
        $this->post('/orders/'.$order->id.'/paid')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed', 'payment_status' => 'paid', 'total_cents' => 4980]);
        $this->get('/dashboard?status=completed')->assertOk()->assertSee('Cliente Piloto');
    }

    public function test_setup_only_exposes_own_stores_and_updates_when_products_are_paused(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->tenant('other');
        $this->store($other, 'segredo');
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato', 'category' => 'Almoço', 'price_cents' => 1000]);
        $this->actingAs($this->owner($tenant))->get('/dashboard')->assertOk()->assertSee('✓ Produtos disponíveis')->assertDontSee('/cardapio/segredo');
        $this->post('/products/'.$product.'/toggle')->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertSee('Falta cadastrar ou ativar um produto');
    }
}
