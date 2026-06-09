<?php

namespace App\Http\Controllers;

use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Integrations\Google\GoogleOAuthClient;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Per-tenant Google connect flow (the OAuth backend; a polished button/picker is
 * a thin §7 follow-up). Authorize redirects the client to Google's consent;
 * the callback exchanges the code, vaults the tokens on the site's Connection,
 * lists the available GSC/GA4 properties (auto-selecting when there's exactly
 * one), and confirms the grant works — the per-tenant live check the platform
 * verify-vendors probe cannot do.
 */
class GoogleConnectController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly GoogleConnectionService $connections,
    ) {}

    public function authorize(Request $request, string $site): RedirectResponse
    {
        $model = Site::withoutGlobalScope(SiteScope::class)->findOrFail($site);

        $state = Str::random(40);
        $request->session()->put('google_oauth', ['state' => $state, 'site_id' => $model->id]);

        return redirect()->away($this->oauth->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $stored = (array) $request->session()->pull('google_oauth', []);

        if (($request->query('state') ?? null) !== ($stored['state'] ?? null) || ! isset($stored['site_id'])) {
            return redirect('/')->with('google_connect_error', 'Invalid OAuth state.');
        }

        if ($request->query('error') !== null) {
            return redirect('/')->with('google_connect_error', 'Consent was denied: '.(string) $request->query('error'));
        }

        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail((string) $stored['site_id']);

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code'));
            $connection = $this->connections->store($site, $token);

            // Per-tenant grant verification: listing properties proves the grant +
            // API access work. Auto-select when exactly one of each is available.
            $gscSites = $this->connections->listGscSites($connection);
            $ga4Properties = $this->connections->listGa4Properties($connection);

            $this->connections->selectProperties(
                $connection,
                count($gscSites) === 1 ? $gscSites[0] : null,
                count($ga4Properties) === 1 ? $ga4Properties[0]['property'] : null,
            );

            return redirect('/')->with('google_connect_ok', sprintf(
                'Google connected: %d GSC site(s), %d GA4 property(ies).',
                count($gscSites),
                count($ga4Properties),
            ));
        } catch (GoogleException $e) {
            return redirect('/')->with('google_connect_error', $e->getMessage());
        }
    }
}
