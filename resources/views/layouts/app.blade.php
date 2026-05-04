<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'E-IMZO')</title>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --border:#334155; --fg:#e2e8f0; --muted:#94a3b8; --accent:#38bdf8; --ok:#22c55e; --err:#ef4444; }
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; margin:0; background:var(--bg); color:var(--fg); }
        header { padding: 16px 24px; border-bottom: 1px solid var(--border); display:flex; gap:24px; align-items:center; }
        header a { color: var(--fg); text-decoration: none; padding: 6px 12px; border-radius: 6px; }
        header a:hover { background: var(--panel); }
        main { max-width: 960px; margin: 32px auto; padding: 0 24px; }
        h1 { margin-top: 0; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        button { background: var(--accent); color: #0f172a; border: 0; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        button:disabled { opacity: 0.4; cursor: not-allowed; }
        button.secondary { background: transparent; color: var(--fg); border: 1px solid var(--border); }
        select, input, textarea { background: #0f172a; color: var(--fg); border: 1px solid var(--border); padding: 10px; border-radius: 8px; width: 100%; font-family: inherit; }
        textarea { font-family: ui-monospace, monospace; font-size: 13px; min-height: 140px; }
        label { display: block; margin: 12px 0 6px; color: var(--muted); font-size: 13px; }
        .row { display: flex; gap: 12px; align-items: end; }
        .row > * { flex: 1; }
        .ok { color: var(--ok); }
        .err { color: var(--err); }
        .muted { color: var(--muted); font-size: 13px; }
        pre { background: #0f172a; padding: 16px; border-radius: 8px; overflow: auto; max-height: 400px; }
        .badge { display:inline-block; padding:2px 8px; border-radius:6px; background:var(--border); font-size:12px; }
    </style>
    @stack('head')
</head>
<body>
<header>
    <strong>E-IMZO Module</strong>
    <a href="{{ route('eimzo.login.form') }}">Login</a>
    <a href="{{ route('eimzo.sign.form') }}">Sign</a>
    <a href="{{ route('eimzo.verify.form') }}">Verify</a>
    <a href="{{ route('eimzo.examples.index') }}">Examples</a>
    @auth
        <span style="margin-left:auto" class="muted">{{ auth()->user()->name ?? auth()->user()->email ?? 'authenticated' }}</span>
        <form method="POST" action="{{ route('eimzo.auth.logout') }}" style="display:inline">@csrf
            <button class="secondary" type="submit">Logout</button>
        </form>
    @endauth
</header>
<main>
    @yield('content')
</main>

<script>
    window.EIMZO_API_KEYS = @json(config('eimzo.api_keys', []));
</script>
<script src="{{ asset('vendor/eimzo/vendor/e-imzo.js') }}"></script>
<script src="{{ asset('vendor/eimzo/vendor/e-imzo-client.js') }}"></script>
<script src="{{ asset('vendor/eimzo/eimzo.js') }}"></script>
@stack('scripts')
</body>
</html>
