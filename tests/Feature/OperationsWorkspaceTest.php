<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DispatchService;
use App\Services\OperationsBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OperationsWorkspaceTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));

        return compact('tenant', 'store');
    }

    private function hash(int $id): string
    {
        return app(OperationsBoard::class)->review(DB::table('orders')->find($id), DB::table('rides')->where('order_id', $id)->first());
    }

    public function test_kanban_separates_scheduled_orders_and_paginates_each_lane(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 26; $i++) {
            $id = $this->order($f['tenant'], $f['store']);
            DB::table('orders')->where('id', $id)->update(['status' => 'accepted']);
        }
        DB::table('orders')->where('id', $id)->update(['prepare_at' => now()->addHour()]);
        $response = $this->get('/kitchen?store_id='.$f['store'])->assertOk();
        $board = $response->viewData('board');
        $this->assertEquals(25, $board['counts']['accepted']);
        $this->assertEquals(1, $board['counts']['scheduled']);
        $this->assertCount(24, $board['lanes']['accepted']);
        $this->get('/kitchen?store_id='.$f['store'].'&accepted_page=2')->assertOk();
        $board = $this->get('/kitchen?mode=pickup')->assertOk()->viewData('board');
        $this->assertEquals(0, $board['counts']['accepted']);
        $this->assertEquals(0, $board['counts']['scheduled']);
        $this->assertCount(0, $this->get('/kitchen?view=list&mode=pickup')->assertOk()->viewData('orders'));
    }

    public function test_summary_counts_all_channels_without_exposing_other_stores(): void
    {
        $f = $this->fixture();
        for ($i = 0; $i < 6; $i++) {
            $id = $this->order($f['tenant'], $f['store']);
            DB::table('orders')->where('id', $id)->update(['status' => 'preparing', 'customer_name' => 'Visible '.$i]);
        }
        $other = $this->store($f['tenant'], 'other');
        $id = $this->order($f['tenant'], $other);
        DB::table('orders')->where('id', $id)->update(['customer_name' => 'Foreign customer']);
        $html = $this->get('/pos/'.$f['store'].'/operations')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('html');
        $this->assertStringContainsString('Exibindo 4 de 6', $html);
        $this->assertStringContainsString('Visible 0', $html);
        $this->assertStringNotContainsString('Visible 5', $html);
        $this->assertStringNotContainsString('Foreign customer', $html);
        $this->actingAs($this->owner($this->tenant('foreign')))->get('/pos/'.$f['store'].'/operations')->assertNotFound();
    }

    public function test_expedition_uses_collection_and_completion_not_order_ready_as_route(): void
    {
        $f = $this->fixture();
        $ready = $this->order($f['tenant'], $f['store']);
        $rides = [];
        foreach (['assigned', 'collected', 'completed', 'completed'] as $index => $status) {
            $rides[$index] = $this->ride($f['tenant'], $f['store']);
            DB::table('rides')->where('id', $rides[$index])->update(['status' => $status, 'completed_at' => $status === 'completed' ? ($index === 3 ? now()->subDays(2) : now()) : null]);
        }
        $response = $this->get('/expedition')->assertOk();
        $this->assertEquals(['waiting' => 2, 'route' => 1, 'done' => 1, 'incidents' => 0], $response->viewData('counts'));
        $this->assertTrue($response->viewData('lanes')['waiting']->contains('id', $ready));
        $this->assertEquals($rides[1], $response->viewData('lanes')['route']->first()->ride_id);
        $foreign = $this->store($this->tenant('foreign'), 'foreign');
        $this->get('/expedition?store_id='.$foreign)->assertNotFound();
        $this->get('/expedition/live?store_id='.$foreign)->assertNotFound();
    }

    public function test_dispatch_from_menu_creates_offer_and_rejects_repeated_review(): void
    {
        $f = $this->fixture();
        $id = $this->order($f['tenant'], $f['store']);
        $driver = $this->driver($f['store']);
        $hash = $this->hash($id);
        $this->postJson('/expedition/orders/'.$id.'/dispatch', ['review_hash' => $hash, 'preferred_driver_id' => $driver])->assertOk();
        $ride = DB::table('rides')->first();
        $this->assertSame('offered', $ride->status);
        $this->assertNull($ride->driver_id);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'ready']);
        $this->postJson('/expedition/orders/'.$id.'/dispatch', ['review_hash' => $hash, 'preferred_driver_id' => $driver])->assertConflict();
        $this->assertDatabaseCount('rides', 1);
        app(DispatchService::class)->accept(DB::table('offers')->value('id'), $driver);
        $counts = $this->get('/expedition')->assertOk()->viewData('counts');
        $this->assertEquals(1, $counts['waiting']);
        $this->assertEquals(0, $counts['route']);
    }

    public function test_payment_and_driver_changes_prevent_dispatch_without_side_effects(): void
    {
        $f = $this->fixture();
        $id = $this->order($f['tenant'], $f['store']);
        $driver = $this->driver($f['store']);
        $hash = $this->hash($id);
        DB::table('orders')->where('id', $id)->update(['payment_method' => 'online_infinitepay']);
        $this->postJson('/expedition/orders/'.$id.'/dispatch', ['review_hash' => $hash])->assertConflict();
        $this->postJson('/expedition/orders/'.$id.'/dispatch', ['review_hash' => $this->hash($id), 'preferred_driver_id' => $driver])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $id, 'preferred_driver_id' => null]);
        $this->assertDatabaseCount('rides', 0);
        DB::table('orders')->where('id', $id)->update(['payment_method' => 'cash']);
        DB::table('drivers')->where('id', $driver)->update(['heartbeat_at' => now()->subMinutes(3)]);
        $this->postJson('/expedition/orders/'.$id.'/dispatch', ['review_hash' => $this->hash($id), 'preferred_driver_id' => $driver])->assertConflict();
        $this->assertDatabaseCount('rides', 0);
    }

    public function test_operator_scope_and_fresh_revocation_apply_to_live_and_actions(): void
    {
        $f = $this->fixture();
        $other = $this->store($f['tenant'], 'other');
        $id = $this->order($f['tenant'], $other);
        $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $f['tenant'], 'staff_profile' => 'operator']);
        DB::table('user_store_access')->insert(['user_id' => $staff->id, 'tenant_id' => $f['tenant'], 'store_id' => $f['store']]);
        $this->actingAs($staff)->get('/expedition')->assertOk();
        $this->get('/pos/'.$other.'/operations')->assertNotFound();
        $this->postJson('/expedition/orders/'.$id.'/dispatch', ['review_hash' => $this->hash($id)])->assertNotFound();
        DB::table('users')->where('id', $staff->id)->update(['access_enabled' => false]);
        $this->getJson('/expedition/live')->assertForbidden();
        $this->getJson('/pos/'.$f['store'].'/operations')->assertForbidden();
    }

    public function test_kitchen_profile_cannot_open_expedition_or_pos_summary(): void
    {
        $f = $this->fixture();
        $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $f['tenant'], 'staff_profile' => 'kitchen']);
        DB::table('user_store_access')->insert(['user_id' => $staff->id, 'tenant_id' => $f['tenant'], 'store_id' => $f['store']]);
        $this->actingAs($staff)->get('/kitchen')->assertOk();
        $this->getJson('/expedition/live')->assertForbidden();
        $this->getJson('/pos/'.$f['store'].'/operations')->assertForbidden();
    }

    public function test_map_only_contains_fresh_positions_for_current_store_and_hides_busy_elsewhere(): void
    {
        $f = $this->fixture();
        $fresh = $this->driver($f['store']);
        $stale = $this->driver($f['store'], 100);
        $busy = $this->driver($f['store']);
        $other = $this->store($f['tenant'], 'other');
        $ride = $this->ride($f['tenant'], $other);
        DB::table('rides')->where('id', $ride)->update(['status' => 'assigned', 'driver_id' => $busy]);
        $response = $this->get('/expedition')->assertOk();
        $this->assertEquals([$fresh], $response->viewData('drivers')->pluck('id')->all());
        $this->assertCount(0, $response->viewData('busy'));
        $response->assertSee('remaining_seconds');
        Http::assertNothingSent();
    }

    public function test_completed_today_uses_store_timezone_and_incidents_are_scoped(): void
    {
        $f = $this->fixture();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        foreach (['2026-11-10 02:59:59', '2026-11-10 03:00:00'] as $date) {
            $ride = $this->ride($f['tenant'], $f['store']);
            DB::table('rides')->where('id', $ride)->update(['status' => 'completed', 'completed_at' => $date]);
        }
        $this->assertEquals(1, $this->get('/expedition')->assertOk()->viewData('counts')['done']);
        $other = $this->store($f['tenant'], 'other');
        $foreign = $this->ride($f['tenant'], $other);
        DB::table('delivery_incidents')->insert(['tenant_id' => $f['tenant'], 'ride_id' => $foreign, 'author_id' => auth()->id(), 'kind' => 'customer_absent', 'description' => 'Other store incident', 'status' => 'open', 'request_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->get('/expedition?incidents=open')->assertOk()->assertDontSee('Other store incident');
        $this->assertEquals(0, $response->viewData('counts')['incidents']);
    }
}
