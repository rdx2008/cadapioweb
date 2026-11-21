<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_referral_records_first_purchase_and_releases_reward_once_after_payment(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $inviter = $this->order($tenant, $store);
        DB::table('orders')->where('id', $inviter)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $settings = ['enabled' => 1, 'reward' => 'Sobremesa', 'minimum' => '25,00'];
        $this->post('/referrals/stores/'.$store, $settings)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/referrals/orders/'.$inviter)->assertRedirect();
        $this->post('/referrals/orders/'.$inviter)->assertRedirect();
        $this->assertDatabaseCount('referral_invites', 1);
        $code = DB::table('referral_invites')->value('code');
        $this->get('/cardapio/loja?indicacao='.$code)->assertOk()->assertSee($code);
        $this->get('/referrals?store_id='.$store)->assertOk()->assertSee($code);

        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Lanche', 'category' => 'Lanches', 'price_cents' => 3000]);
        $cart = ['customer_name' => 'Ana', 'mode' => 'pickup', 'items' => [$product => 1], 'payment_method' => 'cash', 'referral_code' => $code];
        foreach (['+55 (11) 99999-9999', '11988888888', '(11) 98888-8888'] as $phone) {
            $key = (string) Str::uuid();
            $this->post('/cardapio/loja/review', $cart + ['phone' => $phone, 'idempotency_key' => $key])->assertRedirect()->assertSessionHasNoErrors();
            $this->post('/cardapio/loja', ['draft_id' => $key])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('referral_conversions', 1);
        $conversion = DB::table('referral_conversions')->first();
        $this->assertDatabaseHas('orders', ['id' => $conversion->order_id, 'phone' => '11988888888', 'total_cents' => 3000]);
        $deliver = '/referrals/'.$conversion->id.'/deliver';
        $this->post($deliver, ['confirmed' => 1])->assertConflict();
        $this->post('/referrals/stores/'.$store, array_replace($settings, ['enabled' => 0, 'reward' => 'Outra recompensa']))->assertRedirect();
        DB::table('orders')->where('id', $conversion->order_id)->update(['status' => 'completed', 'payment_status' => 'paid']);
        $this->get('/referrals?store_id='.$store)->assertOk()->assertSee('Sobremesa')->assertSee('Registrar entrega');
        $this->post($deliver, ['confirmed' => 1])->assertRedirect();
        $this->post($deliver, ['confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('referral_conversions', 1);
        $this->assertEquals(1, DB::table('audit_events')->where('action', 'referrals.deliver')->count());
        $this->actingAs($this->owner($this->tenant('foreign')));
        $this->get('/referrals?store_id='.$store)->assertNotFound();
        $this->post($deliver, ['confirmed' => 1])->assertNotFound();
    }
}
