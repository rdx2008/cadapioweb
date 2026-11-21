<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\BuildsPlatform;
use Tests\TestCase;

class DeliveryCoverageTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.geoapify.key' => 'PRIVATE-GEOCODING-KEY']);
    }

    private function setupStore(): array
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        DB::table('stores')->where('id', $store)->update(['delivery_by_radius' => true]);
        DB::table('delivery_zones')->insert([
            ['store_id' => $store, 'name' => 'Até 3 km', 'fee_cents' => 500, 'enabled' => true, 'radius_meters' => 3000],
            ['store_id' => $store, 'name' => 'Até 5 km', 'fee_cents' => 800, 'enabled' => true, 'radius_meters' => 5000],
        ]);
        $product = DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'name' => 'Pizza', 'category' => 'Pizzas', 'price_cents' => 3000]);

        return [$tenant, $store, $product];
    }

    private function fakePlace(float $latitude = -23.56, string $type = 'building', string $neighborhood = 'Centro', string $city = 'São Paulo', string $state = 'SP'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [['formatted' => 'Rua A, 100, São Paulo, SP', 'country_code' => 'br', 'housenumber' => '100', 'street' => 'Rua A', 'result_type' => $type, 'lat' => $latitude, 'lon' => -46.63, 'suburb' => $neighborhood, 'city' => $city, 'state_code' => $state, 'rank' => ['confidence' => 1]]]])]);
    }

    private function draft(int $product, string $token): array
    {
        return ['customer_name' => 'Ana', 'phone' => '11999999999', 'address' => 'Rua A, 100, São Paulo, SP', 'mode' => 'delivery', 'items' => [$product => 1], 'idempotency_key' => (string) Str::uuid(), 'delivery_token' => $token];
    }

    public function test_radius_quote_is_server_verified_and_rechecked_before_order_creation(): void
    {
        [, $store, $product] = $this->setupStore();
        $this->fakePlace();
        $response = $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.fee_cents', 500)->assertDontSee('PRIVATE-GEOCODING-KEY');
        $draft = $this->draft($product, $response->json('choices.0.token'));
        $draft['fee_cents'] = 1;
        $draft['address_complement'] = 'Apartamento 12';
        $draft['latitude'] = 0;
        $this->post('/cardapio/loja/review', $draft)->assertRedirect();
        DB::table('delivery_zones')->where('store_id', $store)->where('radius_meters', 3000)->update(['fee_cents' => 600]);
        $this->postJson('/cardapio/loja', ['draft_id' => $draft['idempotency_key']])->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->post('/cardapio/loja/review', $draft)->assertRedirect();
        $this->post('/cardapio/loja', ['draft_id' => $draft['idempotency_key']])->assertRedirect();
        $this->assertDatabaseHas('orders', ['fee_cents' => 600, 'total_cents' => 3600, 'address' => 'Rua A, 100, São Paulo, SP · Apartamento 12']);
        Http::assertSentCount(1);
    }

    public function test_forged_foreign_expired_or_edited_address_cannot_use_a_quote(): void
    {
        [, , $product] = $this->setupStore();
        $this->fakePlace();
        $token = $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->json('choices.0.token');
        $draft = $this->draft($product, 'forged');
        $this->postJson('/cardapio/loja/review', $draft)->assertUnprocessable()->assertJsonValidationErrors('address');
        $draft['delivery_token'] = $token;
        $draft['address'] = 'Rua distante, 900, São Paulo, SP';
        $this->postJson('/cardapio/loja/review', $draft)->assertUnprocessable();
        $other = $this->store($this->tenant('other'), 'other');
        DB::table('stores')->where('id', $other)->update(['delivery_by_radius' => true]);
        $otherProduct = DB::table('products')->insertGetId(['tenant_id' => DB::table('stores')->find($other)->tenant_id, 'store_id' => $other, 'name' => 'Outro', 'category' => 'Pizzas', 'price_cents' => 3000]);
        $this->postJson('/cardapio/other/review', $this->draft($otherProduct, $token))->assertUnprocessable();
        $this->travel(31)->minutes();
        $this->postJson('/cardapio/loja/review', $this->draft($product, $token))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_outside_radius_and_imprecise_addresses_are_not_approved(): void
    {
        $this->setupStore();
        $this->fakePlace(-24.5);
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua distante, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.available', false)->assertJsonPath('choices.0.token', null);
        $this->fakePlace(-23.55, 'street');
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Outra rua sem número, São Paulo, SP'])->assertUnprocessable()->assertJsonValidationErrors('address');
    }

    public function test_missing_key_and_provider_errors_fail_closed_without_leaking_secrets(): void
    {
        $this->setupStore();
        config(['services.geoapify.key' => null]);
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertUnprocessable();
        Http::assertNothingSent();
        config(['services.geoapify.key' => 'PRIVATE-GEOCODING-KEY']);
        Http::fake(['*' => Http::response(['error' => 'PRIVATE-GEOCODING-KEY'], 429)]);
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertUnprocessable()->assertDontSee('PRIVATE-GEOCODING-KEY');
    }

    public function test_outer_band_is_used_and_disabling_it_invalidates_the_location_receipt(): void
    {
        [, $store, $product] = $this->setupStore();
        $this->fakePlace(-23.585);
        $response = $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.fee_cents', 800);
        DB::table('delivery_zones')->where('store_id', $store)->where('radius_meters', 5000)->update(['enabled' => false]);
        $this->postJson('/cardapio/loja/review', $this->draft($product, $response->json('choices.0.token')))->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cached_address_does_not_spend_another_lookup_and_daily_budget_blocks_new_queries(): void
    {
        $this->setupStore();
        config(['services.geoapify.daily_limit' => 1]);
        $this->fakePlace();
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk();
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk();
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua diferente, 200, São Paulo, SP'])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_store_location_is_private_and_public_menu_never_shows_courier_remuneration(): void
    {
        [$tenant, $store] = $this->setupStore();
        $this->get('/cardapio/loja')->assertOk()->assertSee('Sua sacola')->assertSee('motoboy-icon', false)->assertDontSee('Remuneração')->assertDontSee('freelancer')->assertDontSee('PRIVATE-GEOCODING-KEY');
        $this->actingAs($this->owner($this->tenant('other')))->postJson('/stores/'.$store.'/locate', ['address' => 'Rua A, 100, São Paulo, SP'])->assertNotFound();
        Http::assertNothingSent();
        $this->fakePlace();
        $this->actingAs($this->owner($tenant))->postJson('/stores/'.$store.'/locate', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.latitude', -23.56);
    }

    public function test_neighborhood_is_identified_from_address_and_customer_cannot_choose_a_cheaper_zone(): void
    {
        [, $store, $product] = $this->setupStore();
        DB::table('stores')->where('id', $store)->update(['delivery_mode' => 'neighborhood']);
        DB::table('delivery_zones')->insert(['store_id' => $store, 'name' => 'Centro', 'city' => 'Sao Paulo', 'state_code' => 'SP', 'fee_cents' => 900, 'enabled' => true]);
        $cheap = DB::table('delivery_zones')->insertGetId(['store_id' => $store, 'name' => 'Jardim', 'city' => 'Sao Paulo', 'state_code' => 'SP', 'fee_cents' => 1, 'enabled' => true]);
        $this->fakePlace();
        $response = $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.fee_cents', 900);
        $draft = $this->draft($product, $response->json('choices.0.token'));
        $draft['delivery_zone_id'] = $cheap;
        $this->post('/cardapio/loja/review', $draft)->assertSessionHasNoErrors();
        $this->post('/cardapio/loja', ['draft_id' => $draft['idempotency_key']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['fee_cents' => 900, 'delivery_zone_name' => 'Centro']);
        $this->get('/cardapio/loja')->assertOk()->assertDontSee('name="delivery_zone_id"', false)->assertSee('Consultar frete');
    }

    public function test_same_neighborhood_in_another_city_or_missing_neighborhood_is_not_covered(): void
    {
        [, $store] = $this->setupStore();
        DB::table('stores')->where('id', $store)->update(['delivery_mode' => 'neighborhood']);
        DB::table('delivery_zones')->insert(['store_id' => $store, 'name' => 'Centro', 'city' => 'São Paulo', 'state_code' => 'SP', 'fee_cents' => 500, 'enabled' => true]);
        $this->fakePlace(-23.56, 'building', 'Centro', 'Campinas');
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, Campinas, SP'])->assertOk()->assertJsonPath('choices.0.available', false);
        $this->fakePlace(-23.56, 'building', '');
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Outra rua, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.available', false);
    }

    public function test_fixed_and_per_km_customer_fares_need_no_neighborhoods_and_enforce_radius(): void
    {
        [$tenant, $store] = $this->setupStore();
        DB::table('delivery_zones')->where('store_id', $store)->delete();
        $this->actingAs($this->owner($tenant));
        $this->postJson('/stores/'.$store.'/delivery-settings', ['delivery_mode' => 'radius', 'delivery_pricing' => 'fixed'])->assertUnprocessable()->assertJsonValidationErrors('delivery_max_km');
        $this->post('/stores/'.$store.'/delivery-settings', ['delivery_mode' => 'radius', 'delivery_pricing' => 'fixed', 'delivery_max_km' => 5, 'delivery_fixed' => '7,00'])->assertSessionHasNoErrors();
        $this->get('/cardapio/loja')->assertOk()->assertSee('Receber no meu endereço');
        $this->fakePlace();
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.fee_cents', 700);
        $this->post('/stores/'.$store.'/delivery-settings', ['delivery_mode' => 'radius', 'delivery_pricing' => 'per_km', 'delivery_max_km' => 5, 'delivery_base' => '3,00', 'delivery_per_km' => '1,50', 'delivery_minimum' => '0,00'])->assertSessionHasNoErrors();
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.fee_cents', 467);
        $this->post('/stores/'.$store.'/delivery-settings', ['delivery_mode' => 'radius', 'delivery_pricing' => 'per_km', 'delivery_max_km' => 5, 'delivery_base' => '3,00', 'delivery_per_km' => '1,50', 'delivery_minimum' => '6,00'])->assertSessionHasNoErrors();
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua A, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.fee_cents', 600);
        $this->fakePlace(-24.5);
        $this->postJson('/cardapio/loja/delivery', ['address' => 'Rua distante, 100, São Paulo, SP'])->assertOk()->assertJsonPath('choices.0.available', false);
        $this->actingAs($this->owner($this->tenant('other')))->post('/stores/'.$store.'/delivery-settings', ['delivery_mode' => 'neighborhood'])->assertNotFound();
    }

    public function test_neighborhood_configuration_requires_city_and_state_and_radius_band_requires_distance(): void
    {
        [$tenant, $store] = $this->setupStore();
        $this->actingAs($this->owner($tenant));
        $this->postJson('/stores/'.$store.'/zones', ['zone_kind' => 'neighborhood', 'name' => 'Centro', 'fee_cents' => 500, 'enabled' => 1])->assertUnprocessable()->assertJsonValidationErrors(['city', 'state_code']);
        $this->postJson('/stores/'.$store.'/zones', ['zone_kind' => 'radius', 'name' => 'Faixa', 'fee_cents' => 500, 'enabled' => 1])->assertUnprocessable()->assertJsonValidationErrors('radius_km');
    }

    public function test_separate_address_fields_are_preserved_when_editing_and_cannot_change_a_verified_quote(): void
    {
        [, , $product] = $this->setupStore();
        $this->fakePlace();
        $fields = ['street' => 'Rua A', 'number' => '100', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'postcode' => '01000-000'];
        $response = $this->postJson('/cardapio/loja/delivery', ['address_fields' => $fields])->assertOk()->assertJsonPath('choices.0.fee_cents', 500);
        Http::assertSent(fn ($request) => $request['street'] === 'Rua A' && $request['housenumber'] === '100' && $request['city'] === 'São Paulo' && $request['state'] === 'SP' && ! isset($request['text']) && $request['postcode'] === '01000-000');
        $draft = $this->draft($product, $response->json('choices.0.token'));
        $draft['address_fields'] = $fields;
        $draft['address'] = 'valor do navegador ignorado';
        $this->post('/cardapio/loja/review', $draft)->assertSessionHasNoErrors();
        $this->get('/cardapio/loja?edit='.$draft['idempotency_key'])->assertOk()->assertSee('value="Rua A"', false)->assertSee('value="100"', false);
        $draft['address_fields']['number'] = '200';
        $draft['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/cardapio/loja/review', $draft)->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_postcode_is_required_and_cannot_replace_street_and_number(): void
    {
        $this->setupStore();
        $this->fakePlace();
        $fields = ['street' => 'Rua A', 'number' => '100', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'postcode' => '01000-000'];
        $this->postJson('/cardapio/loja/delivery', ['address_fields' => $fields])->assertOk();
        Http::assertSent(fn ($request) => $request['postcode'] === '01000-000' && $request['housenumber'] === '100');
        $fields['postcode'] = '123';
        $this->postJson('/cardapio/loja/delivery', ['address_fields' => $fields])->assertUnprocessable()->assertJsonValidationErrors('address_fields.postcode');
        $this->postJson('/cardapio/loja/delivery', ['address_fields' => ['postcode' => '01000-000']])->assertUnprocessable()->assertJsonValidationErrors(['address_fields.street', 'address_fields.number', 'address_fields.city']);
        Http::assertSentCount(1);
    }

    public function test_postcode_lookup_returns_only_address_fields_caches_results_and_never_quotes_freight(): void
    {
        $this->setupStore();
        Http::fake(['viacep.com.br/ws/01001000/json/' => Http::response(['cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'complemento' => 'lado ímpar'])]);
        $response = $this->postJson('/cardapio/loja/postcode', ['postcode' => '01001-000'])->assertOk()->assertJsonPath('address.street', 'Praça da Sé')->assertJsonPath('address.city', 'São Paulo');
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('latitude', $response->json('address'));
        $this->assertArrayNotHasKey('complemento', $response->json('address'));
        $this->postJson('/cardapio/loja/postcode', ['postcode' => '01001000'])->assertOk();
        Http::assertSentCount(1);
        $this->postJson('/cardapio/loja/postcode', ['postcode' => 'bad'])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_generic_postcodes_allow_missing_street_and_provider_failures_are_safe(): void
    {
        $this->setupStore();
        Http::fake([
            '*/ws/12345000/json/' => Http::response(['cep' => '12345-000', 'logradouro' => '', 'bairro' => '', 'localidade' => 'Cidade', 'uf' => 'SP']),
            '*/ws/99999999/json/' => Http::response(['erro' => true]),
            '*/ws/11111111/json/' => Http::response('unavailable', 503),
        ]);
        $this->postJson('/cardapio/loja/postcode', ['postcode' => '12345-000'])->assertOk()->assertJsonPath('address.street', '')->assertJsonPath('address.city', 'Cidade');
        $this->postJson('/cardapio/loja/postcode', ['postcode' => '99999-999'])->assertUnprocessable()->assertJsonValidationErrors('postcode');
        $this->postJson('/cardapio/loja/postcode', ['postcode' => '11111-111'])->assertUnprocessable()->assertDontSee('unavailable');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_store_branding_upload_and_radius_configuration_are_tenant_scoped(): void
    {
        [$tenant, $store] = $this->setupStore();
        Storage::fake('local');
        $hours = array_fill(0, 7, ['enabled' => 1, 'opens_at' => '00:00', 'closes_at' => '00:00']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII=');
        $data = ['name' => 'Minha Loja', 'slug' => 'loja', 'address' => 'Rua B, 20', 'latitude' => -23.55, 'longitude' => -46.63, 'open' => 1, 'timezone' => 'America/Sao_Paulo', 'minimum_order_cents' => 0, 'use_hours' => 0, 'hours' => $hours, 'rate_mode' => 'fixed', 'base_cents' => 800, 'per_km_cents' => 200, 'minimum_cents' => 800, 'logo' => UploadedFile::fake()->createWithContent('logo.png', $png), 'cover' => UploadedFile::fake()->createWithContent('cover.png', $png), 'delivery_by_radius' => 1];
        $this->actingAs($this->owner($tenant))->post('/stores/'.$store.'/edit', $data)->assertSessionHasNoErrors();
        $saved = DB::table('stores')->find($store);
        Storage::disk('local')->assertExists($saved->logo_path);
        $this->get('/media/stores/'.$store.'/logo')->assertOk();
        $this->get('/media/stores/'.$store.'/secret')->assertNotFound();
        $zone = DB::table('delivery_zones')->where('store_id', $store)->first();
        $payload = ['zone_id' => $zone->id, 'name' => 'Até 4 km', 'fee_cents' => 700, 'enabled' => 1, 'radius_km' => 4];
        $this->post('/stores/'.$store.'/zones', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('delivery_zones', ['id' => $zone->id, 'radius_meters' => 4000]);
        $this->actingAs($this->owner($this->tenant('other')))->post('/stores/'.$store.'/zones', $payload)->assertNotFound();
    }
}
