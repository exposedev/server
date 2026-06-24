<?php

namespace Expose\Server\Http\Controllers;

use Expose\Common\Http\Controllers\Controller;
use Expose\Server\Contracts\ConnectionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;

class CanIssueCertificateController extends Controller
{
    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $domain = $request->get('domain');

        if (blank($domain)) {
            $httpConnection->send(respond_html('Missing domain', 400));

            return;
        }

        // Parse the host the same way TunnelMessageController routes a request,
        // so the certificate decision matches whether a request for this host
        // would actually be served by a live tunnel.
        $serverHost = Str::before(Str::after($domain, '.'), ':');
        $subdomain = Str::before($domain, '.'.$serverHost);

        /** @var ConnectionManager $connectionManager */
        $connectionManager = app(ConnectionManager::class);

        $hasActiveTunnel = $subdomain !== $domain
            && $connectionManager->findControlConnectionForSubdomainAndServerHost($subdomain, $serverHost) !== null;

        // Caddy on-demand TLS treats any 2xx as "issue", anything else as "refuse".
        $httpConnection->send(respond_html(
            $hasActiveTunnel ? 'OK' : 'No active tunnel',
            $hasActiveTunnel ? 200 : 404
        ));
    }
}
