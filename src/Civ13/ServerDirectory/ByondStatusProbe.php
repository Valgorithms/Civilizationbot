<?php

declare(strict_types=1);

namespace Civ13\ServerDirectory;

use React\EventLoop\LoopInterface;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\Factory as DnsFactory;
use React\Dns\Resolver\ResolverInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\Connector;

use function React\Promise\reject;

/** One fixed, read-only BYOND Topic query. No HTTP, credentials or arbitrary queries. */
final class ByondStatusProbe
{
    public const MAX_PACKET_BYTES = 65536;
    public const TIMEOUT_SECONDS = 3.0;

    public function __construct(private LoopInterface $loop, private ConnectorInterface $connector, private ?ConnectorInterface $publicConnector = null,
        private float $timeoutSeconds = self::TIMEOUT_SECONDS, private ?PublicResolver $publicResolver = null, private $monotonic = null)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > self::TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException('Status deadline must be positive and at most three seconds');
        }
        $this->monotonic ??= static fn (): float => hrtime(true) / 1e9;
    }

    /** Production composition: one validated DNS snapshot and sequential literal attempts. */
    public static function create(LoopInterface $loop, ?ResolverInterface $resolver = null, ?ConnectorInterface $tcp = null, ?callable $monotonic = null, float $timeoutSeconds = self::TIMEOUT_SECONDS): self
    {
        $resolver ??= self::systemResolver($loop);
        return new self($loop, self::createConnector($loop, $resolver, $tcp), null,
            $timeoutSeconds, new PublicResolver($resolver), $monotonic);
    }

    private static function systemResolver(LoopInterface $loop): ResolverInterface
    {
        $config = DnsConfig::loadSystemConfigBlocking();
        return $config->nameservers ? (new DnsFactory())->create($config, $loop)
            : new class implements ResolverInterface {
                public function resolve($domain) { return reject(new \RuntimeException('System DNS unavailable')); }
                public function resolveAll($domain, $type) { return reject(new \RuntimeException('System DNS unavailable')); }
            };
    }

    /** No application DNS cache or startup IP pin; hostname probes resolve again each sweep. */
    public static function createConnector(LoopInterface $loop, ?ResolverInterface $resolver = null, ?ConnectorInterface $tcp = null, bool $publicOnly = false): ConnectorInterface
    {
        // Never invent a resolver or synchronously look up a hostname on the loop.
        $resolver ??= self::systemResolver($loop);
        if ($publicOnly) {
            $resolver = new PublicResolver($resolver);
        }
        return new Connector(['dns' => $resolver, 'tcp' => $tcp ?? true, 'tls' => false, 'unix' => false, 'happy_eyeballs' => !$publicOnly, 'timeout' => self::TIMEOUT_SECONDS], $loop);
    }

    public function __invoke(string $address, int $port, bool $publicOnly = false): PromiseInterface
    {
        if ($publicOnly && $this->publicResolver !== null) {
            return $this->probePublic($address, $port);
        }
        if ($publicOnly && ($this->publicConnector === null || !in_array($address, Directory::PROVIDERS, true))) {
            return reject(new \InvalidArgumentException('Public probe must use an approved hostname and public-only connector'));
        }
        $finish = null;
        $deferred = new Deferred(static function () use (&$finish): void {
            if ($finish !== null) { $finish(null, new \RuntimeException('Status probe cancelled')); }
        });
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
        $timer = $this->loop->addTimer($this->timeoutSeconds, static fn () => $finish(null, new \RuntimeException('Status probe timed out')));
        // Targets come exclusively from operator-configured managed/status-only inventories.
        $host = str_contains($address, ':') ? '['.$address.']' : $address;
        try {
            $connecting = ($publicOnly ? $this->publicConnector : $this->connector)->connect('tcp://'.$host.':'.$port);
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

    private function probePublic(string $host, int $port): PromiseInterface
    {
        if (!in_array($host, Directory::PROVIDERS, true) || $port < 1 || $port > 65535) {
            return reject(new \InvalidArgumentException('Invalid public status target'));
        }
        $deadline = ($this->monotonic)() + $this->timeoutSeconds;
        $settled = false; $pending = null; $timer = null; $finish = null;
        $deferred = new Deferred(static function () use (&$finish): void {
            if ($finish !== null) { $finish(null, new \RuntimeException('Public status probe cancelled')); }
        });
        $finish = function (?array $status, ?\Throwable $error = null) use (&$settled, &$pending, &$timer, $deferred): void {
            if ($settled) { return; }
            $settled = true;
            if ($timer !== null) { $this->loop->cancelTimer($timer); }
            if ($pending !== null) { $pending->cancel(); }
            $error === null ? $deferred->resolve($status) : $deferred->reject($error);
        };
        $timer = $this->loop->addTimer($this->timeoutSeconds, static fn () => $finish(null, new \RuntimeException('Public status probe timed out')));
        $pending = $this->publicResolver->resolveAll($host, \React\Dns\Model\Message::TYPE_A);
        $pending->then(function (array $addresses) use ($port, $deadline, &$settled, &$pending, $finish): void {
            // PublicResolver validated EVERY answer before any socket can open.
            $addresses = array_values(array_unique($addresses));
            sort($addresses, SORT_STRING);
            $index = 0;
            $next = function () use (&$next, &$index, $addresses, $port, $deadline, &$settled, &$pending, $finish): void {
                if ($settled) { return; }
                $remaining = $deadline - ($this->monotonic)();
                $count = count($addresses) - $index;
                if ($remaining <= 0 || $remaining > $this->timeoutSeconds || $count === 0) {
                    $finish(null, new \RuntimeException('No complete public status response'));
                    return;
                }
                // One socket per target, with a fair share of the SAME total budget.
                // Slow candidates cannot consume the entire window before later A records.
                $literal = $addresses[$index++];
                $pending = (new self($this->loop, $this->connector, timeoutSeconds: $remaining / $count))($literal, $port);
                $pending->then(function (array $status) use ($next, $finish, $deadline, &$settled): void {
                    if ($settled) { return; }
                    if (!Directory::statusIsComplete($status)) { $next(); return; }
                    $remaining = $deadline - ($this->monotonic)();
                    if ($remaining <= 0 || $remaining > $this->timeoutSeconds) {
                        $finish(null, new \RuntimeException('Public status deadline expired'));
                        return;
                    }
                    $finish($status);
                }, static function () use ($next, &$settled): void { if (!$settled) { $next(); } });
            };
            $next();
        }, static fn (\Throwable $error) => $finish(null, $error));
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
