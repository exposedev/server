<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Allowed Memory
    |--------------------------------------------------------------------------
    |
    | The maximum memory allocated to the expose-server process.
    |
    */
    'memory_limit' => '128M',


    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The SQLite database that your expose server should use. This database
    | will hold all users that are able to authenticate with your server,
    | if you enable authentication token validation.
    |
    */
    'database' => implode(DIRECTORY_SEPARATOR, [
        $_SERVER['HOME'] ?? __DIR__,
        '.expose',
        'expose.db',
    ]),

    /*
    |--------------------------------------------------------------------------
    | Validate auth tokens
    |--------------------------------------------------------------------------
    |
    | By default, once you start an expose server, anyone is able to connect to
    | it, given that they know the server host. If you want to only allow the
    | connection from users that have valid authentication tokens, set this
    | setting to true. You can also modify this at runtime in the server
    | admin interface.
    |
    */
    'validate_auth_tokens' => false,

    /*
    |--------------------------------------------------------------------------
    | Maximum connection length
    |--------------------------------------------------------------------------
    |
    | If you want to limit the amount of time that a single connection can
    | stay connected to the expose server, you can specify the maximum
    | connection length in minutes here. A maximum length of 0 means that
    | clients can stay connected as long as they want.
    |
    */
    'maximum_connection_length' => 0,

    /*
    |--------------------------------------------------------------------------
    | Maximum number of open connections
    |--------------------------------------------------------------------------
    |
    | You can limit the amount of connections that one client/user can have
    | open. A maximum connection count of 0 means that clients can open
    | as many connections as they want.
    |
    | When creating users with the API/admin interface, you can
    | override this setting per user.
    |
    */
    'maximum_open_connections_per_user' => 0,

    /*
    |--------------------------------------------------------------------------
    | Subdomain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain that your expose admin dashboard will be available at.
    | The given subdomain will be reserved, so no other tunnel connection can
    | request this subdomain for their own connection.
    |
    */
    'subdomain' => 'expose',

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomain
    |--------------------------------------------------------------------------
    |
    | Specify any subdomains that you don't want to be able to register
    | on your expose server.
    |
    */
    'reserved_subdomains' => [],

    /*
    |--------------------------------------------------------------------------
    | Subdomain Generator
    |--------------------------------------------------------------------------
    |
    | This is the subdomain generator that will be used, when no specific
    | subdomain was provided. The default implementation simply generates
    | a random string for you. Feel free to change this.
    |
    */
    'subdomain_generator' => \Expose\Server\SubdomainGenerator\RandomSubdomainGenerator::class,

    /*
    |--------------------------------------------------------------------------
    | Connection Callback
    |--------------------------------------------------------------------------
    |
    | This is a callback method that will be called when a new connection is
    | established.
    | The \Expose\Server\Callbacks\WebHookConnectionCallback::class is included out of the box.
    |
    */
    'connection_callback' => null,


    'connection_callbacks' => [
        'webhook' => [
            'url' => null,
            'secret' => null,
        ],
    ],

    /*
        |--------------------------------------------------------------------------
        | Users
        |--------------------------------------------------------------------------
        |
        | The admin dashboard of expose is protected via HTTP basic authentication
        | Here you may add the user/password combinations that you want to
        | accept as valid logins for the dashboard.
        |
        */
    'users' => [
        'username' => 'secret',
    ],

    /*
        |--------------------------------------------------------------------------
        | User Repository
        |--------------------------------------------------------------------------
        |
        | This is the user repository, which by default loads and saves all authorized
        | users in a SQLite database. You can implement your own user repository
        | if you want to store your users in a different store (Redis, MySQL, etc.)
        |
        */
    'user_repository' => \Expose\Server\UserRepository\DatabaseUserRepository::class,

    'subdomain_repository' => \Expose\Server\SubdomainRepository\DatabaseSubdomainRepository::class,

    'logger_repository' => \Expose\Server\LoggerRepository\DatabaseLogger::class,

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    |
    | The default messages that the expose server will send the clients.
    | These settings can also be changed at runtime in the expose admin
    | interface.
    |
    */
    'messages' => [
        'resolve_connection_message' => \Expose\Server\Support\Messages\ResolveConnectionMessage::class,

        'message_of_the_day' => 'Thank you for using expose.',

        'invalid_auth_token' => 'Authentication failed. Please check your authentication token and try again.',

        'subdomain_taken' => 'The chosen subdomain :subdomain is already taken. Please choose a different subdomain.',

        'subdomain_reserved' => 'The chosen subdomain :subdomain is not available. Please choose a different subdomain.',

        'custom_subdomain_unauthorized' => 'You are not allowed to specify custom subdomains. Please upgrade to Expose Pro. Assigning a random subdomain instead.',

        'custom_domain_unauthorized' => 'You are not allowed to use this custom domain. If you think this should work, double-check the server setting and try again.',

        'maximum_connection_length_reached' => 'You have reached the maximum connection length for this server. Please upgrade to Expose Pro for unlimited connection length.',
    ],

    'statistics' => [
        'enable_statistics' => true,

        'interval_in_seconds' => 3600,

        'repository' => \Expose\Server\StatisticsRepository\DatabaseStatisticsRepository::class,
    ],
];
