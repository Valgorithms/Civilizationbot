<?php
declare(strict_types=1);
namespace Civ13;

/** Explicit operator configuration. Never discovers or copies another installation's credentials. */
final class PortableProfile
{
    public static function load(string $file): array
    {
        if (!self::absolute($file)) { throw new \InvalidArgumentException('Absolute profile path required'); }
        $stream = fopen($file, 'rb');
        if ($stream === false) { throw new \RuntimeException('Profile unavailable'); }
        try { $bytes = stream_get_contents($stream, 65537); } finally { fclose($stream); }
        if ($bytes === false || strlen($bytes) > 65536) { throw new \RuntimeException('Profile unavailable or oversized'); }
        $profile = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($profile) || ($profile['schema'] ?? null) !== 'civilizationbot.portable.v1') { throw new \InvalidArgumentException('Unsupported portable profile'); }
        foreach (['root', 'env_file', 'state_dir', 'gitdir'] as $field) {
            if (!is_string($profile[$field] ?? null) || !self::absolute($profile[$field]) || str_contains($profile[$field], "\0")) { throw new \InvalidArgumentException('Absolute configured path required: '.$field); }
        }
        if (!is_dir($profile['root']) || !is_dir($profile['state_dir']) || !is_dir($profile['gitdir'])) { throw new \InvalidArgumentException('Configured directories must exist'); }
        if (!is_array($profile['servers'] ?? null) || count($profile['servers']) !== 1) { throw new \InvalidArgumentException('Portable profile requires exactly one explicitly configured test server'); }
        foreach ($profile['servers'] as $key => &$server) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,32}$/D', $key) || !is_array($server) || !is_string($server['basedir'] ?? null) || !self::absolute($server['basedir']) || !is_dir($server['basedir']) || ($server['ip'] ?? null) !== '127.0.0.1' || ($server['port'] ?? null) !== 1714) { throw new \InvalidArgumentException('Expected explicit loopback TEST1714 server'); }
            foreach (['name', 'host'] as $field) {
                if (!is_string($server[$field] ?? null) || $server[$field] === '') { throw new \InvalidArgumentException('Explicit server identity required'); }
            }
            if (!is_bool($server['enabled'] ?? null)) { throw new \InvalidArgumentException('Explicit enabled flag required'); }
            if (array_key_exists('admin_file', $server)
                && (!is_string($server['admin_file']) || str_contains($server['admin_file'], "\0")
                    || !self::absolute($server['admin_file']))) {
                throw new \InvalidArgumentException('admin_file must be an explicit absolute filesystem path');
            }
            if (isset($server['native_lifecycle'])) {
                if (!is_array($server['native_lifecycle']) || !is_bool($server['native_lifecycle']['enabled'] ?? null)
                    || ($server['native_lifecycle']['enabled'] && !$server['enabled'])) {
                    throw new \InvalidArgumentException('Explicit native lifecycle and server activation flags required');
                }
            }
            foreach (['discussion','playercount','ooc','lobby','asay','ic','transit','adminlog','debug','garbage','runtime','attack'] as $field) {
                if (!is_string($server[$field] ?? null) || ($server[$field] !== '' && !preg_match('/^[0-9]{17,20}$/D', $server[$field]))) { throw new \InvalidArgumentException('Explicit channel string required'); }
            }
            // Stable GameServer stores its port as a strict string.
            $server['port'] = (string) $server['port'];
            $server['key'] = $key;
        }
        unset($server);
        if (!is_int($profile['http_port'] ?? null) || $profile['http_port'] < 1024 || $profile['http_port'] > 65535 || $profile['http_port'] === 1714) { throw new \InvalidArgumentException('Distinct explicit local HTTP port required'); }
        if (!is_array($profile['options'] ?? null)) { throw new \InvalidArgumentException('Explicit portable options required'); }
        foreach (['channel_ids', 'role_ids'] as $field) {
            if (!is_array($profile['options'][$field] ?? null)) { throw new \InvalidArgumentException('Explicit destination map required: '.$field); }
        }
        self::runtimeOptions($profile);
        return $profile;
    }

    /** Replace privileged production defaults; omitted local identities stay disabled. */
    public static function runtimeOptions(array $profile): array
    {
        $options = $profile['options'];
        foreach (['owner_id', 'technician_id', 'civ13_guild_id'] as $field) {
            $value = $options[$field] ?? '';
            if (!is_string($value) || ($value !== '' && preg_match('/^[0-9]{17,20}$/D', $value) !== 1)) {
                throw new \InvalidArgumentException('Expected explicit Discord identity: '.$field);
            }
            $options[$field] = $value;
        }
        $options['gitdir'] = $profile['gitdir'];
        $options['filecache_path'] = $profile['state_dir'].'/json/';
        $options['discover_external_ip'] = false;
        $options['verify_url'] = '';
        $options['verification_read_only'] = false;
        if (array_key_exists('verification_source', $profile)) {
            if (!is_array($profile['verification_source']) || count($profile['verification_source']) !== 2
                || ($profile['verification_source']['mode'] ?? null) !== 'read-only'
                || ($profile['verification_source']['url'] ?? null) !== 'http://civ13.com:55555/verified') {
                throw new \InvalidArgumentException('Expected the explicit hosted read-only verification source');
            }
            $options['verify_url'] = $profile['verification_source']['url'];
            $options['verification_read_only'] = true;
        }
        $options['webserver_url'] = '127.0.0.1';
        return $options;
    }

    /** An inherited process environment is not an explicit credential source. */
    public static function environmentValue(string $name, array $loadedNames): string
    {
        return in_array($name, $loadedNames, true) ? (getenv($name) ?: '') : '';
    }

    public static function assertRoot(array $profile, string $root): void
    {
        if (realpath($profile['root']) !== realpath($root)) { throw new \InvalidArgumentException('Profile belongs to another checkout'); }
    }

    public static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~D', $path) === 1;
    }

    /** No bootstrap, environment load, sockets or external effects. */
    public static function preflight(array $profile, callable $extension, string $version = PHP_VERSION, ?string $dependencyRoot = null): array
    {
        $errors = [];
        if (version_compare($version, '8.3', '<')) { $errors[] = 'PHP 8.3 or newer required (typed class constants)'; }
        foreach (['json', 'mbstring', 'openssl', 'filter', 'zlib', 'curl'] as $name) {
            if (!$extension($name)) { $errors[] = 'Missing extension: '.$name; }
        }
        if (!is_file(($dependencyRoot ?? $profile['root']).'/vendor/autoload.php')) { $errors[] = 'Composer dependencies missing'; }
        if (!is_file($profile['env_file'])) { $errors[] = 'Operator-provisioned separate environment file missing'; }
        return $errors;
    }
}
