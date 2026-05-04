@extends('eimzo::layouts.app')
@section('title', 'E-IMZO Verify')
@section('content')
    <h1>Verify a PKCS#7 signature</h1>

    <div class="card">
        <label for="pkcs7">PKCS#7 (base64)</label>
        <textarea id="pkcs7"></textarea>

        <label for="data">Original data (base64 - only required for detached signatures)</label>
        <textarea id="data"></textarea>

        <div class="row" style="margin-top:16px">
            <button id="verify-btn">Verify</button>
            <span id="verify-status"></span>
        </div>
    </div>

    <div class="card" id="result-card" style="display:none">
        <h3>Result</h3>
        <pre id="result"></pre>
    </div>
@endsection

@push('scripts')
<script>
    const eimzo = new EimzoBridge();
    document.getElementById('verify-btn').addEventListener('click', async () => {
        const pkcs7 = document.getElementById('pkcs7').value.trim();
        const data = document.getElementById('data').value.trim();
        const status = document.getElementById('verify-status');
        const resultCard = document.getElementById('result-card');
        const resultPre = document.getElementById('result');
        status.textContent = 'Verifying...';
        try {
            const res = await eimzo.verify({ pkcs7, data: data || undefined });
            status.innerHTML = res.status === 1
                ? '<span class="ok">valid</span>'
                : '<span class="err">invalid: ' + (res.message || '') + '</span>';
            resultCard.style.display = 'block';
            resultPre.textContent = JSON.stringify(res, null, 2);
        } catch (e) {
            status.innerHTML = '<span class="err">' + e.message + '</span>';
        }
    });
</script>
@endpush
