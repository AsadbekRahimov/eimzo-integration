@extends('eimzo::layouts.app')
@section('title', 'E-IMZO CRM Action Sign Example')
@section('content')
    <h1>CRM action signing example</h1>

    <div class="card">
        <h3>What is signed?</h3>
        <p>For CRM operations, the document should be canonical JSON describing exactly what the user confirms. This example signs an <code>approve_invoice</code> action.</p>
        <label for="payload">canonical JSON payload</label>
        <textarea id="payload" readonly>{{ $payloadJson }}</textarea>
        <p class="muted">Canonical means the backend builds the fields in a stable order and does not trust user-edited text as the source of truth.</p>
    </div>

    <div class="card">
        <h3>Suggested business table</h3>
        <pre>signed_actions
- id
- user_id
- action = approve_invoice
- entity_type = invoice
- entity_id = 1024
- payload_json
- payload_hash = sha256(canonical JSON)
- signature_id
- certificate_id
- signed_at
- ip
- user_agent</pre>
        <p class="muted">The module already stores the PKCS#7 in <code>eimzo_signatures</code>. Your CRM table should store the business context and link it through <code>signature_id</code>.</p>
    </div>

    <div class="card">
        <button id="install">1. Connect E-IMZO desktop client</button>
        <span id="install-status" class="muted" style="margin-left:12px"></span>
    </div>

    <div class="card" id="form-card" style="display:none">
        <label for="cert">2. Choose certificate</label>
        <select id="cert"></select>

        <div class="row" style="margin-top:16px">
            <button id="sign-btn">3. Sign CRM action</button>
            <span id="sign-status"></span>
        </div>
    </div>

    <div class="card" id="result-card" style="display:none">
        <h3>Stored signature <span id="sig-id" class="badge"></span></h3>
        <pre id="result"></pre>
    </div>
@endsection

@push('scripts')
<script>
    const eimzo = new EimzoBridge();
    const installBtn = document.getElementById('install');
    const installStatus = document.getElementById('install-status');
    const formCard = document.getElementById('form-card');
    const certSelect = document.getElementById('cert');
    const signBtn = document.getElementById('sign-btn');
    const signStatus = document.getElementById('sign-status');
    const resultCard = document.getElementById('result-card');
    const resultPre = document.getElementById('result');
    const sigIdBadge = document.getElementById('sig-id');
    const payload = document.getElementById('payload').value;

    let keys = [];

    installBtn.addEventListener('click', async () => {
        installBtn.disabled = true;
        installStatus.textContent = 'Connecting...';
        try {
            await eimzo.install();
            keys = await eimzo.listKeys();
            certSelect.innerHTML = '';
            keys.forEach((k, i) => {
                const opt = document.createElement('option');
                opt.value = i;
                opt.textContent = `${k.CN || k.name || 'unknown'}  (${k.TIN || k.PINFL || k.serialNumber || ''})`;
                certSelect.appendChild(opt);
            });
            formCard.style.display = 'block';
            installStatus.className = 'ok';
            installStatus.textContent = keys.length + ' key(s) found';
        } catch (e) {
            installStatus.className = 'err';
            installStatus.textContent = e.message;
            installBtn.disabled = false;
        }
    });

    signBtn.addEventListener('click', async () => {
        signBtn.disabled = true;
        signStatus.textContent = 'Signing action...';
        try {
            const key = keys[Number(certSelect.value)];
            const res = await eimzo.sign(key, {
                data: payload,
                detached: false,
                attach_timestamp: true,
                document_type: 'crm-action',
                document_name: 'approve_invoice:invoice:1024',
                meta: {
                    action: 'approve_invoice',
                    entity_type: 'invoice',
                    entity_id: 1024,
                    storage_hint: 'store signature.id as signed_actions.signature_id'
                }
            });
            signStatus.className = 'ok';
            signStatus.textContent = res.signature.verification_status;
            resultCard.style.display = 'block';
            sigIdBadge.textContent = '#' + res.signature.id;
            resultPre.textContent = JSON.stringify({
                signed_actions_example: {
                    action: 'approve_invoice',
                    entity_type: 'invoice',
                    entity_id: 1024,
                    payload_hash: 'sha256(canonical JSON)',
                    signature_id: res.signature.id,
                    certificate: res.signature.certificate
                },
                eimzo_response: res
            }, null, 2);
        } catch (e) {
            signStatus.className = 'err';
            signStatus.textContent = e.message;
        } finally {
            signBtn.disabled = false;
        }
    });
</script>
@endpush
