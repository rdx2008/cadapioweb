<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DeliveryRecordsTest extends TestCase
{
    use BuildsPlatform,RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $driver = $this->driver($store);
        $ride = $this->ride($tenant, $store);
        app(DispatchService::class)->dispatch($ride);
        $offer = DB::table('offers')->where('ride_id', $ride)->firstOrFail();
        app(DispatchService::class)->accept($offer->id, $driver);
        $courier = User::findOrFail(DB::table('drivers')->where('id', $driver)->value('user_id'));
        $owner = $this->owner($tenant);

        return compact('tenant', 'store', 'driver', 'ride', 'courier', 'owner');
    }

    private function incident(): array
    {
        return ['request_key' => (string) Str::uuid(), 'kind' => 'customer_absent', 'description' => 'Cliente não está atendendo.', 'photo' => UploadedFile::fake()->image('tentativa.jpg', 20, 20)];
    }

    public function test_courier_incident_is_idempotent_keeps_assignment_payment_and_claim_and_can_be_resolved_by_owner(): void
    {
        $f = $this->fixture();
        $payload = $this->incident();
        $claim = DB::table('driver_claims')->where('ride_id', $f['ride'])->first();
        $this->actingAs($f['courier'])->get('/deliveries/'.$f['ride'])->assertOk();
        $this->post('/deliveries/'.$f['ride'].'/incidents', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/deliveries/'.$f['ride'].'/incidents', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('delivery_incidents', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'delivery.incident.created')->count());
        $incident = DB::table('delivery_incidents')->firstOrFail();
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
        $ride = DB::table('rides')->find($f['ride']);
        $this->assertSame('assigned', $ride->status);
        $this->assertEquals(800, $ride->fare_cents);
        $this->assertEquals($claim, DB::table('driver_claims')->where('ride_id', $ride->id)->first());
        $this->assertDatabaseHas('orders', ['id' => $ride->order_id, 'payment_status' => 'pending']);
        $this->actingAs($f['owner'])->get('/deliveries?incidents=open')->assertOk()->assertSee('1');
        $this->get('/delivery-incidents/'.$incident->id.'/photo')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $url = '/delivery-incidents/'.$incident->id.'/resolve';
        $this->post($url, ['resolution' => 'Endereço confirmado com o cliente.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['resolution' => 'Tentativa repetida diferente.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('delivery_incidents', ['id' => $incident->id, 'status' => 'resolved', 'resolution' => 'Endereço confirmado com o cliente.']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'delivery.incident.resolved')->count());
        $this->actingAs($f['courier'])->get('/deliveries/'.$f['ride'])->assertOk()->assertSee('Endereço confirmado com o cliente.');
        $this->post($url, ['resolution' => 'Tentando alterar.'])->assertForbidden();
    }

    public function test_completion_saves_private_sanitized_proof_and_retry_cannot_replace_it(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['courier'])->post('/courier/rides/'.$f['ride'], ['status' => 'collected'])->assertRedirect()->assertSessionHasNoErrors();
        $payload = ['status' => 'completed', 'received_by' => 'Pessoa recebedora privada', 'photo' => UploadedFile::fake()->image('entrega.png', 30, 20)];
        $this->post('/courier/rides/'.$f['ride'], $payload)->assertRedirect()->assertSessionHasNoErrors();
        $ride = DB::table('rides')->find($f['ride']);
        $this->assertNotNull($ride->proof_recorded_at);
        $this->assertSame('Pessoa recebedora privada', $ride->received_by);
        $this->assertStringEndsWith('.webp', $ride->proof_photo_path);
        Storage::disk('local')->assertExists($ride->proof_photo_path);
        $this->assertDatabaseHas('orders', ['id' => $ride->order_id, 'status' => 'completed', 'payment_status' => 'paid']);
        $this->assertDatabaseMissing('driver_claims', ['ride_id' => $ride->id]);
        $this->post('/courier/rides/'.$f['ride'], $payload)->assertStatus(409);
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
        $this->actingAs($f['owner'])->get('/deliveries/'.$f['ride'].'/proof')->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->get('/deliveries/'.$f['ride'].'/proof')->headers->get('Cache-Control'));
        $order = DB::table('orders')->find($ride->order_id);
        $this->get('/pedido/'.$order->public_id)->assertOk()->assertDontSee('Pessoa recebedora privada')->assertDontSee($ride->proof_photo_path);
        $this->get('/pedido/'.$order->public_id.'/status')->assertJsonMissingPath('proof_photo_path');
    }

    public function test_evidence_and_incidents_are_isolated_between_owners_drivers_staff_and_guests(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['courier'])->post('/deliveries/'.$f['ride'].'/incidents', $this->incident())->assertRedirect()->assertSessionHasNoErrors();
        $incident = DB::table('delivery_incidents')->firstOrFail();
        $foreignOwner = $this->owner($this->tenant('foreign'));
        $foreignDriver = $this->driver();
        $otherCourier = User::findOrFail(DB::table('drivers')->find($foreignDriver)->user_id);
        foreach ([$foreignOwner, $otherCourier] as $actor) {
            $this->actingAs($actor)->get('/deliveries/'.$f['ride'])->assertNotFound();
            $this->get('/delivery-incidents/'.$incident->id.'/photo')->assertNotFound();
            $this->get('/deliveries/'.$f['ride'].'/proof')->assertNotFound();
            $this->post('/deliveries/'.$f['ride'].'/incidents', $this->incident())->assertNotFound();
        }
        $this->actingAs($foreignOwner)->post('/delivery-incidents/'.$incident->id.'/resolve', ['resolution' => 'Tentativa indevida.'])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'staff', 'tenant_id' => $f['tenant']]))->get('/deliveries/'.$f['ride'])->assertForbidden();
        auth()->logout();
        $this->getJson('/delivery-incidents/'.$incident->id.'/photo')->assertUnauthorized();
        $this->getJson('/deliveries/'.$f['ride'].'/proof')->assertUnauthorized();
    }

    public function test_existing_assignment_can_report_and_complete_during_suspension_and_module_closing(): void
    {
        $f = $this->fixture();
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended', 'courier_module' => 'closing']);
        $this->actingAs($f['owner'])->get('/deliveries')->assertForbidden();
        $this->actingAs($f['courier'])->post('/deliveries/'.$f['ride'].'/incidents', $this->incident())->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/courier/rides/'.$f['ride'], ['status' => 'collected'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/courier/rides/'.$f['ride'], ['status' => 'completed', 'received_by' => 'Ana'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'completed', 'received_by' => 'Ana']);
        $this->assertDatabaseHas('tenants', ['id' => $f['tenant'], 'courier_module' => 'disabled']);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'active']);
        $this->actingAs($f['owner'])->get('/deliveries')->assertOk();
    }

    public function test_non_cash_payment_and_invalid_evidence_cannot_complete_or_leave_files(): void
    {
        $f = $this->fixture();
        $ride = DB::table('rides')->find($f['ride']);
        DB::table('orders')->where('id', $ride->order_id)->update(['payment_method' => 'card']);
        $this->actingAs($f['courier'])->post('/courier/rides/'.$f['ride'], ['status' => 'collected'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/courier/rides/'.$f['ride'], ['status' => 'completed', 'photo' => UploadedFile::fake()->image('entrega.jpg')])->assertStatus(409);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'collected', 'proof_photo_path' => null]);
        $this->postJson('/deliveries/'.$f['ride'].'/incidents', array_replace($this->incident(), ['photo' => UploadedFile::fake()->create('bad.pdf', 5, 'application/pdf')]))->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertDatabaseCount('delivery_incidents', 0);
        $this->postJson('/courier/rides/'.$f['ride'], ['status' => 'completed', 'photo' => UploadedFile::fake()->image('huge.jpg', 5000, 10)])->assertUnprocessable();
    }

    public function test_store_filter_and_owner_unassigned_incident_preserve_other_store_privacy(): void
    {
        $f = $this->fixture();
        $otherStore = $this->store($f['tenant'], 'second');
        $waiting = $this->ride($f['tenant'], $otherStore);
        $payload = $this->incident();
        $payload['kind'] = 'no_courier';
        $this->actingAs($f['owner'])->post('/deliveries/'.$waiting.'/incidents', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/deliveries?store_id='.$f['store'])->assertOk()->assertViewHas('rides', fn ($rides) => $rides->count() === 1 && $rides->first()->id === $f['ride']);
        $this->get('/deliveries?incidents=open')->assertOk()->assertViewHas('rides', fn ($rides) => $rides->count() === 1 && $rides->first()->id === $waiting);
        $foreignStore = $this->store($this->tenant('other-store'), 'foreign');
        $this->get('/deliveries?store_id='.$foreignStore)->assertNotFound();
        $this->actingAs($f['courier'])->get('/deliveries/'.$waiting)->assertNotFound();
    }

    public function test_failed_transaction_removes_the_proof_file_and_preserves_ride_and_payment(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['courier'])->post('/courier/rides/'.$f['ride'], ['status' => 'collected'])->assertRedirect()->assertSessionHasNoErrors();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_events')) {
                throw new \RuntimeException('Simulated database failure');
            }
        });
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            $this->post('/courier/rides/'.$f['ride'], ['status' => 'completed', 'photo' => UploadedFile::fake()->image('proof.jpg')]);
        } catch (\RuntimeException $e) {
            $failed = true;
        }
        $this->assertTrue($failed);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('rides', ['id' => $f['ride'], 'status' => 'collected', 'proof_photo_path' => null]);
        $this->assertDatabaseHas('orders', ['id' => DB::table('rides')->find($f['ride'])->order_id, 'payment_status' => 'pending']);
        $this->assertDatabaseHas('driver_claims', ['ride_id' => $f['ride']]);
    }
}
