<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Sandwich', 'category' => 'Food', 'price_cents' => 1500, 'available' => true]);
        $this->actingAs($owner);
        $key = (string) Str::uuid();
        $data = ['idempotency_key' => $key, 'payment_method' => 'cash', 'received' => 'yes', 'change_for' => '50,00', 'cart_lines' => [['product_id' => $product, 'quantity' => 2]]];

        return compact('tenant', 'store', 'owner', 'product', 'key', 'data');
    }

    private function prepare(array $f): array
    {
        $this->post('/pos/'.$f['store'].'/review', $f['data'])->assertRedirect('/pos/'.$f['store'].'/review/'.$f['key'])->assertSessionHasNoErrors();
        $response = $this->get('/pos/'.$f['store'].'/review/'.$f['key'])->assertOk();

        return ['review_hash' => hash('sha256', json_encode($response->viewData('draft'), JSON_THROW_ON_ERROR))];
    }

    public function test_sale_review_confirmation_snapshot_audit_and_retries(): void
    {
        $f = $this->fixture();
        $this->get('/pos')->assertOk()->assertSee('Loja loja');
        $this->get('/pos/'.$f['store'])->assertOk()->assertSee('Sandwich')->assertDontSee('online_infinitepay');
        $hash = $this->prepare($f);
        $this->assertDatabaseCount('orders', 0);
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertRedirect();
        $order = DB::table('orders')->first();
        $this->assertSame('accepted', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pos', $order->source);
        $this->assertSame('pickup', $order->mode);
        $this->assertEquals(3000, $order->total_cents);
        $this->assertEquals(0, $order->fee_cents);
        $this->assertEquals(5000, $order->change_for_cents);
        $this->assertEquals($f['owner']->id, $order->created_by);
        $this->assertNotNull($order->accepted_at);
        $this->assertSame('', $order->phone);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2, 'unit_cents' => 1500]);
        $this->assertDatabaseHas('audit_events', ['action' => 'pos.sale', 'tenant_id' => $f['tenant']]);
        $this->travel(31)->minutes();
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->get('/kitchen')->assertOk()->assertSee('Sandwich');
    }

    public function test_staff_scope_and_access_revocation_are_enforced_at_confirmation(): void
    {
        $f = $this->fixture();
        $operator = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'operator']);
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'user_id' => $operator->id]);
        $this->actingAs($operator);
        $hash = $this->prepare($f);
        DB::table('user_store_access')->where('user_id', $operator->id)->delete();
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
        foreach (['admin', 'courier'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/pos')->assertForbidden();
        }
        $kitchen = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff', 'staff_profile' => 'kitchen']);
        $this->actingAs($kitchen)->get('/pos')->assertForbidden();
        $this->actingAs($this->owner($this->tenant('other')))->get('/pos/'.$f['store'])->assertNotFound();
    }

    public function test_price_drift_and_review_changes_require_new_confirmation(): void
    {
        $f = $this->fixture();
        $hash = $this->prepare($f);
        DB::table('products')->where('id', $f['product'])->update(['price_cents' => 2000]);
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertRedirect('/pos/'.$f['store'])->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $hash = $this->prepare($f);
        $f['data']['received'] = 'no';
        $this->prepare($f);
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_internal_counter_sale_can_run_when_public_menu_is_closed_and_below_minimum(): void
    {
        $f = $this->fixture();
        DB::table('stores')->where('id', $f['store'])->update(['open' => false, 'minimum_order_cents' => 9000, 'accepts_pix' => true]);
        $f['data']['received'] = 'no';
        $f['data']['payment_method'] = 'pix';
        unset($f['data']['change_for']);
        $hash = $this->prepare($f);
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertRedirect();
        $this->assertDatabaseHas('orders', ['source' => 'pos', 'payment_status' => 'pending', 'payment_method' => 'pix']);
        $this->assertFalse(app(StoreAvailability::class)->isOpen(DB::table('stores')->find($f['store'])));
    }

    public function test_invalid_payment_tender_items_draft_expiry_and_suspension_do_not_create_sales(): void
    {
        $f = $this->fixture();
        $this->post('/pos/'.$f['store'].'/review', array_replace($f['data'], ['payment_method' => 'online_infinitepay']))->assertSessionHasErrors('payment_method');
        $this->post('/pos/'.$f['store'].'/review', array_replace($f['data'], ['change_for' => '10,00']))->assertSessionHasErrors('change_for');
        $invalid = $f['data'];
        $invalid['cart_lines'][0]['product_id'] = 99999;
        $this->post('/pos/'.$f['store'].'/review', $invalid)->assertSessionHasErrors('cart');
        $hash = $this->prepare($f);
        $this->travel(31)->minutes();
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertStatus(419);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/pos')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_audit_failure_rolls_back_order_and_items(): void
    {
        $f = $this->fixture();
        $hash = $this->prepare($f);
        DB::statement("CREATE TRIGGER fail_pos_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'pos.sale' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_variant_and_required_options_are_priced_on_server_and_frozen(): void
    {
        $f = $this->fixture();
        DB::table('products')->where('id', $f['product'])->update(['uses_variants' => true]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $f['product'], 'name' => 'Large', 'price_cents' => 1800]);
        $group = DB::table('option_groups')->insertGetId(['product_id' => $f['product'], 'name' => 'Sauce', 'minimum' => 1, 'maximum' => 1]);
        $option = DB::table('product_options')->insertGetId(['option_group_id' => $group, 'name' => 'Cheese', 'price_cents' => 200]);
        $f['data']['cart_lines'][0]['variant_id'] = $variant;
        $this->post('/pos/'.$f['store'].'/review', $f['data'])->assertSessionHasErrors('cart');
        $f['data']['cart_lines'][0]['choices'] = [$group => [$option]];
        $hash = $this->prepare($f);
        $this->post('/pos/'.$f['store'].'/review/'.$f['key'].'/confirm', $hash)->assertRedirect();
        $item = DB::table('order_items')->first();
        $this->assertEquals(2000, $item->unit_cents);
        $this->assertDatabaseHas('orders', ['total_cents' => 4000]);
        DB::table('product_options')->where('id', $option)->update(['name' => 'Renamed', 'price_cents' => 999]);
        $this->get('/orders/'.$item->order_id)->assertOk()->assertSee('Cheese')->assertDontSee('Renamed');
    }
}
