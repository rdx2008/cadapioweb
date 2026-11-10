<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OrderPrintingTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $order = $this->order($tenant, $store);
        DB::table('order_items')->insert(['order_id' => $order, 'name' => 'Pizza com duas metades', 'quantity' => 2, 'unit_cents' => 1500, 'options' => json_encode([['group' => 'Sabores', 'name' => 'Calabresa + queijo', 'price_cents' => 500], ['group' => 'Borda', 'name' => '<script>test</script>', 'price_cents' => 0]])]);
        $this->actingAs($owner);

        return compact('tenant', 'store', 'owner', 'order');
    }

    private function payload(string $kind = 'production', string $paper = '80'): array
    {
        return ['request_key' => (string) Str::uuid(), 'kind' => $kind, 'paper' => $paper];
    }

    private function staff(array $f, string $profile, bool $linked = true): User
    {
        $user = User::factory()->create(['role' => 'staff', 'tenant_id' => $f['tenant'], 'staff_profile' => $profile, 'access_enabled' => true]);
        if ($linked) {
            DB::table('user_store_access')->insert(['user_id' => $user->id, 'tenant_id' => $f['tenant'], 'store_id' => $f['store']]);
        }

        return $user;
    }

    public function test_production_copy_has_items_and_choices_without_prices_customer_or_delivery_secrets(): void
    {
        $f = $this->fixture();
        $before = DB::table('orders')->find($f['order']);
        $response = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Calabresa + queijo')->assertSee('&lt;script&gt;test&lt;/script&gt;', false)->assertDontSee('<script>test</script>', false)->assertDontSee('R$')->assertDontSee('Rua do Cliente')->assertDontSee('11999999999')->assertSee('DOCUMENTO NÃO FISCAL');
        $snapshot = json_decode(Crypt::decryptString(DB::table('order_prints')->value('payload')), true);
        $this->assertArrayNotHasKey('customer', $snapshot);
        $this->assertArrayNotHasKey('payment', $snapshot);
        $this->assertArrayNotHasKey('unit_cents', $snapshot['items'][0]);
        $this->assertEquals($before, DB::table('orders')->find($f['order']));
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_conference_copy_freezes_prices_and_details_and_does_not_double_charge_options(): void
    {
        $f = $this->fixture();
        DB::table('orders')->where('id', $f['order'])->update(['change_for_cents' => 5000]);
        $response = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload('conference', '58'))->assertRedirect();
        $print = DB::table('order_prints')->firstOrFail();
        $this->assertStringNotContainsString('11999999999', $print->payload);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Rua do Cliente')->assertSee('11999999999')->assertSee('35,00')->assertSee('15,00')->assertSee('30,00')->assertSee('Troco previsto')->assertSee('PAGAMENTO NÃO CONFIRMADO')->assertHeader('Cache-Control', 'no-store, private');
        DB::table('order_items')->where('order_id', $f['order'])->update(['unit_cents' => 9999, 'name' => 'New menu name']);
        DB::table('orders')->where('id', $f['order'])->update(['total_cents' => 9999]);
        $this->get($response->headers->get('Location'))->assertSee('35,00')->assertSee('Pizza com duas metades')->assertDontSee('New menu name');
    }

    public function test_duplicate_prepare_is_idempotent_and_a_new_request_is_identified_as_reprint(): void
    {
        $f = $this->fixture();
        $payload = $this->payload();
        $first = $this->post('/orders/'.$f['order'].'/print-jobs', $payload)->assertRedirect();
        $again = $this->post('/orders/'.$f['order'].'/print-jobs', $payload)->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $again->headers->get('Location'));
        $this->assertDatabaseCount('order_prints', 1);
        $second = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertRedirect();
        $this->get($second->headers->get('Location'))->assertOk()->assertSee('2ª VIA · REIMPRESSÃO');
        $this->get($second->headers->get('Location'))->assertOk();
        $this->assertSame(2, DB::table('audit_events')->where('action', 'order.print.prepared')->count());
        $this->post('/orders/'.$f['order'].'/print-jobs', array_replace($payload, ['paper' => 'a4']))->assertStatus(409);
    }

    public function test_kitchen_print_access_is_store_scoped_and_cannot_request_or_open_a_conference_copy(): void
    {
        $f = $this->fixture();
        $conference = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload('conference'))->headers->get('Location');
        $kitchen = $this->staff($f, 'kitchen');
        $this->actingAs($kitchen)->post('/orders/'.$f['order'].'/print-jobs', $this->payload('conference'))->assertForbidden();
        $this->get($conference)->assertForbidden();
        $production = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertRedirect();
        $this->get($production->headers->get('Location'))->assertOk()->assertDontSee('11999999999');
        $this->get('/operation/orders/'.$f['order'])->assertOk()->assertSee('Preparar via de impressão')->assertDontSee($conference);
        $this->actingAs($this->staff($f, 'operator', false))->get($production->headers->get('Location'))->assertNotFound();
        $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertNotFound();
        $operator = $this->staff($f, 'operator');
        $this->actingAs($operator)->get($conference)->assertOk();
        DB::table('user_store_access')->where('user_id', $operator->id)->delete();
        $this->get($conference)->assertNotFound();
    }

    public function test_disabled_foreign_suspended_and_courier_users_cannot_access_prints(): void
    {
        $f = $this->fixture();
        $url = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->headers->get('Location');
        $this->actingAs($this->owner($this->tenant('other')))->get($url)->assertForbidden();
        $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'courier']))->get($url)->assertForbidden();
        $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->get($url)->assertForbidden();
        $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertForbidden();
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'active']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->get($url)->assertForbidden();
    }

    public function test_pending_online_scheduled_and_cancelled_orders_have_explicit_production_notices(): void
    {
        $f = $this->fixture();
        foreach ([['status' => 'pending'], ['payment_method' => 'online_infinitepay', 'payment_status' => 'pending'], ['payment_method' => 'cash', 'status' => 'accepted', 'prepare_at' => now()->addHour(), 'scheduled_at' => now()->addHours(2), 'scheduled_timezone' => 'America/Sao_Paulo']] as $changes) {
            DB::table('orders')->where('id', $f['order'])->update($changes);
            $url = $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertRedirect()->headers->get('Location');
            $expected = isset($changes['prepare_at']) ? 'AGENDADO' : (isset($changes['payment_method']) ? 'PAGAMENTO ONLINE NÃO CONFIRMADO' : 'AGUARDANDO ACEITE');
            $this->get($url)->assertOk()->assertSee($expected);
        }
        DB::table('orders')->where('id', $f['order'])->update(['status' => 'cancelled']);
        $this->get($url)->assertOk()->assertSee('PEDIDO CANCELADO')->assertSee('SITUAÇÃO ATUAL');
    }

    public function test_audit_failure_rolls_back_prepared_copy_without_changing_order(): void
    {
        $f = $this->fixture();
        DB::statement("CREATE TRIGGER fail_print_audit BEFORE INSERT ON audit_events WHEN NEW.action = 'order.print.prepared' BEGIN SELECT RAISE(FAIL, 'audit unavailable'); END");
        $this->post('/orders/'.$f['order'].'/print-jobs', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('order_prints', 0);
        $this->assertDatabaseHas('orders', ['id' => $f['order'], 'status' => 'ready', 'payment_status' => 'pending']);
    }
}
