<?php

namespace Expose\Server\Http\Controllers;

use Expose\Server\Configuration;
use Expose\Server\Connections\ControlConnection;
use Expose\Server\Connections\HttpConnection;
use Expose\Server\Contracts\ConnectionManager;
use Expose\Server\Contracts\DomainRepository;
use Expose\Server\Contracts\StatisticsCollector;
use Expose\Common\Http\Controllers\Controller;
use GuzzleHttp\Psr7\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nyholm\Psr7\Factory\Psr17Factory;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\Frame;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

class TunnelMessageController extends Controller
{
    /** @var ConnectionManager */
    protected $connectionManager;

    /** @var Configuration */
    protected $configuration;

    protected $keepConnectionOpen = true;

    protected $modifiers = [];

    /** @var StatisticsCollector */
    protected $statisticsCollector;

    /** @var DomainRepository */
    protected $domainRepository;

    public function __construct(ConnectionManager $connectionManager, StatisticsCollector $statisticsCollector, Configuration $configuration, DomainRepository $domainRepository)
    {
        $this->connectionManager = $connectionManager;
        $this->configuration = $configuration;
        $this->statisticsCollector = $statisticsCollector;
        $this->domainRepository = $domainRepository;
    }

    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $subdomain = $this->detectSubdomain($request);
        $serverHost = $this->detectServerHost($request);

        if (is_null($subdomain)) {
            $httpConnection->send(
                respond_html($this->getBlade($httpConnection, 'server.homepage'), 200)
            );
            $httpConnection->close();

            return;
        }

        $controlConnection = $this->connectionManager->findControlConnectionForSubdomainAndServerHost($subdomain, $serverHost);

        if (is_null($controlConnection)) {
            $this->domainRepository
                ->getDomainByName(strtolower($serverHost))
                ->then(function ($domain) use ($subdomain, $httpConnection) {
                    if (is_null($domain) || is_null($domain['error_page'])) {
                        $errorPageContent = $this->getBlade($httpConnection, 'server.errors.404', ['subdomain' => $subdomain]);
                    } else {
                        $errorPageContent = str_replace(
                            ['%%subdomain%%'],
                            [$subdomain],
                            $domain['error_page']
                        );
                    }

                    $httpConnection->send(
                        respond_html($errorPageContent, 404)
                    );
                    $httpConnection->close();
                });
            return;
        }

        $this->statisticsCollector->incomingRequest();

        $this->sendRequestToClient($request, $controlConnection, $httpConnection);
    }

    protected function detectSubdomain(Request $request): ?string
    {
        $serverHost = $this->detectServerHost($request);

        $subdomain = Str::before($request->header('Host'), '.'.$serverHost);

        return $subdomain === $request->header('Host') ? null : $subdomain;
    }

    protected function detectServerHost(Request $request): ?string
    {
        return Str::before(Str::after($request->header('Host'), '.'), ':');
    }

    protected function sendRequestToClient(Request $request, ControlConnection $controlConnection, ConnectionInterface $httpConnection)
    {
        $request = $this->prepareRequest($request, $controlConnection);

        $requestId = $request->header('X-Expose-Request-ID');

        $httpConnection = $this->connectionManager->storeHttpConnection($httpConnection, $requestId);

        transform($this->passRequestThroughModifiers($request, $httpConnection), function (Request $request) use ($httpConnection, $controlConnection, $requestId) {
            $controlConnection->once('proxy_ready_'.$requestId, function (ConnectionInterface $proxy) use ($httpConnection, $request) {
                // Convert the Laravel request into a PSR7 request
                $psr17Factory = new Psr17Factory();
                $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
                $request = $psrHttpFactory->createRequest($request);

                $httpConnection->getConnection()->on('data', function($d) use ($proxy) {
                    $proxy->send(new Frame($d, true, Frame::OP_BINARY));
                });

                $binaryMsg = new Frame(Message::toString($request), true, Frame::OP_BINARY);
                $proxy->send($binaryMsg);
            });

            $controlConnection->registerProxy($requestId);
        });
    }

    protected function passRequestThroughModifiers(Request $request, HttpConnection $httpConnection): ?Request
    {
        foreach ($this->modifiers as $modifier) {
            $request = app($modifier)->handle($request, $httpConnection);

            if (is_null($request)) {
                break;
            }
        }

        return $request;
    }

    protected function prepareRequest(Request $request, ControlConnection $controlConnection): Request
    {
        $request::setTrustedProxies(
            [$controlConnection->socket->remoteAddress, '127.0.0.1'],
            Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $host = $controlConnection->serverHost;

        if (! $request->isSecure()) {
            $host .= ":{$this->configuration->port()}";
        }

        $request->headers->set('Host', $controlConnection->host);
        $request->headers->set('X-Forwarded-Proto', $request->isSecure() ? 'https' : 'http');
        $request->headers->set('X-Expose-Request-ID', uniqid());
        $request->headers->set('Upgrade-Insecure-Requests', 1);
        $request->headers->set('X-Exposed-By', config('app.name').' '.config('app.version'));
        $request->headers->set('X-Original-Host', "{$controlConnection->subdomain}.{$host}");
        $request->headers->set('X-Forwarded-Host', "{$controlConnection->subdomain}.{$host}");

        return $request;
    }
}
