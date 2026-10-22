<?php

declare(strict_types=1);

namespace Civ13\ServerDirectory;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/** Directory-only composition: no Civ13, GameServer, Discord or application config. */
final class Standalone
{
    public readonly Directory $directory;
    public readonly int $port;
    public readonly bool $enabled;
    private Endpoint $endpoint;
    private int $window = 0;
    private int $requests = 0;

    public function __construct(array $config, callable $probe, private $clock = null)
    {
        $this->clock ??= time(...);
        self::validateShape($config);
        $this->port = $config['listen_port'];
        $this->directory = new Directory([], [], $probe, $this->clock, $config['targets']);
        $this->endpoint = new Endpoint($this->directory);
        $this->enabled = $config['targets'] !== [];
    }

    /** Pure schema check usable before any dependency autoloader is executed. */
    public static function validateShape(array $config): void
    {
        if (count($config) !== 2 || array_diff(array_keys($config), ['listen_port', 'targets']) ||
            !is_int($config['listen_port']) || $config['listen_port'] < 1024 || $config['listen_port'] > 65535 || !is_array($config['targets'])) {
            throw new \InvalidArgumentException('Expected only an unprivileged listen_port and targets list');
        }
    }

    public function address(): string
    {
        return '127.0.0.1:'.$this->port;
    }

    public function handle(ServerRequestInterface $request): Response
    {
        // One bounded budget for the trusted local proxy, not unbounded per-IP state.
        $now = ($this->clock)();
        if ($now < $this->window || $now - $this->window >= 60) {
            $this->window = $now;
            $this->requests = 0;
        }
        if (++$this->requests > 600) {
            return new Response(429, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Retry-After' => '60', 'X-Content-Type-Options' => 'nosniff'],
                $request->getMethod() === 'HEAD' ? '' : '{"error":"rate_limited"}');
        }
        return $this->endpoint->handle($request);
    }
}
