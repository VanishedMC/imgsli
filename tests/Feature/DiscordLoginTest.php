<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class DiscordLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_discord_redirect_points_at_discord_authorize(): void
    {
        $response = $this->get('/auth/discord');

        $response->assertRedirect();
        $this->assertStringContainsString('discord.com/api/oauth2/authorize', $response->headers->get('Location'));
    }

    public function test_discord_callback_logs_in_and_creates_user(): void
    {
        Socialite::fake('discord', SocialiteUser::fake([
            'id' => '999',
            'nickname' => 'tester',
            'avatar' => 'https://cdn.example/a.png',
        ]));

        $this->get('/auth/discord/callback')
            ->assertRedirect(route('compare'));

        $user = User::where('discord_id', '999')->first();
        $this->assertNotNull($user);
        $this->assertSame('tester', $user->username);
        $this->assertSame('https://cdn.example/a.png', $user->avatar_url);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_syncs_existing_user(): void
    {
        $user = User::factory()->create([
            'discord_id' => '555',
            'username' => 'old-name',
            'avatar_url' => null,
        ]);

        Socialite::fake('discord', SocialiteUser::fake([
            'id' => '555',
            'nickname' => 'new-name',
            'avatar' => 'https://cdn.example/b.png',
        ]));

        $this->get('/auth/discord/callback')->assertRedirect(route('compare'));

        $this->assertSame(1, User::count());
        $this->assertSame('new-name', $user->fresh()->username);
        $this->assertSame('https://cdn.example/b.png', $user->fresh()->avatar_url);
    }
}
