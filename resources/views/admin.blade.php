@extends('layout')

@section('title', 'Admin')

@section('content')
<div class="page">
    <div class="panel">
        <h1>Users</h1>
        @if($users->isEmpty())
            <p class="hint">No registered users yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Discord ID</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr>
                            <td>{{ $user->id }}</td>
                            <td>{{ $user->discord_id }}</td>
                            <td>
                                @if($user->avatar_url)
                                    <img class="admin-avatar" src="{{ $user->avatar_url }}" alt="" width="20" height="20">
                                @endif
                                {{ $user->username }}
                            </td>
                            <td>
                                @if($user->is_admin)
                                    <span class="badge admin-badge">admin</span>
                                @else
                                    <span class="badge">user</span>
                                @endif
                            </td>
                            <td><time datetime="{{ $user->created_at->toIso8601String() }}">{{ $user->created_at->format('Y-m-d') }}</time></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel admin-panel-gap">
        <h1>Albums</h1>
        <p class="hint">All albums, public and private.</p>
        @if($albums->isEmpty())
            <p class="hint">No albums yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Owner</th>
                        <th>Visibility</th>
                        <th>Images</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($albums as $album)
                        <tr>
                            <td>
                                <a class="album-title" href="{{ route('album', $album) }}">{{ $album->title ?? 'Untitled' }}</a>
                            </td>
                            <td>{{ $album->user->username ?? '—' }}</td>
                            <td>
                                @if($album->is_public)
                                    <span class="badge">public</span>
                                @else
                                    <span class="badge admin-private">private</span>
                                @endif
                            </td>
                            <td>{{ count($album->images) }}</td>
                            <td><time datetime="{{ $album->created_at->toIso8601String() }}">{{ $album->created_at->format('Y-m-d') }}</time></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
