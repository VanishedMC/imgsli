<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_dashboard_pagination_preserves_each_table_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->count(50)->create();
        for ($i = 1; $i <= 51; $i++) {
            Album::create([
                'user_id' => $admin->id,
                'title' => "Album {$i}",
                'visibility' => Album::VISIBILITY_PRIVATE,
                'images' => [],
            ]);
        }

        $first = $this->actingAs($admin)->get('/admin?users_page=2');
        $perPage = $first->viewData('users')->perPage();
        $secondPageEnd = min(2 * $perPage, 51);
        $first->assertOk()
            ->assertSee('aria-label="Users pagination"', false)
            ->assertSee('aria-label="Albums pagination"', false)
            ->assertSee('Showing '.($perPage + 1)."–{$secondPageEnd} of 51")
            ->assertSee("Showing 1–{$perPage} of 51")
            ->assertSee('users_page=2&amp;albums_page=2', false)
            ->assertSee('aria-current="page"', false);

        $second = $this->actingAs($admin)->get('/admin?users_page=2&albums_page=2');
        $second->assertOk()
            ->assertSee('Showing '.($perPage + 1)."–{$secondPageEnd} of 51")
            ->assertSee('users_page=2&amp;albums_page=1', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_dashboard_limits_page_links_for_large_results(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $perPage = $this->actingAs($admin)->get('/admin')->viewData('albums')->perPage();
        $count = 11 * $perPage + 1;
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'user_id' => $admin->id,
                'title' => "Album {$i}",
                'visibility' => Album::VISIBILITY_PRIVATE,
                'images' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('albums')->insert($chunk);
        }

        foreach ([1, 6, 12] as $page) {
            $response = $this->actingAs($admin)->get("/admin?albums_page={$page}");
            $response->assertOk()
                ->assertSee('aria-label="Page '.$page.'"', false)
                ->assertSee('page-ellipsis', false);

            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $nav = '//nav[@aria-label="Albums pagination"]';
            $pageLinks = $xpath->query($nav.'//*[@aria-current="page" or starts-with(@aria-label, "Go to page ")]');
            $this->assertLessThanOrEqual(8, $pageLinks->count());
            $this->assertSame(1, $xpath->query($nav.'//*[@aria-label="Page 1" or @aria-label="Go to page 1"]')->count());
            $this->assertSame(1, $xpath->query($nav.'//*[@aria-label="Page 12" or @aria-label="Go to page 12"]')->count());
        }
    }
}
