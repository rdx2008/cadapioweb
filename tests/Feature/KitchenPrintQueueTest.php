<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsPlatform;
use Tests\TestCase;

class KitchenPrintQueueTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    public function test_station_jobs_are_claimed_once_and_reports_are_scoped_to_the_store(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->store($tenant, 'other');
        $this->actingAs($this->owner($tenant));
        $this->post(route('kitchen.printer.configure', $store), ['action' => 'activate'])->assertRedirect();
        $token = session('printer_token');
        $this->get(route('kitchen.printer', $store))->assertOk()->assertSee('Baixar agente para Windows');
        $this->post(route('kitchen.printer.configure', $other), ['action' => 'activate'])->assertRedirect();
        $otherToken = session('printer_token');
        $order = $this->order($tenant, $store);
        DB::table('orders')->where('id', $order)->update(['status' => 'accepted', 'customer_name' => 'Nome reservado']);
        DB::table('order_items')->insert([
            ['order_id' => $order, 'name' => 'Pizza', 'quantity' => 1, 'unit_cents' => 2500, 'kitchen_station' => 'Forno'],
            ['order_id' => $order, 'name' => 'Suco', 'quantity' => 2, 'unit_cents' => 500, 'kitchen_station' => 'Bebidas'],
        ]);
        $this->post('/orders/'.$order.'/status', ['status' => 'preparing'])->assertRedirect();
        $this->assertDatabaseCount('kitchen_print_jobs', 2);
        $claim = '/api/v1/kitchen-printer/claim';
        $this->postJson($claim)->assertUnauthorized();
        $this->postJson($claim, [], ['Authorization' => 'Bearer '.$otherToken])->assertOk()->assertExactJson(['job' => null]);
        $headers = ['Authorization' => 'Bearer '.$token];
        $job = $this->postJson($claim, [], $headers)->assertOk()->assertJsonPath('job.station', 'Forno')->json('job');
        $this->assertStringContainsString('1 x Pizza', $job['ticket']);
        $this->assertStringNotContainsString('Suco', $job['ticket']);
        $this->assertStringNotContainsString('Nome reservado', $job['ticket']);
        $this->postJson($claim, [], $headers)->assertOk()->assertJsonPath('job.station', 'Bebidas');
        $this->postJson($claim, [], $headers)->assertOk()->assertExactJson(['job' => null]);
        $report = '/api/v1/kitchen-printer/'.$job['id'].'/report';
        $this->postJson($report, ['status' => 'submitted'], ['Authorization' => 'Bearer '.$otherToken])->assertNotFound();
        $this->postJson($report, ['status' => 'submitted'], $headers)->assertOk();
        $this->postJson($report, ['status' => 'submitted'], $headers)->assertOk();
        $this->postJson($report, ['status' => 'failed'], $headers)->assertConflict();
        $this->post(route('kitchen.printer.configure', $store), ['action' => 'disable'])->assertRedirect();
        $this->postJson($claim, [], $headers)->assertUnauthorized();
    }

    public function test_cancellation_removes_unclaimed_ticket_from_the_queue(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));
        $this->post(route('kitchen.printer.configure', $store), ['action' => 'activate'])->assertRedirect();
        $token = session('printer_token');
        $order = $this->order($tenant, $store);
        DB::table('orders')->where('id', $order)->update(['status' => 'accepted']);
        DB::table('order_items')->insert(['order_id' => $order, 'name' => 'Refeição', 'quantity' => 1, 'unit_cents' => 1500]);
        $this->post('/orders/'.$order.'/status', ['status' => 'preparing'])->assertRedirect();
        $this->post('/orders/'.$order.'/status', ['status' => 'cancelled', 'cancellation_reason' => 'Cancelado pelo cliente'])->assertRedirect();
        $this->assertDatabaseHas('kitchen_print_jobs', ['order_id' => $order, 'status' => 'cancelled']);
        $this->postJson('/api/v1/kitchen-printer/claim', [], ['Authorization' => 'Bearer '.$token])->assertExactJson(['job' => null]);
    }
}
