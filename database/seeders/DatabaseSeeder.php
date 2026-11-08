<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::factory()->create([
            'discord_id' => 'seed-demo-user',
            'username' => 'demo',
        ]);

        $this->makeAlbum($user, 'The Rise of the Tomb Raider — ReShade test', [
            'week 0', 'week 1', 'week 2', 'week 4',
        ]);
    }

    private function makeAlbum(User $user, string $title, array $weeks): Album
    {
        $album = Album::create([
            'user_id' => $user->id,
            'title' => $title,
            'visibility' => Album::VISIBILITY_PUBLIC,
            'images' => [],
        ]);

        $disk = Storage::disk('public');
        $dir = 'albums/'.$album->id;
        $stored = [];

        $hue = 200;
        foreach ($weeks as $i => $week) {
            // Generated gradient stand-in (no bundled binary assets): the
            // "grade" shifts each week, like a re-shade test shot.
            $img = imagecreatetruecolor(800, 450);
            [$r1, $g1, $b1] = [40 + $i * 40, 60, 90 + $i * 30];
            [$r2, $g2, $b2] = [120 + $i * 30, 180 - $i * 30, 200 - $i * 20];
            for ($y = 0; $y < 450; $y++) {
                $t = $y / 449;
                $c = imagecolorallocate(
                    $img,
                    (int) round($r1 + ($r2 - $r1) * $t),
                    (int) round($g1 + ($g2 - $g1) * $t),
                    (int) round($b1 + ($b2 - $b1) * $t),
                );
                imageline($img, 0, $y, 799, $y, $c);
            }
            imagestring($img, 5, 30, 30, $week.'  ·  imgsli demo', imagecolorallocate($img, 255, 255, 255));
            ob_start();
            imagepng($img, null, 9);
            $bytes = ob_get_clean();
            imagedestroy($img);

            $name = bin2hex(random_bytes(16)).'.png';
            $disk->put($dir.'/'.$name, $bytes);
            $stored[] = ['key' => $dir.'/'.$name, 'description' => $week];
        }

        $album->forceFill(['images' => $stored])->save();

        return $album;
    }
}
