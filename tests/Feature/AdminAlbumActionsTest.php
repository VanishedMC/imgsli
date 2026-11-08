<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admins manage all albums regardless of ownership: rename,
 * public/private toggle, and delete (PATCH/DELETE /api/albums/{album}),
 * in addition to opening the album page (covered in AlbumVisibilityTest).
 */
class AdminAlbumActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->create(['is_admin' => false]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function makeAlbum(bool $public): Album
    {
        $album = Album::create([
            'user_id' => $this->owner->id,
            'title' => 'Owned',
            'visibility' => $public ? 'public' : 'private',
            'images' => [],
        ]);

        foreach (['a.png', 'b.png'] as $name) {
            Storage::disk('public')->put($name, 'x');
        }
        $album->forceFill(['images' => array_map(
            fn ($key) => ['key' => $key, 'description' => ''],
            ['a.png', 'b.png']
        )])->save();

        return $album;
    }

    public function test_admin_renames_other_users_album(): void
    {
        $album = $this->makeAlbum(true);

        $this->actingAs($this->admin)
            ->patchJson(route('albums.update', $album), ['title' => 'Renamed'])
            ->assertStatus(200)
            ->assertJsonPath('title', 'Renamed');

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'title' => 'Renamed']);

        // Admin can also clear the title.
        $this->actingAs($this->admin)
            ->patchJson(route('albums.update', $album), ['title' => null])
            ->assertStatus(200)
            ->assertJsonPath('title', null);
    }

    public function test_admin_toggles_other_users_album_visibility(): void
    {
        $album = $this->makeAlbum(true);

        $this->actingAs($this->admin)
            ->patchJson(route('albums.update', $album), ['visibility' => 'private'])
            ->assertStatus(200)
            ->assertJsonPath('visibility', 'private');

        $this->assertDatabaseHas('albums', ['id' => $album->id, 'visibility' => 'private']);

        // Regular users lose access to the now-private album...
        $this->actingAs(User::factory()->create())
            ->get(route('album', $album))
            ->assertStatus(404);

        // ...but the admin can still open it.
        $this->actingAs($this->admin)
            ->get(route('album', $album))
            ->assertOk();
    }

    public function test_admin_deletes_other_users_album_and_files(): void
    {
        $album = $this->makeAlbum(true);

        $this->actingAs($this->admin)
            ->deleteJson(route('albums.destroy', $album))
            ->assertStatus(204);

        $this->assertDatabaseMissing('albums', ['id' => $album->id]);
        Storage::disk('public')->assertMissing('a.png');
        Storage::disk('public')->assertMissing('b.png');
    }
}
