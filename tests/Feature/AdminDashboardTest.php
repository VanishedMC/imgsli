<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlbum(int $images, bool $public, User $owner): Album
    {
        $keys = [];
        for ($i = 0; $i < $images; $i++) {
            $keys[] = "img{$i}.png";
            Storage::disk('public')->put("img{$i}.png", 'x');
        }

        return Album::create([
            'user_id' => $owner->id,
            'title' => 'A'.count($keys),
            'visibility' => $public ? 'public' : 'private',
            'images' => array_map(fn ($k) => ['key' => $k, 'description' => ''], $keys),
        ]);
    }

    public function test_guest_visiting_admin_is_redirected_to_discord_login(): void
    {
        $this->get('/admin')->assertRedirect(route('auth.discord'));
    }

    public function test_non_admin_user_gets_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_sees_all_users_and_all_albums_including_private(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'username' => 'admin']);
        $member = User::factory()->create(['is_admin' => false, 'username' => 'member']);

        $public = $this->makeAlbum(2, true, $member);
        $private = $this->makeAlbum(3, false, $member);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()
            ->assertSee('admin')
            ->assertSee('member')
            ->assertSee($public->title)
            ->assertSee($private->title)
            ->assertSee('private');
    }

    public function test_admin_dashboard_is_linked_in_header_for_admins_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)->get('/')->assertSee(route('admin.index'));
        $this->actingAs($member)->get('/')->assertDontSee(route('admin.index'));
    }
}
