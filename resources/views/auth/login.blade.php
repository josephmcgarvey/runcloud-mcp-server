@extends('layouts.plain')

@section('title', 'Sign in · '.config('app.name'))

@section('content')
    <h1>Sign in</h1>
    <p class="sub">{{ config('app.name') }} — RunCloud MCP server</p>

    @if ($errors->any())
        <div class="errors" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}"
               required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password"
               required autocomplete="current-password">

        <div class="row">
            <input id="remember" name="remember" type="checkbox" value="1">
            <label for="remember" style="margin:0;font-weight:400;">Stay signed in</label>
        </div>

        <button type="submit" class="btn-primary">Sign in</button>
    </form>

    <p class="footnote">
        Accounts are created by an administrator. There is no self-service registration, because
        signing in here grants access to server management tooling.
    </p>
@endsection
