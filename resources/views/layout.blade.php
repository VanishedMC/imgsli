<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Compare') — Imgsli</title>
    @if(isset($album))
        <meta property="og:title" content="{{ $album->title ?? 'Untitled album' }}">
        @if(isset($urls) && count($urls))
            <meta property="og:image" content="{{ $urls[0]['url'] }}">
        @endif
        <meta name="description" content="Before/after album on Imgsli: {{ $album->title ?? 'Untitled' }}">
    @endif
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css?v=' . filemtime(public_path('css/app.css'))) }}">
    @stack('head')
</head>
<body>
<header class="site-header">
    <a class="logo" href="{{ route('compare') }}">imgsli</a>
    <nav class="site-nav">
        @auth
            <a href="{{ route('albums.mine') }}">My albums</a>
            <span class="who" title="{{ auth()->user()->username }}">
                @if(auth()->user()->avatar_url)
                    <img src="{{ auth()->user()->avatar_url }}" alt="" width="24" height="24">
                @endif
                {{ auth()->user()->username }}
            </span>
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <button type="submit" class="btn">Log out</button>
            </form>
        @else
            <a class="btn" href="{{ route('auth.discord') }}">Log in with Discord</a>
        @endauth
    </nav>
</header>
<main>
    @yield('content')
</main>
<script src="{{ asset('image-compare.js?v=' . filemtime(public_path('image-compare.js'))) }}"></script>
<script src="{{ asset('js/fit-height.js?v=' . filemtime(public_path('js/fit-height.js'))) }}"></script>
@stack('scripts')
</body>
</html>
