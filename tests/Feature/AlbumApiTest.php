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
use Tests\TestCase;

class AlbumApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RemoteImageFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create();
        // The store route is throttled: reset the rate limiter per test.
        Cache::flush();
        // Deterministic DNS for URL fetches (public IP for any hostname);
        // tests needing different resolution set $this->fetcher->resolver.
        $this->fetcher = new RemoteImageFetcher;
        $this->fetcher->resolver = fn (string $host) => ['93.184.216.34'];
        $this->app->instance(RemoteImageFetcher::class, $this->fetcher);
    }

    private function postAlbums(array $images, array $extra = [])
    {
        return $this->actingAs($this->user)
            ->postJson('/api/albums', array_merge([
                'title' => 'Test Album',
                'is_public' => true,
            ], $extra, ['images' => $images]), [
                'Accept' => 'application/json',
            ]);
    }

    public function test_anonymous_cannot_create_album(): void
    {
        $response = $this->postJson('/api/albums', [
            'images' => [
                ['file' => UploadedFile::fake()->image('a.png')],
                ['file' => UploadedFile::fake()->image('b.png')],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
        $this->assertDatabaseCount('albums', 0);
    }

    public function test_two_file_images_create_album(): void
    {
        $files = [
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.jpg'),
        ];

        $response = $this->postAlbums([
            ['file' => $files[0], 'description' => 'Before'],
            ['file' => $files[1], 'description' => 'After'],
        ]);

        $response->assertStatus(201);

        /** @var Album $album */
        $album = Album::findOrFail($response->json('id'));
        $response->assertJsonPath('id', $album->id);
        $response->assertJsonPath('url', route('album', $album));

        $this->assertCount(2, $album->images);
        $this->assertSame('Before', $album->images[0]['description']);
        $this->assertSame('After', $album->images[1]['description']);

        foreach ($album->images as $image) {
            Storage::disk('public')->assertExists($image['key']);
        }
    }

    public function test_single_image_rejected(): void
    {
        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_nine_images_rejected(): void
    {
        $images = [];
        for ($i = 0; $i < 9; $i++) {
            $images[] = ['file' => UploadedFile::fake()->image("img{$i}.png")];
        }

        $this->postAlbums($images)->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_non_image_rejected(): void
    {
        $file = UploadedFile::fake()->create('note.txt', 10, 'text/plain');

        $this->postAlbums([
            ['file' => $file],
            ['file' => UploadedFile::fake()->image('b.png')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_oversize_rejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($tmp, str_repeat('x', 11 * 1024 * 1024));
        $big = new UploadedFile($tmp, 'big.jpg', 'image/jpeg', null, true);

        $this->postAlbums([
            ['file' => $big],
            ['file' => UploadedFile::fake()->image('b.png')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_url_image_is_fetched_and_stored(): void
    {
        $img = imagecreatetruecolor(40, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        Http::fake([
            'example.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $response = $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png'), 'description' => 'Local'],
            ['url' => 'https://example.com/photo.jpg', 'description' => 'Remote'],
        ]);

        $response->assertStatus(201);

        $album = Album::first();
        $this->assertCount(2, $album->images);
        Storage::disk('public')->assertExists($album->images[1]['key']);
    }

    public function test_both_sources_on_one_index_rejected(): void
    {
        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png'), 'url' => 'https://example.com/x.jpg'],
            ['file' => UploadedFile::fake()->image('b.png')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_private_urls_rejected(): void
    {
        foreach (['http://127.0.0.1/x.jpg', 'http://192.168.1.10/x.jpg', 'file:///etc/passwd'] as $url) {
            Http::preventStrayRequests();

            $this->postAlbums([
                ['file' => UploadedFile::fake()->image('a.png')],
                ['url' => $url],
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('albums', 0);
        Http::assertNothingSent();
    }

    public function test_redirect_to_private_ip_rejected(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/x.jpg']),
        ]);

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['url' => 'https://example.com/a.jpg'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_connection_is_pinned_to_resolved_public_ip(): void
    {
        $img = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        Http::fake([
            'example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['url' => 'https://example.com/photo.png'],
        ]);

        $response->assertStatus(201);
        // The transport was pinned (CURLOPT_RESOLVE) to the address that was
        // validated, so a second DNS lookup cannot reach a different host.
        $this->assertSame('example.com:443:93.184.216.34', $this->fetcher->lastResolve);
    }

    public function test_dns_resolved_private_ip_rejected(): void
    {
        // A public-looking hostname that resolves to a loopback address
        // (the DNS-rebinding case) must be rejected before any request.
        Http::preventStrayRequests();
        $this->fetcher->resolver = fn (string $host) => ['127.0.0.1'];

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['url' => 'https://rebind.example/x.jpg'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
        Http::assertNothingSent();
    }

    public function test_mine_lists_only_own_albums(): void
    {
        $mine = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Mine',
            'is_public' => true,
            'images' => [['key' => 'x', 'description' => '']],
        ]);
        $other = Album::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Other',
            'is_public' => true,
            'images' => [['key' => 'y', 'description' => '']],
        ]);

        $response = $this->actingAs($this->user)->get('/mine');

        $response->assertStatus(200);
        $response->assertSee('Mine');
        $response->assertDontSee('Other');
        $response->assertSee('publicity-toggle', false);
    }

    public function test_owner_deletes_album_and_files(): void
    {
        $keys = [];
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'ToDelete',
            'is_public' => true,
            'images' => [],
        ]);
        foreach (['a.png', 'b.png'] as $i => $name) {
            Storage::disk('public')->put($name, 'x');
            $keys[] = $name;
        }
        $album->forceFill(['images' => array_map(fn ($k) => ['key' => $k, 'description' => ''], $keys)])->save();

        $this->actingAs($this->user)
            ->deleteJson(route('albums.destroy', $album))
            ->assertStatus(204);

        $this->assertDatabaseMissing('albums', ['id' => $album->id]);
        foreach ($keys as $key) {
            Storage::disk('public')->assertMissing($key);
        }
    }

    public function test_non_owner_cannot_delete(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Mine',
            'is_public' => true,
            'images' => [],
        ]);

        $this->actingAs(User::factory()->create())
            ->deleteJson(route('albums.destroy', $album))
            ->assertStatus(404);

        $this->assertDatabaseHas('albums', ['id' => $album->id]);
    }

    public function test_owner_toggles_visibility(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Toggle',
            'is_public' => false,
            'images' => [],
        ]);
        $stranger = User::factory()->create();

        // private: non-owner cannot view
        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(404);

        // private -> public: non-owner can view
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['is_public' => true])
            ->assertStatus(200)
            ->assertJsonPath('is_public', true);

        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(200);

        // public -> private: non-owner loses access
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['is_public' => false])
            ->assertStatus(200)
            ->assertJsonPath('is_public', false);

        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(404);
        $this->assertDatabaseHas('albums', ['id' => $album->id, 'is_public' => false]);
    }

    public function test_owner_renames_album(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Before',
            'is_public' => false,
            'images' => [],
        ]);
        $stranger = User::factory()->create();

        // rename
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['title' => 'After'])
            ->assertStatus(200)
            ->assertJsonPath('title', 'After');

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'title' => 'After']);

        // rename + visibility in one patch
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['title' => 'Both', 'is_public' => true])
            ->assertStatus(200)
            ->assertJsonPath('title', 'Both')
            ->assertJsonPath('is_public', true);

        // clearing the title
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['title' => null])
            ->assertStatus(200)
            ->assertJsonPath('title', null);

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'title' => null]);

        // non-owner cannot rename
        $this->actingAs($stranger)
            ->patchJson(route('albums.update', $album), ['title' => 'Hijack'])
            ->assertStatus(404);

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'title' => null]);
    }

    public function test_non_owner_cannot_toggle_visibility(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Toggle',
            'is_public' => true,
            'images' => [],
        ]);

        $this->actingAs(User::factory()->create())
            ->patchJson(route('albums.update', $album), ['is_public' => false])
            ->assertStatus(404);

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'is_public' => true]);
    }

    public function test_update_requires_boolean(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Toggle',
            'is_public' => false,
            'images' => [],
        ]);

        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), [])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['is_public' => 'not-a-bool'])
            ->assertStatus(422);

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'is_public' => false]);
    }
}
