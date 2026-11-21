<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeliveryCoverage;
use App\Services\DeliveryPolygon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DeliveryAreasTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $owner = $this->owner($tenant);
        $this->actingAs($owner);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Prato', 'category' => 'Pratos', 'price_cents' => 3000]);

        return compact('tenant', 'store', 'owner', 'product');
    }

    private function polygon(): array
    {
        return [[-23.54, -46.64], [-23.54, -46.62], [-23.56, -46.62], [-23.56, -46.64]];
    }

    private function area(string $name = 'Centro', string $fee = '5,00'): array
    {
        return ['name' => $name, 'fee' => $fee, 'enabled' => 1, 'revision' => 0, 'request_key' => (string) Str::uuid(), 'color' => '#ff623f', 'polygon' => json_encode($this->polygon())];
    }

    private function fakeAddress(): void
    {
        config(['services.geoapify.key' => 'PRIVATE-KEY']);
        Http::preventStrayRequests();
        Http::fake(['viacep.com.br/*' => Http::response(['cep' => '01001-000', 'logradouro' => 'Rua A', 'bairro' => 'Centro', 'localidade' => 'São Paulo', 'uf' => 'SP']),
            'api.geoapify.com/*' => Http::response(['results' => [['formatted' => 'Rua A, 100, São Paulo, SP', 'country_code' => 'br', 'housenumber' => '100', 'street' => 'Rua A', 'result_type' => 'building', 'lat' => -23.55, 'lon' => -46.63, 'suburb' => 'Centro', 'city' => 'São Paulo', 'state_code' => 'SP', 'rank' => ['confidence' => 1]]]])]);
    }

    public function test_create_edit_pause_remove_and_optimistic_revision_preserve_store_configuration(): void
    {
        $f = $this->fixture();
        $path = '/stores/'.$f['store'].'/delivery-areas';
        $area = $this->area();
        $this->get($path)->assertOk()->assertSee('Onde sua loja entrega?')->assertHeader('Cache-Control', 'no-store, private');
        $this->post($path, $area)->assertSessionHasNoErrors();
        $this->post($path, $area)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('delivery_areas', 1);
        $id = DB::table('delivery_areas')->value('id');
        $this->assertNull(DB::table('stores')->find($f['store'])->delivery_mode);
        $this->post($path, array_replace($area, ['fee' => '6,00']))->assertConflict();
        $edit = array_replace($area, ['area_id' => $id, 'revision' => 1, 'name' => 'Centro novo', 'fee' => '7,00', 'enabled' => 0]);
        $this->post($path, $edit)->assertSessionHasNoErrors();
        $this->post($path, $edit)->assertConflict();
        $this->assertDatabaseHas('delivery_areas', ['id' => $id, 'revision' => 2, 'name' => 'Centro novo', 'fee_cents' => 700, 'enabled' => false]);
        $this->post($path.'/'.$id.'/remove', ['revision' => 1, 'confirmed' => 1])->assertConflict();
        $this->post($path.'/'.$id.'/remove', ['revision' => 2, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('delivery_areas')->find($id)->deleted_at);
        $this->get($path)->assertOk()->assertDontSee('Centro novo');
        $this->post($path, array_replace($edit, ['revision' => 3]))->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'delivery.area_removed', 'tenant_id' => $f['tenant']]);
    }

    public function test_invalid_shapes_and_prices_never_save_and_audit_failure_rolls_back(): void
    {
        $f = $this->fixture();
        $path = '/stores/'.$f['store'].'/delivery-areas';
        $area = $this->area();
        $invalid = [[], [[0, 0], [0, 1], [1, 1]], [[-23.54, -46.64], [-23.56, -46.62], [-23.54, -46.62], [-23.56, -46.64]], [[-23.55, -46.63], [-23.55, -46.62], [-23.55, -46.61]], [...$this->polygon(), $this->polygon()[0]], array_fill(0, 65, [-23.55, -46.63]), ['bad'], [[-23.55, -46.63], [-23.55, -46.62], [null, -46.63]]];
        foreach ($invalid as $points) {
            $this->post($path, array_replace($area, ['polygon' => json_encode($points)]))->assertSessionHasErrors('polygon');
        }
        $this->post($path, array_replace($area, ['polygon' => '"text"']))->assertSessionHasErrors('polygon');
        $this->post($path, array_replace($area, ['fee' => '-1']))->assertSessionHasErrors('fee');
        $this->post($path, array_replace($area, ['fee' => '1000,01']))->assertSessionHasErrors('fee_cents');
        $this->post($path, array_replace($area, ['color' => 'red']))->assertSessionHasErrors('color');
        $this->assertDatabaseCount('delivery_areas', 0);
        DB::statement("CREATE TRIGGER fail_area BEFORE INSERT ON audit_events WHEN NEW.action='delivery.area_saved' BEGIN SELECT RAISE(FAIL,'audit unavailable'); END");
        $this->post($path, $area)->assertStatus(500);
        $this->assertDatabaseCount('delivery_areas', 0);
    }

    public function test_polygons_include_boundaries_concavity_and_both_vertex_directions(): void
    {
        $geometry = app(DeliveryPolygon::class);
        foreach ([$this->polygon(), array_reverse($this->polygon())] as $polygon) {
            $this->assertTrue($geometry->contains($polygon, -23.55, -46.63));
            $this->assertTrue($geometry->contains($polygon, -23.54, -46.63));
            $this->assertTrue($geometry->contains($polygon, -23.54, -46.64));
            $this->assertFalse($geometry->contains($polygon, -23.54001, -46.64001));
            $this->assertFalse($geometry->contains($polygon, -23.57, -46.63));
        }
        $concave = [[0, 0], [0, 2], [1, 1], [2, 2], [2, 0]];
        $this->assertFalse($geometry->contains($concave, 1, 1.5));
        $this->assertTrue($geometry->contains($concave, 1, .5));
        $this->assertFalse($geometry->contains([], 0, 0));
        $this->assertFalse($geometry->contains($this->polygon(), INF, 0));
        $redundant = [[-23.54, -46.64], [-23.54, -46.63], [-23.54, -46.62], [-23.56, -46.62], [-23.56, -46.64]];
        $this->assertCount(5, $geometry->validate($redundant, (object) ['latitude' => -23.55, 'longitude' => -46.63]));
        $mercator = fn ($lat) => log(tan(M_PI / 4 + deg2rad($lat) / 2));
        $midpoint = rad2deg(2 * atan(exp(($mercator(-23.54) + $mercator(-23.56)) / 2)) - M_PI / 2);
        $this->assertTrue($geometry->contains([[-23.54, -46.64], [-23.56, -46.62], [-23.57, -46.64]], $midpoint, -46.63));
    }

    public function test_cheapest_enabled_area_wins_and_polygon_mode_never_falls_back_to_radius(): void
    {
        $f = $this->fixture();
        $path = '/stores/'.$f['store'].'/delivery-areas';
        $this->post('/stores/'.$f['store'].'/delivery-settings', ['delivery_mode' => 'polygon'])->assertSessionHasErrors('delivery_mode');
        $this->post($path, $this->area('Centro', '8'))->assertSessionHasNoErrors();
        $this->post($path, $this->area('Oferta', '4'))->assertSessionHasNoErrors();
        $this->post('/stores/'.$f['store'].'/delivery-settings', ['delivery_mode' => 'polygon'])->assertSessionHasNoErrors();
        $store = DB::table('stores')->find($f['store']);
        $coverage = app(DeliveryCoverage::class);
        $this->assertSame(400, $coverage->polygonZone($store, ['latitude' => -23.55, 'longitude' => -46.63])->fee_cents);
        $this->get('/cardapio/loja')->assertOk()->assertViewHas('hasDelivery', true)->assertDontSee('data-map-data');
        DB::table('delivery_areas')->where('name', 'Oferta')->update(['enabled' => false]);
        $this->assertSame(800, $coverage->polygonZone($store, ['latitude' => -23.55, 'longitude' => -46.63])->fee_cents);
        DB::table('delivery_areas')->update(['enabled' => false]);
        DB::table('delivery_zones')->insert(['store_id' => $f['store'], 'name' => 'Até 5 km', 'radius_meters' => 5000, 'fee_cents' => 1, 'enabled' => true]);
        $this->get('/cardapio/loja')->assertOk()->assertViewHas('hasDelivery', false);
        $this->assertNull($coverage->polygonZone($store, ['latitude' => -23.55, 'longitude' => -46.63]));
    }

    public function test_customer_quote_and_checkout_recheck_area_and_tariff_without_trusting_coordinates(): void
    {
        $f = $this->fixture();
        $this->post('/stores/'.$f['store'].'/delivery-areas', $this->area())->assertSessionHasNoErrors();
        DB::table('stores')->where('id', $f['store'])->update(['delivery_mode' => 'polygon']);
        $this->fakeAddress();
        $response = $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.available', true)->assertJsonPath('choices.0.fee_cents', 500);
        $draft = ['customer_name' => 'Ana', 'phone' => '11999999999', 'address' => 'Rua A, 100, São Paulo, SP', 'mode' => 'delivery', 'payment_method' => 'cash', 'items' => [$f['product'] => 1], 'idempotency_key' => (string) Str::uuid(), 'delivery_token' => $response->json('choices.0.token'), 'fee_cents' => 1, 'latitude' => 0, 'longitude' => 0];
        $this->post('/cardapio/loja/review', $draft)->assertSessionHasNoErrors();
        DB::table('delivery_areas')->update(['fee_cents' => 700]);
        $this->postJson('/cardapio/loja', ['draft_id' => $draft['idempotency_key']])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->post('/cardapio/loja/review', $draft)->assertSessionHasNoErrors();
        $this->post('/cardapio/loja', ['draft_id' => $draft['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['fee_cents' => 700, 'delivery_zone_name' => 'Centro']);
        DB::table('delivery_areas')->update(['polygon' => json_encode([[-23.52, -46.64], [-23.52, -46.62], [-23.53, -46.62], [-23.53, -46.64]])]);
        $draft['idempotency_key'] = (string) Str::uuid();
        $this->post('/cardapio/loja/review', $draft)->assertSessionHasErrors('address');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_preview_looks_up_postcode_and_house_number_using_saved_areas_even_before_activation(): void
    {
        $f = $this->fixture();
        $this->post('/stores/'.$f['store'].'/delivery-areas', $this->area())->assertSessionHasNoErrors();
        $this->fakeAddress();
        $this->postJson('/stores/'.$f['store'].'/delivery-areas/test', ['postcode' => '01001-000', 'number' => '100'])->assertOk()->assertJsonPath('results.0.available', true)->assertJsonPath('results.0.fee_cents', 500)->assertJsonPath('preview', true)->assertDontSee('PRIVATE-KEY');
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'geoapify') && $req['housenumber'] === '100' && $req['street'] === 'Rua A');
        $this->assertDatabaseCount('orders', 0);
        $this->assertNull(DB::table('stores')->find($f['store'])->delivery_mode);
    }

    public function test_other_tenants_staff_disabled_users_and_suspended_companies_cannot_manage_areas(): void
    {
        $f = $this->fixture();
        $path = '/stores/'.$f['store'].'/delivery-areas';
        $area = $this->area();
        $this->post($path, $area)->assertSessionHasNoErrors();
        $id = DB::table('delivery_areas')->value('id');
        $second = $this->store($f['tenant'], 'second');
        $this->post('/stores/'.$second.'/delivery-areas', array_replace($area, ['area_id' => $id, 'revision' => 1]))->assertNotFound();
        $this->actingAs($this->owner($this->tenant('other')));
        $this->get($path)->assertNotFound();
        $this->post($path, $area)->assertNotFound();
        $this->post($path.'/'.$id.'/remove', ['revision' => 1, 'confirmed' => 1])->assertNotFound();
        $this->postJson($path.'/test', ['postcode' => '01001000', 'number' => '100'])->assertNotFound();
        $this->actingAs(User::factory()->create(['tenant_id' => $f['tenant'], 'role' => 'staff']));
        $this->get($path)->assertForbidden();
        $this->post($path, $area)->assertForbidden();
        $this->actingAs($f['owner']);
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => false]);
        $this->get($path)->assertForbidden();
        DB::table('users')->where('id', $f['owner']->id)->update(['access_enabled' => true]);
        DB::table('tenants')->where('id', $f['tenant'])->update(['status' => 'suspended']);
        $this->post($path, $area)->assertForbidden();
    }
}
