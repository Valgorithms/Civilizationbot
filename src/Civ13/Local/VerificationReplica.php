<?php
declare(strict_types=1);
namespace Civ13\Local;

/** A public-key authenticated observation, never verification authority. */
final class VerificationReplica
{
    public const PROTOCOL = 'civ13.verification-replica.v1';
    public const MAX_BYTES = 4194304;

    public function __construct(private string $directory, private string $publicKey, private string $authority, private $clock, private $beforePublish = null)
    {
        if (!extension_loaded('sodium') || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $authority) || !is_callable($clock) || ($beforePublish !== null && !is_callable($beforePublish)) || !is_dir($directory) || is_link($directory)) { throw new \InvalidArgumentException('Invalid replica configuration'); }
        $this->directory = realpath($directory) ?: throw new \InvalidArgumentException('Invalid replica root');
        self::noLinks($directory);
    }

    /** Official adapter signs exact bytes; only its own approved installation has the secret. */
    public static function export(array $records, string $authority, int $revision, int $issued, int $expires, string $secretKey): string
    {
        $body = json_encode(['protocol' => self::PROTOCOL, 'authority' => $authority, 'revision' => $revision, 'issued_at' => $issued, 'expires_at' => $expires, 'records' => $records], JSON_THROW_ON_ERROR);
        self::validateBody(json_decode($body, true, 32, JSON_THROW_ON_ERROR));
        if (strlen($body) > self::MAX_BYTES || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { throw new \InvalidArgumentException('Invalid export bounds/key'); }
        return json_encode(['body' => base64_encode($body), 'signature' => base64_encode(sodium_crypto_sign_detached(self::PROTOCOL."\n".$body, $secretKey))], JSON_THROW_ON_ERROR);
    }

    public function import(string $wire): void
    {
        $incoming = $this->authenticate($wire, true);
        $lockPath = $this->path('snapshot.lock');
        $lock = fopen($lockPath, 'c+b');
        if (!$lock) { throw new \RuntimeException('Replica lock unavailable'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new \RuntimeException('Replica writer busy'); }
        $temporary = null;
        try {
            $snapshot = $this->path('snapshot.json');
            if (is_file($snapshot)) {
                $old = $this->authenticate($this->readFile($snapshot), false);
                if ($incoming['data']['revision'] < $old['data']['revision'] || ($incoming['data']['revision'] === $old['data']['revision'] && $incoming['digest'] !== $old['digest'])) { throw new \UnexpectedValueException('Replica rollback/equivocation'); }
                if ($incoming['digest'] === $old['digest']) { return; }
            }
            if (count(glob($this->directory.DIRECTORY_SEPARATOR.'snapshot-*.tmp') ?: []) >= 16) { throw new \RuntimeException('Replica staging recovery required'); }
            $temporary = $this->path('snapshot-'.bin2hex(random_bytes(12)).'.tmp');
            $file = fopen($temporary, 'xb');
            if (!$file) { throw new \RuntimeException('Replica staging failed'); }
            try {
                chmod($temporary, 0600);
                if (fwrite($file, $wire) !== strlen($wire) || !fflush($file) || !fsync($file)) { throw new \RuntimeException('Replica staging incomplete'); }
            } finally { fclose($file); }
            if ($this->beforePublish !== null) { ($this->beforePublish)(); }
            // One writer, same directory. Atomic rename on the supported Linux host.
            if (!rename($temporary, $snapshot)) { throw new \RuntimeException('Replica publication failed'); }
            $temporary = null;
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function snapshot(): array
    {
        return $this->authenticate($this->readFile($this->path('snapshot.json')), true)['data'];
    }

    /** Injected source must perform only the configured authenticated read, never redirects or writes. */
    public function sync(callable $readOnlySource): void { $this->import($readOnlySource()); }

    private function path(string $name): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        self::noLinks($this->directory);
        clearstatcache(true, $path);
        if (is_link($path)) { throw new \RuntimeException('Replica symlink rejected'); }
        return $path;
    }
    private function readFile(string $path): string
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES * 2) { throw new \RuntimeException('Replica unavailable'); }
        return file_get_contents($path) ?: throw new \RuntimeException('Replica unavailable');
    }
    private function authenticate(string $wire, bool $fresh): array
    {
        if (strlen($wire) > self::MAX_BYTES * 2) { throw new \UnexpectedValueException('Replica exceeds wire bounds'); }
        $sealed = json_decode($wire, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($sealed) || count($sealed) !== 2 || !is_string($sealed['body'] ?? null) || !is_string($sealed['signature'] ?? null)) { throw new \UnexpectedValueException('Invalid replica envelope'); }
        $body = base64_decode($sealed['body'], true); $signature = base64_decode($sealed['signature'], true);
        if ($body === false || strlen($body) > self::MAX_BYTES || base64_encode($body) !== $sealed['body'] || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || base64_encode($signature) !== $sealed['signature'] || !sodium_crypto_sign_verify_detached($signature, self::PROTOCOL."\n".$body, $this->publicKey)) { throw new \UnexpectedValueException('Replica authentication failed'); }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR); self::validateBody($data);
        if ($data['authority'] !== $this->authority) { throw new \UnexpectedValueException('Wrong verification authority'); }
        $now = ($this->clock)();
        if ($fresh && ($data['issued_at'] > $now + 5 || $data['expires_at'] <= $now)) { throw new \UnexpectedValueException('Replica stale or future-dated'); }
        return ['data' => $data, 'digest' => hash('sha256', $body)];
    }
    private static function noLinks(string $path): void
    {
        for ($i = 0; $i < 100; $i++) { clearstatcache(true, $path); if (is_link($path)) { throw new \RuntimeException('Replica symlink ancestor rejected'); } $parent = dirname($path); if ($parent === $path) { return; } $path = $parent; }
        throw new \RuntimeException('Replica path depth exceeded');
    }
    private static function validateBody(mixed $data): void
    {
        $fields = ['authority', 'expires_at', 'issued_at', 'protocol', 'records', 'revision'];
        if (!is_array($data)) { throw new \UnexpectedValueException('Invalid replica body'); }
        $keys = array_keys($data); sort($keys);
        if ($keys !== $fields || $data['protocol'] !== self::PROTOCOL || !is_string($data['authority']) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $data['authority'])) { throw new \UnexpectedValueException('Invalid replica schema'); }
        foreach (['revision', 'issued_at', 'expires_at'] as $key) { if (!is_int($data[$key]) || $data[$key] < 0 || $data[$key] > 9007199254740991) { throw new \UnexpectedValueException('Invalid replica counter'); } }
        if ($data['expires_at'] <= $data['issued_at'] || $data['expires_at'] - $data['issued_at'] > 86400 || !is_array($data['records']) || !array_is_list($data['records']) || count($data['records']) > 50000) { throw new \UnexpectedValueException('Invalid replica validity/records'); }
        $ckeys = []; $discords = [];
        foreach ($data['records'] as $record) {
            if (!is_array($record)) { throw new \UnexpectedValueException('Invalid verification record'); }
            $keys = array_keys($record); sort($keys);
            if ($keys !== ['discord', 'ss13'] || !is_string($record['ss13']) || !preg_match('/^[a-z0-9]{1,64}$/D', $record['ss13']) || !is_string($record['discord']) || !preg_match('/^[0-9]{1,22}$/D', $record['discord']) || isset($ckeys[$record['ss13']]) || isset($discords[$record['discord']])) { throw new \UnexpectedValueException('Invalid/non-unique verification record'); }
            $ckeys[$record['ss13']] = true; $discords[$record['discord']] = true;
        }
    }
}
