<?php

namespace Tests\Feature\Server;

use Expose\Server\Connections\ConnectionManager;
use Expose\Server\Connections\ControlConnection;
use Expose\Server\Contracts\LoggerRepository;
use Expose\Server\Contracts\StatisticsCollector;
use Expose\Server\Contracts\SubdomainGenerator;
use Expose\Server\Contracts\UserRepository;
use Mockery;
use React\EventLoop\LoopInterface;
use Tests\Feature\TestCase;

class ConnectionManagerTest extends TestCase
{
    /** @test */
    public function it_does_not_apply_connection_length_limits_to_users_that_can_specify_subdomains()
    {
        $loop = Mockery::mock(LoopInterface::class);
        $loop->shouldNotReceive('addTimer');

        $statisticsCollector = Mockery::mock(StatisticsCollector::class);
        $statisticsCollector->shouldNotReceive('cooldownTriggered');

        $connection = Mockery::mock(ControlConnection::class);
        $connection->authToken = 'pro-user-token';
        $connection->shouldNotReceive('setMaximumConnectionLength');
        $connection->shouldNotReceive('closeWithoutReconnect');

        $this->app->instance(UserRepository::class, Mockery::mock(UserRepository::class));

        $manager = new ConnectionManager(
            Mockery::mock(SubdomainGenerator::class),
            $statisticsCollector,
            Mockery::mock(LoggerRepository::class),
            $loop
        );

        $manager->limitConnectionLength($connection, 60, [
            'can_specify_subdomains' => 1,
        ]);
    }

    /** @test */
    public function it_still_applies_connection_length_limits_to_users_without_custom_subdomains()
    {
        config()->set('expose-server.connection_cooldown_period', 10);

        $timerCallback = null;

        $loop = Mockery::mock(LoopInterface::class);
        $loop->shouldReceive('addTimer')
            ->once()
            ->withArgs(function ($seconds, $callback) use (&$timerCallback) {
                $this->assertSame(60, $seconds);
                $timerCallback = $callback;

                return is_callable($callback);
            });

        $statisticsCollector = Mockery::mock(StatisticsCollector::class);
        $statisticsCollector->shouldReceive('cooldownTriggered')->once();

        $connection = Mockery::mock(ControlConnection::class);
        $connection->authToken = 'regular-user-token';
        $connection->shouldReceive('setMaximumConnectionLength')->once()->with(1);
        $connection->shouldReceive('closeWithoutReconnect')->once();

        $userRepository = Mockery::mock(UserRepository::class);
        $userRepository->shouldReceive('setCooldownForToken')
            ->once()
            ->withArgs(function ($authToken, $cooldownEndsAt) {
                $this->assertSame('regular-user-token', $authToken);
                $this->assertIsInt($cooldownEndsAt);
                $this->assertGreaterThan(time(), $cooldownEndsAt);

                return true;
            })
            ->andReturn(\React\Promise\resolve(null));

        $this->app->instance(UserRepository::class, $userRepository);

        $manager = new ConnectionManager(
            Mockery::mock(SubdomainGenerator::class),
            $statisticsCollector,
            Mockery::mock(LoggerRepository::class),
            $loop
        );

        $manager->limitConnectionLength($connection, 1, [
            'can_specify_subdomains' => 0,
        ]);

        $this->assertNotNull($timerCallback);

        $timerCallback();
    }
}
