<?php

namespace Expose\Server;

use Expose\Server\Connections\ConnectionManager;
use Expose\Server\Contracts\ConnectionManager as ConnectionManagerContract;
use Expose\Server\Contracts\DomainRepository;
use Expose\Server\Contracts\LoggerRepository;
use Expose\Server\Contracts\StatisticsCollector;
use Expose\Server\Contracts\StatisticsRepository;
use Expose\Server\Contracts\SubdomainGenerator;
use Expose\Server\Contracts\SubdomainRepository;
use Expose\Server\Contracts\UserRepository;
use Expose\Server\DomainRepository\DatabaseDomainRepository;
use Expose\Server\Http\Controllers\Admin\DeleteSubdomainController;
use Expose\Server\Http\Controllers\Admin\DeleteUsersController;
use Expose\Server\Http\Controllers\Admin\DisconnectSiteController;
use Expose\Server\Http\Controllers\Admin\GetDashboardStatsController;
use Expose\Server\Http\Controllers\Admin\GetLogsController;
use Expose\Server\Http\Controllers\Admin\GetLogsForSubdomainController;
use Expose\Server\Http\Controllers\Admin\GetSettingsController;
use Expose\Server\Http\Controllers\Admin\GetSiteDetailsController;
use Expose\Server\Http\Controllers\Admin\GetSitesController;
use Expose\Server\Http\Controllers\Admin\GetStatisticsController;
use Expose\Server\Http\Controllers\Admin\GetUserDetailsController;
use Expose\Server\Http\Controllers\Admin\GetUsersController;
use Expose\Server\Http\Controllers\Admin\ListSitesController;
use Expose\Server\Http\Controllers\Admin\ListUsersController;
use Expose\Server\Http\Controllers\Admin\RedirectToUsersController;
use Expose\Server\Http\Controllers\Admin\ShowSettingsController;
use Expose\Server\Http\Controllers\Admin\StoreDomainController;
use Expose\Server\Http\Controllers\Admin\StoreSettingsController;
use Expose\Server\Http\Controllers\Admin\StoreSubdomainController;
use Expose\Server\Http\Controllers\Admin\StoreUsersController;
use Expose\Server\Http\Controllers\Admin\UpdateDomainController;
use Expose\Server\Http\Controllers\ControlMessageController;
use Expose\Server\Http\Controllers\HealthController;
use Expose\Server\Http\Controllers\TunnelMessageController;
use Expose\Server\Http\Router;
use Expose\Server\Http\Server as HttpServer;
use Expose\Server\LoggerRepository\NullLogger;
use Expose\Server\StatisticsCollector\DatabaseStatisticsCollector;
use Expose\Server\StatisticsRepository\DatabaseStatisticsRepository;
use Expose\Server\SubdomainRepository\DatabaseSubdomainRepository;
use Clue\React\SQLite\DatabaseInterface;
use Expose\Common\Http\RouteGenerator;
use Phar;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use React\Socket\SocketServer;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;

class Factory
{
    /** @var string */
    protected $host = '127.0.0.1';

    /** @var string */
    protected $hostname = 'localhost';

    /** @var int */
    protected $port = 8080;

    /** @var \React\EventLoop\LoopInterface */
    protected $loop;

    /** @var RouteGenerator */
    protected $router;

    /** @var SocketServer */
    protected $socket;

    public function __construct()
    {
        gc_disable();

        $this->loop = Loop::get();
        $this->loop->addPeriodicTimer(30, fn () => gc_collect_cycles());

        $this->router = new RouteGenerator();
    }

    public function setHost(string $host)
    {
        $this->host = $host;

        return $this;
    }

    public function setPort(int $port)
    {
        $this->port = $port;

        return $this;
    }

    public function setLoop(LoopInterface $loop)
    {
        $this->loop = $loop;

        return $this;
    }

    public function setHostname(string $hostname)
    {
        $this->hostname = $hostname;

        return $this;
    }

    protected function addTunnelRoute()
    {
        $this->router->addSymfonyRoute('tunnel',
            new Route('/{__catchall__}', [
                '_controller' => app(TunnelMessageController::class),
            ], [
                '__catchall__' => '.*',
            ]));
    }

    protected function addControlConnectionRoute(): WsServer
    {
        $wsServer = new WsServer(app(ControlMessageController::class));

        $this->router->addSymfonyRoute('expose-control',
            new Route('/expose/control', [
                '_controller' => $wsServer,
            ], [], [], '', [], [], 'request.headers.get("x-expose-control") matches "/enabled/i"'));

        return $wsServer;
    }

    protected function addAdminRoutes()
    {
        $adminCondition = 'request.headers.get("Host") matches "/^'.config('expose-server.subdomain').'\\\\./i"';

        $this->router->get('/', RedirectToUsersController::class, $adminCondition);
        $this->router->get('/users', ListUsersController::class, $adminCondition);
        $this->router->get('/settings', ShowSettingsController::class, $adminCondition);
        $this->router->get('/sites', ListSitesController::class, $adminCondition);

        $this->router->get('/api/dashboard', GetDashboardStatsController::class, $adminCondition);

        $this->router->get('/api/statistics', GetStatisticsController::class, $adminCondition);
        $this->router->get('/api/settings', GetSettingsController::class, $adminCondition);
        $this->router->post('/api/settings', StoreSettingsController::class, $adminCondition);

        $this->router->get('/api/users', GetUsersController::class, $adminCondition);
        $this->router->post('/api/users', StoreUsersController::class, $adminCondition);
        $this->router->get('/api/users/{id}', GetUserDetailsController::class, $adminCondition);
        $this->router->delete('/api/users/{id}', DeleteUsersController::class, $adminCondition);

        $this->router->get('/api/logs', GetLogsController::class, $adminCondition);
        $this->router->get('/api/logs/{subdomain}', GetLogsForSubdomainController::class, $adminCondition);

        $this->router->post('/api/domains', StoreDomainController::class, $adminCondition);
        $this->router->put('/api/domains/{id}', UpdateDomainController::class, $adminCondition);
        $this->router->delete('/api/domains/{domain}', DeleteSubdomainController::class, $adminCondition);

        $this->router->post('/api/subdomains', StoreSubdomainController::class, $adminCondition);
        $this->router->delete('/api/subdomains/{subdomain}', DeleteSubdomainController::class, $adminCondition);

        $this->router->get('/api/sites', GetSitesController::class, $adminCondition);
        $this->router->get('/api/sites/{site}', GetSiteDetailsController::class, $adminCondition);
        $this->router->delete('/api/sites/{id}', DisconnectSiteController::class, $adminCondition);

        $this->router->get('/api/health', HealthController::class, $adminCondition);
    }

    protected function bindConfiguration()
    {
        app()->singleton(Configuration::class, function ($app) {
            return new Configuration($this->hostname, $this->port);
        });

        return $this;
    }

    protected function bindSubdomainGenerator()
    {
        app()->singleton(SubdomainGenerator::class, function ($app) {
            return $app->make(config('expose-server.subdomain_generator'));
        });

        return $this;
    }

    protected function bindConnectionManager()
    {
        app()->singleton(ConnectionManagerContract::class, function ($app) {
            return $app->make(ConnectionManager::class);
        });

        return $this;
    }

    public function createServer()
    {
        $this->socket = new SocketServer("{$this->host}:{$this->port}", [], $this->loop);

        $this->bindConfiguration()
            ->bindSubdomainGenerator()
            ->bindUserRepository()
            ->bindLoggerRepository()
            ->bindSubdomainRepository()
            ->bindDomainRepository()
            ->bindDatabase()
            ->ensureDatabaseIsInitialized()
            ->registerStatisticsCollector()
            ->bindConnectionManager()
            ->addAdminRoutes();

        $controlConnection = $this->addControlConnectionRoute();

        $this->addTunnelRoute();

        $urlMatcher = new UrlMatcher($this->router->getRoutes(), new RequestContext);

        $router = new Router($urlMatcher);

        $http = new HttpServer($router);

        $server = new IoServer($http, $this->socket, $this->loop);

        $controlConnection->enableKeepAlive($this->loop);

        return $server;
    }

    public function getSocket(): SocketServer
    {
        return $this->socket;
    }

    protected function bindUserRepository()
    {
        app()->singleton(UserRepository::class, function () {
            return app(config('expose-server.user_repository'));
        });

        return $this;
    }

    protected function bindSubdomainRepository()
    {
        app()->singleton(SubdomainRepository::class, function () {
            return app(config('expose-server.subdomain_repository', DatabaseSubdomainRepository::class));
        });

        return $this;
    }

    protected function bindLoggerRepository()
    {
        app()->singleton(LoggerRepository::class, function () {
            return app(config('expose-server.logger_repository', NullLogger::class));
        });

        return $this;
    }

    protected function bindDomainRepository()
    {
        app()->singleton(DomainRepository::class, function () {
            return app(config('expose-server.domain_repository', DatabaseDomainRepository::class));
        });

        return $this;
    }

    protected function bindDatabase()
    {
        app()->singleton(DatabaseInterface::class, function () {
            $factory = new \Clue\React\SQLite\Factory(
                $this->loop,
                Phar::running(false) ? null : ''
            );

            return $factory->openLazy(
                config('expose-server.database', ':memory:')
            );
        });

        return $this;
    }

    protected function ensureDatabaseIsInitialized()
    {
        /** @var DatabaseInterface $db */
        $db = app(DatabaseInterface::class);

        $migrations = (new Finder())
            ->files()
            ->ignoreDotFiles(true)
            ->in(database_path('migrations'))
            ->name('*.sql')
            ->sortByName();

        /** @var SplFileInfo $migration */
        foreach ($migrations as $migration) {
            $db->exec($migration->getContents())
                ->catch(function ($error) {
                    //
                });
        }

        return $this;
    }

    public function validateAuthTokens(bool $validate)
    {
        config()->set('expose-server.validate_auth_tokens', $validate);

        return $this;
    }

    protected function registerStatisticsCollector()
    {
        if (config('expose-server.statistics.enable_statistics', true) === false) {
            return $this;
        }

        app()->singleton(StatisticsRepository::class, function () {
            return app(config('expose-server.statistics.repository', DatabaseStatisticsRepository::class));
        });

        app()->singleton(StatisticsCollector::class, function () {
            return app(DatabaseStatisticsCollector::class);
        });

        $intervalInSeconds = config('expose-server.statistics.interval_in_seconds', 3600);

        $this->loop->addPeriodicTimer($intervalInSeconds, function () {
            app(StatisticsCollector::class)->save();
        });

        return $this;
    }
}
