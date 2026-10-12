<?php
declare(strict_types=1);
namespace Civ13\Local;

/** Separate profile: no ambient .env, official constants or legacy controls. */
final class Profile
{
    public static function load(string $file): array
    {
        $config = json_decode(file_get_contents($file) ?: '', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) { throw new \InvalidArgumentException('Invalid local profile object'); }
        $fields = ['allowed_channel_ids', 'application_id', 'authority', 'bind', 'enabled', 'guild_id', 'official_application_id', 'pc_root', 'port', 'public_key_file', 'read_key_file', 'servers', 'state_directory', 'token_file'];
        if (array_key_exists('controls', $config)) { $fields[] = 'controls'; sort($fields); }
        $keys = array_keys($config); sort($keys);
        if ($keys !== $fields || !is_bool($config['enabled']) || $config['bind'] !== '127.0.0.1' || !is_int($config['port']) || $config['port'] < 1024 || $config['port'] > 65535 || $config['application_id'] === $config['official_application_id']) { throw new \InvalidArgumentException('Invalid isolated local profile'); }
        foreach (['application_id', 'guild_id', 'official_application_id'] as $key) { if (!is_string($config[$key]) || !preg_match('/^[0-9]{1,22}$/D', $config[$key])) { throw new \InvalidArgumentException('Invalid local Discord identity'); } }
        if (!is_array($config['allowed_channel_ids']) || !array_is_list($config['allowed_channel_ids']) || count($config['allowed_channel_ids']) < 1 || count($config['allowed_channel_ids']) > 16 || count(array_unique($config['allowed_channel_ids'])) !== count($config['allowed_channel_ids'])) { throw new \InvalidArgumentException('Explicit private local channel allowlist required'); }
        foreach ($config['allowed_channel_ids'] as $id) { if (!is_string($id) || !preg_match('/^[0-9]{1,22}$/D', $id)) { throw new \InvalidArgumentException('Invalid local channel'); } }
        $root = self::directory($config['pc_root']);
        if (dirname($root) === $root || str_starts_with($root, '\\\\') || preg_match('#^/home/(civ13|valithor)(/|$)#i', str_replace('\\', '/', $root))) { throw new \InvalidArgumentException('Official/server root forbidden'); }
        foreach (['state_directory', 'token_file', 'public_key_file', 'read_key_file'] as $key) { self::inside($config[$key], $root); }
        self::directory($config['state_directory']);
        foreach (['token_file', 'read_key_file', 'public_key_file'] as $key) { if (!is_file($config[$key]) || filesize($config[$key]) > 4096) { throw new \InvalidArgumentException('Bounded local credential file required'); } }
        if ($config['token_file'] === $config['read_key_file'] || $config['token_file'] === $config['public_key_file']) { throw new \InvalidArgumentException('Separate credential files required'); }
        if (!is_string($config['authority']) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $config['authority'])) { throw new \InvalidArgumentException('Invalid pinned authority'); }
        if (!is_array($config['servers']) || array_keys($config['servers']) !== ['nomads', 'tdm']) { throw new \InvalidArgumentException('Only local Nomads/TDM pointers are supported'); }
        $ports = [$config['port']];
        foreach ($config['servers'] as $server) {
            if (is_array($server) && array_key_exists('engine', $server)) {
                $keys = array_keys($server); sort($keys);
                if ($keys !== ['engine', 'gameplay_port', 'host', 'runtime_dir', 'topic_port'] || $server['engine'] !== 'opendream' || $server['host'] !== '127.0.0.1') { throw new \InvalidArgumentException('Unsupported local engine profile'); }
                self::inside($server['runtime_dir'], $root); self::directory($server['runtime_dir']);
                foreach (['gameplay_port', 'topic_port'] as $name) {
                    if (!is_int($server[$name]) || $server[$name] < 1024 || $server[$name] > 65535 || in_array($server[$name], $ports, true)) { throw new \InvalidArgumentException('Distinct local gameplay and Topic ports required'); }
                    $ports[] = $server[$name];
                }
                continue;
            }
            if (!is_array($server) || array_keys($server) !== ['directory', 'host', 'port'] || $server['host'] !== '127.0.0.1' || !is_int($server['port']) || $server['port'] < 1024 || $server['port'] > 65535 || in_array($server['port'], $ports, true)) { throw new \InvalidArgumentException('Nonlocal/duplicate server destination'); }
            self::inside($server['directory'], $root); self::directory($server['directory']); $ports[] = $server['port'];
        }
        if (isset($config['controls'])) {
            $control = $config['controls']; $keys = is_array($control) ? array_keys($control) : []; sort($keys);
            if ($keys !== ['controller', 'enabled', 'profiles', 'python', 'read_key_file'] || !is_bool($control['enabled']) || !is_array($control['profiles']) || array_keys($control['profiles']) !== ['nomads', 'tdm']) { throw new \InvalidArgumentException('Exact control configuration required'); }
            self::inside($control['read_key_file'], $root);
            if ($control['read_key_file'] === $config['read_key_file'] || $control['read_key_file'] === $config['token_file'] || filesize($control['read_key_file']) > 4096 || strlen(trim(file_get_contents($control['read_key_file']) ?: '')) < 32) { throw new \InvalidArgumentException('Separate bounded control credential required'); }
            foreach (['python', 'controller'] as $key) { if (!is_string($control[$key]) || !is_file($control[$key]) || !(str_starts_with($control[$key], '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $control[$key]))) { throw new \InvalidArgumentException('Absolute existing adapter path required'); } self::noLinks($control[$key]); }
            foreach ($control['profiles'] as $key => $path) { self::inside($path, $root); if (!is_file($path) || ($config['servers'][$key]['engine'] ?? null) !== 'opendream') { throw new \InvalidArgumentException('Local OpenDream control profile required'); } }
        }
        return $config;
    }
    public static function token(array $config): string
    {
        $token = trim(file_get_contents($config['token_file']) ?: '');
        $segment = explode('.', $token)[0];
        $identity = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($token === '' || $identity !== $config['application_id']) { throw new \InvalidArgumentException('Token does not belong to the configured separate local application'); }
        return $token;
    }
    public static function accepts(array $config, string $guild, string $channel): bool
    {
        return $guild === $config['guild_id'] && in_array($channel, $config['allowed_channel_ids'], true);
    }
    private static function directory(mixed $path): string
    {
        if (!is_string($path) || is_link($path) || !is_dir($path) || !($real = realpath($path))) { throw new \InvalidArgumentException('Missing/local directory required'); }
        self::noLinks($path); return $real;
    }
    private static function inside(mixed $path, string $root): void
    {
        if (!is_string($path) || !file_exists($path)) { throw new \InvalidArgumentException('Local path must exist'); }
        self::noLinks($path);
        $real = realpath($path);
        $prefix = rtrim($root, '/\\').DIRECTORY_SEPARATOR;
        if (!$real || !str_starts_with($real, $prefix)) { throw new \InvalidArgumentException('Local path escapes PC root'); }
    }
    private static function noLinks(string $path): void
    {
        for ($i = 0; $i < 100; $i++) { if (is_link($path)) { throw new \InvalidArgumentException('Local symlink forbidden'); } $parent = dirname($path); if ($parent === $path) { return; } $path = $parent; }
        throw new \InvalidArgumentException('Local path depth exceeded');
    }
}
