@extends('eimzo::layouts.app')
@section('title', 'E-IMZO Login')
@section('content')
    <h1>Login with E-IMZO</h1>

    <div class="card">
        <button id="install">1. Connect E-IMZO desktop client</button>
        <span id="install-status" class="muted" style="margin-left:12px"></span>
    </div>

    <div class="card" id="cert-card" style="display:none">
        <label for="cert">2. Choose your certificate</label>
        <select id="cert"></select>
        <div class="row" style="margin-top:16px">
            <button id="login-btn">3. Sign challenge & login</button>
            <span id="login-status"></span>
        </div>
    </div>

    <div class="card" id="result-card" style="display:none">
        <h3>Authenticated</h3>
        <pre id="result"></pre>
    </div>
@endsection

@push('scripts')
<script>
    const eimzo = new EimzoBridge();
    const installBtn = document.getElementById('install');
    const installStatus = document.getElementById('install-status');
    const certCard = document.getElementById('cert-card');
    const certSelect = document.getElementById('cert');
    const loginBtn = document.getElementById('login-btn');
    const loginStatus = document.getElementById('login-status');
    const resultCard = document.getElementById('result-card');
    const resultPre = document.getElementById('result');

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
            certCard.style.display = 'block';
            installStatus.innerHTML = '<span class="ok">' + keys.length + ' key(s) found</span>';
        } catch (e) {
            installStatus.innerHTML = '<span class="err">' + e.message + '</span>';
            installBtn.disabled = false;
        }
    });

    loginBtn.addEventListener('click', async () => {
        loginBtn.disabled = true;
        loginStatus.textContent = 'Signing & verifying...';
        try {
            const key = keys[Number(certSelect.value)];
            const res = await eimzo.login(key);
            loginStatus.innerHTML = '<span class="ok">Logged in</span>';
            resultCard.style.display = 'block';
            resultPre.textContent = JSON.stringify(res, null, 2);
            if (res.redirect) {
                setTimeout(() => { window.location.href = res.redirect; }, 1200);
            }
        } catch (e) {
            loginStatus.innerHTML = '<span class="err">' + e.message + '</span>';
            loginBtn.disabled = false;
        }
    });
</script>
@endpush
