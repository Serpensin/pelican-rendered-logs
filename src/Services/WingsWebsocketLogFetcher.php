<?php

namespace Serpensin\RenderedLogs\Services;

use App\Enums\NodeJwtScope;
use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use App\Services\Nodes\NodeJWTService;
use App\Services\Servers\GetUserPermissionsService;
use React\EventLoop\Loop;
use RuntimeException;

class WingsWebsocketLogFetcher
{
    public function __construct(
        private readonly GetUserPermissionsService $permissions,
        private readonly NodeJWTService $jwt,
    ) {}

    /** @return string[] */
    public function fetch(Server $server, User $user): array
    {
        if ($user->cannot(SubuserPermission::WebsocketConnect, $server)) {
            throw new RuntimeException('Console access is not permitted.');
        }

        $token = $this->jwt
            ->setExpiresAt(now()->addMinutes(2)->toImmutable())
            ->setScopes(NodeJwtScope::Websocket)
            ->setUser($user)
            ->setClaims([
                'server_uuid' => $server->uuid,
                'permissions' => $this->permissions->handle($server, $user),
            ])
            ->handle($server->node, $user->id . $server->uuid)
            ->toString();
        $url = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $server->node->getConnectionAddress()) . "/api/servers/{$server->uuid}/ws";
        $loop = Loop::get();
        $logs = [];
        $error = null;
        $connection = null;
        $finished = false;

        \Ratchet\Client\connect($url, [], [
            // The node's reverse proxy enforces the same origin policy as the browser console.
            'Origin' => rtrim((string) config('app.url'), '/'),
        ])->then(
            function ($socket) use (&$connection, &$logs, &$finished, $token, $loop): void {
                $connection = $socket;
                $socket->on('message', function ($message) use (&$logs, &$finished, $socket, $loop): void {
                    $payload = json_decode((string) $message, true);
                    if (($payload['event'] ?? null) === 'auth success') {
                        $socket->send(json_encode(['event' => 'send logs', 'args' => [null]], JSON_THROW_ON_ERROR));
                        // Wings sends a finite initial buffer but no explicit end event.
                        // Keep the socket open long enough for a 1,000-line buffer,
                        // then close it without subscribing to subsequent live output.
                        $loop->addTimer(3, function () use (&$finished, $socket, $loop): void {
                            $finished = true;
                            $socket->close();
                            $loop->stop();
                        });
                    }
                    if (($payload['event'] ?? null) === 'console output' && isset($payload['args'][0])) {
                        $logs[] = (string) $payload['args'][0];
                    }
                });
                $socket->send(json_encode(['event' => 'auth', 'args' => [$token]], JSON_THROW_ON_ERROR));
            },
            function (\Throwable $exception) use (&$error, &$finished): void { $error = $exception; $finished = true; },
        );
        $loop->addTimer(5, function () use (&$finished, &$connection, $loop): void { $finished = true; if ($connection) { $connection->close(); } $loop->stop(); });
        $loop->run();
        if ($error !== null) { throw new RuntimeException('Could not retrieve console logs from Wings.', previous: $error); }
        return $logs;
    }
}
