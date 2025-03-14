<?php

namespace Expose\Server\Http\Controllers\Admin;

use Expose\Server\Configuration;
use Expose\Server\Contracts\ConnectionManager;
use Illuminate\Http\Request;
use Ratchet\ConnectionInterface;

class ListTcpConnectionsController extends AdminController
{
    /** @var ConnectionManager */
    protected $connectionManager;
    /** @var Configuration */
    protected $configuration;

    public function __construct(ConnectionManager $connectionManager, Configuration $configuration)
    {
        $this->connectionManager = $connectionManager;
        $this->configuration = $configuration;
    }

    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $sites = $this->getView($httpConnection, 'server.tcp.index', [
            'scheme' => $this->configuration->port() === 443 ? 'https' : 'http',
            'configuration' => $this->configuration,
        ]);

        $httpConnection->send(
            respond_html($sites)
        );
    }
}
