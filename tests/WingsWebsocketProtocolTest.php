<?php

require dirname(__DIR__) . '/src/Services/WingsWebsocketLogFetcher.php';

use Serpensin\RenderedLogs\Services\WingsWebsocketLogFetcher;

function wsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function wsThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

$key = 'dGhlIHNhbXBsZSBub25jZQ==';
$request = WingsWebsocketLogFetcher::buildHandshakeRequest('/api/servers/example/ws', 'wings.example:8080', $key, 'https://panel.example');
wsAssert(str_contains($request, "GET /api/servers/example/ws HTTP/1.1\r\n"), 'Handshake uses the requested target.');
wsAssert(str_contains($request, "Sec-WebSocket-Version: 13\r\n"), 'Handshake declares RFC6455 version 13.');

$response = "HTTP/1.1 101 Switching Protocols\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: keep-alive, Upgrade\r\n"
    . "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n";
WingsWebsocketLogFetcher::validateHandshakeResponse($response, $key);
wsThrows(
    static fn () => WingsWebsocketLogFetcher::validateHandshakeResponse(str_replace('s3pPL', 'AAAAA', $response), $key),
    'Handshake validation accepted an invalid Sec-WebSocket-Accept value.',
);

$payload = str_repeat('x', 130);
$frame = WingsWebsocketLogFetcher::encodeClientFrame(0x1, $payload);
$header = WingsWebsocketLogFetcher::decodeFrameHeader($frame);
wsAssert($header['fin'] && $header['opcode'] === 0x1, 'Client frame has the expected FIN and opcode.');
wsAssert($header['masked'], 'Client WebSocket frames must be masked.');
wsAssert($header['payloadLength'] === strlen($payload), 'Extended payload length was encoded correctly.');
$mask = substr($frame, $header['headerLength'], 4);
$maskedPayload = substr($frame, $header['headerLength'] + 4);
$unmasked = '';
for ($index = 0; $index < strlen($maskedPayload); $index++) {
    $unmasked .= $maskedPayload[$index] ^ $mask[$index % 4];
}
wsAssert($unmasked === $payload, 'Client frame payload does not round-trip through its mask.');

$serverTextHeader = WingsWebsocketLogFetcher::decodeFrameHeader(chr(0x81) . chr(3));
wsAssert(!$serverTextHeader['masked'] && $serverTextHeader['payloadLength'] === 3, 'Server text-frame header was parsed incorrectly.');
wsThrows(
    static fn () => WingsWebsocketLogFetcher::decodeFrameHeader(chr(0x09) . chr(126) . pack('n', 126)),
    'Fragmented control frame was accepted.',
);
wsThrows(
    static fn () => WingsWebsocketLogFetcher::decodeFrameHeader(chr(0xC1) . chr(0)),
    'RSV WebSocket extension bit was accepted.',
);

$fetcherSource = file_get_contents(dirname(__DIR__) . '/src/Services/WingsWebsocketLogFetcher.php');
$forbiddenWrite = 'f' . 'write' . '(';
wsAssert(!str_contains($fetcherSource, $forbiddenWrite), 'Hub-blocked write function remains in the WebSocket client.');

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
wsAssert($pair !== false, 'Could not create a local stream socket pair.');
$fetcher = (new ReflectionClass(WingsWebsocketLogFetcher::class))->newInstanceWithoutConstructor();
$writeAll = new ReflectionMethod(WingsWebsocketLogFetcher::class, 'writeAll');
$writeAll->invoke($fetcher, $pair[0], 'masked client frame');
stream_set_timeout($pair[1], 1);
wsAssert(stream_get_contents($pair[1]) === 'masked client frame', 'Native stream write did not deliver the complete payload.');
fclose($pair[0]);
fclose($pair[1]);

print "websocket protocol assertions passed\n";
