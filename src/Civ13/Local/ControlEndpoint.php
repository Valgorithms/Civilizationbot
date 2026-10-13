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
        if (!preg_match('#^/v1/local/servers/(nomads|tdm)/(start|stop|restart|status|topic-status|mapswap|operation-status)$#D', $path, $match) || !isset($this->adapters[$match[1]])) { return resolve([404, ['error' => 'unsupported_route']]); }
        try {
            if (strlen($body) > 1024) { throw new \InvalidArgumentException('Body too large'); }
            $payload = json_decode($body, false, 4, JSON_THROW_ON_ERROR);
            if (!$payload instanceof \stdClass) { throw new \InvalidArgumentException('Object required'); }
            $keys = array_keys((array) $payload); sort($keys);
            if ($match[2] === 'operation-status') {
                $expected = isset($payload->map) ? ['action', 'map', 'operation_id'] : ['action', 'operation_id'];
                if ($keys !== $expected || !is_string($payload->action) || !is_string($payload->operation_id) || (isset($payload->map) && !is_string($payload->map))) { throw new \InvalidArgumentException('Exact recovery payload required'); }
                $promise = $this->adapters[$match[1]]->recover($payload->operation_id, $payload->action, $payload->map ?? null);
            } else {
                if (in_array($match[2], ['start', 'stop', 'restart', 'mapswap'], true) && $this->adapters[$match[1]]->requiresOperationId() && !property_exists($payload, 'operation_id')) { throw new \InvalidArgumentException('Stable caller operation identity required'); }
                $expected = $match[2] === 'mapswap' ? ['map'] : [];
                if (property_exists($payload, 'operation_id')) { $expected[] = 'operation_id'; if (!is_string($payload->operation_id)) { throw new \InvalidArgumentException('String operation identity required'); } }
                sort($expected);
                if ($keys !== $expected || ($match[2] === 'mapswap' && !is_string($payload->map))) { throw new \InvalidArgumentException('Exact payload required'); }
                $promise = $this->adapters[$match[1]]->request($match[2], $payload->map ?? null, $payload->operation_id ?? null);
            }
            return $promise->then(static fn ($result) => [200, ['view' => $match[2] === 'operation-status' ? 'lifecycle-receipt' : ($match[2] === 'topic-status' ? 'topic' : 'process'), 'data' => $result]], static fn () => [503, ['error' => 'outcome_unknown_or_rejected', 'retry' => false]]);
        } catch (\Throwable) { return resolve([400, ['error' => 'invalid_request']]); }
    }
}
