@extends('layout')

@section('title', 'My albums')

@section('content')
<div class="page">
    <div class="panel">
        <div class="mine-head">
            <h1>My albums</h1>
            @if($albums->total() > 0)
                <a class="btn" href="{{ route('albums.download') }}" download
                   title="Download all {{ $albums->total() }} albums as a zip">Download all (zip archive)</a>
            @endif
        </div>
        @if($albums->isEmpty())
            <p class="hint">You have not saved any albums yet — build a comparison on the home page and press "Save album".</p>
        @else
            <ul class="album-list">
                @foreach($albums as $album)
                    <li>
                        <span class="title-cell">
                            <a class="album-title" data-id="{{ $album->id }}" data-value="{{ $album->title ?? '' }}"
                               href="{{ route('album', $album) }}">{{ $album->title ?? 'Untitled' }}</a>
                            <button type="button" class="rename-title" data-id="{{ $album->id }}" title="Rename album">&#9998;</button>
                        </span>
                        <label class="publicity">
                            <select class="publicity-toggle" data-id="{{ $album->id }}" aria-label="Visibility">
                                <option value="public" @selected($album->visibility === 'public')>Public</option>
                                <option value="unlisted" @selected($album->visibility === 'unlisted')>Unlisted</option>
                                <option value="private" @selected($album->visibility === 'private')>Private</option>
                            </select>
                        </label>
                        <time datetime="{{ $album->created_at->toIso8601String() }}">{{ $album->created_at->format('Y-m-d') }}</time>
                        <form method="POST" action="{{ route('albums.destroy', $album) }}" class="inline"
                              onsubmit="return confirm('Delete this album? The stored images will be removed.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn danger">Delete</button>
                        </form>
                    </li>
                @endforeach
            </ul>
            {{ $albums->links('pagination.default', ['label' => 'Albums']) }}
        @endif
    </div>
</div>
@endsection

@push('scripts')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="{{ asset('js/publicity.js?v=' . filemtime(public_path('js/publicity.js'))) }}"></script>
    <script src="{{ asset('js/rename.js?v=' . filemtime(public_path('js/rename.js'))) }}"></script>
    <script src="{{ asset('js/mine.js?v=' . filemtime(public_path('js/mine.js'))) }}"></script>
@endpush
