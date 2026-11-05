<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RemoteImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Guards the central upload limits (config/uploads.php): the UPLOAD_* values
 * must actually drive store() behaviour and the unit-correct error messages,
 * not hardcoded values. AlbumApiTest covers the defaults; a runtime config
 * override here proves the centralization is honoured (regression: someone
 * re-hardcoding a limit would fail this, not the default-behaviour tests).
 */
class UploadLimitsConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Cache::flush();
        $fetcher = new RemoteImageFetcher;
        $fetcher->resolver = fn (string $host) => ['93.184.216.34'];
        $this->app->instance(RemoteImageFetcher::class, $fetcher);
    }

    private function postImages(array $files)
    {
        $images = array_map(fn (UploadedFile $f) => ['file' => $f], $files);

        return $this->actingAs($this->user)
            ->postJson('/api/albums', ['title' => 'T', 'is_public' => true, 'images' => $images], [
                'Accept' => 'application/json',
            ]);
    }

    /** A real, noisy PNG (~hundreds of KB): >1 KB and <10 MB. */
    private function noisyPng(): UploadedFile
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

        return UploadedFile::fake()->createWithContent('noisy.png', $content, ['mime' => 'image/png']);
    }

    public function test_image_count_is_config_driven(): void
    {
        $mk = fn () => UploadedFile::fake()->image('a.png');
        // default max 8: nine rejected, eight accepted
        $this->postImages(array_map($mk, range(1, 9)))->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->postImages(array_map($mk, range(1, 8)))->assertStatus(201);

        // override to 3: four rejected (dynamic message), three accepted
        config(['uploads.max_images' => 3]);
        $response = $this->postImages(array_map($mk, range(1, 4)));
        $response->assertStatus(422)->assertJsonValidationErrors(['images']);
        $this->assertSame('Add at most 3 images.', $response->json('errors.images.0'));
        $this->postImages(array_map($mk, range(1, 3)))->assertStatus(201);
    }

    public function test_per_image_size_is_config_driven(): void
    {
        // default 10 MB: the ~hundreds-of-KB noisy image passes
        $this->postImages([$this->noisyPng(), $this->noisyPng()])->assertStatus(201);

        // override to 1 KB: rejected with a dynamic, unit-correct message
        config(['uploads.max_image_bytes' => 1024]);
        $response = $this->postImages([$this->noisyPng(), $this->noisyPng()]);
        $response->assertStatus(422)->assertJsonValidationErrors(['images.0.file', 'images.1.file']);
        // Error keys are flat literal strings ("images.0.file"), not nested.
        $errors = $response->json('errors');
        $this->assertSame('Image #1 is too large (max 1 KB).', $errors['images.0.file'][0]);
    }

    /**
     * The compare page exposes the EFFECTIVE per-image cap (admin bypass
     * included) so the client-side save checks mirror the server's.
     */
    public function test_compare_page_exposes_effective_max_image_bytes(): void
    {
        // Defaults: 10 MB for guests and regular users, 50 MB for admins.
        $this->get('/')->assertSee('<meta name="max-image-bytes" content="10485760">', false);
        $this->actingAs($this->user)->get('/')->assertSee('<meta name="max-image-bytes" content="10485760">', false);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/')->assertSee('<meta name="max-image-bytes" content="52428800">', false);

        // Config-driven: overrides change what each role sees. (Feature-test
        // guard state persists across requests, so re-establish each actor
        // before its request — a bare get() keeps the last actingAs user.)
        config(['uploads.max_image_bytes' => 1024, 'uploads.admin_max_image_bytes' => 2048]);
        $this->app['auth']->guard()->logout();
        $this->get('/')->assertSee('<meta name="max-image-bytes" content="1024">', false);
        $this->actingAs($this->user)->get('/')->assertSee('<meta name="max-image-bytes" content="1024">', false);
        $this->actingAs($admin)->get('/')->assertSee('<meta name="max-image-bytes" content="2048">', false);
    }
}
