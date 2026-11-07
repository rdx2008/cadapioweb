<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CashRegister;
use App\Services\DiningRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class WaiterAccessTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner)->post('/dining/tables', ['store_id' => $store, 'name' => 'Mesa 1', 'seats' => 4, 'enabled' => 1])->assertRedirect();
        $table = DB::table('dining_tables')->firstOrFail();
        $waiter = User::factory()->create(['tenant_id' => $tenant, 'role' => 'staff', 'staff_profile' => 'waiter']);
        DB::table('user_store_access')->insert(['tenant_id' => $tenant, 'store_id' => $store, 'user_id' => $waiter->id]);
        $this->actingAs($waiter)->post('/dining/tables/'.$table->id.'/open', ['guests' => 2, 'guest_label' => 'Grupo da Ana', 'request_key' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $tab = DB::table('dining_tabs')->firstOrFail();
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato da casa', 'category' => 'Pratos', 'price_cents' => 3000]);

        return compact('tenant', 'store', 'owner', 'waiter', 'table', 'tab', 'product');
    }

    private function draft(array $f): array
    {
        $key = (string) Str::uuid();
        $payload = ['idempotency_key' => $key, 'dining_tab_id' => $f['tab']->id, 'mode' => 'dine_in', 'payment_method' => 'cash', 'received' => 'no', 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]];
        $this->post('/pos/'.$f['store'].'/review', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $preview = $this->get('/pos/'.$f['store'].'/review/'.$key)->assertOk()->assertDontSee('Forma:')->assertDontSee('Caixa</a>', false);

        return ['key' => $key, 'hash' => hash('sha256', json_encode($preview->viewData('draft'), JSON_THROW_ON_ERROR)), 'payload' => $payload];
    }

    private function orderForTab(array $f): object
    {
        $draft = $this->draft($f);
        $url = '/pos/'.$f['store'].'/review/'.$draft['key'].'/confirm';
        $this->post($url, ['review_hash' => $draft['hash']])->assertRedirect('/dining/tabs/'.$f['tab']->id)->assertSessionHasNoErrors();
        $this->post($url, ['review_hash' => $draft['hash']])->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('orders')->where('idempotency_key', $draft['key'])->firstOrFail();
    }

    public function test_waiter_opens_table_launches_order_without_cash_and_serves_ready_items(): void
    {
        $f = $this->fixture();
        $this->get('/app')->assertRedirect('/dining');
        $this->get('/pos')->assertRedirect('/dining');
        $this->get('/dining')->assertOk()->assertSee('Ocupada')->assertSee('Sair da conta')->assertDontSee('Ir para o PDV');
        $this->get('/pos/'.$f['store'].'?tab='.$f['tab']->id)->assertOk()->assertSee('Conferir pedido')->assertDontSee('Como deseja cobrar')->assertDontSee('Você já recebeu')->assertDontSee('Caixa fechado');
        $order = $this->orderForTab($f);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'created_by' => $f['waiter']->id, 'payment_status' => 'pending', 'mode' => 'dine_in', 'status' => 'accepted', 'total_cents' => 3000]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cash_entries', 0);
        $this->get('/pos/orders/'.$order->id)->assertRedirect('/dining/tabs/'.$f['tab']->id);
        $this->get('/dining/tabs/'.$f['tab']->id)->assertOk()->assertSee('Prato da casa')->assertSee('Consumo da comanda')->assertDontSee('Receber a conta')->assertDontSee('Encerrar atendimento')->assertDontSee('Transferir para outra mesa')->assertDontSee('Unir com outra comanda');
        $this->post('/dining/tabs/'.$f['tab']->id.'/orders/'.$order->id.'/serve')->assertConflict();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'ready']);
        $this->post('/dining/tabs/'.$f['tab']->id.'/orders/'.$order->id.'/serve')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed', 'payment_status' => 'pending']);
    }

    public function test_waiter_cannot_forge_payments_remote_sales_or_account_management_and_cannot_read_financials(): void
    {
        $f = $this->fixture();
        $draft = $this->draft($f);
        foreach ([['received' => 'yes'], ['mode' => 'pickup', 'dining_tab_id' => null], ['payment_method' => 'online_pagbank'], ['change_for' => '50']] as $override) {
            $this->post('/pos/'.$f['store'].'/review', array_replace($draft['payload'], $override))->assertForbidden();
        }
        $order = $this->orderForTab($f);
        $this->get('/pos/'.$f['store'])->assertForbidden();
        foreach (['/cash', '/team', '/dashboard', '/bi', '/kitchen', '/operation', '/operation/orders/'.$order->id, '/dining/tabs/'.$f['tab']->id.'/print'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $pay = ['payment_method' => 'cash', 'review_hash' => app(DiningRoom::class)->summary($f['tab'])['review_hash'], 'request_key' => (string) Str::uuid(), 'confirmed' => 1];
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay)->assertForbidden();
        $this->post('/dining/tabs/'.$f['tab']->id.'/close', ['request_key' => (string) Str::uuid(), 'confirmed' => 1])->assertForbidden();
        $this->post('/dining/tabs/'.$f['tab']->id.'/transfer', ['from_table_id' => $f['table']->id, 'to_table_id' => $f['table']->id, 'reason' => 'Teste', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->get('/dining/tabs/'.$f['tab']->id.'/merge?target_tab_id='.$f['tab']->id)->assertForbidden();
        $this->post('/dining/tabs/'.$f['tab']->id.'/merge', ['target_tab_id' => $f['tab']->id, 'source_hash' => str_repeat('a', 64), 'target_hash' => str_repeat('a', 64), 'reason' => 'Teste', 'confirmed' => 1, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->post('/dining/tables', ['store_id' => $f['store'], 'name' => 'Mesa nova', 'seats' => 2, 'enabled' => 1])->assertForbidden();
        $this->post('/pos/'.$f['store'].'/postcode', ['postcode' => '80000000'])->assertForbidden();
        $this->post('/pos/'.$f['store'].'/delivery', [])->assertForbidden();
        $this->post('/pos/orders/'.$order->id.'/dispatch', [])->assertForbidden();
        $this->post('/operation/orders/'.$order->id.'/paid')->assertForbidden();
        $this->post('/operation/orders/'.$order->id.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Nao permitido'])->assertForbidden();
        $this->actingAs($f['owner']);
        app(CashRegister::class)->open(DB::table('stores')->find($f['store']), $f['owner']->id, 0, (string) Str::uuid());
        $this->post('/dining/tabs/'.$f['tab']->id.'/pay', $pay + ['amount' => '10'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($f['waiter'])->get('/dining/tabs/'.$f['tab']->id)->assertOk()->assertDontSee('10,00')->assertDontSee('Saldo a receber')->assertDontSee('Histórico de recebimentos');
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_waiter_can_print_only_production_of_table_orders_in_assigned_store(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $print = ['kind' => 'production', 'paper' => '80', 'request_key' => (string) Str::uuid()];
        $response = $this->post('/orders/'.$order->id.'/print-jobs', $print)->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Prato da casa')->assertDontSee('R$')->assertDontSee('Grupo da Ana');
        $this->post('/orders/'.$order->id.'/print-jobs', array_replace($print, ['kind' => 'conference']))->assertForbidden();
        $remote = $this->order($f['tenant'], $f['store']);
        $this->post('/orders/'.$remote.'/print-jobs', $print)->assertForbidden();
        $this->assertDatabaseCount('order_prints', 1);
    }

    public function test_revocation_and_downgrade_are_checked_again_before_confirming_saved_draft(): void
    {
        $f = $this->fixture();
        $draft = $this->draft($f);
        $url = '/pos/'.$f['store'].'/review/'.$draft['key'].'/confirm';
        DB::table('user_store_access')->where('user_id', $f['waiter']->id)->delete();
        $this->post($url, ['review_hash' => $draft['hash']])->assertNotFound();
        $this->get('/dining/tabs/'.$f['tab']->id)->assertNotFound();
        DB::table('user_store_access')->insert(['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'user_id' => $f['waiter']->id]);
        DB::table('users')->where('id', $f['waiter']->id)->update(['staff_profile' => 'kitchen']);
        $this->post($url, ['review_hash' => $draft['hash']])->assertForbidden();
        DB::table('users')->where('id', $f['waiter']->id)->update(['staff_profile' => 'waiter', 'access_enabled' => false]);
        $this->post($url, ['review_hash' => $draft['hash']])->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_operator_draft_cannot_receive_money_after_downgrade_to_waiter(): void
    {
        $f = $this->fixture();
        DB::table('users')->where('id', $f['waiter']->id)->update(['staff_profile' => 'operator']);
        $key = (string) Str::uuid();
        $data = ['idempotency_key' => $key, 'received' => 'yes', 'payment_method' => 'cash', 'cart_lines' => [['product_id' => $f['product'], 'quantity' => 1]]];
        $this->post('/pos/'.$f['store'].'/review', $data)->assertRedirect()->assertSessionHasNoErrors();
        $draft = $this->get('/pos/'.$f['store'].'/review/'.$key)->assertOk()->viewData('draft');
        DB::table('users')->where('id', $f['waiter']->id)->update(['staff_profile' => 'waiter']);
        $this->get('/pos/'.$f['store'].'/review/'.$key)->assertForbidden();
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/edit')->assertForbidden();
        $this->post('/pos/'.$f['store'].'/review/'.$key.'/confirm', ['review_hash' => hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR))])->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_waiter_cannot_access_another_store_or_suspended_tenant(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $foreignTenant = $this->tenant('foreign');
        $foreignStore = $this->store($foreignTenant, 'foreign');
        $foreignUser = User::factory()->create(['tenant_id' => $foreignTenant, 'role' => 'staff', 'staff_profile' => 'waiter']);
        DB::table('user_store_access')->insert(['tenant_id' => $foreignTenant, 'store_id' => $foreignStore, 'user_id' => $foreignUser->id]);
        $this->actingAs($foreignUser)->get('/dining?store_id='.$f['store'])->assertNotFound();
        $this->get('/dining/tabs/'.$f['tab']->id)->assertNotFound();
        $this->get('/pos/'.$f['store'].'?tab='.$f['tab']->id)->assertNotFound();
        $this->get('/pos/orders/'.$order->id)->assertNotFound();
        $this->post('/dining/tabs/'.$f['tab']->id.'/orders/'.$order->id.'/serve')->assertNotFound();
        $this->actingAs($f['waiter']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get('/dining')->assertForbidden();
        $this->get('/pos/'.$f['store'].'?tab='.$f['tab']->id)->assertForbidden();
    }

    public function test_waiter_live_feed_updates_readiness_and_pagination_without_financial_data(): void
    {
        $f = $this->fixture();
        $order = $this->orderForTab($f);
        $url = '/dining/tabs/'.$f['tab']->id.'/live';
        $first = $this->getJson($url)->assertOk()->assertJsonPath('audience', 'waiter');
        $this->assertSame($first->json('signature'), $this->getJson($url)->json('signature'));
        DB::table('orders')->where('id', $order->id)->update(['status' => 'ready', 'phone' => '11987654321']);
        $ready = $this->getJson($url)->assertOk();
        $this->assertNotSame($first->json('signature'), $ready->json('signature'));
        $html = $ready->json('html');
        $this->assertStringContainsString('Marcar como servido', $html);
        foreach (['11987654321', 'Saldo a receber', 'Histórico de recebimentos', 'review_hash'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
        $copy = (array) DB::table('orders')->find($order->id);
        unset($copy['id']);
        for ($i = 0; $i < 21; $i++) {
            DB::table('orders')->insert(array_replace($copy, ['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()]));
        }
        $page = $this->getJson($url.'?page=2')->assertOk()->json('html');
        $this->assertStringContainsString('/dining/tabs/'.$f['tab']->id.'?page=', $page);
        $this->assertStringNotContainsString('/live?page=', $page);
        DB::table('user_store_access')->where('user_id', $f['waiter']->id)->delete();
        $this->getJson($url)->assertNotFound();
        $this->getJson('/dining/live?store_id='.$f['store'])->assertNotFound();
    }

    public function test_live_feed_revalidates_profile_and_hidden_map_controls(): void
    {
        $f = $this->fixture();
        $map = $this->getJson('/dining/live?store_id='.$f['store'])->assertOk();
        $this->assertStringNotContainsString('Cadastrar mesa', $map->json('html'));
        $url = '/dining/tabs/'.$f['tab']->id.'/live';
        DB::table('users')->where('id', $f['waiter']->id)->update(['staff_profile' => 'operator']);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/dining/live?store_id='.$f['store'])->assertOk()->assertJsonPath('audience', 'operator');
        DB::table('users')->where('id', $f['waiter']->id)->update(['staff_profile' => 'kitchen']);
        $this->getJson('/dining/live?store_id='.$f['store'])->assertForbidden();
    }

    public function test_owner_can_create_and_change_waiter_profile_with_store_scoping(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->get('/team')->assertOk()->assertSee('Garçom');
        $data = ['name' => 'Garcom novo', 'email' => 'waiter-new@example.test', 'password' => 'WaiterPassword2026!', 'password_confirmation' => 'WaiterPassword2026!', 'staff_profile' => 'waiter', 'stores' => [$f['store']]];
        $this->post('/team', $data)->assertRedirect()->assertSessionHasNoErrors();
        $member = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('waiter', $member->staff_profile);
        $this->assertDatabaseHas('user_store_access', ['user_id' => $member->id, 'store_id' => $f['store']]);
        $this->post('/team/'.$member->id, ['staff_profile' => 'operator', 'access_enabled' => 1, 'stores' => [$f['store']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('operator', $member->fresh()->staff_profile);
        $this->post('/team/'.$member->id, ['staff_profile' => 'waiter', 'access_enabled' => 1, 'stores' => [$f['store']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('waiter', $member->fresh()->staff_profile);
    }
}
