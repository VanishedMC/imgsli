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

/**
 * Admins bypass the regular per-image size cap on both upload paths (file
 * picks and server-side URL fetches): their effective cap is
 * uploads.admin_max_image_bytes (the max() with the regular cap means a
 * lower admin value is a no-op). Regular users keep the regular cap.
 */
class AdminUploadBypassTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['is_admin' => false]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        // The store route is throttled: reset the rate limiter per test.
        Cache::flush();
        $fetcher = new RemoteImageFetcher;
        $fetcher->resolver = fn (string $host) => ['93.184.216.34'];
        $this->app->instance(RemoteImageFetcher::class, $fetcher);

        // Small caps keep the tests fast: regular 1 KB, admin 1 MB.
        config([
            'uploads.max_image_bytes' => 1024,
            'uploads.admin_max_image_bytes' => 1048576,
        ]);
    }

    /** A real, noisy PNG (~hundreds of KB): >1 KB and <1 MB. */
    private function noisyPng(): string
    {
        $img = imagecreatetruecolor(256, 256);
        for ($x = 0; $x < 256; $x++) {
            for ($y = 0; $y < 256; $y++) {
                imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img);
        $content = (string) ob_get_clean();
        imagedestroy($img);

        return $content;
    }

    /** A PNG larger than 1 MB: above even the admin cap. */
    private function bigPng(): string
    {
        $img = imagecreatetruecolor(1024, 1024);
        for ($x = 0; $x < 1024; $x++) {
            for ($y = 0; $y < 1024; $y++) {
                imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img);
        $content = (string) ob_get_clean();
        imagedestroy($img);

        return $content;
    }

    private function postAs(User $user, array $images)
    {
        return $this->actingAs($user)
            ->postJson('/api/albums', ['title' => 'T', 'visibility' => 'public', 'images' => $images], [
                'Accept' => 'application/json',
            ]);
    }

    public function test_admin_file_pick_bypasses_regular_cap(): void
    {
        $png = $this->noisyPng();
        $images = [
            ['file' => UploadedFile::fake()->createWithContent('a.png', $png, ['mime' => 'image/png'])],
            ['file' => UploadedFile::fake()->createWithContent('b.png', $png, ['mime' => 'image/png'])],
        ];

        // Regular user: rejected at the regular cap, message shows it.
        $response = $this->postAs($this->user, $images);
        $response->assertStatus(422)->assertJsonValidationErrors(['images.0.file', 'images.1.file']);
        // Error keys are flat literal strings ("images.0.file"), not nested.
        $errors = $response->json('errors');
        $this->assertSame('Image #1 is too large (max 1 KB).', $errors['images.0.file'][0]);

        // Admin: the same files pass.
        $response = $this->postAs($this->admin, $images);
        $response->assertStatus(201);
        Storage::disk('public')->assertExists(
            Album::firstOrFail()->images[0]['key']
        );
    }

    public function test_admin_album_image_count_bypasses_regular_limit(): void
    {
        config(['uploads.max_images' => 2, 'uploads.admin_max_images' => 3]);
        $png = $this->noisyPng();
        $images = array_map(
            fn (int $n) => ['file' => UploadedFile::fake()->createWithContent("i{$n}.png", $png, ['mime' => 'image/png'])],
            range(1, 4),
        );
        $three = array_slice($images, 0, 3);

        // Regular user: rejected above the regular count, dynamic message.
        $response = $this->postAs($this->user, $three);
        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->assertSame('Add at most 2 images.', $response->json('errors.images.0'));

        // Admin: the same three images pass.
        $this->postAs($this->admin, $three)->assertStatus(201);

        // Admin is still bounded by the admin count.
        $response = $this->postAs($this->admin, $images);
        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->assertSame('Add at most 3 images.', $response->json('errors.images.0'));
    }

    public function test_admin_url_fetch_bypasses_regular_cap(): void
    {
        $png = $this->noisyPng();
        Http::fake([
            'example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $images = [
            ['file' => UploadedFile::fake()->image('small.png')],
            ['url' => 'https://example.com/photo.png'],
        ];

        // Regular user: the fetch is cut off at the regular cap.
        $response = $this->postAs($this->user, $images);
        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->assertSame('The image is too large (max 1 KB).', $response->json('errors.images.0'));

        // Admin: the same URL passes.
        $this->postAs($this->admin, $images)->assertStatus(201);
    }

    public function test_admin_still_capped_at_admin_cap(): void
    {
        $png = $this->bigPng();
        $images = [
            ['file' => UploadedFile::fake()->createWithContent('a.png', $png, ['mime' => 'image/png'])],
            ['file' => UploadedFile::fake()->createWithContent('b.png', $png, ['mime' => 'image/png'])],
        ];

        $response = $this->postAs($this->admin, $images);
        $response->assertStatus(422)->assertJsonValidationErrors(['images.0.file', 'images.1.file']);
        $this->assertSame('Image #1 is too large (max 1 MB).', $response->json('errors')['images.0.file'][0]);
    }
}
