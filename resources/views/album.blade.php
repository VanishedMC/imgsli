@extends('layout')

@section('title', $album->title ?? 'Untitled')

@section('content')
<div class="page album-page">
    <div class="panel album-head">
        <h1 class="album-title" data-id="{{ $album->id }}" data-value="{{ $album->title ?? '' }}" data-page-title>{{ $album->title ?? 'Untitled' }}</h1>
        @if(auth()->user() && auth()->id() === $album->user_id)
            <div class="album-head-actions">
                <button type="button" class="rename-title" data-id="{{ $album->id }}" title="Rename album">&#9998; Rename</button>
                <label class="publicity">
                    <input type="checkbox" class="publicity-toggle" data-id="{{ $album->id }}" @checked($album->is_public)>
                    Public
                </label>
                <button type="button" class="btn danger" id="delete-album" data-id="{{ $album->id }}">Delete</button>
            </div>
        @endif
    </div>
    <div class="panel compare-panel">
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
    <script src="{{ asset('js/publicity.js?v=' . filemtime(public_path('js/publicity.js'))) }}"></script>
    <script src="{{ asset('js/rename.js?v=' . filemtime(public_path('js/rename.js'))) }}"></script>
    <script src="{{ asset('js/album.js?v=' . filemtime(public_path('js/album.js'))) }}"></script>
@endpush
