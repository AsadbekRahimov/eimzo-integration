@extends('eimzo::layouts.app')
@section('title', 'E-IMZO Login Example')
@section('content')
    <h1>Login example</h1>

    <div class="card">
        <h3>What is signed?</h3>
        <p>For login, the document is not a PDF or business record. The document is a short-lived challenge issued by the backend.</p>
        <pre>GET /eimzo/auth/challenge
{
  "status": 1,
  "challenge": "server-issued-random-string",
  "ttl": 120
}</pre>
        <p>The browser sends that challenge to E-IMZO as the payload for <code>create_pkcs7</code>. The backend verifies the returned PKCS#7 through E-IMZO-SERVER and logs the user in when the certificate matches a user.</p>
    </div>

    <div class="card">
        <h3>Database records</h3>
        <pre>eimzo_challenges
- challenge
- purpose = auth
- ip
- user_agent
- expires_at
- used_at

eimzo_certificates
- serial_number
- cn
- tin
- pinfl
- valid_from / valid_to
- last_verify_payload

eimzo_signatures
- document_type = auth-challenge
- document_name = challenge:{challenge}
- document_hash = sha256(challenge)
- pkcs7
- verification_payload
- verification_status</pre>
    </div>

    <div class="card">
        <h3>Try it</h3>
        <p class="muted">This uses the same working page as the normal demo login.</p>
        <a href="{{ route('eimzo.login.form') }}">Open interactive login page</a>
    </div>
@endsection
