<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ProductTypeTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Produto teste', 'category' => 'Produtos', 'price_cents' => 2000]);

        return compact('tenant', 'store', 'product');
    }

    private function update(string $type): array
    {
        return ['name' => 'Produto teste', 'category' => 'Produtos', 'price_cents' => 2000, 'available' => 1, 'product_type' => $type];
    }

    public function test_common_editor_hides_pizza_configuration_and_common_requests_cannot_create_flavors_or_borders(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->owner($f['tenant']))->get('/products/'.$f['product'].'/edit')->assertOk()->assertSee('Tipo de produto')->assertDontSee('id="pizza"', false)->assertDontSee('Sabores de pizza')->assertDontSee('Máximo de sabores')->assertDontSee('Borda (uma escolha)');
        $this->postJson('/products/'.$f['product'].'/pizza', ['pizza_enabled' => 1, 'pizza_pricing' => 'highest'])->assertUnprocessable();
        $this->postJson('/products/'.$f['product'].'/flavors', ['name' => 'Inválido', 'available' => 1, 'prices' => [1 => '20,00']])->assertUnprocessable();
        $this->postJson('/products/'.$f['product'].'/groups', ['name' => 'Borda', 'kind' => 'border', 'minimum' => 1, 'maximum' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('pizza_flavors', 0);
        $this->assertDatabaseCount('option_groups', 0);
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'product_type' => 'common', 'pizza_enabled' => false]);
    }

    public function test_type_selection_is_persisted_on_creation_and_edit_is_paused_before_showing_pizza_tools(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->owner($f['tenant']))->get('/products/create?store_id='.$f['store'])->assertOk()->assertSee('Tipo de produto');
        $this->post('/products', ['store_id' => $f['store'], 'name' => 'Pizza nova', 'product_type' => 'pizza', 'category' => 'Pizzas', 'price' => '20,00', 'available' => 0])->assertRedirect();
        $pizza = DB::table('products')->where('name', 'Pizza nova')->first();
        $this->assertSame('pizza', $pizza->product_type);
        $this->get('/products/'.$pizza->id.'/edit')->assertOk()->assertSee('id="pizza"', false);
        $this->post('/products/'.$f['product'].'/edit', $this->update('pizza'))->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'product_type' => 'pizza', 'available' => false, 'pizza_enabled' => false]);
        $this->get('/products/'.$f['product'].'/edit')->assertOk()->assertSee('Sabores de pizza');
        $this->postJson('/products/'.$f['product'].'/edit', $this->update('combo'))->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $f['tenant'], 'action' => 'product.type_changed']);
    }

    public function test_switch_to_common_preserves_pizza_data_but_hides_it_and_ignores_border_in_checkout(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['product_type' => 'pizza', 'pizza_enabled' => true, 'uses_variants' => true]);
        $size = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Grande', 'price_cents' => 2000]);
        $flavor = DB::table('pizza_flavors')->insertGetId(['product_id' => $f['product'], 'name' => 'Calabresa preservada', 'prices' => json_encode([$size => 5000])]);
        $border = DB::table('option_groups')->insertGetId(['product_id' => $f['product'], 'name' => 'Borda preservada', 'kind' => 'border', 'minimum' => 1, 'maximum' => 1]);
        $this->actingAs($this->owner($f['tenant']))->post('/products/'.$f['product'].'/edit', $this->update('common'))->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'product_type' => 'common', 'pizza_enabled' => false, 'available' => false]);
        $this->assertDatabaseHas('pizza_flavors', ['id' => $flavor]);
        $this->assertDatabaseHas('option_groups', ['id' => $border, 'kind' => 'border']);
        $this->get('/products/'.$f['product'].'/edit')->assertOk()->assertDontSee('Calabresa preservada')->assertDontSee('Borda preservada')->assertDontSee('Máximo de sabores');
        $this->post('/products/'.$f['product'].'/edit', $this->update('common'))->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'available' => true]);
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('Borda preservada')->assertDontSee('Calabresa preservada');
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'idempotency_key' => (string) Str::uuid(), 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1, 'variant_id' => $size]]];
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 2000]);
        $this->post('/products/'.$f['product'].'/edit', $this->update('pizza'))->assertRedirect();
        $this->get('/products/'.$f['product'].'/edit')->assertOk()->assertSee('Calabresa preservada')->assertSee('Borda preservada');
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'available' => false, 'pizza_enabled' => false]);
    }

    public function test_other_tenant_and_staff_cannot_change_product_type(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->owner($this->tenant('other')))->post('/products/'.$f['product'].'/edit', $this->update('pizza'))->assertNotFound();
        $this->actingAs(User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']))->post('/products/'.$f['product'].'/edit', $this->update('pizza'))->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $f['product'], 'product_type' => 'common']);
    }
}
