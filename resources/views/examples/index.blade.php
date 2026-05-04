@extends('eimzo::layouts.app')
@section('title', 'E-IMZO Examples')
@section('content')
    <h1>E-IMZO usage examples</h1>

    <div class="card">
        <h3>Login example</h3>
        <p>Shows the challenge based authentication flow. The signed document is the short-lived server challenge.</p>
        <p class="muted">Stores rows in <code>eimzo_challenges</code>, <code>eimzo_certificates</code>, and <code>eimzo_signatures</code>.</p>
        <a href="/eimzo/examples/login">Open login example</a>
    </div>

    <div class="card">
        <h3>Document sign example</h3>
        <p>Shows how to sign a real business document such as a contract, invoice, XML, JSON, or plain text.</p>
        <p class="muted">The signed payload is saved through <code>EimzoSignature</code> with hashes, PKCS#7, timestamp and verification data.</p>
        <a href="/eimzo/examples/document-sign">Open document signing example</a>
    </div>

    <div class="card">
        <h3>CRM action sign example</h3>
        <p>Shows how to sign an operation, not a file. The document is a canonical JSON payload describing the action.</p>
        <p class="muted">Useful for approve, reject, confirm, cancel, publish and similar auditable CRM actions.</p>
        <a href="/eimzo/examples/action-sign">Open action signing example</a>
    </div>
@endsection
