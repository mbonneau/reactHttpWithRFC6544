<?php

use Psr\Http\Message\ServerRequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\Http\Response;
use React\Socket\Server;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;

require __DIR__ . '/vendor/autoload.php';

$loop = Factory::create();

$socket = new Server('127.0.0.1:12345', $loop);

$server = new \React\Http\Server($socket, function (ServerRequestInterface $request) use ($loop) {
    $negotiator = new ServerNegotiator(new RequestVerifier());

    $response = $negotiator->handshake($request);

    if ($response->getStatusCode() !== 101) {
        echo "Invalid websocket connection from: " . $request->getServerParams()['REMOTE_ADDR'] . "\n";
        return new Response($response->getStatusCode(), $response->getHeaders());
    }

    echo "Connection from " . $request->getServerParams()['REMOTE_ADDR'] . "\n";

    $out = new ThroughStream();
    $in = new ThroughStream();

    $stream = new CompositeStream(
        $out,
        $in
    );

    $messageBuffer = new \Ratchet\RFC6455\Messaging\MessageBuffer(
        new CloseFrameChecker(),
        function (MessageInterface $message, MessageBuffer $messageBuffer) {
            $messageBuffer->sendMessage($message->getPayload(), true, $message->isBinary());
        },
        function (FrameInterface $frame) use ($out, &$messageBuffer) {
            switch ($frame->getOpCode()) {
                case Frame::OP_CLOSE:
                    $out->end($frame->getContents());
                    break;
                case Frame::OP_PING:
                    $out->write($messageBuffer->newFrame($frame->getPayload(), true, Frame::OP_PONG)->getContents());
                    break;
            }
        },
        true,
        null,
        [$out, 'write']
    );

    $in->on('data', [$messageBuffer, 'onData']);

    $in->on('close', function () use ($out) {
        $out->emit('close', func_get_args());
    });
    $in->on('error', function () use ($out) {
        $out->emit('error', func_get_args());
    });

    return new Response(
        $response->getStatusCode(),
        $response->getHeaders(),
        $stream
    );
});

$loop->run();
