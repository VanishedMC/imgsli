<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class DiscordController extends Controller
{
    /**
     * OAuth callback: sync the Discord profile into the local user and log in.
     */
    public function callback(Request $request): RedirectResponse
    {
        /** @var \Laravel\Socialite\Two\User $discord */
        $discord = Socialite::driver('discord')->user();

        $user = User::firstOrNew(['discord_id' => (string) $discord->id]);
        $user->username = $discord->nickname ?: $discord->username ?: 'discord-'.$discord->id;
        $user->avatar_url = $discord->avatar_url ?: $discord->avatar;
        $user->save();

        // Persistent login: the remember cookie lets the guard re-establish
        // a session after the session cookie's idle window expires, so users
        // do not have to redo the Discord OAuth round trip.
        Auth::login($user, true);
        // New session ID on auth: a pre-login session cannot be pinned
        // (session fixation).
        $request->session()->regenerate();

        return redirect()->route('compare');
    }
}
