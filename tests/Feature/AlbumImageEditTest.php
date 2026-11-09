<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use App\Services\RemoteImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUT /api/albums/{album}/images replaces the image list: existing entries
 * are kept by key, new ones arrive as files or URLs, and removed images have
 * their stored files deleted. The album-page editor is covered at the end.
 */
class AlbumImageEditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true]);
        Cache::flush();
        $this->fetcher = new RemoteImageFetcher;
        $this->fetcher->resolver = fn (string $host) => ['93.184.216.34'];
        $this->app->instance(RemoteImageFetcher::class, $this->fetcher);
    }

    private RemoteImageFetcher $fetcher;

    private function makeAlbum(array $descriptions = ['Before', 'After']): Album
    {
        $images = [];
        $dir = 'albums/'.$this->owner->id.'/'.Str::random(8);

        foreach ($descriptions as $i => $description) {
            $key = "{$dir}/img{$i}.png";
            Storage::disk('public')->put($key, 'x');
            $images[] = ['key' => $key, 'description' => $description];
        }

        return Album::create([
            'user_id' => $this->owner->id,
            'title' => 'Edit me',
            'visibility' => 'public',
            'images' => $images,
        ]);
    }

    /** @return list<string> */
    private function keys(Album $album): array
    {
        return array_column($album->images, 'key');
    }

    private function putImages(Album $album, array $images, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->put("/api/albums/{$album->id}/images", ['images' => $images], [
                'Accept' => 'application/json',
            ]);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(32, 32);
        imagefill($img, 0, 0, imagecolorallocate($img, 60, 120, 200));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_owner_adds_a_file_image_and_keeps_the_existing_ones(): void
    {
        $album = $this->makeAlbum(['Before', 'After']);
        [$first, $second] = $this->keys($album);

        $response = $this->putImages($album, [
            ['key' => $first],
            ['key' => $second],
            ['file' => UploadedFile::fake()->image('third.png'), 'description' => 'Third'],
        ]);

        $response->assertOk()->assertJsonPath('images', 3);

        $images = $album->fresh()->images;
        $this->assertCount(3, $images);
        $this->assertSame([$first, $second], array_slice(array_column($images, 'key'), 0, 2));
        $this->assertSame('Before', $images[0]['description']);
        $this->assertSame('Third', $images[2]['description']);

        Storage::disk('public')->assertExists($first);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertExists($images[2]['key']);
    }

    public function test_owner_removes_an_image_and_its_file(): void
    {
        $album = $this->makeAlbum(['Before', 'After', 'Third']);
        [$first, $removed, $last] = $this->keys($album);

        $this->putImages($album, [
            ['key' => $first],
            ['key' => $last, 'description' => 'Third!'],
        ])->assertOk()->assertJsonPath('images', 2);

        $images = $album->fresh()->images;
        $this->assertSame([$first, $last], array_column($images, 'key'));
        $this->assertSame('Third!', $images[1]['description']);

        Storage::disk('public')->assertMissing($removed);
        Storage::disk('public')->assertExists($first);
        Storage::disk('public')->assertExists($last);
    }

    public function test_owner_adds_a_url_image(): void
    {
        Http::fake([
            'example.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);

        $album = $this->makeAlbum();
        [$first, $second] = $this->keys($album);

        $this->putImages($album, [
            ['key' => $first],
            ['key' => $second],
            ['url' => 'https://example.com/new.png', 'description' => 'Remote'],
        ])->assertOk()->assertJsonPath('images', 3);

        $images = $album->fresh()->images;
        $this->assertSame('Remote', $images[2]['description']);
        Storage::disk('public')->assertExists($images[2]['key']);
    }

    public function test_malformed_redirect_preserves_album_and_removes_new_files(): void
    {
        Http::fake([
            'example.com/image' => Http::response('', 302, ['Location' => '//[invalid']),
        ]);

        $album = $this->makeAlbum();
        $original = $album->images;
        [$first, $second] = $this->keys($album);

        $this->putImages($album, [
            ['key' => $first],
            ['file' => UploadedFile::fake()->image('new.png')],
            ['url' => 'https://example.com/image'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images']);

        $this->assertSame($original, $album->fresh()->images);
        $this->assertEqualsCanonicalizing([$first, $second], Storage::disk('public')->allFiles());
    }

    public function test_images_must_be_a_list(): void
    {
        $album = $this->makeAlbum();
        $original = $album->images;
        [$first, $second] = $this->keys($album);

        foreach ([
            ['before' => ['key' => $first], 'after' => ['file' => UploadedFile::fake()->image('b.png')]],
            [0 => ['key' => $first], 2 => ['key' => $second]],
        ] as $images) {
            $this->putImages($album, $images)->assertUnprocessable()->assertJsonValidationErrors(['images']);
        }

        $this->assertSame($original, $album->fresh()->images);
        Storage::disk('public')->assertExists($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_count_limits_are_enforced(): void
    {
        config(['uploads.max_images' => 3]);
        $album = $this->makeAlbum();
        [$first, $second] = $this->keys($album);
        Cache::flush();

        // Below the minimum.
        $response = $this->putImages($album, [['key' => $first]]);
        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->assertSame('Keep at least 2 images.', $response->json('errors.images.0'));

        // Above the regular max (2 kept + 2 new = 4 > 3).
        $response = $this->putImages($album, [
            ['key' => $first],
            ['key' => $second],
            ['file' => UploadedFile::fake()->image('a.png')],
            ['file' => UploadedFile::fake()->image('b.png')],
        ]);
        $response->assertStatus(422);
        $this->assertSame('Keep at most 3 images.', $response->json('errors.images.0'));
        $this->assertCount(2, $album->fresh()->images);

        // Admins get the higher count: the same four images are accepted.
        config(['uploads.max_images' => 2, 'uploads.admin_max_images' => 4]);
        $this->putImages($album, [
            ['key' => $first],
            ['key' => $second],
            ['file' => UploadedFile::fake()->image('c.png')],
            ['file' => UploadedFile::fake()->image('d.png')],
        ], $this->admin)->assertOk()->assertJsonPath('images', 4);
    }

    public function test_key_from_another_album_is_rejected(): void
    {
        $album = $this->makeAlbum();
        [$first, $second] = $this->keys($album);
        $other = $this->makeAlbum(['X', 'Y']);
        $foreign = $other->images[0]['key'];

        $response = $this->putImages($album, [
            ['key' => $foreign],
            ['key' => $second],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
        // Nothing was deleted from the album that owns the key.
        Storage::disk('public')->assertExists($foreign);
        $this->assertSame([$first, $second], $this->keys($album->fresh()));
    }

    public function test_entry_needs_exactly_one_source(): void
    {
        $album = $this->makeAlbum();
        [$first, $second] = $this->keys($album);

        // key + file
        $this->putImages($album, [
            ['key' => $first, 'file' => UploadedFile::fake()->image('x.png')],
            ['key' => $second],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);

        // no source at all
        $this->putImages($album, [
            ['description' => 'nothing'],
            ['key' => $second],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);

        // the same existing image twice
        $this->putImages($album, [
            ['key' => $first],
            ['key' => $first],
        ])->assertStatus(422)->assertJsonValidationErrors(['images']);

        // The album and its files are untouched.
        $this->assertSame([$first, $second], $this->keys($album->fresh()));
        Storage::disk('public')->assertExists($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_non_owner_cannot_edit_images(): void
    {
        $album = $this->makeAlbum();
        [$first, $second] = $this->keys($album);

        $this->putImages($album, [
            ['key' => $first],
            ['file' => UploadedFile::fake()->image('new.png')],
        ], User::factory()->create())->assertStatus(404);

        $this->assertSame([$first, $second], $this->keys($album->fresh()));
    }

    public function test_admin_can_edit_another_users_album(): void
    {
        $album = $this->makeAlbum();
        [$first, $second] = $this->keys($album);

        $this->putImages($album, [
            ['key' => $first],
            ['key' => $second],
            ['file' => UploadedFile::fake()->image('admin-add.png')],
        ], $this->admin)->assertOk()->assertJsonPath('images', 3);

        $newKey = $album->fresh()->images[2]['key'];
        // New files stay in the album owner's directory.
        $this->assertStringStartsWith('albums/'.$this->owner->id.'/', $newKey);
        Storage::disk('public')->assertExists($newKey);
    }

    public function test_album_page_shows_the_editor_to_owner_and_admin_only(): void
    {
        $album = $this->makeAlbum();

        $this->actingAs($this->owner)->get(route('album', $album))
            ->assertOk()
            ->assertSee('id="edit-images"', false)
            ->assertSee('id="album-edit"', false);

        $this->actingAs($this->admin)->get(route('album', $album))
            ->assertOk()
            ->assertSee('id="edit-images"', false);

        // Guard state persists across requests in feature tests.
        $this->app['auth']->guard()->logout();
        $this->get(route('album', $album))
            ->assertOk()
            ->assertDontSee('id="edit-images"', false)
            ->assertDontSee('id="album-edit"', false);
    }

    public function test_caption_can_be_cleared(): void
    {
        $album = $this->makeAlbum(['Before', 'After']);
        [$a, $b] = $this->keys($album);

        // An empty description is sent (not omitted) and must overwrite the
        // stored caption; omitting the field means "keep it".
        $this->putImages($album, [
            ['key' => $a, 'description' => ''],
            ['key' => $b, 'description' => 'After'],
        ])->assertOk();

        $fresh = $album->fresh();
        $this->assertSame('', $fresh->images[0]['description']);
        $this->assertSame('After', $fresh->images[1]['description']);
    }

    public function test_editor_request_with_method_spoofing_saves(): void
    {
        // What the browser actually sends: POST + _method=PUT, because PHP
        // does not parse multipart bodies for a real PUT.
        $album = $this->makeAlbum(['Before', 'After']);
        [$a, $b] = $this->keys($album);

        $this->actingAs($this->owner)
            ->post("/api/albums/{$album->id}/images", [
                '_method' => 'PUT',
                'images' => [
                    ['key' => $a, 'description' => 'New caption'],
                    ['key' => $b, 'description' => 'After'],
                ],
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('New caption', $album->fresh()->images[0]['description']);
    }
}
