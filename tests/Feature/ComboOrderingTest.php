<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ComboOrderingTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function product(int $tenant, int $store, string $name, string $type = 'common'): int
    {
        return DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => $name, 'category' => 'Combos e produtos', 'price_cents' => 1000, 'product_type' => $type]);
    }

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $combo = $this->product($tenant, $store, 'Combo da casa', 'combo');
        DB::table('products')->where('id', $combo)->update(['price_cents' => 3000]);
        $burger = $this->product($tenant, $store, 'Lanche');
        $drink = $this->product($tenant, $store, 'Refrigerante');
        $foodGroup = DB::table('combo_groups')->insertGetId(['product_id' => $combo, 'name' => 'Seu lanche', 'minimum' => 1, 'maximum' => 1]);
        $drinkGroup = DB::table('combo_groups')->insertGetId(['product_id' => $combo, 'name' => 'Sua bebida', 'minimum' => 1, 'maximum' => 1]);
        $foodOption = DB::table('combo_options')->insertGetId(['combo_group_id' => $foodGroup, 'component_product_id' => $burger, 'quantity' => 1, 'surcharge_cents' => 0]);
        $drinkOption = DB::table('combo_options')->insertGetId(['combo_group_id' => $drinkGroup, 'component_product_id' => $drink, 'quantity' => 2, 'surcharge_cents' => 300]);
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'idempotency_key' => (string) Str::uuid(), 'cart_lines' => [['product_id' => $combo, 'quantity' => 2, 'combo_choices' => [$foodGroup => [$foodOption], $drinkGroup => [$drinkOption]]]]];

        return compact('tenant', 'store', 'combo', 'burger', 'drink', 'foodGroup', 'drinkGroup', 'foodOption', 'drinkOption', 'cart');
    }

    public function test_fixed_base_and_upgrade_per_component_unit_are_snapshotted_and_idempotent(): void
    {
        $f = $this->fixture();
        $response = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('72,00')->assertSee('2 × Refrigerante')->assertSee('1 × Lanche');
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('order_items', ['unit_cents' => 3600, 'quantity' => 2]);
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 7200]);
        DB::table('products')->where('id', $f['drink'])->update(['name' => 'Nome alterado']);
        $this->get('/pedido/'.DB::table('orders')->value('public_id'))->assertOk()->assertSee('Refrigerante')->assertDontSee('Nome alterado');
        $kitchen = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'kitchen']);
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'user_id' => $kitchen->id]);
        $this->actingAs($kitchen)->get('/operation/orders/'.DB::table('orders')->value('id'))->assertOk()->assertSee('2 × Refrigerante')->assertDontSee('72,00');
    }

    public function test_required_choices_duplicates_and_foreign_option_ids_cannot_be_used(): void
    {
        $f = $this->fixture();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['combo_choices'] = [];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['combo_choices'][$f['foodGroup']] = [$f['foodOption'], $f['foodOption']];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['combo_choices'][$f['foodGroup']] = [$f['drinkOption']];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $cart = $f['cart'];
        $cart['cart_lines'][0]['combo_choices'][999] = [$f['foodOption']];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_component_pause_or_new_required_addons_hide_combo_and_prevent_confirmation(): void
    {
        $f = $this->fixture();
        $this->get('/cardapio/loja')->assertOk()->assertSee('Combo da casa');
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('products')->where('id', $f['drink'])->update(['available' => false]);
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('Combo da casa');
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        DB::table('products')->where('id', $f['drink'])->update(['available' => true]);
        DB::table('option_groups')->insert(['product_id' => $f['drink'], 'name' => 'Escolha obrigatória', 'minimum' => 1, 'maximum' => 1]);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_upgrade_changes_require_review_but_standalone_component_price_does_not_change_combo_price(): void
    {
        $f = $this->fixture();
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('combo_options')->where('id', $f['drinkOption'])->update(['surcharge_cents' => 400]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
        DB::table('combo_options')->where('id', $f['drinkOption'])->update(['surcharge_cents' => 300]);
        $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        DB::table('products')->where('id', $f['drink'])->update(['price_cents' => 9999]);
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 7200]);
    }

    public function test_configuration_is_store_scoped_and_blocks_nested_or_required_choice_components(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->owner($f['tenant']));
        $data = ['group_id' => $f['foodGroup'], 'component_key' => $f['burger'].':0', 'quantity' => 1, 'surcharge' => '2,50', 'available' => 1];
        $this->post('/products/'.$f['combo'].'/combo/options', $data)->assertRedirect();
        $this->assertDatabaseHas('combo_options', ['combo_group_id' => $f['foodGroup'], 'surcharge_cents' => 250]);
        $otherStore = $this->store($f['tenant'], 'outra');
        $otherProduct = $this->product($f['tenant'], $otherStore, 'Outra loja');
        $this->post('/products/'.$f['combo'].'/combo/options', array_replace($data, ['component_key' => $otherProduct.':0']))->assertNotFound();
        $this->post('/products/'.$f['combo'].'/combo/options', array_replace($data, ['component_key' => $f['combo'].':0']))->assertNotFound();
        DB::table('option_groups')->insert(['product_id' => $f['burger'], 'name' => 'Molho obrigatório', 'minimum' => 1, 'maximum' => 1]);
        $this->postJson('/products/'.$f['combo'].'/combo/options', $data)->assertUnprocessable();
        $this->postJson('/products/'.$f['burger'].'/combo/groups', ['name' => 'Inválido', 'minimum' => 1, 'maximum' => 1])->assertUnprocessable();
        $this->postJson('/products/'.$f['combo'].'/pricing-mode', ['uses_variants' => 1])->assertUnprocessable();
        $this->actingAs($this->owner($this->tenant('other')))->post('/products/'.$f['combo'].'/combo/groups', ['name' => 'Invasão', 'minimum' => 1, 'maximum' => 1])->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $f['tenant'], 'action' => 'combo.option.saved']);
    }

    public function test_fixed_component_variant_must_be_owned_and_available(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['drink'])->update(['uses_variants' => true]);
        $size = DB::table('product_variants')->insertGetId(['product_id' => $f['drink'], 'name' => 'Lata', 'price_cents' => 600]);
        $this->postJson('/cardapio/loja/review', $f['cart'])->assertUnprocessable();
        DB::table('combo_options')->where('id', $f['drinkOption'])->update(['component_variant_id' => $size]);
        $review = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('Refrigerante · Lata');
        DB::table('product_variants')->where('id', $size)->update(['available' => false]);
        $this->postJson('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertUnprocessable();
    }

    public function test_combo_creation_hides_pizza_tools_and_supports_independent_cart_combinations(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->owner($f['tenant']))->get('/products/'.$f['combo'].'/edit')->assertOk()->assertSee('Monte o combo')->assertDontSee('id="pizza"', false)->assertDontSee('id="variants"', false);
        $otherOption = DB::table('combo_options')->insertGetId(['combo_group_id' => $f['drinkGroup'], 'component_product_id' => $f['drink'], 'quantity' => 1, 'surcharge_cents' => 0]);
        $cart = $f['cart'];
        $cart['cart_lines'][0]['quantity'] = 1;
        $cart['cart_lines'][] = $cart['cart_lines'][0];
        $cart['cart_lines'][1]['combo_choices'][$f['drinkGroup']] = [$otherOption];
        $review = $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->get($review->headers->get('Location'))->assertOk()->assertSee('66,00');
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('order_items', ['unit_cents' => 3600]);
        $this->assertDatabaseHas('order_items', ['unit_cents' => 3000]);
    }

    public function test_own_combo_choices_do_not_create_artificial_products_and_are_charged_correctly(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->owner($f['tenant']));
        $this->post('/products/'.$f['combo'].'/combo/options', ['group_id' => $f['foodGroup'], 'label' => '1/2 Calabresa', 'quantity' => 1, 'surcharge' => '0,00', 'available' => 1])->assertSessionHasNoErrors();
        $custom = DB::table('combo_options')->where('label', '1/2 Calabresa')->firstOrFail();
        $this->assertNull($custom->component_product_id);
        $this->assertDatabaseCount('products', 3);
        $f['cart']['cart_lines'][0]['combo_choices'][$f['foodGroup']] = [$custom->id];
        $response = $this->post('/cardapio/loja/review', $f['cart'])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('1/2 Calabresa')->assertSee('72,00');
        $this->post('/cardapio/loja', ['draft_id' => $f['cart']['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 7200]);
    }

    public function test_closed_combo_can_be_ordered_without_required_choices_only_after_explicit_configuration(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $combo = $this->product($tenant, $store, 'Combo fechado', 'combo');
        $cart = ['customer_name' => 'Ana', 'phone' => '11999999999', 'mode' => 'pickup', 'idempotency_key' => (string) Str::uuid(), 'items' => [$combo => 1]];
        $this->postJson('/cardapio/loja/review', $cart)->assertUnprocessable();
        $this->actingAs($this->owner($tenant))->post('/products/'.$combo.'/combo/settings', ['combo_fixed' => 1])->assertSessionHasNoErrors();
        $this->post('/cardapio/loja/review', $cart)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $cart['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['subtotal_cents' => 1000]);
    }
}
