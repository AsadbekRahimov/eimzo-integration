@extends('eimzo::layouts.app')
@section('title', 'E-IMZO Document Sign Example')
@section('content')
    <h1>Document signing example</h1>

    <div class="card">
        <h3>What is signed?</h3>
        <p>The document can be a file, XML, JSON, or text. In the browser it is base64 encoded and passed to E-IMZO <code>create_pkcs7</code>.</p>
        <pre>{
  "document_type": "contract",
  "document_name": "contract-2026-001.json",
  "data": "{... original contract content ...}",
  "detached": false,
  "attach_timestamp": true
}</pre>
        <p>With attached signing, the original document is embedded inside the PKCS#7. With detached signing, the PKCS#7 contains the signature only and the original document must be stored separately and provided during verification.</p>
    </div>

    <div class="card">
        <h3>Database records</h3>
        <pre>eimzo_signatures
- user_id
- certificate_id
- document_type
- document_name
- document_size
- document_hash
- pkcs7
- pkcs7_with_timestamp
- detached
- signed_at
- timestamp_at
- verification_status
- verification_payload
- verified_at
- meta</pre>
        <p class="muted">If this signature belongs to your own model, store <code>signable_type</code> and <code>signable_id</code> or keep the returned signature <code>id</code> on your business table.</p>
    </div>

    <div class="card">
        <h3>Try it</h3>
        <p class="muted">Use this for contract, invoice, act, XML, JSON or any content that must be legally/auditably signed.</p>
        <a href="{{ route('eimzo.sign.form') }}">Open interactive document signing page</a>
    </div>
@endsection
