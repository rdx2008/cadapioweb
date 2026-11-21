<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CustomerHistoryTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_history_groups_paid_completed_orders_and_enforces_store_scope(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->store($tenant, 'other');
        $foreignTenant = $this->tenant('foreign');
        $foreign = $this->store($foreignTenant, 'foreign');
        $this->actingAs($this->owner($tenant));

        foreach ([
            [$tenant, $store, '+55 (11) 99999-9999', 'completed', 'paid', 'menu'],
            [$tenant, $store, '11999999999', 'completed', 'paid', 'pos'],
            [$tenant, $store, '11999999999', 'cancelled', 'paid', 'menu'],
            [$tenant, $store, '11999999999', 'completed', 'pending', 'menu'],
            [$tenant, $store, '11999999999', 'completed', 'paid', 'ifood'],
            [$tenant, $store, 'sem telefone', 'completed', 'paid', 'pos'],
            [$tenant, $other, '11999999999', 'completed', 'paid', 'menu'],
            [$foreignTenant, $foreign, '11999999999', 'completed', 'paid', 'menu'],
        ] as [$owner, $unit, $phone, $status, $payment, $source]) {
            $id = $this->order($owner, $unit);
            DB::table('orders')->where('id', $id)->update([
                'phone' => $phone, 'status' => $status, 'payment_status' => $payment, 'source' => $source,
            ]);
        }
        $inactive = $this->order($tenant, $store);
        DB::table('orders')->where('id', $inactive)->update([
            'phone' => '11988888888', 'status' => 'completed', 'payment_status' => 'paid', 'created_at' => now()->subDays(70),
        ]);

        $response = $this->get('/customers?store_id='.$store.'&segment=returning')->assertOk();
        $this->assertSame(['identified' => 2, 'returning' => 1, 'inactive' => 1, 'unidentified' => 1], $response->viewData('metrics'));
        $this->assertCount(1, $response->viewData('customers'));
        $this->assertEquals(7000, $response->viewData('customers')->first()->total_cents);
        $this->get('/customers?store_id='.$store.'&segment=inactive')->assertOk()->assertSee('11988888888')->assertDontSee('11999999999');

        $path = route('customers.show', ['storeId' => $store, 'customer' => Crypt::encryptString($tenant.':11999999999')]);
        $detail = $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(2, $detail->viewData('history'));
        $this->get('/customers/'.$store.'?customer=invalid')->assertNotFound();
        $this->get('/customers?store_id='.$foreign)->assertNotFound();
        $this->actingAs($this->owner($foreignTenant))->get($path)->assertNotFound();
    }
}
