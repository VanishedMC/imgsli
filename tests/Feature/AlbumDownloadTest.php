<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AlbumDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Cache::flush();
    }

    private function album(User $user, ?string $title, array $images, string $visibility = 'public'): Album
    {
        $entries = [];
        foreach ($images as $key => $description) {
            Storage::disk('public')->put($key, 'bytes-of-'.$key);
            $entries[] = ['key' => $key, 'description' => $description];
        }

        return Album::create([
            'user_id' => $user->id,
            'title' => $title,
            'visibility' => $visibility,
            'images' => $entries,
        ]);
    }

    /**
     * @return array<string, string> entry name => contents
     */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! str_ends_with($name, '/')) {
                $entries[$name] = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $entries;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/mine/download')->assertRedirect();
    }

    public function test_user_without_albums_gets_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/mine/download')
            ->assertNotFound();
    }

    public function test_zip_contains_all_own_albums_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $a = $this->album($user, 'Sunset', ['albums/1/a.png' => 'Before', 'albums/1/b.jpg' => 'After']);
        $b = $this->album($user, null, ['albums/1/c.webp' => '', 'albums/1/d.png' => 'a/b: "x"'], 'private');
        $this->album($other, 'Not mine', ['albums/2/z.png' => '']);

        $response = $this->actingAs($user)->get('/mine/download');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $entries = $this->zipEntries($response->getFile()->getPathname());
        $aDir = 'Sunset ('.$a->created_at->format('Y-m-d').')';
        $bDir = 'Untitled ('.$b->created_at->format('Y-m-d').')';

        $this->assertEqualsCanonicalizing([
            "$aDir/01 - Before.png",
            "$aDir/02 - After.jpg",
            "$bDir/01.webp",
            "$bDir/02 - a_b_ _x_.png",
        ], array_keys($entries));
        $this->assertSame('bytes-of-albums/1/b.jpg', $entries["$aDir/02 - After.jpg"]);
    }

    public function test_duplicate_titles_get_distinct_folders(): void
    {
        $user = User::factory()->create();
        $this->album($user, 'Same', ['albums/1/a.png' => '', 'albums/1/b.png' => '']);
        $second = $this->album($user, 'Same', ['albums/1/c.png' => '', 'albums/1/d.png' => '']);

        $response = $this->actingAs($user)->get('/mine/download');

        $entries = $this->zipEntries($response->getFile()->getPathname());
        $this->assertCount(4, $entries);
        $this->assertArrayHasKey(
            'Same ('.$second->created_at->format('Y-m-d').') '.$second->id.'/01.png',
            $entries,
        );
    }

    public function test_missing_files_are_skipped(): void
    {
        $user = User::factory()->create();
        $album = $this->album($user, 'Gap', ['albums/1/a.png' => '', 'albums/1/b.png' => '']);
        Storage::disk('public')->delete('albums/1/a.png');

        $response = $this->actingAs($user)->get('/mine/download');

        $response->assertOk();
        $entries = $this->zipEntries($response->getFile()->getPathname());
        $this->assertSame(['Gap ('.$album->created_at->format('Y-m-d').')/02.png'], array_keys($entries));
    }

    public function test_mine_page_shows_download_button_only_with_albums(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/mine')->assertDontSee(route('albums.download'));

        $this->album($user, 'One', ['albums/1/a.png' => '', 'albums/1/b.png' => '']);

        $this->actingAs($user)->get('/mine')->assertSee(route('albums.download'));
    }
}
