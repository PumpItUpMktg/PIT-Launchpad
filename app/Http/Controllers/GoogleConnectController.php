<?php

namespace App\Http\Controllers;

use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Integrations\Google\GoogleOAuthClient;
use App\Models\GoogleAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Platform-wide Google connect flow — the OAuth backend for the ONE shared grant ("one email" the
 * operator connects once; every client then adds that email as a user on their GSC + GA4 property).
 * Authorize redirects to Google's consent; the callback exchanges the code, vaults the tokens on the
 * {@see GoogleAccount} singleton, and confirms the grant works by listing the visible
 * properties. WHICH property each tenant reads is chosen later in the per-site property picker — the
 * callback no longer auto-selects (there's no single tenant to select for).
 */
class GoogleConnectController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly GoogleConnectionService $connections,
    ) {}

    public function authorize(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('google_oauth', ['state' => $state]);

        return redirect()->away($this->oauth->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $stored = (array) $request->session()->pull('google_oauth', []);

        if (($request->query('state') ?? null) !== ($stored['state'] ?? null)) {
            return redirect('/')->with('google_connect_error', 'Invalid OAuth state.');
        }

        if ($request->query('error') !== null) {
            return redirect('/')->with('google_connect_error', 'Consent was denied: '.(string) $request->query('error'));
        }

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code'));
            $account = $this->connections->store($token);

            // Grant verification: listing properties proves the grant + API access work. These are the
            // full set the shared account can see across ALL clients — each tenant picks its own next.
            $gscSites = $this->connections->listGscSites($account);
            $ga4Properties = $this->connections->listGa4Properties($account);

            return redirect('/')->with('google_connect_ok', sprintf(
                'Google connected: %d GSC site(s), %d GA4 property(ies) visible to the shared account.',
                count($gscSites),
                count($ga4Properties),
            ));
        } catch (GoogleException $e) {
            return redirect('/')->with('google_connect_error', $e->getMessage());
        }
    }
}
