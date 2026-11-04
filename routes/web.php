<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\Auth\DiscordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;

Route::get('/', [AlbumController::class, 'compare'])->name('compare');

Route::get('/a/{album}/{before?}/{after?}', [AlbumController::class, 'show'])
    ->whereNumber('before')
    ->whereNumber('after')
    ->name('album');

Route::middleware('auth')->group(function () {
    Route::get('/mine', [AlbumController::class, 'mine'])->name('albums.mine');
    Route::post('/api/albums', [AlbumController::class, 'store'])
        ->middleware('throttle:'.config('uploads.store_rate'))
        ->name('albums.store');
    Route::patch('/api/albums/{album}', [AlbumController::class, 'update'])->name('albums.update');
    Route::delete('/api/albums/{album}', [AlbumController::class, 'destroy'])->name('albums.destroy');
});

Route::get('/auth/discord', fn () => Socialite::driver('discord')->redirect())
    ->name('auth.discord');
Route::get('/auth/discord/callback', [DiscordController::class, 'callback'])
    ->name('auth.discord.callback');

Route::post('/logout', function (Request $request) {
    auth()->guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('compare');
})->name('logout');
