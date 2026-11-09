@extends('layout')

@section('title', 'Compare')

@push('head')
    <meta name="max-image-bytes" content="{{ $maxImageBytes }}">
    <meta name="max-images" content="{{ $maxImages }}">
@endpush

@section('content')
<div class="page compare-page">
    <div id="slider-root">
        <p class="slider-hint">Add at least 2 images to compare.</p>
    </div>

    <aside class="compare-side">
        <div class="panel">
            <h1>Compare</h1>
            <p class="hint">
                Drop, paste (Ctrl+V) or pick {{ config('uploads.min_images') }}–{{ $maxImages }} images, or paste URLs; drag a box's grip to reorder.
                Files stay in your browser — nothing is uploaded unless you save.
            </p>

            <div class="boxes" id="boxes">
                @for ($i = 0; $i < 2; $i++)
                    <div class="box">
                        <div class="dropzone" tabindex="0" role="button" aria-label="Choose image {{ $i + 1 }}">
                            <img alt="" hidden>
                            <span class="placeholder">Image {{ $i + 1 }}</span>
                        </div>
                        <div class="box-info"></div>
                        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="text" class="caption-input" maxlength="200" placeholder="Caption (optional)">
                        <input type="url" class="url-input" placeholder="… or paste an image URL">
                        <button type="button" class="btn clear" title="Clear box">× clear</button>
                        <button type="button" class="box-grip" title="Drag to reorder, or use the arrow keys" aria-label="Move image {{ $i + 1 }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h3v3H8zM13 4h3v3h-3zM8 10.5h3v3H8zM13 10.5h3v3h-3zM8 17h3v3H8zM13 17h3v3h-3z"/></svg></button>
                    </div>
                @endfor
                <template id="box-template">
                    <div class="box">
                        <div class="dropzone" tabindex="0" role="button" aria-label="Choose image">
                            <img alt="" hidden>
                            <span class="placeholder">Image</span>
                        </div>
                        <div class="box-info"></div>
                        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="text" class="caption-input" maxlength="200" placeholder="Caption (optional)">
                        <input type="url" class="url-input" placeholder="… or paste an image URL">
                        <button type="button" class="btn clear" title="Clear box">× clear</button>
                        <button type="button" class="box-grip" title="Drag to reorder, or use the arrow keys" aria-label="Move image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h3v3H8zM13 4h3v3h-3zM8 10.5h3v3H8zM13 10.5h3v3h-3zM8 17h3v3H8zM13 17h3v3h-3z"/></svg></button>
                    </div>
                </template>
            </div>

            <div class="box-actions">
                <button type="button" class="btn" id="add-box">+ add image</button>
                <button type="button" class="btn" id="remove-box">remove last</button>
            </div>

            <ul class="warnings" id="box-warnings" role="status"></ul>
        </div>

        <div class="panel save-panel" id="save-panel">
            @auth
                <h2>Save</h2>
                <input type="text" id="album-title" maxlength="200" placeholder="Title">
                <label class="check" for="album-visibility">
                    Visibility
                    <select id="album-visibility">
                        <option value="public" selected>Public — listed on the homepage</option>
                        <option value="unlisted">Unlisted — link only</option>
                        <option value="private">Private — only me</option>
                    </select>
                </label>
                <button type="button" class="btn primary" id="save-btn">Save album</button>
                <div class="progress-row" hidden>
                    <progress id="progress" max="1" value="0"></progress>
                    <span id="progress-text"></span>
                </div>
                <ul id="save-errors" class="errors" role="alert"></ul>
            @else
                <h2>Save</h2>
                <p>Log in with Discord to save your comparisons (public, unlisted or private).</p>
                <a class="btn primary" href="{{ route('auth.discord') }}">Log in with Discord</a>
            @endauth
        </div>

        @if($mine->isNotEmpty())
            <div class="panel">
                <h2>My albums</h2>
                <ul class="album-list">
                    @foreach($mine as $album)
                        <li>
                            <a href="{{ route('album', $album) }}">{{ $album->title ?? 'Untitled' }}</a>
                            @unless($album->visibility === \App\Models\Album::VISIBILITY_PUBLIC)
                                <span class="badge">{{ $album->visibility }}</span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($public->isNotEmpty())
            <div class="panel">
                <h2>Public albums</h2>
                <ul class="album-list">
                    @foreach($public as $album)
                        <li><a href="{{ route('album', $album) }}">{{ $album->title ?? 'Untitled' }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </aside>
</div>
@endsection

@push('scripts')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="{{ asset('js/compare.js?v=' . filemtime(public_path('js/compare.js'))) }}"></script>
@endpush
