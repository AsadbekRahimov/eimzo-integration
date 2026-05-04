@extends('eimzo::layouts.app')
@section('title', 'E-IMZO Sign')
@section('content')
    <h1>Sign a document</h1>

    <div class="card">
        <button id="install">1. Connect E-IMZO desktop client</button>
        <span id="install-status" class="muted" style="margin-left:12px"></span>
    </div>

    <div class="card" id="form-card" style="display:none">
        <label for="cert">2. Choose your certificate</label>
        <select id="cert"></select>

        <label for="document_name">Document name</label>
        <input id="document_name" placeholder="contract.pdf">

        <label for="document_type">Document type tag</label>
        <input id="document_type" placeholder="contract" value="contract">

        <label for="data">3. Document content (will be base64-encoded before signing)</label>
        <textarea id="data" placeholder="Type or paste document text here"></textarea>

        <label><input type="checkbox" id="detached"> detached signature</label>
        <label><input type="checkbox" id="ts" checked> attach TSA timestamp</label>

        <div class="row" style="margin-top:16px">
            <button id="sign-btn">4. Sign & save</button>
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
                opt.textContent = `${k.CN || 'unknown'}  (${k.TIN || k.PINFL || ''})`;
                certSelect.appendChild(opt);
            });
            formCard.style.display = 'block';
            installStatus.innerHTML = '<span class="ok">ready</span>';
        } catch (e) {
            installStatus.innerHTML = '<span class="err">' + e.message + '</span>';
            installBtn.disabled = false;
        }
    });

    signBtn.addEventListener('click', async () => {
        signBtn.disabled = true;
        signStatus.textContent = 'Signing...';
        try {
            const key = keys[Number(certSelect.value)];
            const data = document.getElementById('data').value;
            const detached = document.getElementById('detached').checked;
            const ts = document.getElementById('ts').checked;

            const res = await eimzo.sign(key, {
                data,
                detached,
                attach_timestamp: ts,
                document_name: document.getElementById('document_name').value || null,
                document_type: document.getElementById('document_type').value || null
            });
            signStatus.innerHTML = '<span class="ok">' + res.signature.verification_status + '</span>';
            resultCard.style.display = 'block';
            sigIdBadge.textContent = '#' + res.signature.id;
            resultPre.textContent = JSON.stringify(res, null, 2);
        } catch (e) {
            signStatus.innerHTML = '<span class="err">' + e.message + '</span>';
        } finally {
            signBtn.disabled = false;
        }
    });
</script>
@endpush
