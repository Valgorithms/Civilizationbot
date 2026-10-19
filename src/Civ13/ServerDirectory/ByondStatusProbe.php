<?php

declare(strict_types=1);

namespace Civ13\ServerDirectory;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;

/** One fixed, read-only BYOND Topic query. No HTTP, credentials or arbitrary queries. */
final class ByondStatusProbe
{
    public const MAX_PACKET_BYTES = 65536;
    public const TIMEOUT_SECONDS = 3.0;

    public function __construct(private LoopInterface $loop, private ConnectorInterface $connector)
    {
    }

    public function __invoke(string $address, int $port): PromiseInterface
    {
        $deferred = new Deferred();
        $connection = null;
        $connecting = null;
        $settled = false;
        $timer = null;
        $finish = function (?array $status, ?\Throwable $error = null) use ($deferred, &$connection, &$connecting, &$settled, &$timer): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($timer !== null) {
                $this->loop->cancelTimer($timer);
            }
            if ($connection !== null) {
                $connection->close();
            } elseif ($connecting !== null) {
                $connecting->cancel();
            }
            $error === null ? $deferred->resolve($status) : $deferred->reject($error);
        };
        $timer = $this->loop->addTimer(self::TIMEOUT_SECONDS, static fn () => $finish(null, new \RuntimeException('Status probe timed out')));
        // Targets come exclusively from the bot's configured server registry.
        $host = str_contains($address, ':') ? '['.$address.']' : $address;
        try {
            $connecting = $this->connector->connect('tcp://'.$host.':'.$port);
            $connecting->then(function (ConnectionInterface $stream) use (&$connection, &$settled, $finish): void {
                if ($settled) {
                    $stream->close();
                    return;
                }
                $connection = $stream;
                $buffer = '';
                $stream->on('data', static function ($chunk) use (&$buffer, $finish): void {
                    if (strlen($buffer) + strlen($chunk) > self::MAX_PACKET_BYTES) {
                        $finish(null, new \UnexpectedValueException('Oversized status response'));
                        return;
                    }
                    $buffer .= $chunk;
                    try {
                        if (($status = self::decodePacket($buffer)) !== null) {
                            $finish($status);
                        }
                    } catch (\Throwable $error) {
                        $finish(null, $error);
                    }
                });
                $stream->on('error', static fn (\Throwable $error) => $finish(null, $error));
                $stream->on('close', static fn () => $finish(null, new \UnexpectedValueException('Incomplete status response')));
                $query = "?status=1&format=json\0";
                try {
                    $stream->write("\0\x83".pack('n', strlen($query) + 5).str_repeat("\0", 5).$query);
                } catch (\Throwable $error) {
                    $finish(null, $error);
                }
            }, static fn (\Throwable $error) => $finish(null, $error));
        } catch (\Throwable $error) {
            $finish(null, $error);
        }
        return $deferred->promise();
    }

    /** Returns null only for an incomplete frame; rejects all non-string/non-JSON replies. */
    public static function decodePacket(string $packet): ?array
    {
        if (strlen($packet) > self::MAX_PACKET_BYTES) {
            throw new \UnexpectedValueException('Oversized status response');
        }
        if (strlen($packet) < 4) {
            return null;
        }
        $length = unpack('nlength', substr($packet, 2, 2))['length'];
        if (substr($packet, 0, 2) !== "\0\x83" || $length < 4 || $length + 4 > self::MAX_PACKET_BYTES) {
            throw new \UnexpectedValueException('Invalid status frame');
        }
        if (strlen($packet) < $length + 4) {
            return null;
        }
        if (strlen($packet) !== $length + 4 || $packet[4] !== "\x06" || substr($packet, -1) !== "\0") {
            throw new \UnexpectedValueException('Invalid status payload');
        }
        $status = json_decode(substr($packet, 5, -1), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($status) || array_is_list($status)) {
            throw new \UnexpectedValueException('Status must be an object');
        }
        return $status;
    }
}
