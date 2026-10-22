<?php

namespace Serpensin\RenderedLogs\Services;

use App\Enums\NodeJwtScope;
use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use App\Services\Nodes\NodeJWTService;
use App\Services\Servers\GetUserPermissionsService;
use JsonException;
use RuntimeException;

class WingsWebsocketLogFetcher
{
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const READ_TIMEOUT_SECONDS = 5;
    private const LOG_COLLECTION_SECONDS = 3;
    private const MAX_FRAME_BYTES = 1_048_576;
    private const MAX_MESSAGE_BYTES = 1_048_576;

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

        $socket = null;
        try {
            $socket = $this->connect($this->wingsUrl($server), rtrim((string) config('app.url'), '/'));
            $this->sendText($socket, json_encode(['event' => 'auth', 'args' => [$token]], JSON_THROW_ON_ERROR));

            return $this->collectLogs($socket);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the Wings WebSocket message.', previous: $exception);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function wingsUrl(Server $server): string
    {
        $address = rtrim($server->node->getConnectionAddress(), '/');
        $address = preg_replace('#^https://#i', 'wss://', $address) ?? $address;
        $address = preg_replace('#^http://#i', 'ws://', $address) ?? $address;

        return $address . "/api/servers/{$server->uuid}/ws";
    }

    /** @return resource */
    private function connect(string $url, string $origin)
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');

        if (!in_array($scheme, ['ws', 'wss'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || str_contains($host, "\r") || str_contains($host, "\n")) {
            throw new RuntimeException('Wings returned an invalid WebSocket endpoint.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Wings returned an invalid WebSocket port.');
        }

        $hostForSocket = str_contains($host, ':') ? "[{$host}]" : $host;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ],
        ]);
        $transport = $scheme === 'wss' ? 'tls' : 'tcp';
        $socket = @stream_socket_client(
            "{$transport}://{$hostForSocket}:{$port}",
            $errorNumber,
            $errorMessage,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new RuntimeException("Could not connect to Wings WebSocket endpoint ({$errorNumber}: {$errorMessage}).");
        }

        $hostHeader = $hostForSocket . ((($scheme === 'wss' && $port !== 443) || ($scheme === 'ws' && $port !== 80)) ? ":{$port}" : '');
        $target = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $key = base64_encode(random_bytes(16));
        $request = self::buildHandshakeRequest($target, $hostHeader, $key, $origin);
        $this->writeAll($socket, $request);
        self::validateHandshakeResponse($this->readHttpHeaders($socket), $key);

        return $socket;
    }

    /** @return string[] */
    private function collectLogs($socket): array
    {
        $logs = [];
        $authenticated = false;
        $deadline = microtime(true) + self::READ_TIMEOUT_SECONDS;
        $fragmentOpcode = null;
        $fragmentPayload = '';

        while (microtime(true) < $deadline) {
            try {
                $frame = $this->readFrame($socket, $deadline);
            } catch (RuntimeException $exception) {
                if ($authenticated && str_contains($exception->getMessage(), 'timed out')) {
                    break;
                }
                throw $exception;
            }

            $opcode = $frame['opcode'];
            $payload = $frame['payload'];
            if ($opcode === 0x8) {
                $this->sendFrame($socket, 0x8, $payload);
                break;
            }
            if ($opcode === 0x9) {
                $this->sendFrame($socket, 0xA, $payload);
                continue;
            }
            if ($opcode === 0xA) {
                continue;
            }

            if ($opcode === 0x0) {
                if ($fragmentOpcode === null) {
                    throw new RuntimeException('Wings sent an unexpected WebSocket continuation frame.');
                }
                $fragmentPayload .= $payload;
                if (strlen($fragmentPayload) > self::MAX_MESSAGE_BYTES) {
                    throw new RuntimeException('Wings sent an oversized WebSocket message.');
                }
                if (!$frame['fin']) {
                    continue;
                }
                $opcode = $fragmentOpcode;
                $payload = $fragmentPayload;
                $fragmentOpcode = null;
                $fragmentPayload = '';
            } elseif (!$frame['fin']) {
                if ($fragmentOpcode !== null || !in_array($opcode, [0x1, 0x2], true)) {
                    throw new RuntimeException('Wings sent an invalid fragmented WebSocket message.');
                }
                $fragmentOpcode = $opcode;
                $fragmentPayload = $payload;
                continue;
            } elseif ($fragmentOpcode !== null) {
                throw new RuntimeException('Wings sent an invalid WebSocket message sequence.');
            }

            if ($opcode === 0x2) {
                continue;
            }
            if ($opcode !== 0x1) {
                throw new RuntimeException('Wings sent an unsupported WebSocket frame.');
            }

            $message = json_decode($payload, true);
            if (!is_array($message)) {
                continue;
            }
            if (($message['event'] ?? null) === 'auth success' && !$authenticated) {
                $authenticated = true;
                $this->sendText($socket, json_encode(['event' => 'send logs', 'args' => [null]], JSON_THROW_ON_ERROR));
                $deadline = min($deadline, microtime(true) + self::LOG_COLLECTION_SECONDS);
                continue;
            }
            if (($message['event'] ?? null) === 'console output' && isset($message['args'][0])) {
                $logs[] = (string) $message['args'][0];
            }
        }

        if (!$authenticated) {
            throw new RuntimeException('Wings did not authenticate the WebSocket connection.');
        }

        return $logs;
    }

    private function readHttpHeaders($socket): string
    {
        $headers = '';
        $deadline = microtime(true) + self::READ_TIMEOUT_SECONDS;
        while (!str_contains($headers, "\r\n\r\n")) {
            $headers .= $this->readExact($socket, 1, $deadline);
            if (strlen($headers) > 16_384) {
                throw new RuntimeException('Wings sent oversized HTTP response headers.');
            }
        }

        return $headers;
    }

    /** @return array{fin: bool, opcode: int, payload: string} */
    private function readFrame($socket, float $deadline): array
    {
        $firstTwoBytes = $this->readExact($socket, 2, $deadline);
        $header = self::decodeFrameHeader($firstTwoBytes);
        if ($header['headerLength'] > 2) {
            $header = self::decodeFrameHeader($firstTwoBytes . $this->readExact($socket, $header['headerLength'] - 2, $deadline));
        }
        if ($header['masked']) {
            throw new RuntimeException('Wings sent a masked WebSocket frame.');
        }
        if ($header['payloadLength'] > self::MAX_FRAME_BYTES) {
            throw new RuntimeException('Wings sent an oversized WebSocket frame.');
        }

        return [
            'fin' => $header['fin'],
            'opcode' => $header['opcode'],
            'payload' => $this->readExact($socket, $header['payloadLength'], $deadline),
        ];
    }

    private function readExact($socket, int $length, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Wings WebSocket read timed out.');
            }
            $seconds = (int) $remaining;
            $microseconds = max(1, (int) (($remaining - $seconds) * 1_000_000));
            stream_set_timeout($socket, $seconds, $microseconds);
            $chunk = fread($socket, $length - strlen($data));
            $meta = stream_get_meta_data($socket);
            if ($chunk === false || $chunk === '' || $meta['timed_out']) {
                throw new RuntimeException('Wings WebSocket read timed out.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function sendText($socket, string $payload): void
    {
        $this->sendFrame($socket, 0x1, $payload);
    }

    private function sendFrame($socket, int $opcode, string $payload): void
    {
        $this->writeAll($socket, self::encodeClientFrame($opcode, $payload));
    }

    private function writeAll($socket, string $data): void
    {
        $written = 0;
        while ($written < strlen($data)) {
            $count = stream_socket_sendto($socket, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('Could not write to the Wings WebSocket connection.');
            }
            $written += $count;
        }
    }

    public static function buildHandshakeRequest(string $target, string $host, string $key, string $origin): string
    {
        if ($target === '' || $target[0] !== '/' || preg_match('/[\r\n]/', $target . $host . $key . $origin)) {
            throw new RuntimeException('Invalid WebSocket handshake value.');
        }

        return "GET {$target} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Origin: {$origin}\r\n\r\n";
    }

    public static function validateHandshakeResponse(string $response, string $key): void
    {
        $lines = explode("\r\n", rtrim($response, "\r\n"));
        $status = array_shift($lines);
        if (!is_string($status) || preg_match('#^HTTP/\d\.\d\s+101(?:\s|$)#i', $status) !== 1) {
            throw new RuntimeException('Wings rejected the WebSocket handshake.');
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, null);
            if ($value === null) {
                throw new RuntimeException('Wings sent malformed WebSocket handshake headers.');
            }
            $headers[strtolower(trim($name))][] = trim($value);
        }
        $upgrade = implode(',', $headers['upgrade'] ?? []);
        $connection = implode(',', $headers['connection'] ?? []);
        $acceptValues = $headers['sec-websocket-accept'] ?? [];
        $accept = count($acceptValues) === 1 ? $acceptValues[0] : '';
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!self::headerHasToken($upgrade, 'websocket') || !self::headerHasToken($connection, 'upgrade') || !hash_equals($expected, $accept)) {
            throw new RuntimeException('Wings returned an invalid WebSocket handshake.');
        }
    }

    /** @return array{fin: bool, opcode: int, masked: bool, payloadLength: int, headerLength: int} */
    public static function decodeFrameHeader(string $header): array
    {
        if (strlen($header) < 2) {
            throw new RuntimeException('Incomplete WebSocket frame header.');
        }
        $first = ord($header[0]);
        $second = ord($header[1]);
        if (($first & 0x70) !== 0) {
            throw new RuntimeException('WebSocket extensions are not supported.');
        }
        $lengthCode = $second & 0x7F;
        $headerLength = 2 + ($lengthCode === 126 ? 2 : ($lengthCode === 127 ? 8 : 0));
        if (strlen($header) < $headerLength) {
            return ['fin' => (bool) ($first & 0x80), 'opcode' => $first & 0x0F, 'masked' => (bool) ($second & 0x80), 'payloadLength' => 0, 'headerLength' => $headerLength];
        }
        if ($lengthCode < 126) {
            $payloadLength = $lengthCode;
        } elseif ($lengthCode === 126) {
            $payloadLength = unpack('n', substr($header, 2, 2))[1];
        } else {
            $length = unpack('Nhigh/Nlow', substr($header, 2, 8));
            if (($length['high'] & 0x80000000) !== 0 || $length['high'] > 0 || $length['low'] > PHP_INT_MAX) {
                throw new RuntimeException('WebSocket frame length is too large.');
            }
            $payloadLength = $length['low'];
        }
        $opcode = $first & 0x0F;
        if (in_array($opcode, [0x8, 0x9, 0xA], true) && (!(bool) ($first & 0x80) || $payloadLength > 125)) {
            throw new RuntimeException('Invalid WebSocket control frame.');
        }

        return ['fin' => (bool) ($first & 0x80), 'opcode' => $opcode, 'masked' => (bool) ($second & 0x80), 'payloadLength' => $payloadLength, 'headerLength' => $headerLength];
    }

    public static function encodeClientFrame(int $opcode, string $payload): string
    {
        if (!in_array($opcode, [0x1, 0x2, 0x8, 0x9, 0xA], true)) {
            throw new RuntimeException('Unsupported client WebSocket opcode.');
        }
        $length = strlen($payload);
        if ($length > self::MAX_FRAME_BYTES || (in_array($opcode, [0x8, 0x9, 0xA], true) && $length > 125)) {
            throw new RuntimeException('WebSocket client frame is too large.');
        }

        $mask = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }
        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('NN', 0, $length);
        }

        return $header . $mask . $masked;
    }

    private static function headerHasToken(string $value, string $token): bool
    {
        foreach (explode(',', $value) as $part) {
            if (strcasecmp(trim($part), $token) === 0) {
                return true;
            }
        }

        return false;
    }
}
