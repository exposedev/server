<?php

namespace Expose\Server\Http\Controllers\Admin;

use Expose\Server\Contracts\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;

class StoreUsersController extends AdminController
{
    protected $keepConnectionOpen = true;

    /** @var UserRepository */
    protected $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'auth_token' => 'nullable',
            'max_connections' => ['nullable', 'integer'],
        ], [
            'required' => 'The :attribute field is required.',
        ]);

        if ($validator->fails()) {
            $httpConnection->send(respond_json(['errors' => $validator->getMessageBag()], 401));
            $httpConnection->close();

            return;
        }

        $insertData = [
            'name' => $request->get('name'),
            'auth_token' => $request->get('auth_token', (string) Str::uuid()),
            'can_specify_subdomains' => (int) $request->get('can_specify_subdomains'),
            'can_specify_domains' => (int) $request->get('can_specify_domains'),
            'can_share_tcp_ports' => 0,
            'max_connections' => (int) $request->get('max_connections'),
        ];

        $this->userRepository
            ->storeUser($insertData)
            ->then(function ($user) use ($httpConnection) {
                $httpConnection->send(respond_json(['user' => $user], 200));
                $httpConnection->close();
            });
    }
}
