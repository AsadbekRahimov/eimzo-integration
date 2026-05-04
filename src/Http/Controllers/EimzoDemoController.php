<?php

namespace AsadbekRahimov\EimzoIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Renders the bundled demo UI (login, sign, verify pages). Drop these views
 * once you have your own UI - they are wired only to make the module work
 * out-of-the-box.
 */
class EimzoDemoController extends Controller
{
    public function index(): View
    {
        return view('eimzo::index');
    }

    public function login(): View
    {
        return view('eimzo::login');
    }

    public function sign(Request $request): View
    {
        return view('eimzo::sign');
    }

    public function verify(): View
    {
        return view('eimzo::verify');
    }

    public function examples(): View
    {
        return view('eimzo::examples.index');
    }

    public function loginExample(): View
    {
        return view('eimzo::examples.login');
    }

    public function documentSignExample(): View
    {
        return view('eimzo::examples.document-sign');
    }

    public function actionSignExample(): View
    {
        $payload = [
            'action' => 'approve_invoice',
            'entity_type' => 'invoice',
            'entity_id' => 1024,
            'amount' => 1500000,
            'currency' => 'UZS',
            'approved_by' => optional(auth()->user())->getAuthIdentifier() ?: 1,
            'issued_at' => now()->toIso8601String(),
            'nonce' => 'example-nonce-123',
        ];

        ksort($payload);

        return view('eimzo::examples.action-sign', [
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
