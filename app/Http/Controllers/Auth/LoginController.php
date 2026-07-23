<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login, which exists so Passport's OAuth consent screen has an authenticated user.
 *
 * Claude Desktop's connector flow sends the browser to /oauth/authorize, and Passport throws an
 * authentication exception when no session exists. Without a page for that redirect to land on,
 * the flow dead-ends. There is deliberately no registration route — accounts are created from the
 * console, because anyone who can log in here reaches infrastructure tooling.
 */
class LoginController extends Controller
{
    public function create(): RedirectResponse|View
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            /*
             * Deliberately does not distinguish an unknown email from a wrong password. This page is
             * publicly reachable, so a specific message would confirm which accounts exist.
             */
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
