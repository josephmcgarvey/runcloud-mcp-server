@extends('layouts.plain')

@section('title', 'Authorize · '.config('app.name'))
@section('panel-class', 'panel--wide')

@section('content')
    <h1>Authorize {{ $client->name }}</h1>
    <p class="sub">Signed in as {{ $user->email ?? $user->name }}</p>

    <div class="notice">
        <strong>This grants access to server management.</strong>
        Approving lets this application read and change infrastructure through your RunCloud
        account, within whatever tool profile this server runs under. Only continue if you started
        this yourself and recognise the application above.
    </div>

    <p style="margin:0 0 .75rem;font-size:.875rem;">It is requesting:</p>

    <ul class="scopes">
        @forelse ($scopes as $scope)
            <li>
                {{ $scope->description }}
                <br><code>{{ $scope->id }}</code>
            </li>
        @empty
            <li>Access to this server's MCP endpoint.</li>
        @endforelse
    </ul>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="inline-form">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="btn-primary">Approve</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="inline-form">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="btn-secondary">Cancel</button>
        </form>
    </div>

    <p class="footnote">
        You can revoke this at any time by deleting the token in the database, or by removing the
        connector in the application that requested it.
    </p>
@endsection
