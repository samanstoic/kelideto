<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentPhaseFifteenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://keypro.test']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_public_article_has_seo_metadata_and_only_published_content_enters_sitemap(): void
    {
        $manager = $this->createUser('content_manager');
        $publishedId = $this->createPage($manager, [
            'slug' => 'published-lock-guide',
            'content_type' => 'article',
            'title' => 'راهنمای قفل',
            'meta_title' => 'عنوان سئو',
            'meta_description' => 'توضیح متای واقعی',
            'body' => '<script>متن باید escaped شود</script>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $this->createPage($manager, [
            'slug' => 'unpublished-draft',
            'content_type' => 'page',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->get('/articles/published-lock-guide')
            ->assertOk()
            ->assertSee('<title>عنوان سئو</title>', false)
            ->assertSee('<meta name="description" content="توضیح متای واقعی">', false)
            ->assertSee('<link rel="canonical" href="https://keypro.test/articles/published-lock-guide">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('Article', false)
            ->assertSee('&lt;script&gt;متن باید escaped شود&lt;/script&gt;', false)
            ->assertDontSee('<script>متن باید escaped شود</script>', false);

        $this->get('/pages/unpublished-draft')->assertNotFound();
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('https://keypro.test/articles/published-lock-guide', false)
            ->assertDontSee('unpublished-draft', false);
        $this->assertDatabaseHas('content_pages', ['id' => $publishedId, 'status' => 'published']);
    }

    public function test_scheduled_publication_stays_private_until_the_publish_command_runs(): void
    {
        $manager = $this->createUser('content_manager');
        Sanctum::actingAs($manager);
        $this->postJson('/api/admin/console/resources/content', [
            'slug' => 'scheduled-service-page',
            'content_type' => 'page',
            'title' => 'صفحه زمان‌بندی‌شده',
            'body' => 'محتوای واقعی صفحه',
            'status' => 'published',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'reason' => 'برنامه‌ریزی زمان انتشار صفحه',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
        $id = DB::table('content_pages')->where('slug', 'scheduled-service-page')->value('id');

        $this->get('/pages/scheduled-service-page')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('scheduled-service-page', false);

        $this->travelTo(now()->addDays(2));
        Artisan::call('content:publish-scheduled');
        $this->assertDatabaseHas('content_pages', [
            'id' => $id,
            'status' => 'published',
            'scheduled_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.content.scheduled_published',
            'auditable_id' => $id,
        ]);
        $this->get('/pages/scheduled-service-page')->assertOk();
    }

    public function test_content_management_requires_rbac_and_unique_slugs_and_creates_slug_redirects(): void
    {
        $customer = $this->createUser('customer');
        Sanctum::actingAs($customer);
        $this->postJson('/api/admin/console/resources/content', [
            'slug' => 'restricted-page',
            'content_type' => 'page',
            'title' => 'صفحه محدود',
            'body' => 'متن',
            'reason' => 'تلاش بدون مجوز',
        ])->assertForbidden();

        $manager = $this->createUser('content_manager');
        Sanctum::actingAs($manager);
        $pagePayload = [
            'slug' => 'stable-old-slug',
            'content_type' => 'article',
            'title' => 'مقالهٔ منتشرشده',
            'body' => 'محتوای مقاله',
            'status' => 'published',
            'reason' => 'انتشار محتوای تاییدشده',
        ];
        $created = $this->postJson('/api/admin/console/resources/content', $pagePayload)
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/admin/console/resources/content', $pagePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->patchJson('/api/admin/console/resources/content/'.$created['id'], [
            'slug' => 'stable-new-slug',
            'reason' => 'تغییر نشانی برای اصلاح ساختار',
        ])->assertOk();

        $this->get('/articles/stable-old-slug')
            ->assertRedirect('/articles/stable-new-slug');
        $this->assertDatabaseHas('content_redirects', [
            'source_path' => '/articles/stable-old-slug',
            'target_path' => '/articles/stable-new-slug',
            'status_code' => 301,
        ]);
    }

    public function test_preview_is_permission_gated_and_escapes_user_content(): void
    {
        $manager = $this->createUser('content_manager');
        Sanctum::actingAs($manager);
        $response = $this->postJson('/api/admin/console/content/preview', [
            'slug' => 'safe-preview',
            'content_type' => 'page',
            'title' => 'پیش‌نمایش',
            'body' => '<img src=x onerror=alert(1)>',
        ])->assertOk()->assertJsonPath('success', true);
        $html = $response->json('data.html');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_uploaded_media_is_private_until_referenced_by_a_published_page(): void
    {
        Storage::fake('local');
        $manager = $this->createUser('content_manager');
        Sanctum::actingAs($manager);

        $upload = $this->withHeader('Accept', 'application/json')->post('/api/admin/console/content/media', [
            'image' => UploadedFile::fake()->image('cover.png', 120, 80),
        ])->assertCreated();
        $media = $upload->json('data');
        $this->assertDatabaseHas('content_media', [
            'id' => $media['id'],
            'width' => 120,
            'height' => 80,
        ]);
        $this->get($media['public_url'])->assertNotFound();

        $this->postJson('/api/admin/console/resources/content', [
            'slug' => 'page-with-real-image',
            'content_type' => 'page',
            'title' => 'صفحه دارای تصویر',
            'body' => 'محتوای صفحه',
            'og_image_id' => $media['id'],
            'status' => 'published',
            'reason' => 'انتشار صفحه دارای تصویر واقعی',
        ])->assertCreated();

        $this->get($media['public_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'immutable, max-age=86400, public');
        $this->get('/pages/page-with-real-image')
            ->assertOk()
            ->assertSee('loading="lazy" decoding="async"', false)
            ->assertSee('width="120" height="80"', false);
    }

    public function test_service_and_coverage_pages_are_built_only_from_active_catalog_data(): void
    {
        $categoryId = DB::table('service_categories')->insertGetId([
            'name_fa' => 'خدمات قفل',
            'slug' => 'lock-services',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('services')->insert([
            'category_id' => $categoryId,
            'title_fa' => 'بازکردن قفل واقعی',
            'slug' => 'real-lock-opening',
            'description' => 'شرح واقعی خدمت ثبت‌شده',
            'base_price' => 0,
            'estimated_duration_minutes' => 25,
            'difficulty_level' => 'medium',
            'requires_ownership_proof' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('service_areas')->insert([
            'city_name' => 'شهر ثبت‌شده',
            'zone_name' => 'محدوده ثبت‌شده',
            'slug' => 'real-coverage-zone',
            'center_lat' => 35.7,
            'center_lng' => 51.4,
            'radius_km' => 8,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/services/real-lock-opening')
            ->assertOk()
            ->assertSee('شرح واقعی خدمت ثبت‌شده')
            ->assertSee('"@type":"Service"', false)
            ->assertDontSee('aggregateRating', false)
            ->assertSee('<link rel="canonical" href="https://keypro.test/services/real-lock-opening">', false);
        $this->get('/areas/real-coverage-zone')
            ->assertOk()
            ->assertSee('محدوده ثبت‌شده')
            ->assertSee('"@type":"Place"', false);
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://keypro.test/sitemap.xml', false);
        $this->get('/sitemap.xml')
            ->assertSee('https://keypro.test/services/real-lock-opening', false)
            ->assertSee('https://keypro.test/areas/real-coverage-zone', false);
    }

    public function test_managed_redirects_are_internal_audited_and_reject_loops(): void
    {
        $manager = $this->createUser('content_manager');
        Sanctum::actingAs($manager);
        $redirect = $this->postJson('/api/admin/console/content/redirects', [
            'source_path' => '/legacy-guide',
            'target_path' => '/articles/current-guide',
            'status_code' => 301,
            'is_active' => true,
            'reason' => 'انتقال مسیر قدیمی مقاله',
        ])->assertCreated()->json('data');

        $this->get('/legacy-guide')->assertRedirect('/articles/current-guide');
        $this->getJson('/api/admin/console/content/redirects')
            ->assertOk()
            ->assertJsonPath('data.data.0.source_path', '/legacy-guide');
        $this->patchJson('/api/admin/console/content/redirects/'.$redirect['id'], [
            'target_path' => '/legacy-guide',
            'reason' => 'تلاش برای ساخت حلقه تغییر مسیر',
        ])->assertUnprocessable()->assertJsonValidationErrors(['target_path']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'action' => 'admin.content.redirect_created',
            'auditable_id' => $redirect['id'],
        ]);
    }

    private function createPage(User $actor, array $overrides = []): int
    {
        return DB::table('content_pages')->insertGetId(array_replace([
            'slug' => 'page-'.Str::lower(Str::random(12)),
            'content_type' => 'page',
            'title' => 'عنوان صفحه',
            'body' => 'متن صفحه',
            'status' => 'published',
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createUser(string $role): User
    {
        $user = User::factory()->create([
            'status' => 'active',
            'password' => Hash::make('content-admin-secret'),
        ]);
        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        return $user;
    }
}
