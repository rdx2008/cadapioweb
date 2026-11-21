<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class OrderFeedbackTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(16, 0));
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $id = $this->order($tenant, $store);
        DB::table('orders')->where('id', $id)->update(['status' => 'completed', 'payment_status' => 'paid', 'source' => 'menu']);
        $order = DB::table('orders')->find($id);

        return compact('tenant', 'store', 'owner', 'order');
    }

    public function test_customer_submits_once_with_immutable_rating_and_no_public_listing(): void
    {
        $f = $this->fixture();
        $id = $f['order']->public_id;
        $this->get('/pedido/'.$id)->assertOk()->assertViewHas('canEvaluate', true);
        $data = ['rating' => 2, 'comment' => '  Chegou frio <script>alert(1)</script>  ', 'tenant_id' => 99999];
        $this->post('/pedido/'.$id.'/avaliacao', $data)->assertRedirect('/pedido/'.$id)->assertSessionHasNoErrors();
        $this->post('/pedido/'.$id.'/avaliacao', $data)->assertRedirect();
        $this->assertDatabaseCount('order_feedback', 1);
        $this->assertDatabaseHas('order_feedback', ['tenant_id' => $f['tenant'], 'store_id' => $f['store'], 'order_id' => $f['order']->id, 'rating' => 2, 'comment' => trim($data['comment'])]);
        $this->post('/pedido/'.$id.'/avaliacao', ['rating' => 5])->assertConflict();
        $this->get('/pedido/'.$id)->assertOk()->assertViewHas('canEvaluate', false)->assertSee(trim($data['comment']))->assertDontSee('<script>alert(1)</script>', false);
        $this->getJson('/pedido/'.$id.'/status')->assertOk()->assertJson(['can_evaluate' => false, 'feedback_submitted' => true])->assertJsonMissingPath('comment');
        $this->get('/feedback')->assertRedirect('/login');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'feedback.received')->count());
        $this->assertDatabaseCount('financial_settlements', 0);
    }

    public function test_rejects_unfinished_cancelled_unpaid_external_and_unknown_orders(): void
    {
        $f = $this->fixture();
        $id = $f['order']->public_id;
        foreach ([['status' => 'ready'], ['status' => 'cancelled'], ['payment_status' => 'pending'], ['payment_status' => 'review'], ['source' => 'ifood']] as $change) {
            DB::table('orders')->where('id', $f['order']->id)->update(array_replace(['status' => 'completed', 'payment_status' => 'paid', 'source' => 'menu'], $change));
            $this->post('/pedido/'.$id.'/avaliacao', ['rating' => 5])->assertConflict();
            $this->getJson('/pedido/'.$id.'/status')->assertJsonPath('can_evaluate', false);
        }
        $this->post('/pedido/'.Str::uuid().'/avaliacao', ['rating' => 5])->assertNotFound();
        $this->post('/pedido/'.$f['order']->id.'/avaliacao', ['rating' => 5])->assertNotFound();
        $this->assertDatabaseCount('order_feedback', 0);
    }

    public function test_validation_and_suspended_company_are_checked_on_server(): void
    {
        $f = $this->fixture();
        $url = '/pedido/'.$f['order']->public_id.'/avaliacao';
        foreach ([['rating' => 0], ['rating' => 6], ['rating' => 1.5], ['rating' => 4, 'comment' => str_repeat('x', 1001)]] as $data) {
            $this->post($url, $data)->assertSessionHasErrors();
        }
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->post($url, ['rating' => 5])->assertConflict();
        $this->assertDatabaseCount('order_feedback', 0);
    }

    public function test_owner_treatment_is_confirmed_idempotent_private_and_does_not_modify_rating(): void
    {
        $f = $this->fixture();
        $this->post('/pedido/'.$f['order']->public_id.'/avaliacao', ['rating' => 1, 'comment' => 'Faltou bebida'])->assertRedirect();
        $id = DB::table('order_feedback')->value('id');
        $this->actingAs($f['owner']);
        $this->get('/customers')->assertOk()->assertSee('Avaliações dos clientes');
        $this->get('/feedback')->assertOk()->assertSee('Faltou bebida');
        $this->post('/feedback/'.$id.'/treat', ['note' => 'Conferido'])->assertSessionHasErrors('confirmed');
        $data = ['note' => 'Enviamos a bebida faltante', 'confirmed' => 1];
        $this->post('/feedback/'.$id.'/treat', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/feedback/'.$id.'/treat', $data)->assertRedirect();
        $this->post('/feedback/'.$id.'/treat', ['note' => 'Outra resolução', 'confirmed' => 1])->assertConflict();
        $this->assertDatabaseHas('order_feedback', ['id' => $id, 'rating' => 1, 'treated_by' => $f['owner']->id, 'treatment_note' => $data['note']]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'feedback.treated')->count());
        $this->get('/pedido/'.$f['order']->public_id)->assertOk()->assertDontSee($data['note']);
        $this->getJson('/pedido/'.$f['order']->public_id.'/status')->assertJsonMissingPath('treatment_note');
        $this->get('/feedback?status=pending')->assertOk()->assertDontSee('Faltou bebida');
        $this->get('/feedback?status=treated')->assertOk()->assertSee($data['note'])->assertSee($f['owner']->name);
    }

    public function test_company_and_store_boundaries_and_staff_restrictions_are_enforced(): void
    {
        $f = $this->fixture();
        $this->post('/pedido/'.$f['order']->public_id.'/avaliacao', ['rating' => 3, 'comment' => 'Somente loja original'])->assertRedirect();
        $id = DB::table('order_feedback')->value('id');
        $otherStore = $this->store($f['tenant'], 'second');
        $this->actingAs($f['owner'])->get('/feedback?store_id='.$otherStore)->assertOk()->assertDontSee('Somente loja original');
        $otherTenant = $this->tenant('other');
        $this->actingAs($this->owner($otherTenant));
        $this->get('/feedback')->assertOk()->assertDontSee('Somente loja original');
        $this->get('/feedback?store_id='.$f['store'])->assertNotFound();
        $this->post('/feedback/'.$id.'/treat', ['note' => 'Não autorizado', 'confirmed' => 1])->assertNotFound();
        $staff = User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']);
        $this->actingAs($staff)->get('/feedback')->assertForbidden();
        $this->post('/feedback/'.$id.'/treat', ['note' => 'Não autorizado', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->get('/feedback')->assertForbidden();
    }

    public function test_metrics_and_date_filters_use_store_local_day_and_do_not_invent_an_average(): void
    {
        $f = $this->fixture();
        $this->post('/pedido/'.$f['order']->public_id.'/avaliacao', ['rating' => 2, 'comment' => 'Dia local anterior'])->assertRedirect();
        DB::table('order_feedback')->update(['created_at' => '2026-11-17 02:59:59']);
        $this->actingAs($f['owner']);
        $this->get('/feedback?from=2026-11-17&to=2026-11-17')->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['count'] === 0 && $metrics['average'] === null);
        $this->get('/feedback?from=2026-11-16&to=2026-11-16')->assertOk()->assertSee('Dia local anterior')->assertViewHas('metrics', fn ($metrics) => $metrics['count'] === 1 && (float) $metrics['average'] === 2.0 && $metrics['pending'] === 1);
        $this->get('/feedback?from=2026-11-16&to=2026-11-16&rating=5')->assertOk()->assertDontSee('Dia local anterior')->assertViewHas('metrics', fn ($metrics) => $metrics['count'] === 1);
        $this->get('/feedback?from=2026-11-20&to=2026-11-01')->assertSessionHasErrors('to');
    }

    public function test_feedback_becomes_available_in_live_tracking_when_order_completes(): void
    {
        $f = $this->fixture();
        DB::table('orders')->where('id', $f['order']->id)->update(['status' => 'ready', 'source' => 'pos']);
        $url = '/pedido/'.$f['order']->public_id;
        $this->get($url)->assertOk()->assertViewHas('canEvaluate', false);
        $this->getJson($url.'/status')->assertJsonPath('can_evaluate', false);
        DB::table('orders')->where('id', $f['order']->id)->update(['status' => 'completed']);
        $this->getJson($url.'/status')->assertJsonPath('can_evaluate', true);
        $this->post($url.'/avaliacao', ['rating' => 5])->assertRedirect();
        $this->assertDatabaseHas('order_feedback', ['order_id' => $f['order']->id, 'rating' => 5, 'comment' => null]);

    }
}
