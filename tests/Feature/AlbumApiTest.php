<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use App\Services\RemoteImageFetcher;
use Illuminate\Contracts\Filesystem\Filesystem;
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
                'visibility' => 'public',
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

    public function test_visibility_is_stored_on_create(): void
    {
        $images = [
            ['file' => UploadedFile::fake()->image('a.png')],
            ['file' => UploadedFile::fake()->image('b.png')],
        ];

        // Explicit choice is honoured...
        $response = $this->postAlbums($images, ['visibility' => 'unlisted']);
        $response->assertStatus(201);
        $this->assertSame('unlisted', Album::findOrFail($response->json('id'))->visibility);

        // ...and the default (no field) is public.
        $response = $this->postAlbums($images);
        $response->assertStatus(201);
        $this->assertSame('public', Album::findOrFail($response->json('id'))->visibility);

        // Anything else is rejected.
        $this->postAlbums($images, ['visibility' => 'secret'])->assertStatus(422);
    }

    public function test_single_image_rejected(): void
    {
        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_images_must_be_a_list(): void
    {
        foreach ([
            ['before' => ['file' => UploadedFile::fake()->image('a.png')], 'after' => ['file' => UploadedFile::fake()->image('b.png')]],
            [0 => ['file' => UploadedFile::fake()->image('a.png')], 2 => ['file' => UploadedFile::fake()->image('b.png')]],
        ] as $images) {
            $this->postAlbums($images)->assertUnprocessable()->assertJsonValidationErrors(['images']);
        }

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
            'visibility' => 'public',
            'images' => [['key' => 'x', 'description' => '']],
        ]);
        $other = Album::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Other',
            'visibility' => 'public',
            'images' => [['key' => 'y', 'description' => '']],
        ]);

        $response = $this->actingAs($this->user)->get('/mine');

        $response->assertStatus(200);
        $response->assertSee('Mine');
        $response->assertDontSee('Other');
        $response->assertSee('publicity-toggle', false);
    }

    public function test_mine_is_paginated(): void
    {
        $perPage = $this->actingAs($this->user)->get('/mine')->viewData('albums')->perPage();
        for ($i = 1; $i <= $perPage + 1; $i++) {
            Album::forceCreate([
                'user_id' => $this->user->id,
                'title' => "Album {$i}",
                'visibility' => 'private',
                'images' => [['key' => "k{$i}", 'description' => '']],
                'created_at' => now()->subMinutes($perPage + 1 - $i),
            ]);
        }

        $first = $this->actingAs($this->user)->get('/mine');
        $first->assertOk()
            ->assertSee('class="pagination"', false)
            ->assertSee('page=2', false)
            ->assertSee('Album '.($perPage + 1).'<', false)
            ->assertDontSee('>Album 1<', false);

        $this->actingAs($this->user)->get('/mine?page=2')
            ->assertOk()
            ->assertSee('>Album 1<', false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($this->user)->get('/mine?page=9')
            ->assertRedirect('/mine?page=2');
    }

    public function test_mine_has_no_pagination_for_a_single_page(): void
    {
        Album::create([
            'user_id' => $this->user->id,
            'title' => 'Only',
            'visibility' => 'public',
            'images' => [['key' => 'x', 'description' => '']],
        ]);

        $this->actingAs($this->user)->get('/mine')
            ->assertOk()
            ->assertSee('Only')
            ->assertDontSee('class="pagination"', false);
    }

    public function test_owner_deletes_album_and_files(): void
    {
        $keys = [];
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'ToDelete',
            'visibility' => 'public',
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
            'visibility' => 'public',
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
            'visibility' => 'private',
            'images' => [],
        ]);
        $stranger = User::factory()->create();

        // private: non-owner cannot view
        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(404);

        // private -> public: non-owner can view
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['visibility' => 'public'])
            ->assertStatus(200)
            ->assertJsonPath('visibility', 'public');

        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(200);

        // public -> unlisted: still reachable by URL, just not listed
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['visibility' => 'unlisted'])
            ->assertStatus(200)
            ->assertJsonPath('visibility', 'unlisted');

        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(200);

        // unlisted -> private: non-owner loses access
        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['visibility' => 'private'])
            ->assertStatus(200)
            ->assertJsonPath('visibility', 'private');

        $this->actingAs($stranger)->get(route('album', $album))->assertStatus(404);
        $this->assertDatabaseHas('albums', ['id' => $album->id, 'visibility' => 'private']);
    }

    public function test_owner_renames_album(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Before',
            'visibility' => 'private',
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
            ->patchJson(route('albums.update', $album), ['title' => 'Both', 'visibility' => 'public'])
            ->assertStatus(200)
            ->assertJsonPath('title', 'Both')
            ->assertJsonPath('visibility', 'public');

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
            'visibility' => 'public',
            'images' => [],
        ]);

        $this->actingAs(User::factory()->create())
            ->patchJson(route('albums.update', $album), ['visibility' => 'private'])
            ->assertStatus(404);

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'visibility' => 'public']);
    }

    public function test_update_requires_valid_visibility(): void
    {
        $album = Album::create([
            'user_id' => $this->user->id,
            'title' => 'Toggle',
            'visibility' => 'private',
            'images' => [],
        ]);

        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), [])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->patchJson(route('albums.update', $album), ['visibility' => 'secret'])
            ->assertStatus(422);

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'visibility' => 'private']);
    }

    /** A real PNG of the given size (GD), for checks that read the header. */
    private function pngBytes(int $width = 8, int $height = 8): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 80, 160));
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    public function test_oversized_dimensions_are_rejected(): void
    {
        // 1 px tall, but wider than the cap: a small file that would still
        // decode to a huge bitmap in a viewer.
        $maxSide = (int) config('uploads.max_image_side');

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('normal.png')],
            ['file' => UploadedFile::fake()->image('wide.png', $maxSide + 1, 1)],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_url_image_with_oversized_dimensions_is_rejected(): void
    {
        $maxSide = (int) config('uploads.max_image_side');
        Http::fake([
            'example.com/wide.png' => Http::response($this->pngBytes($maxSide + 1, 1), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['url' => 'https://example.com/wide.png'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_failed_disk_write_fails_the_request_and_leaves_no_album(): void
    {
        // The disk raises (throw=true): the request must fail instead of
        // committing an album that points at files nothing wrote.
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(new \RuntimeException('disk full'));
        $disk->shouldReceive('delete')->andReturnTrue();
        Storage::set('public', $disk);

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['file' => UploadedFile::fake()->image('b.png')],
        ])->assertStatus(500);

        $this->assertDatabaseCount('albums', 0);
    }

    public function test_malformed_redirect_is_rejected_without_leaving_files(): void
    {
        Http::fake([
            'example.com/image' => Http::response('', 302, ['Location' => '//[invalid']),
        ]);

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['url' => 'https://example.com/image'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['images']);

        $this->assertDatabaseCount('albums', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_relative_redirect_resolves_against_the_requested_url(): void
    {
        // Location: img.png from /a/b.png must become /a/img.png, not /img.png.
        Http::fake([
            'example.com/a/b.png' => Http::response('', 302, ['Location' => 'img.png']),
            'example.com/a/img.png' => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postAlbums([
            ['file' => UploadedFile::fake()->image('a.png')],
            ['url' => 'https://example.com/a/b.png'],
        ])->assertStatus(201);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.com/a/img.png');
    }
}
