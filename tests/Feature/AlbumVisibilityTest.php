<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AlbumVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlbum(int $images, bool $public, ?User $user = null): Album
    {
        $keys = [];
        for ($i = 0; $i < $images; $i++) {
            $keys[] = "img{$i}.png";
        }

        $album = Album::create([
            'user_id' => $user?->id ?? User::factory()->create()->id,
            'title' => 'Visibility Album',
            'is_public' => $public,
            'images' => array_map(fn ($k) => ['key' => $k, 'description' => ''], $keys),
        ]);

        foreach ($keys as $key) {
            Storage::disk('public')->put($key, 'x');
        }

        return $album;
    }

    public function test_guest_visiting_mine_is_redirected_to_discord_login(): void
    {
        $this->get('/mine')
            ->assertRedirect(route('auth.discord'));
    }

    public function test_compare_page_renders_anonymously(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_public_album_visible_to_anonymous(): void
    {
        $album = $this->makeAlbum(2, public: true);

        $this->get(route('album', $album))
            ->assertOk()
            ->assertSee('image-compare')
            ->assertSee('Visibility Album');
    }

    public function test_private_album_hidden_from_anonymous_and_others(): void
    {
        $owner = User::factory()->create();
        $album = $this->makeAlbum(2, public: false, user: $owner);

        $this->get(route('album', $album))->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('album', $album))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('album', $album))
            ->assertOk();
    }

    public function test_initial_picker_positions_from_url(): void
    {
        $album = $this->makeAlbum(4, public: true);

        $this->get(route('album', [$album, 1, 3]))
            ->assertOk()
            ->assertSee('initial-before="1"', false)
            ->assertSee('initial-after="3"', false);
    }
}
