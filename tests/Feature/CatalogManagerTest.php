<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsPlatform;
use Tests\TestCase;

class CatalogManagerTest extends TestCase
{
    use BuildsPlatform, RefreshDatabase;

    private function category(int $store, string $name = 'Pizzas', int $order = 0): int
    {
        return DB::table('catalog_categories')->insertGetId(['store_id' => $store, 'name' => $name, 'sort_order' => $order]);
    }

    private function product(int $tenant, int $store, int $category, string $name = 'Pizza da casa'): int
    {
        return DB::table('products')->insertGetId(['tenant_id' => $tenant, 'store_id' => $store, 'category_id' => $category, 'category' => DB::table('catalog_categories')->where('id', $category)->value('name'), 'name' => $name, 'price_cents' => 2000]);
    }

    public function test_empty_store_shows_correct_next_step_and_foreign_store_cannot_be_selected(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $foreign = $this->store($this->tenant('other'), 'other');
        $this->actingAs($this->owner($tenant))->get('/catalog?store_id='.$store)->assertOk()->assertSee('Seu primeiro produto começa aqui')->assertDontSee('Crie uma loja e adicione');
        $this->get('/catalog?store_id='.$foreign)->assertNotFound();
        $this->get('/products/create?store_id='.$foreign)->assertNotFound();
        $this->get('/products/create?store_id='.$store)->assertOk()->assertSee('Foto')->assertSee('Por tamanho / variação')->assertSee('Salvar e configurar adicionais');
        $this->actingAs($this->owner($this->tenant('empty')))->get('/catalog')->assertOk()->assertSee('Vamos começar pela sua loja?');
        $this->actingAs(User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant]))->get('/catalog')->assertForbidden();
    }

    public function test_categories_are_store_scoped_and_rename_updates_only_its_products(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->store($tenant, 'outra');
        $category = $this->category($store);
        $otherCategory = $this->category($other);
        $product = $this->product($tenant, $store, $category);
        $otherProduct = $this->product($tenant, $other, $otherCategory);
        $this->actingAs($this->owner($tenant))->post('/catalog/categories', ['store_id' => $store, 'category_id' => $category, 'name' => 'Pizzas especiais', 'sort_order' => 3])->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product, 'category' => 'Pizzas especiais']);
        $this->assertDatabaseHas('products', ['id' => $otherProduct, 'category' => 'Pizzas']);
        $this->post('/catalog/categories', ['store_id' => $store, 'category_id' => $otherCategory, 'name' => 'Invasão', 'sort_order' => 0])->assertNotFound();
        $this->postJson('/catalog/categories', ['store_id' => $store, 'name' => 'Pizzas especiais', 'sort_order' => 0])->assertUnprocessable();
        $this->post('/catalog/categories', ['store_id' => $store, 'name' => 'Bebidas', 'sort_order' => 1])->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant, 'action' => 'catalog.category.saved']);
    }

    public function test_creation_saves_photo_category_sizes_and_featured_as_one_transaction(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $category = $this->category($store);
        $this->actingAs($this->owner($tenant))->post('/products', ['store_id' => $store, 'name' => 'Pizza completa', 'category_id' => $category, 'available' => 0, 'featured' => 1, 'sort_order' => 4, 'uses_variants' => 1, 'new_variants' => [['name' => 'Pequena', 'price' => '30,90'], ['name' => 'Grande', 'price' => '45,00']], 'photo' => UploadedFile::fake()->createWithContent('pizza.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII=')), 'editor_flow' => 1])->assertRedirect('/products/1/edit')->assertSessionHasNoErrors();
        $product = DB::table('products')->first();
        $this->assertSame(3090, $product->price_cents);
        $this->assertSame($category, $product->category_id);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'featured' => true, 'available' => false, 'sort_order' => 4]);
        $this->assertDatabaseCount('product_variants', 2);
        Storage::disk('local')->assertExists($product->photo_path);
        $this->get('/products/'.$product->id.'/edit')->assertOk()->assertSee('Adicionais e revisão')->assertSee('Pequena')->assertSee('Grande');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant, 'action' => 'product.created']);
    }

    public function test_invalid_category_and_sizes_never_leave_half_created_product_or_orphan_photo(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $other = $this->category($this->store($this->tenant('foreign'), 'foreign'));
        $this->actingAs($this->owner($tenant));
        $base = ['store_id' => $store, 'name' => 'Não deve salvar', 'category_id' => $other, 'price' => '20,00'];
        $this->post('/products', $base + ['photo' => UploadedFile::fake()->createWithContent('foto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII='))])->assertNotFound();
        $this->assertDatabaseCount('products', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $base['category_id'] = $this->category($store);
        $base['uses_variants'] = 1;
        $this->postJson('/products', $base)->assertUnprocessable();
        $this->postJson('/products', $base + ['new_variants' => [['name' => 'Inválida', 'price' => '0,00']]])->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_filters_paginate_and_never_mix_stores_or_tenants(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $category = $this->category($store);
        $first = $this->product($tenant, $store, $category, 'Alvo destaque');
        DB::table('products')->where('id', $first)->update(['featured' => true]);
        $paused = $this->product($tenant, $store, $category, 'Pausado especial');
        DB::table('products')->where('id', $paused)->update(['available' => false]);
        $other = $this->store($tenant, 'outra');
        $this->product($tenant, $other, $this->category($other), 'Segredo outra loja');
        $this->actingAs($this->owner($tenant))->get('/catalog?store_id='.$store.'&q=Alvo&availability=featured')->assertOk()->assertSee('Alvo destaque')->assertDontSee('Pausado especial')->assertDontSee('Segredo outra loja');
        $this->get('/catalog?store_id='.$store.'&availability=paused')->assertOk()->assertSee('Pausado especial')->assertDontSee('Alvo destaque');
        $this->get('/catalog?store_id='.$store.'&category_id='.$this->category($other, 'Outra categoria'))->assertNotFound();
        for ($i = 0; $i < 25; $i++) {
            $this->product($tenant, $store, $category, 'Produto '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->get('/catalog?store_id='.$store)->assertOk()->assertSee('Página 1 de 2')->assertSee('Próxima');
        $this->get('/catalog?store_id='.$store.'&page=2')->assertOk()->assertSee('Página 2 de 2')->assertSee('Anterior');
    }

    public function test_public_menu_respects_category_product_order_and_curated_featured_items(): void
    {
        $tenant = $this->tenant();
        $store = $this->store($tenant);
        $last = $this->category($store, 'Almoço', 10);
        $first = $this->category($store, 'Sobremesas', 0);
        $this->product($tenant, $store, $last, 'Arroz');
        $later = $this->product($tenant, $store, $first, 'Bolo');
        DB::table('products')->where('id', $later)->update(['sort_order' => 5]);
        $highlight = $this->product($tenant, $store, $first, 'Torta');
        DB::table('products')->where('id', $highlight)->update(['featured' => true]);
        $response = $this->get('/cardapio/loja')->assertOk();
        $response->assertSeeInOrder(['href="#category-0">Sobremesas', 'href="#category-1">Almoço']);
        $html = $response->getContent();
        $this->assertStringContainsString('data-discover-open="product-title-'.$highlight.'"', $html);
        $this->assertStringNotContainsString('data-discover-open="product-title-'.$later.'"', $html);
        $this->assertLessThan(strpos($html, 'data-name="Bolo"'), strpos($html, 'data-name="Torta"'));
    }
}
