<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ContentAdminController extends Controller
{
    public function redirects(Request $request): JsonResponse
    {
        $this->authorizeContentManager($request);
        $redirects = DB::table('content_redirects')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json(['success' => true, 'data' => $redirects]);
    }

    public function storeRedirect(Request $request): JsonResponse
    {
        $actor = $this->authorizeContentManager($request);
        $data = $this->validateRedirect($request, true);
        $reason = $data['reason'];
        unset($data['reason']);
        $now = now();
        $id = DB::transaction(function () use ($data, $reason, $actor, $request, $now): int {
            $id = DB::table('content_redirects')->insertGetId($data + [
                'created_by_user_id' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($request, $actor->id, 'admin.content.redirect_created', $id, null,
                (array) DB::table('content_redirects')->where('id', $id)->first(), $reason);

            return $id;
        });

        return response()->json([
            'success' => true,
            'data' => DB::table('content_redirects')->where('id', $id)->first(),
        ], 201);
    }

    public function updateRedirect(Request $request, int $id): JsonResponse
    {
        $actor = $this->authorizeContentManager($request);
        $data = $this->validateRedirect($request, false, $id);
        $reason = $data['reason'];
        unset($data['reason']);
        $redirect = DB::transaction(function () use ($data, $reason, $actor, $request, $id) {
            $old = DB::table('content_redirects')->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404, 'مسیر بازنویسی پیدا نشد.');
            $data['updated_at'] = now();
            DB::table('content_redirects')->where('id', $id)->update($data);
            $new = DB::table('content_redirects')->where('id', $id)->first();
            $this->audit($request, $actor->id, 'admin.content.redirect_updated', $id, (array) $old, (array) $new, $reason);

            return $new;
        });

        return response()->json(['success' => true, 'data' => $redirect]);
    }

    public function deleteRedirect(Request $request, int $id): JsonResponse
    {
        $actor = $this->authorizeContentManager($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:1000']]);
        DB::transaction(function () use ($request, $actor, $id, $data): void {
            $old = DB::table('content_redirects')->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404, 'مسیر بازنویسی پیدا نشد.');
            DB::table('content_redirects')->where('id', $id)->delete();
            $this->audit($request, $actor->id, 'admin.content.redirect_deleted', $id, (array) $old, null, $data['reason']);
        });

        return response()->json(['success' => true]);
    }

    public function mediaIndex(Request $request): JsonResponse
    {
        $this->authorizeContentManager($request);
        $media = DB::table('content_media')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $query->where('original_name', 'like', '%'.$request->string('q')->toString().'%');
            })
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100), [
                'id', 'public_id', 'original_name', 'mime_type', 'size_bytes', 'width', 'height', 'created_at',
            ]);

        return response()->json(['success' => true, 'data' => $media]);
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $actor = $this->authorizeContentManager($request);
        $data = $request->validate([
            'image' => [
                'required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120',
                'dimensions:max_width=4000,max_height=4000',
            ],
        ]);
        /** @var UploadedFile $image */
        $image = $data['image'];
        $dimensions = getimagesize($image->getRealPath());
        abort_unless(is_array($dimensions), 422, 'ابعاد تصویر قابل خواندن نیست.');
        $publicId = (string) Str::uuid();
        $extension = match ($image->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => abort(422, 'قالب تصویر پشتیبانی نمی‌شود.'),
        };
        $path = $image->storeAs('content-media', $publicId.'.'.$extension, 'local');

        $mediaId = DB::table('content_media')->insertGetId([
            'public_id' => $publicId,
            'original_name' => Str::limit(basename($image->getClientOriginalName()), 180, ''),
            'file_path' => $path,
            'mime_type' => $image->getMimeType(),
            'size_bytes' => $image->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'uploaded_by_user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'user_id' => $actor->id,
            'action' => 'admin.content.media_uploaded',
            'auditable_type' => 'content_media',
            'auditable_id' => $mediaId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => json_encode([
                'public_id' => $publicId,
                'mime_type' => $image->getMimeType(),
                'size_bytes' => $image->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
            ], JSON_THROW_ON_ERROR),
            'reason' => 'بارگذاری رسانه در پنل مدیریت محتوا',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $mediaId,
                'public_id' => $publicId,
                'mime_type' => $image->getMimeType(),
                'size_bytes' => $image->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'public_url' => '/content-media/'.$publicId,
            ],
        ], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeContentManager($request);
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'title' => ['required', 'string', 'max:200'],
            'content_type' => ['required', Rule::in(['page', 'article'])],
            'body' => ['required', 'string', 'max:50000'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'og_image_id' => ['nullable', 'integer', 'exists:content_media,id'],
        ]);
        $canonical = ($data['canonical_url'] ?? null)
            ?: $this->absoluteUrl($this->pagePath($data['content_type'], $data['slug']));
        $image = isset($data['og_image_id'])
            ? DB::table('content_media')->where('id', $data['og_image_id'])->first()
            : null;
        $previewImage = $image
            ? 'data:'.$image->mime_type.';base64,'.base64_encode(Storage::disk('local')->get($image->file_path))
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'html' => view('public-content.show', [
                    'title' => ($data['meta_title'] ?? null) ?: $data['title'],
                    'description' => ($data['meta_description'] ?? null) ?: ($data['excerpt'] ?? null),
                    'canonical' => $canonical,
                    'ogImage' => $previewImage,
                    'ogImageWidth' => $image?->width,
                    'ogImageHeight' => $image?->height,
                    'contentTitle' => $data['title'],
                    'excerpt' => $data['excerpt'] ?? null,
                    'body' => $data['body'],
                    'publishedAt' => null,
                    'structuredData' => $data['content_type'] === 'article' ? [
                        '@context' => 'https://schema.org',
                        '@type' => 'Article',
                        'headline' => $data['title'],
                        'url' => $canonical,
                    ] : null,
                ])->render(),
            ],
        ]);
    }

    private function authorizeContentManager(Request $request): object
    {
        $actor = $request->user();
        abort_unless($actor, 401, 'احراز هویت الزامی است.');
        $allowed = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_id', $actor->id)
            ->where('permissions.name', 'admin.content.manage')
            ->exists();
        abort_unless(DB::table('users')->where('id', $actor->id)->where('status', 'active')->exists(), 403,
            'حساب کاربری فعال نیست.');
        abort_unless($allowed, 403, 'مجوز مدیریت محتوا را ندارید.');

        return $actor;
    }

    private function validateRedirect(Request $request, bool $creating, ?int $id = null): array
    {
        $data = $request->validate([
            'source_path' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:220',
                'regex:/^\/(?!\/)[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/',
                $creating ? Rule::unique('content_redirects', 'source_path')
                    : Rule::unique('content_redirects', 'source_path')->ignore($id),
            ],
            'target_path' => [
                $creating ? 'required' : 'sometimes', 'string', 'max:220',
                'regex:/^\/(?!\/)[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/',
            ],
            'status_code' => ['sometimes', 'integer', Rule::in([301, 302])],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ]);
        $old = $id ? DB::table('content_redirects')->where('id', $id)->first() : null;
        $source = $data['source_path'] ?? $old?->source_path;
        $target = $data['target_path'] ?? $old?->target_path;
        if ($source && $target) {
            $seen = [$source => true];
            $current = $target;
            for ($depth = 0; $depth < 20; $depth++) {
                if (isset($seen[$current])) {
                    throw ValidationException::withMessages([
                        'target_path' => 'این مقصد باعث حلقه در زنجیرهٔ تغییر مسیر می‌شود.',
                    ]);
                }
                $seen[$current] = true;
                $next = DB::table('content_redirects')
                    ->where('source_path', $current)
                    ->where('is_active', true)
                    ->when($id, fn ($query) => $query->where('id', '<>', $id))
                    ->value('target_path');
                if (! $next) {
                    break;
                }
                $current = $next;
            }
        }

        return $data;
    }

    private function audit(Request $request, int $actorId, string $action, int $id, ?array $old, ?array $new, string $reason): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actorId,
            'action' => $action,
            'auditable_type' => 'content_redirect',
            'auditable_id' => $id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
            'new_values' => $new ? json_encode($new, JSON_THROW_ON_ERROR) : null,
            'reason' => $reason,
            'request_id' => $request->header('X-Request-Id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function pagePath(string $type, string $slug): string
    {
        return '/'.($type === 'article' ? 'articles' : 'pages').'/'.$slug;
    }
}
