<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\BuildsPlatform;
use Tests\TestCase;

class StoreAddressTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.geoapify.key' => 'PRIVATE-STORE-KEY']);
    }

    private function fields(): array
    {
        return ['postcode' => '82510-000', 'street' => 'Rua da Loja', 'number' => '72', 'neighborhood' => 'Boa Vista', 'city' => 'Curitiba', 'state' => 'pr'];
    }

    public function test_owner_without_any_store_can_lookup_postcode_and_location_then_save_coordinates(): void
    {
        $tenant = $this->tenant();
        Http::fake([
            'viacep.com.br/*' => Http::response(['cep' => '82510-000', 'logradouro' => 'Rua da Loja', 'bairro' => 'Boa Vista', 'localidade' => 'Curitiba', 'uf' => 'PR']),
            'api.geoapify.com/*' => Http::response(['results' => [['formatted' => 'Rua da Loja, 72, Curitiba, PR', 'country_code' => 'br', 'housenumber' => '72', 'street' => 'Rua da Loja', 'result_type' => 'building', 'lat' => -25.4, 'lon' => -49.2, 'rank' => ['confidence' => 1]]]]),
        ]);
        $this->actingAs($this->owner($tenant))->get('/dashboard')->assertOk()->assertSee('data-store-address', false)->assertSee('data-store-create-form', false)->assertDontSee('PRIVATE-STORE-KEY');
        $this->postJson('/stores/address/postcode', ['postcode' => '82510-000'])->assertOk()->assertJsonPath('address.street', 'Rua da Loja')->assertJsonPath('address.city', 'Curitiba');
        $choice = $this->postJson('/stores/address/locate', $this->fields())->assertOk()->assertJsonPath('choices.0.latitude', -25.4)->assertDontSee('PRIVATE-STORE-KEY')->json('choices.0');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com') && $request['street'] === 'Rua da Loja' && $request['housenumber'] === '72' && $request['postcode'] === '82510000' && $request['state'] === 'PR');
        $this->post('/stores', ['name' => 'Minha loja', 'slug' => 'minha-loja', 'address' => 'Rua da Loja, 72 - Boa Vista, Curitiba - PR, 82510000', 'latitude' => $choice['latitude'], 'longitude' => $choice['longitude'], 'rate_mode' => 'fixed', 'base_fare' => '8,00', 'per_km_fare' => '2,00', 'minimum_fare' => '8,00', 'customer_fee' => '5,00'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stores', ['tenant_id' => $tenant, 'slug' => 'minha-loja', 'latitude' => -25.4, 'longitude' => -49.2]);
        $this->assertDatabaseCount('stores', 1);
        Http::assertSentCount(2);
    }

    public function test_lookup_is_owner_only_and_suspended_companies_cannot_consume_it(): void
    {
        $this->postJson('/stores/address/postcode', ['postcode' => '82510000'])->assertUnauthorized();
        $tenant = $this->tenant();
        foreach (['admin', 'staff', 'courier'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'tenant_id' => $tenant]))->postJson('/stores/address/locate', $this->fields())->assertForbidden();
        }
        DB::table('tenants')->where('id', $tenant)->update(['status' => 'suspended']);
        $this->actingAs($this->owner($tenant))->postJson('/stores/address/postcode', ['postcode' => '82510000'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_or_incomplete_addresses_and_unconfigured_geocoder_do_not_return_a_point(): void
    {
        $this->actingAs($this->owner($this->tenant()));
        $this->postJson('/stores/address/postcode', ['postcode' => '../bad'])->assertUnprocessable();
        $this->postJson('/stores/address/locate', array_replace($this->fields(), ['number' => '']))->assertUnprocessable()->assertJsonValidationErrors('number');
        config(['services.geoapify.key' => null]);
        $this->postJson('/stores/address/locate', $this->fields())->assertUnprocessable()->assertDontSee('PRIVATE-STORE-KEY');
        Http::assertNothingSent();
    }

    public function test_imprecise_geocoder_results_are_rejected_and_postcode_outage_allows_manual_fallback(): void
    {
        $this->actingAs($this->owner($this->tenant()));
        Http::fake(['viacep.com.br/*' => Http::response('provider offline', 503), 'api.geoapify.com/*' => Http::response(['results' => [['country_code' => 'br', 'street' => 'Rua da Loja', 'result_type' => 'street', 'lat' => -25.4, 'lon' => -49.2]]])]);
        $this->postJson('/stores/address/postcode', ['postcode' => '82510000'])->assertUnprocessable()->assertJsonValidationErrors('postcode')->assertDontSee('provider offline');
        $this->postJson('/stores/address/locate', $this->fields())->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_existing_store_uses_the_same_form_and_other_tenants_cannot_edit_it(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant))->get('/stores/'.$store.'/edit')->assertOk()->assertSee('data-existing="1"', false)->assertSee('Rua da Loja, 100');
        $this->actingAs($this->owner($this->tenant('other')))->get('/stores/'.$store.'/edit')->assertNotFound();
    }
}
