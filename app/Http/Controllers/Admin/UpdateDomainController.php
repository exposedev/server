<?php

namespace Expose\Server\Http\Controllers\Admin;

use Expose\Server\Contracts\DomainRepository;
use Expose\Server\Contracts\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Ratchet\ConnectionInterface;

class UpdateDomainController extends AdminController
{
    protected $keepConnectionOpen = true;

    /** @var DomainRepository */
    protected $domainRepository;

    /** @var UserRepository */
    protected $userRepository;

    public function __construct(UserRepository $userRepository, DomainRepository $domainRepository)
    {
        $this->userRepository = $userRepository;
        $this->domainRepository = $domainRepository;
    }

    public function handle(Request $request, ConnectionInterface $httpConnection)
    {
        $id = $request->get('id');

        $validator = Validator::make($request->all(), [
            'error_page' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            $httpConnection->send(respond_json(['errors' => $validator->getMessageBag()], 401));
            $httpConnection->close();

            return;
        }

        $this->userRepository
            ->getUserByToken($request->get('auth_token', ''))
            ->then(function ($user) use ($httpConnection, $request, $id) {
                if (is_null($user)) {
                    $httpConnection->send(respond_json(['error' => 'The user does not exist'], 404));
                    $httpConnection->close();

                    return;
                }

                if ($user['can_specify_domains'] === 0) {
                    $httpConnection->send(respond_json(['error' => 'The user is not allowed to reserve custom domains.'], 401));
                    $httpConnection->close();

                    return;
                }

                $updateData = [
                    'error_page' => $request->get('error_page'),
                ];

                $this->domainRepository
                    ->updateDomain($id, $updateData)
                    ->then(function ($domain) use ($httpConnection) {
                        $httpConnection->send(respond_json(['domain' => $domain], 200));
                        $httpConnection->close();
                    });
            });
    }
}
