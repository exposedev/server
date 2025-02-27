<?php

declare(strict_types=1);

namespace Expose\Server\Support\Messages;

use Expose\Server\Connections\ControlConnection;

class ResolveConnectionMessage
{
    public function __invoke(ControlConnection $connectionInfo, array|null $user)
    {
        return config('expose-server.messages.message_of_the_day');
    }
}
