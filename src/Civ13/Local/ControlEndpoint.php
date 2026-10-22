<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Separate authenticated loopback-only route; no official or arbitrary destinations. */
final class ControlEndpoint
{
    public function __construct(private array $adapters, private string $key, private bool $enabled = false)
    { if (strlen($key) < 32 || array_diff(array_keys($adapters), ['nomads', 'tdm'])) { throw new \InvalidArgumentException('Invalid local control configuration'); } }
    public function handle(string $method, string $path, string $authorization, string $remote, string $body): PromiseInterface
    {
        if (!in_array($remote, ['127.0.0.1', '::1'], true) || !hash_equals('Bearer '.$this->key, $authorization)) { return resolve([401, ['error' => 'unauthorized']]); }
        if (!$this->enabled) { return resolve([503, ['error' => 'controls_disabled']]); }
        if ($method !== 'POST') { return resolve([405, ['error' => 'post_required']]); }
        if (!preg_match('#^/v1/local/servers/(nomads|tdm)/(start|stop|restart|status|topic-status|mapswap)$#D', $path, $match) || !isset($this->adapters[$match[1]])) { return resolve([404, ['error' => 'unsupported_route']]); }
        try {
            if (strlen($body) > 1024) { throw new \InvalidArgumentException('Body too large'); }
            $payload = json_decode($body, false, 4, JSON_THROW_ON_ERROR);
            if (!$payload instanceof \stdClass || ($match[2] === 'mapswap' ? array_keys((array) $payload) !== ['map'] || !is_string($payload->map) : (array) $payload !== [])) { throw new \InvalidArgumentException('Exact payload required'); }
            return $this->adapters[$match[1]]->request($match[2], $payload->map ?? null)->then(static fn ($result) => [200, ['view' => $match[2] === 'topic-status' ? 'topic' : 'process', 'data' => $result]], static fn () => [503, ['error' => 'outcome_unknown_or_rejected', 'retry' => false]]);
        } catch (\Throwable) { return resolve([400, ['error' => 'invalid_request']]); }
    }
}
