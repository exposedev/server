<?php

namespace Expose\Server\Http\Controllers;

use Expose\Common\Http\Controllers\Controller;
use Expose\Server\Configuration;
use Expose\Server\Contracts\ConnectionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;

/**
 * Answers Caddy's on-demand TLS "ask" endpoint, permitting certificate
 * issuance only for the server's infrastructure hosts or a host with a live
 * tunnel. See the SSL documentation for how to wire up the Caddyfile.
 */
class CanIssueCertificateController extends Controller
{
    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $domain = $request->get('domain');

        if (blank($domain)) {
            $httpConnection->send(respond_html('Missing domain', 400));

            return;
        }

        $allowed = $this->isInfrastructureHost($domain) || $this->hasActiveTunnel($domain);

        // Caddy on-demand TLS treats any 2xx as "issue", anything else as "refuse".
        $httpConnection->send(respond_html(
            $allowed ? 'OK' : 'No active tunnel',
            $allowed ? 200 : 404
        ));
    }

    /**
     * Hosts that may obtain a certificate without a live tunnel: the bare
     * server hostname (the wildcard certificate covers *.host but not the host
     * itself, so it reaches the on-demand path), the admin dashboard subdomain,
     * and any host configured under on_demand_tls.always_allow_hosts.
     */
    protected function isInfrastructureHost(string $domain): bool
    {
        $hostname = app(Configuration::class)->hostname();

        return $domain === $hostname
            || $domain === config('expose-server.subdomain').'.'.$hostname
            || in_array($domain, config('expose-server.on_demand_tls.always_allow_hosts', []), true);
    }

    /**
     * Whether a control connection for this host is currently registered. The
     * host is parsed exactly like TunnelMessageController routes a request, so
     * the certificate decision matches whether the request would be served.
     */
    protected function hasActiveTunnel(string $domain): bool
    {
        $serverHost = Str::before(Str::after($domain, '.'), ':');
        $subdomain = Str::before($domain, '.'.$serverHost);

        if ($subdomain === $domain) {
            return false;
        }

        return app(ConnectionManager::class)
            ->findControlConnectionForSubdomainAndServerHost($subdomain, $serverHost) !== null;
    }
}
