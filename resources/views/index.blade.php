@extends('eimzo::layouts.app')
@section('title', 'E-IMZO')
@section('content')
    <h1>E-IMZO Module</h1>
    <div class="card">
        <p>This is the demo UI bundled with the <code>asadbekrahimov/eimzo-integration</code> module. It exercises the
            common flows of the Uzbekistan electronic-signature stack:</p>
        <ul>
            <li><a href="{{ route('eimzo.login.form') }}">Login</a> via signed challenge</li>
            <li><a href="{{ route('eimzo.sign.form') }}">Sign</a> arbitrary text/files with a TSA timestamp</li>
            <li><a href="{{ route('eimzo.verify.form') }}">Verify</a> existing PKCS#7 envelopes</li>
        </ul>
        <p class="muted">Make sure E-IMZO desktop client is running on the user's machine
            (https://e-imzo.uz) and that <code>EIMZO_SERVER_URL</code> in <code>.env</code> points at a
            running E-IMZO-SERVER instance (default <code>http://127.0.0.1:8080</code>).</p>
    </div>
@endsection
