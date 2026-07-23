{{--
    Self-contained layout for the authentication and OAuth consent pages.

    Deliberately does not use @vite: these pages must render correctly on a fresh deployment before
    any asset build has run, and an unstyled or broken consent screen is where a user is being asked
    to grant access to infrastructure tooling.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', config('app.name'))</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: light-dark(#f5f5f4, #18181b);
            --panel: light-dark(#ffffff, #27272a);
            --text: light-dark(#18181b, #fafafa);
            --muted: light-dark(#71717a, #a1a1aa);
            --border: light-dark(#e4e4e7, #3f3f46);
            --accent: light-dark(#1d4ed8, #60a5fa);
            --accent-text: light-dark(#ffffff, #0b1220);
            --danger: light-dark(#b91c1c, #f87171);
            --warn-bg: light-dark(#fffbeb, #2a2313);
            --warn-border: light-dark(#fcd34d, #a16207);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .panel {
            width: 100%;
            max-width: 26rem;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2rem;
        }
        .panel--wide { max-width: 30rem; }
        h1 { margin: 0 0 .35rem; font-size: 1.25rem; font-weight: 600; }
        .sub { margin: 0 0 1.5rem; color: var(--muted); font-size: .875rem; }
        label { display: block; margin-bottom: .35rem; font-size: .8125rem; font-weight: 500; }
        input[type=email], input[type=password] {
            width: 100%;
            padding: .6rem .75rem;
            margin-bottom: 1rem;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            font: inherit;
        }
        input:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
        .row { display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem; font-size: .875rem; }
        button {
            width: 100%;
            padding: .65rem 1rem;
            border: 1px solid transparent;
            border-radius: 8px;
            font: inherit;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-primary { background: var(--accent); color: var(--accent-text); }
        .btn-secondary { background: transparent; color: var(--text); border-color: var(--border); }
        .actions { display: flex; gap: .75rem; }
        .errors {
            margin: 0 0 1.25rem;
            padding: .75rem 1rem;
            border: 1px solid var(--danger);
            border-radius: 8px;
            color: var(--danger);
            font-size: .875rem;
        }
        .errors ul { margin: 0; padding-left: 1.1rem; }
        .notice {
            margin: 0 0 1.5rem;
            padding: .75rem 1rem;
            background: var(--warn-bg);
            border: 1px solid var(--warn-border);
            border-radius: 8px;
            font-size: .8125rem;
        }
        .scopes { margin: 0 0 1.5rem; padding: 0; list-style: none; }
        .scopes li {
            padding: .6rem .75rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: .5rem;
            font-size: .875rem;
        }
        .scopes code { font-size: .8125rem; color: var(--muted); }
        .footnote { margin: 1.5rem 0 0; color: var(--muted); font-size: .75rem; }
        .inline-form { display: contents; }
    </style>
</head>
<body>
    <main class="panel @yield('panel-class')">
        @yield('content')
    </main>
</body>
</html>
