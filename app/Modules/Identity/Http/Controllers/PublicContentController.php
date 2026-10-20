<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicContentController extends Controller
{
    public function page(string $slug, string $type = 'page')
    {
        abort_unless(in_array($type, ['page', 'article'], true), 404);
        $path = $this->pagePath($type, $slug);
        $redirect = $this->activeRedirect($path);
        if ($redirect) {
            return redirect($redirect->target_path, (int) $redirect->status_code);
        }
        $page = DB::table('content_pages')
            ->where('slug', $slug)
            ->where('content_type', $type)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNull('scheduled_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->first();
        abort_unless($page, 404);

        $image = $page->og_image_id
            ? DB::table('content_media')->where('id', $page->og_image_id)->first()
            : null;

        return view('public-content.show', [
            'title' => $page->meta_title ?: $page->title,
            'description' => $page->meta_description ?: $page->excerpt,
            'canonical' => $page->canonical_url ?: $this->absoluteUrl($path),
            'ogImage' => $image ? $this->absoluteUrl('/content-media/'.$image->public_id) : null,
            'ogImageWidth' => $image?->width,
            'ogImageHeight' => $image?->height,
            'contentTitle' => $page->title,
            'excerpt' => $page->excerpt,
            'body' => $page->body,
            'publishedAt' => $page->published_at,
            'structuredData' => $type === 'article' ? [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $page->title,
                'datePublished' => $page->published_at,
                'url' => $this->absoluteUrl($path),
            ] : null,
        ]);
    }

    public function article(string $slug)
    {
        return $this->page($slug, 'article');
    }

    public function services()
    {
        $services = DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.is_active', true)
            ->where('service_categories.is_active', true)
            ->orderBy('service_categories.display_order')
            ->orderBy('services.title_fa')
            ->get([
                'services.title_fa as title', 'services.slug', 'services.description',
                'services.estimated_duration_minutes', 'service_categories.name_fa as category',
            ]);

        return view('public-content.catalog-index', [
            'title' => 'خدمات کلیدتو',
            'description' => null,
            'canonical' => $this->absoluteUrl('/services'),
            'heading' => 'خدمات قابل ارائه',
            'records' => $services,
            'recordType' => 'service',
        ]);
    }

    public function service(string $slug)
    {
        $redirect = $this->activeRedirect('/services/'.$slug);
        if ($redirect) {
            return redirect($redirect->target_path, (int) $redirect->status_code);
        }
        $service = DB::table('services')
            ->join('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.slug', $slug)
            ->where('services.is_active', true)
            ->where('service_categories.is_active', true)
            ->select([
                'services.title_fa as title', 'services.slug', 'services.description',
                'services.estimated_duration_minutes', 'service_categories.name_fa as category',
            ])
            ->first();
        abort_unless($service, 404);

        $path = '/services/'.$service->slug;

        return view('public-content.show', [
            'title' => $service->title.' | خدمات کلیدتو',
            'description' => $service->description,
            'canonical' => $this->absoluteUrl($path),
            'ogImage' => null,
            'ogImageWidth' => null,
            'ogImageHeight' => null,
            'contentTitle' => $service->title,
            'excerpt' => $service->description,
            'body' => null,
            'publishedAt' => null,
            'serviceDetails' => $service,
            'structuredData' => $service->description ? [
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                'name' => $service->title,
                'description' => $service->description,
                'url' => $this->absoluteUrl($path),
            ] : null,
        ]);
    }

    public function areas()
    {
        $areas = DB::table('service_areas')
            ->where('is_active', true)
            ->orderBy('city_name')
            ->orderBy('zone_name')
            ->get(['city_name as title', 'zone_name', 'slug', 'radius_km']);

        return view('public-content.catalog-index', [
            'title' => 'مناطق تحت پوشش کلیدتو',
            'description' => null,
            'canonical' => $this->absoluteUrl('/areas'),
            'heading' => 'مناطق تحت پوشش',
            'records' => $areas,
            'recordType' => 'area',
        ]);
    }

    public function area(string $slug)
    {
        $redirect = $this->activeRedirect('/areas/'.$slug);
        if ($redirect) {
            return redirect($redirect->target_path, (int) $redirect->status_code);
        }
        $area = DB::table('service_areas')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first(['city_name', 'zone_name', 'slug', 'radius_km', 'updated_at']);
        abort_unless($area, 404);
        $name = $area->city_name.'، '.$area->zone_name;
        $path = '/areas/'.$area->slug;

        return view('public-content.show', [
            'title' => $name.' | مناطق تحت پوشش کلیدتو',
            'description' => null,
            'canonical' => $this->absoluteUrl($path),
            'ogImage' => null,
            'ogImageWidth' => null,
            'ogImageHeight' => null,
            'contentTitle' => $name,
            'excerpt' => null,
            'body' => null,
            'publishedAt' => null,
            'areaDetails' => $area,
            'structuredData' => [
                '@context' => 'https://schema.org',
                '@type' => 'Place',
                'name' => $name,
                'url' => $this->absoluteUrl($path),
            ],
        ]);
    }

    public function sitemap()
    {
        $urls = collect([
            ['loc' => $this->absoluteUrl('/'), 'lastmod' => null],
            ['loc' => $this->absoluteUrl('/services'), 'lastmod' => null],
            ['loc' => $this->absoluteUrl('/areas'), 'lastmod' => null],
        ]);

        DB::table('services')->where('is_active', true)->orderBy('id')->get(['slug', 'updated_at'])
            ->each(fn (object $row) => $urls->push([
                'loc' => $this->absoluteUrl('/services/'.$row->slug),
                'lastmod' => $row->updated_at,
            ]));
        DB::table('service_areas')->where('is_active', true)->orderBy('id')->get(['slug', 'updated_at'])
            ->each(fn (object $row) => $urls->push([
                'loc' => $this->absoluteUrl('/areas/'.$row->slug),
                'lastmod' => $row->updated_at,
            ]));
        DB::table('content_pages')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNull('scheduled_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('id')
            ->get(['slug', 'content_type', 'canonical_url', 'updated_at'])
            ->each(function (object $row) use ($urls): void {
                $urls->push([
                    'loc' => $row->canonical_url ?: $this->absoluteUrl($this->pagePath($row->content_type, $row->slug)),
                    'lastmod' => $row->updated_at,
                ]);
            });

        return response()->view('public-content.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots()
    {
        $sitemapUrl = $this->absoluteUrl('/sitemap.xml');

        return response("User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api/\nSitemap: {$sitemapUrl}\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function media(string $publicId): StreamedResponse
    {
        $media = DB::table('content_media')
            ->join('content_pages', 'content_pages.og_image_id', '=', 'content_media.id')
            ->where('content_media.public_id', $publicId)
            ->where('content_pages.status', 'published')
            ->whereNull('content_pages.deleted_at')
            ->whereNull('content_pages.scheduled_at')
            ->whereNotNull('content_pages.published_at')
            ->where('content_pages.published_at', '<=', now())
            ->select('content_media.*')
            ->first();
        abort_unless($media && Storage::disk('local')->exists($media->file_path), 404);

        return Storage::disk('local')->response($media->file_path, $media->original_name, [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function redirectOrSpa(Request $request, string $path)
    {
        $source = '/'.trim($path, '/');
        $redirect = DB::table('content_redirects')
            ->where('source_path', $source)
            ->where('is_active', true)
            ->first();
        if ($redirect) {
            return redirect($redirect->target_path, (int) $redirect->status_code);
        }

        return view('app');
    }

    private function pagePath(string $type, string $slug): string
    {
        return '/'.($type === 'article' ? 'articles' : 'pages').'/'.$slug;
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function activeRedirect(string $sourcePath): ?object
    {
        $redirect = DB::table('content_redirects')->where('source_path', $sourcePath)
            ->where('is_active', true)->first(['target_path', 'status_code']);

        return $redirect;
    }
}
