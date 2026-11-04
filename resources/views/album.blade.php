@extends('layout')

@section('title', $album->title ?? 'Untitled')

@section('content')
<div class="page album-page">
    <div class="panel album-head">
        <h1>{{ $album->title ?? 'Untitled' }}</h1>
        @unless($album->is_public)<span class="badge">private</span>@endunless
        @if(auth()->user() && auth()->id() === $album->user_id)
            <button type="button" class="btn danger" id="delete-album" data-id="{{ $album->id }}">Delete</button>
        @endif
    </div>
    <div class="panel">
        <image-compare
            id="compare"
            title="{{ $album->title ?? '' }}"
            album-url="{{ route('album', $album, false) }}"
            images='@json($urls, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES)'
            initial-before="{{ $before }}"
            initial-after="{{ $after }}"></image-compare>
    </div>
</div>
@endsection

@push('scripts')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="{{ asset('js/album.js?v=' . filemtime(public_path('js/album.js'))) }}"></script>
@endpush
