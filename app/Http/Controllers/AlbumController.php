<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\User;
use App\Services\RemoteImageFetcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class AlbumController extends Controller
{
    /** Albums per page on /mine (matches the admin dashboard). */
    private const MINE_PER_PAGE = 50;

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
     * Writes one album image and returns its storage key.
     *
     * Uploaded files are streamed to the disk (an admin may upload 50 MB per
     * image); bytes fetched from a URL are already in memory and are written
     * directly. Both are checked for pixel dimensions first: a small file can
     * declare an enormous bitmap and exhaust every viewer's browser.
     *
     * @param  string  $position  human-facing index for the error message
     */
    private function storeImage(Filesystem $disk, string $dir, string $extension, string|UploadedFile $source, int $position): string
    {
        $maxSide = (int) config('uploads.max_image_side');
        $name = bin2hex(random_bytes(16)).'.'.$extension;
        $key = $dir.'/'.$name;

        if ($source instanceof UploadedFile) {
            // getimagesize() reads the header only, so this stays cheap.
            $this->assertImageDimensions(@getimagesize($source->getRealPath()), $maxSide, $position);
            $disk->putFileAs($dir, $source, $name);
        } else {
            $this->assertImageDimensions(@getimagesizefromstring($source), $maxSide, $position);
            $disk->put($key, $source);
        }

        return $key;
    }

    /**
     * @param  array<int, int>|false  $size  as returned by getimagesize()
     */
    private function assertImageDimensions(array|false $size, int $maxSide, int $position): void
    {
        $message = match (true) {
            $size === false => "Image #{$position} could not be read as an image.",
            $size[0] > $maxSide || $size[1] > $maxSide => "Image #{$position} is too large (max {$maxSide} px per side).",
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['images' => $message]);
        }
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

        $urls = $album->imageUrls();

        return view('album', [
            'album' => $album,
            'urls' => $urls,
            // The image editor works on the stored entries (key + display
            // URL + caption) so keeping an image round-trips its key.
            'editImages' => collect($album->images)->values()->map(fn (array $image, int $i) => [
                'key' => $image['key'],
                'url' => $urls[$i]['url'],
                'description' => $image['description'] ?? '',
            ])->all(),
            'maxImages' => $this->effectiveMaxImages(auth()->user()),
            'before' => $clamp($before, 0),
            'after' => $clamp($after, max($count - 1, 1)),
        ]);
    }

    /**
     * The user's albums (public + private) with delete buttons, paginated.
     */
    public function mine(Request $request): View|RedirectResponse
    {
        $albums = Album::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(self::MINE_PER_PAGE)
            ->onEachSide(1)
            ->withQueryString();

        // Past the end (e.g. js/mine.js reloads after the last row on the final
        // page was deleted): go to the last page rather than an empty list.
        if ($albums->isEmpty() && $albums->currentPage() > 1 && $albums->total() > 0) {
            return redirect($albums->url($albums->lastPage()));
        }

        return view('mine', ['albums' => $albums]);
    }

    /**
     * Zip of all the user's albums (every page, every visibility): one folder
     * per album, images numbered in album order and named after their caption.
     *
     * Images are copied to a temp dir and added by path rather than from
     * strings: ZipArchive buffers string entries in memory until close(),
     * and an album may hold many 50 MB admin images (or live on S3).
     */
    public function download(Request $request): BinaryFileResponse
    {
        $albums = Album::where('user_id', $request->user()->id)->oldest()->get();
        abort_if($albums->isEmpty(), 404);

        set_time_limit(0);

        $disk = Storage::disk('public');
        $workDir = sys_get_temp_dir().'/imgsli-zip-'.bin2hex(random_bytes(8));
        mkdir($workDir, 0700);
        $zipPath = $workDir.'.zip';

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                abort(500, 'Could not create the archive.');
            }

            $usedFolders = [];
            $tmpCount = 0;
            foreach ($albums as $album) {
                $folder = $this->zipName($album->title ?? 'Untitled', 'Untitled')
                    .' ('.$album->created_at->format('Y-m-d').')';
                // Same title + same day: disambiguate with the album id.
                if (isset($usedFolders[mb_strtolower($folder)])) {
                    $folder .= ' '.$album->id;
                }
                $usedFolders[mb_strtolower($folder)] = true;
                $zip->addEmptyDir($folder);

                $pad = max(2, strlen((string) count($album->images)));
                foreach (array_values($album->images) as $i => $image) {
                    $stream = null;
                    try {
                        $stream = $disk->readStream($image['key']);
                    } catch (Throwable) {
                        // A missing file must not break the whole export.
                    }
                    if (! is_resource($stream)) {
                        continue;
                    }

                    $tmp = $workDir.'/'.(++$tmpCount);
                    $out = fopen($tmp, 'wb');
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                    fclose($stream);

                    $extension = pathinfo($image['key'], PATHINFO_EXTENSION);
                    $name = str_pad((string) ($i + 1), $pad, '0', STR_PAD_LEFT);
                    $description = trim($image['description'] ?? '');
                    if ($description !== '') {
                        $name .= ' - '.$this->zipName($description, '');
                    }
                    $entry = $folder.'/'.rtrim($name, ' -').($extension !== '' ? '.'.$extension : '');

                    $zip->addFile($tmp, $entry);
                    // Images are already compressed: store them as-is.
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                }
            }

            if (! $zip->close()) {
                abort(500, 'Could not create the archive.');
            }
        } catch (Throwable $e) {
            @unlink($zipPath);

            throw $e;
        } finally {
            foreach (glob($workDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }

        $filename = 'imgsli-albums-'.now()->format('Y-m-d').'.zip';

        return response()->download($zipPath, $filename, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * A user-supplied string made safe as a zip path segment.
     */
    private function zipName(string $value, string $fallback): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $value) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '', ' .');
        $clean = mb_substr($clean, 0, 80);

        return $clean === '' ? $fallback : $clean;
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
            'images' => ['required', 'array', 'list', 'min:'.$minImages, 'max:'.$maxImages],
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
                    if ($extension === 'bin') {
                        $extension = 'jpg';
                    }
                }

                $key = $this->storeImage($disk, $dir, $extension, $hasUrl ? $bytes : $file, $i + 1);
                $written[] = $key;

                $images[] = [
                    'key' => $key,
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
     * API: replace an album's image list (add and/or remove images, keeping
     * the rest). PUT /api/albums/{album}/images, multipart: each entry is
     * {key} for an existing image to keep, or {file} / {url} for a new one;
     * {description} updates the caption. Removed files are deleted only
     * after the album row has been updated.
     */
    public function updateImages(Request $request, Album $album, RemoteImageFetcher $fetcher): JsonResponse
    {
        $user = $request->user();
        // Owners manage their albums; admins manage all of them. 404 (not
        // 403) so album existence stays hidden from regular strangers.
        abort_unless($album->user_id === $user->id || $user->is_admin, 404);

        $minImages = (int) config('uploads.min_images');
        $maxImages = $this->effectiveMaxImages($user);
        $maxBytes = $this->effectiveMaxImageBytes($user);

        $validated = $request->validate([
            'images' => ['required', 'array', 'list', 'min:'.$minImages, 'max:'.$maxImages],
            'images.*' => ['array'],
            'images.*.key' => ['nullable', 'string', 'max:255'],
            'images.*.file' => ['nullable', 'file', 'image:png,jpeg,gif,webp', 'max:'.intdiv($maxBytes, 1024)],
            'images.*.url' => ['nullable', 'string', 'max:'.(int) config('uploads.max_url_length')],
            'images.*.description' => ['nullable', 'string', 'max:200'],
        ], [
            'images.min' => "Keep at least {$minImages} images.",
            'images.max' => "Keep at most {$maxImages} images.",
            'images.*.file' => 'Image #:position must be a PNG, JPEG, GIF or WebP file.',
            'images.*.file.max' => 'Image #:position is too large (max '.RemoteImageFetcher::humanBytesFor($maxBytes).').',
        ]);

        // Existing entries indexed by storage key: a submission may only
        // keep keys that are actually in this album.
        $existing = [];
        foreach ($album->images as $image) {
            $existing[$image['key']] = $image;
        }

        $images = [];
        $kept = [];
        $written = [];
        // New images live next to the album's existing ones, under the
        // owner's directory (an admin editing someone else's album included).
        $dir = 'albums/'.$album->user_id;
        $disk = Storage::disk('public');
        // Same total budget as store(): URL fetches share one deadline.
        $deadline = microtime(true) + (int) config('uploads.store_deadline');

        try {
            foreach ($validated['images'] as $i => $entry) {
                $key = $entry['key'] ?? null;
                $hasFile = isset($entry['file']);
                $hasUrl = ! empty($entry['url'] ?? null);
                $sources = (int) ($key !== null) + (int) $hasFile + (int) $hasUrl;

                if ($sources !== 1) {
                    throw ValidationException::withMessages([
                        'images' => 'Each image needs exactly one source: an existing key, a file or a URL.',
                    ]);
                }

                $description = array_key_exists('description', $entry)
                    ? trim((string) $entry['description'])
                    : null;

                if ($key !== null) {
                    if (! isset($existing[$key]) || isset($kept[$key])) {
                        throw ValidationException::withMessages([
                            'images' => 'An image in the list is not part of this album (or is listed twice).',
                        ]);
                    }

                    $kept[$key] = true;
                    $images[] = [
                        'key' => $key,
                        'description' => $description ?? ($existing[$key]['description'] ?? ''),
                    ];

                    continue;
                }

                if ($hasUrl) {
                    [$bytes, $extension] = $fetcher->fetch(
                        $entry['url'],
                        $deadline,
                        $user->is_admin ? $maxBytes : null,
                    );
                } else {
                    /** @var UploadedFile $file */
                    $file = $entry['file'];
                    $extension = $file->guessExtension();
                    if ($extension === 'bin') {
                        $extension = 'jpg';
                    }
                }

                $storedKey = $this->storeImage($disk, $dir, $extension, $hasUrl ? $bytes : $file, $i + 1);
                $written[] = $storedKey;

                $images[] = [
                    'key' => $storedKey,
                    'description' => $description ?? '',
                ];
            }

            $album->images = $images;
            $album->save();
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }

        // Drop the files of images that are no longer referenced.
        $disk->delete(array_keys(array_diff_key($existing, $kept)));

        return response()->json([
            'id' => $album->id,
            'images' => count($images),
            'url' => route('album', $album),
        ]);
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
