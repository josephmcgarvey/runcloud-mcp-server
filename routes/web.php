<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| There is deliberately no route for "/". This is a headless MCP server, not a
| website, so the root returns 404 rather than exposing a landing page. The only
| web routes are the authentication pages, which exist so Passport's OAuth
| consent screen has a session to work with — the Claude Desktop connector flow
| cannot complete without somewhere to sign in.
|
| Login attempts are throttled because this page is publicly reachable and a
| successful sign-in leads to infrastructure tooling. There is no registration
| route: accounts are created from the console.
|
*/

Route::get('login', [LoginController::class, 'create'])->name('login');

Route::post('login', [LoginController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('login.store');

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
