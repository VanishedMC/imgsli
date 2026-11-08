<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\User;
use App\Services\RemoteImageFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AlbumController extends Controller
{
    /**
     * The compare tool (drop/pick or URL boxes + live slider + save).
     */
    public function compare(Request $request): View
    {
        $mine = collect();

        if ($request->user()) {
            $mine = Album::where('user_id', $request->user()->id)->latest()->get();
        }

        return view('compare', [
            'mine' => $mine,
            'public' => Album::where('visibility', Album::VISIBILITY_PUBLIC)->latest()->limit(12)->get(),
            // The client-side save checks mirror the server caps; admins get
            // the higher effective ones (see effectiveMaxImageBytes() /
            // effectiveMaxImages()).
            'maxImageBytes' => $this->effectiveMaxImageBytes($request->user()),
            'maxImages' => $this->effectiveMaxImages($request->user()),
        ]);
    }

    /**
     * The effective per-image size cap (bytes) for a user: the regular cap,
     * or for admins the higher of the regular and admin caps (bypass).
     */
    private function effectiveMaxImageBytes(?User $user): int
    {
        $regular = (int) config('uploads.max_image_bytes');

        return $user?->is_admin
            ? max($regular, (int) config('uploads.admin_max_image_bytes'))
            : $regular;
    }

    /**
     * The effective per-album image count for a user: the regular limit, or
     * for admins the higher of the regular and admin limits (bypass).
     */
    private function effectiveMaxImages(?User $user): int
    {
        $regular = (int) config('uploads.max_images');

        return $user?->is_admin
            ? max($regular, (int) config('uploads.admin_max_images'))
            : $regular;
    }

    /**
     * Album viewer: /a/{album} or /a/{album}/{before}/{after} (initial picker
     * positions, mirroring the original site's URL shape).
     */
    public function show(Album $album, ?int $before = null, ?int $after = null): View
    {
        abort_unless($album->isViewableBy(auth()->user()), 404);

        $count = count($album->images);
        $clamp = fn (?int $i, int $default) => $i === null
            ? $default
            : min(max($i, 0), $count - 1);

        return view('album', [
            'album' => $album,
            'urls' => $album->imageUrls(),
            'before' => $clamp($before, 0),
            'after' => $clamp($after, max($count - 1, 1)),
        ]);
    }

    /**
     * The user's albums (public + private) with delete buttons.
     */
    public function mine(Request $request): View
    {
        $albums = Album::where('user_id', $request->user()->id)->latest()->get();

        return view('mine', ['albums' => $albums]);
    }

    /**
     * API: create an album from a mixed file/URL list.
     */
    public function store(Request $request, RemoteImageFetcher $fetcher): JsonResponse
    {
        $minImages = (int) config('uploads.min_images');

        // Admins bypass the regular per-image and per-album caps: their
        // effective values are max(regular, admin) so a lower admin value is
        // a no-op. The same max() feeds the entrypoint's transport caps
        // (config/uploads.php).
        $user = $request->user();
        $maxImages = $this->effectiveMaxImages($user);
        $maxBytes = $this->effectiveMaxImageBytes($user);
        $maxKb = intdiv($maxBytes, 1024);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'visibility' => ['sometimes', Rule::in(Album::VISIBILITIES)],
            'images' => ['required', 'array', 'min:'.$minImages, 'max:'.$maxImages],
            'images.*' => ['array'],
            'images.*.file' => ['nullable', 'file', 'image:png,jpeg,gif,webp', 'max:'.$maxKb],
            'images.*.url' => ['nullable', 'string', 'max:'.(int) config('uploads.max_url_length')],
            'images.*.description' => ['nullable', 'string', 'max:200'],
        ], [
            'images.min' => "Add at least {$minImages} images.",
            'images.max' => "Add at most {$maxImages} images.",
            'images.*.file' => 'Image #:position must be a PNG, JPEG, GIF or WebP file.',
            'images.*.file.max' => 'Image #:position is too large (max '.RemoteImageFetcher::humanBytesFor($maxBytes).').',
        ]);

        $images = [];
        $dir = 'albums/'.$request->user()->id;
        $disk = Storage::disk('public');
        $written = [];
        // Hard bound on the whole request: slow/dead hosts can pin a php-fpm
        // worker for a long time, so the URL fetches share a total deadline
        // (config: uploads.store_deadline, seconds).
        $deadline = microtime(true) + (int) config('uploads.store_deadline');

        // Any failure before the album row is committed (a rejected URL, a
        // late validation error, even the DB write) leaves no orphans on
        // the public disk.
        try {
            foreach ($validated['images'] as $i => $entry) {
                $hasFile = isset($entry['file']);
                $hasUrl = ! empty($entry['url'] ?? null);

                if ($hasFile === $hasUrl) {
                    throw ValidationException::withMessages([
                        'images' => 'Each image needs exactly one source: a file or a URL.',
                    ]);
                }

                if ($hasUrl) {
                    // null = the fetcher's own cap (config); admins get the
                    // higher effective cap so oversized URL images pass.
                    [$bytes, $extension] = $fetcher->fetch(
                        $entry['url'],
                        $deadline,
                        $user->is_admin ? $maxBytes : null,
                    );
                } else {
                    /** @var UploadedFile $file */
                    $file = $entry['file'];
                    $extension = $file->guessExtension();
                    $bytes = file_get_contents($file->getRealPath());
                    if ($extension === 'bin') {
                        $extension = 'jpg';
                    }
                }

                $name = bin2hex(random_bytes(16)).'.'.$extension;
                $disk->put($dir.'/'.$name, $bytes);
                $written[] = $dir.'/'.$name;

                $images[] = [
                    'key' => $dir.'/'.$name,
                    'description' => trim($entry['description'] ?? ''),
                ];
            }
            $album = Album::create([
                'user_id' => $request->user()->id,
                'title' => $validated['title'] ?? null,
                'visibility' => $validated['visibility'] ?? Album::VISIBILITY_PUBLIC,
                'images' => $images,
            ]);
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }

        return response()->json(['id' => $album->id, 'url' => route('album', $album)], 201);
    }

    /**
     * Update: PATCH /api/albums/{album} with {visibility: "public" |
     * "unlisted" | "private"} and/or {title} (rename; null clears the title).
     */
    public function update(Request $request, Album $album): JsonResponse
    {
        $user = $request->user();
        // Owners manage their albums; admins manage all of them. 404 (not
        // 403) so album existence stays hidden from regular strangers.
        abort_unless($album->user_id === $user->id || $user->is_admin, 404);

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'visibility' => ['sometimes', Rule::in(Album::VISIBILITIES)],
        ]);

        abort_if($validated === [], 422);

        $album->update($validated);

        return response()->json([
            'id' => $album->id,
            'title' => $album->title,
            'visibility' => $album->visibility,
            'url' => route('album', $album),
        ]);
    }

    /**
     * API: delete an album and its stored files.
     */
    public function destroy(Request $request, Album $album): Response
    {
        $user = $request->user();
        // Owners manage their albums; admins manage all of them.
        abort_unless($album->user_id === $user->id || $user->is_admin, 404);

        $disk = Storage::disk('public');
        $disk->delete(array_column($album->images, 'key'));
        $album->delete();

        return response()->noContent();
    }
}
