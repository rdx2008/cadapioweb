<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class KitchenBoardTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_queue_uses_preparation_time_then_acceptance_and_keeps_pagination_on_board(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $ids = [];
        for ($i = 0; $i < 31; $i++) {
            $id = $this->order($tenant, $store);
            DB::table('orders')->where('id', $id)->update(['status' => 'accepted', 'created_at' => '2026-10-20 15:00:00', 'accepted_at' => '2026-10-20 15:05:00']);
            $ids[] = $id;
        }
        DB::table('orders')->where('id', $ids[30])->update(['prepare_at' => '2026-10-20 14:00:00']);
        $this->actingAs($this->owner($tenant));
        $response = $this->get('/kitchen?store_id='.$store)->assertOk();
        $this->assertSame($ids[30], $response->viewData('orders')->first()->id);
        $this->assertCount(30, $response->viewData('orders'));
        $this->assertEquals(31, $response->viewData('counts')['accepted']);
        $html = $this->get('/kitchen/live?stage=accepted&store_id='.$store.'&page=2')->assertOk()->json('html');
        $this->assertStringContainsString('/kitchen?', $html);
        $this->assertStringNotContainsString('/kitchen/live?', $html);
        $this->assertStringContainsString('page=1', $html);
    }

    public function test_board_scopes_stage_counts_and_never_exposes_customer_or_prices(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->tenant('foreign');
        $foreign = $this->store($other, 'foreign');
        foreach (['accepted', 'preparing', 'ready', 'pending', 'cancelled', 'completed'] as $status) {
            $id = $this->order($tenant, $store);
            DB::table('orders')->where('id', $id)->update(['status' => $status, 'customer_name' => 'Secret Customer']);
            DB::table('order_items')->insert(['order_id' => $id, 'name' => 'Item '.$status, 'quantity' => 2, 'unit_cents' => 99999, 'options' => json_encode([['group' => 'Choice', 'name' => 'Cheese', 'price_cents' => 456789]])]);
        }
        $id = $this->order($other, $foreign);
        DB::table('orders')->where('id', $id)->update(['status' => 'accepted']);
        $this->actingAs($this->owner($tenant));
        $this->get('/kitchen')->assertOk()->assertSee('Item accepted')->assertDontSee('Item preparing')->assertDontSee('Secret Customer')->assertDontSee('11999999999')->assertDontSee('99999')->assertDontSee('456789');
        $response = $this->get('/kitchen/live?stage=preparing')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $html = $response->json('html');
        $this->assertStringContainsString('Item preparing', $html);
        $this->assertStringNotContainsString('Secret Customer', $html);
        $this->get('/kitchen?store_id='.$foreign)->assertNotFound();
        $this->get('/kitchen?stage=completed')->assertSessionHasErrors('stage');
    }

    public function test_staff_permissions_are_rechecked_on_feed_and_mutations(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $unassigned = $this->store($tenant, 'unassigned');
        $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant, 'staff_profile' => 'kitchen', 'access_enabled' => true]);
        DB::table('user_store_access')->insert(['user_id' => $staff->id, 'tenant_id' => $tenant, 'store_id' => $store]);
        $id = $this->order($tenant, $store);
        DB::table('orders')->where('id', $id)->update(['status' => 'accepted']);
        $other = $this->order($tenant, $unassigned);
        DB::table('orders')->where('id', $other)->update(['status' => 'accepted']);
        $this->actingAs($staff)->get('/kitchen')->assertOk();
        $this->post('/kitchen/orders/'.$other.'/status', ['status' => 'preparing'])->assertNotFound();
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'preparing'])->assertRedirect();
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'ready'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'ready', 'payment_status' => 'pending']);
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'completed'])->assertSessionHasErrors('status');
        DB::table('user_store_access')->where('user_id', $staff->id)->delete();
        $this->get('/kitchen/live?store_id='.$store)->assertNotFound();
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'ready'])->assertNotFound();
        DB::table('users')->where('id', $staff->id)->update(['access_enabled' => false]);
        $this->get('/kitchen/live')->assertForbidden();
    }

    public function test_schedule_payment_and_stale_transitions_remain_blocked(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $id = $this->order($tenant, $store);
        DB::table('orders')->where('id', $id)->update(['status' => 'accepted', 'prepare_at' => now()->addHour()]);
        $this->actingAs($this->owner($tenant));
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'preparing'])->assertConflict();
        DB::table('orders')->where('id', $id)->update(['prepare_at' => null, 'payment_method' => 'online_infinitepay', 'payment_status' => 'pending']);
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'preparing'])->assertConflict();
        DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid', 'payment_method' => 'cash']);
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'preparing'])->assertRedirect();
        $started = DB::table('orders')->where('id', $id)->value('preparation_started_at');
        $this->post('/kitchen/orders/'.$id.'/status', ['status' => 'preparing'])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'preparing', 'preparation_started_at' => $started]);
    }

    public function test_suspended_company_and_unrelated_roles_cannot_use_the_board(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->owner($tenant));
        DB::table('tenants')->where('id', $tenant)->update(['status' => 'suspended']);
        $this->get('/kitchen')->assertForbidden();
        $this->get('/kitchen/live')->assertForbidden();
        foreach (['admin', 'courier'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/kitchen')->assertForbidden();
        }
    }
}
