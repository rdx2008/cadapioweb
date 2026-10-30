<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsPlatform;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function fixture(): int
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $this->actingAs($this->owner($tenant));

        return $store;
    }

    public function test_saving_payments_does_not_require_or_overwrite_address_hours_or_identity(): void
    {
        $store = $this->fixture();
        $before = DB::table('stores')->find($store);
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'pagamentos', 'accepts_cash' => 0, 'accepts_card' => 1, 'accepts_pix' => 0, 'name' => 'Should not change', 'address' => '', 'base_fare' => 'invalid'])
            ->assertRedirect(route('stores.edit', $store).'#pagamentos')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stores', ['id' => $store, 'name' => $before->name, 'address' => $before->address, 'latitude' => $before->latitude, 'weekly_hours' => $before->weekly_hours, 'base_cents' => $before->base_cents, 'accepts_cash' => false, 'accepts_card' => true, 'accepts_pix' => false]);
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'pagamentos', 'accepts_cash' => 0, 'accepts_card' => 0, 'accepts_pix' => 0])->assertSessionHasErrors('accepts_cash');
        $this->assertDatabaseHas('stores', ['id' => $store, 'accepts_card' => true]);
        $this->assertStringContainsString('pagamentos', DB::table('audit_events')->where('action', 'store.updated')->value('details'));
    }

    public function test_address_and_delivery_payments_are_saved_independently(): void
    {
        $store = $this->fixture();
        $before = DB::table('stores')->find($store);
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'endereco', 'address' => 'Rua Nova, 90 - Curitiba, PR', 'latitude' => -25.43, 'longitude' => -49.27, 'accepts_cash' => 0, 'delivery_fixed_cents' => 99000])->assertRedirect(route('stores.edit', $store).'#endereco')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stores', ['id' => $store, 'address' => 'Rua Nova, 90 - Curitiba, PR', 'latitude' => -25.43, 'longitude' => -49.27, 'accepts_cash' => $before->accepts_cash, 'delivery_fixed_cents' => $before->delivery_fixed_cents, 'name' => $before->name]);
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'endereco', 'address' => 'Invalid point', 'latitude' => 100, 'longitude' => -49.27])->assertSessionHasErrors('latitude');
        $this->assertDatabaseHas('stores', ['id' => $store, 'address' => 'Rua Nova, 90 - Curitiba, PR']);
    }

    public function test_business_data_and_courier_quote_do_not_change_each_other(): void
    {
        $store = $this->fixture();
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'dados', 'name' => 'New name', 'slug' => 'new-slug', 'open' => 0, 'minimum_order' => '35,90', 'rate_mode' => 'per_ride', 'base_fare' => '99'])->assertRedirect()->assertSessionHasNoErrors();
        $before = DB::table('stores')->find($store);
        $this->assertEquals(3590, $before->minimum_order_cents);
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'motoboy', 'rate_mode' => 'per_km', 'base_fare' => '9,50', 'per_km_fare' => '2,20', 'minimum_fare' => '10', 'open' => 1, 'minimum_order' => '0'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stores', ['id' => $store, 'name' => 'New name', 'slug' => 'new-slug', 'open' => false, 'minimum_order_cents' => 3590, 'rate_mode' => 'per_km', 'base_cents' => 950, 'per_km_cents' => 220, 'minimum_cents' => 1000]);
    }

    public function test_hours_and_scheduling_keep_their_validation_and_do_not_clear_other_settings(): void
    {
        $store = $this->fixture();
        $hours = array_fill(0, 7, ['enabled' => 1, 'opens_at' => '10:00', 'closes_at' => '22:00']);
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'horarios', 'timezone' => 'America/Manaus', 'use_hours' => 1, 'hours' => $hours])->assertRedirect()->assertSessionHasNoErrors();
        $stored = DB::table('stores')->where('id', $store)->value('weekly_hours');
        $this->assertCount(7, json_decode($stored, true));
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'agendamentos', 'scheduling_enabled' => 1, 'scheduling_lead_minutes' => 15, 'scheduling_prep_minutes' => 60, 'scheduling_days' => 3])->assertSessionHasErrors('scheduling_lead_minutes');
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'agendamentos', 'scheduling_enabled' => 1, 'scheduling_lead_minutes' => 90, 'scheduling_prep_minutes' => 60, 'scheduling_days' => 3, 'use_hours' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stores', ['id' => $store, 'timezone' => 'America/Manaus', 'weekly_hours' => $stored, 'scheduling_enabled' => true, 'scheduling_lead_minutes' => 90]);
    }

    public function test_branding_upload_is_independent_and_is_displayed_in_the_settings_preview(): void
    {
        Storage::fake('local');
        $store = $this->fixture();
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'aparencia', 'logo' => UploadedFile::fake()->image('logo.jpg', 100, 100), 'open' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $row = DB::table('stores')->find($store);
        Storage::disk('local')->assertExists($row->logo_path);
        $this->assertTrue((bool) $row->open);
        $this->get('/stores/'.$store.'/edit')->assertOk()->assertSee('Logo atual')->assertSee(route('stores.branding', [$store, 'logo']));
        $this->get(route('stores.branding', [$store, 'logo']))->assertOk();
    }

    public function test_sections_cannot_be_used_to_edit_another_tenant_or_bypass_required_fields(): void
    {
        $store = $this->fixture();
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'unknown'])->assertSessionHasErrors('settings_section');
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'pagamentos', 'accepts_cash' => 1])->assertSessionHasErrors(['accepts_card', 'accepts_pix']);
        $this->actingAs($this->owner($this->tenant('another')))->get('/stores/'.$store.'/edit')->assertNotFound();
        $this->post('/stores/'.$store.'/edit', ['settings_section' => 'dados', 'name' => 'Foreign name', 'slug' => 'foreign-slug', 'open' => 1, 'minimum_order' => 0])->assertNotFound();
        $this->assertDatabaseHas('stores', ['id' => $store, 'name' => 'Loja loja']);
    }
}
