<?php
declare(strict_types=1);
namespace Civ13\Server;

use Civ13\AccessListProjection;
use Civ13\PortableProfile;

/** Gateway host consumes this request; the bot never writes authority or allocates a revision. */
final class GatewayAccessProposal
{
    private const MAX_WIRE = 8_388_608;

    private array $receipt = ['state' => 'not_attempted'];

    public function __construct(private readonly string $config, private readonly array $binding, private readonly array $targets, private readonly int $maxBytes) {}

    public static function binding(string $config): array
    {
        if (!PortableProfile::absoluteAdminFile($config) || is_link($config) || !is_file($config)) {
            throw new \InvalidArgumentException('Explicit local gateway group configuration required');
        }
        $bytes = @file_get_contents($config, false, null, 0, 1_048_577);
        $group = is_string($bytes) && strlen($bytes) <= 1_048_576
            ? json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $bytes), true, 32) : null;
        if (!is_array($group) || ($group['gateway_verified_access'] ?? null) !== true
            || ($group['gateway_verified_access_inbox'] ?? null) !== true
            || !is_string($group['group'] ?? null) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $group['group'])
            || !PortableProfile::absoluteAdminFile($group['root'] ?? null)
            || !is_int($group['relay_port'] ?? null) || $group['relay_port'] < 1 || $group['relay_port'] > 65535
            || !is_string($group['gate'] ?? null) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $group['gate'])
            || !is_array($group['regions'] ?? null)) {
            throw new \InvalidArgumentException('Gateway verified access must be enabled in the bound shard group');
        }
        $gates = array_values(array_filter($group['regions'], static fn ($region): bool => is_array($region) && ($region['id'] ?? null) === $group['gate']));
        if (count($gates) !== 1 || ($gates[0]['kind'] ?? null) !== 'GATEWAY') {
            throw new \InvalidArgumentException('Dedicated gateway required');
        }
        $root = rtrim(str_replace('\\', '/', $group['root']), '/');
        $directory = $root.'/front-door/access-inbox';
        clearstatcache(true);
        $resolved = realpath($directory);
        if (!is_dir($directory) || $resolved === false || !self::samePath($directory, $resolved)
            || is_link($directory) || is_link(dirname($directory))) {
            throw new \InvalidArgumentException('Provision a protected local gateway access inbox first');
        }
        return ['group' => $group['group'], 'directory' => $directory, 'gateway' => $root.'/'.$group['gate']];
    }

    private static function samePath(string $a, string $b): bool
    {
        $a = str_replace('\\', '/', $a); $b = str_replace('\\', '/', $b);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    public function lastReceipt(): array { return $this->receipt; }

    public function __invoke(string $wire, int $generation, callable $canCommit): array
    {
        $stage = null;
        $stream = null;
        try {
            $data = AccessListProjection::decode($wire);
            if ($data['unmapped_role_holders'] !== 0) { return $this->receipt = ['state' => 'blocked', 'reason' => 'unmapped-role-holders']; }
            if ($generation < 0 || $canCommit() !== true) { return $this->receipt = ['state' => 'superseded']; }
            foreach ($data['outputs'] as $kind => $content) {
                if (strlen($content) > $this->maxBytes || ($content === "\n" && !$this->targets[$kind]['allow_empty'])) {
                    return $this->receipt = ['state' => 'blocked', 'reason' => 'output-policy'];
                }
            }
            $payload = json_encode(['group' => $this->binding['group']] + $data['outputs'], JSON_THROW_ON_ERROR)."\n";
            if (strlen($payload) > self::MAX_WIRE || self::binding($this->config) !== $this->binding) {
                return $this->receipt = ['state' => 'blocked', 'reason' => 'gateway-binding-or-budget'];
            }
            $dir = $this->binding['directory'];
            $path = $dir.'/proposal.json';
            $candidate = $dir.'/.proposal-'.bin2hex(random_bytes(16)).'.tmp';
            $stream = @fopen($candidate, 'x+b');
            if ($stream === false) { throw new \RuntimeException('stage-open'); }
            $stage = $candidate;
            if (fwrite($stream, $payload) !== strlen($payload) || !fflush($stream) || !fsync($stream) || !rewind($stream)
                || stream_get_contents($stream, self::MAX_WIRE + 1) !== $payload) { throw new \RuntimeException('stage-verify'); }
            if (!fclose($stream)) { throw new \RuntimeException('stage-close'); }
            $stream = null;
            if ($canCommit() !== true) { return $this->receipt = ['state' => 'superseded']; }
            // The guard may have observed a group reconfiguration; validate the
            // protected binding and destination immediately before replacement.
            clearstatcache(true, $path);
            if (self::binding($this->config) !== $this->binding || is_link($path) || (file_exists($path) && !is_file($path))) {
                return $this->receipt = ['state' => 'blocked', 'reason' => 'gateway-destination-changed'];
            }
            if (!@rename($stage, $path)) { throw new \RuntimeException('replace-refused'); }
            $stage = null;
            $this->receipt = ['state' => 'submitted'];
            return ['state' => 'updated', 'stage' => 'gateway-inbox'];
        } catch (\Throwable) {
            return $this->receipt = ['state' => 'blocked', 'reason' => 'gateway-proposal-refused'];
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if ($stage !== null) { @unlink($stage); }
        }
    }
}
