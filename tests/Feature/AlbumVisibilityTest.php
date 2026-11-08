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

    private function makeAlbum(int $images, string $visibility, ?User $user = null, string $title = 'Visibility Album'): Album
    {
        $keys = [];
        for ($i = 0; $i < $images; $i++) {
            $keys[] = "img{$i}.png";
        }

        $album = Album::create([
            'user_id' => $user?->id ?? User::factory()->create()->id,
            'title' => $title,
            'visibility' => $visibility,
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
        $album = $this->makeAlbum(2, Album::VISIBILITY_PUBLIC);

        $this->get(route('album', $album))
            ->assertOk()
            ->assertSee('image-compare')
            ->assertSee('Visibility Album');
    }

    public function test_unlisted_album_reachable_by_url_but_never_listed(): void
    {
        $album = $this->makeAlbum(2, Album::VISIBILITY_UNLISTED);

        $this->get(route('album', $album))
            ->assertOk()
            ->assertSee('Visibility Album');

        $this->actingAs(User::factory()->create())
            ->get(route('album', $album))
            ->assertOk();

        $this->get('/')->assertOk()->assertDontSee('Visibility Album');
    }

    public function test_only_public_albums_are_listed_on_the_homepage(): void
    {
        $this->makeAlbum(2, Album::VISIBILITY_PUBLIC, title: 'Listed Album');
        $this->makeAlbum(2, Album::VISIBILITY_UNLISTED, title: 'Hidden Unlisted Album');
        $this->makeAlbum(2, Album::VISIBILITY_PRIVATE, title: 'Hidden Private Album');

        $this->get('/')
            ->assertOk()
            ->assertSee('Listed Album')
            ->assertDontSee('Hidden Unlisted Album')
            ->assertDontSee('Hidden Private Album');
    }

    public function test_private_album_hidden_from_anonymous_and_others(): void
    {
        $owner = User::factory()->create();
        $album = $this->makeAlbum(2, Album::VISIBILITY_PRIVATE, user: $owner);

        $this->get(route('album', $album))->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('album', $album))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('album', $album))
            ->assertOk();

        // Admins manage all albums, so they can open them too.
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('album', $album))
            ->assertOk();
    }

    public function test_album_page_shows_actions_to_owner_and_admins_only(): void
    {
        $owner = User::factory()->create();
        $album = $this->makeAlbum(2, Album::VISIBILITY_PRIVATE, user: $owner);

        $this->actingAs($owner)
            ->get(route('album', $album))
            ->assertOk()
            ->assertSee('publicity-toggle', false);

        // An admin can open the album and see the management actions.
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('album', $album))
            ->assertOk()
            ->assertSee('publicity-toggle', false)
            ->assertSee('rename-title', false)
            ->assertSee('delete-album', false);

        $this->actingAs(User::factory()->create())
            ->get(route('album', $album))->assertNotFound();

        $public = $this->makeAlbum(2, Album::VISIBILITY_PUBLIC);
        $this->get(route('album', $public))
            ->assertOk()
            ->assertDontSee('publicity-toggle', false);
    }

    public function test_initial_picker_positions_from_url(): void
    {
        $album = $this->makeAlbum(4, Album::VISIBILITY_PUBLIC);

        $this->get(route('album', [$album, 1, 3]))
            ->assertOk()
            ->assertSee('initial-before="1"', false)
            ->assertSee('initial-after="3"', false);
    }
}
