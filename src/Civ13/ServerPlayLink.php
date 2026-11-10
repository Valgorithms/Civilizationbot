<?php

declare(strict_types=1);

namespace Civ13;

/** Operator-selected HTTPS join page; never derive a link from a game/control address. */
final class ServerPlayLink
{
    private function __construct(public readonly string $url) {}

    public static function fromConfig(mixed $config, string $key, string $engine): ?self
    {
        if ($config === null || $config === []) { return null; }
        if (!is_array($config) || $engine !== 'byond' || array_diff(array_keys($config), ['url'])) {
            throw new \InvalidArgumentException('Play requires a BYOND server and only a public HTTPS page URL');
        }
        $url = $config['url'] ?? null;
        $parts = is_string($url) && strlen($url) <= 512 && !preg_match('/[\x00-\x20\x7f\\\\]/', $url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !self::publicHost($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Play requires an explicit public HTTPS page URL without credentials, query or fragment');
        }
        return new self($url);
    }

    /** Syntax only: operators must verify destination ownership, DNS and the page's join target. */
    private static function publicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false;
        }
        if (strlen($host) > 253 || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD', $host)) { return false; }
        $suffix = strtolower(substr($host, strrpos($host, '.') + 1));
        return !in_array($suffix, ['localhost', 'local', 'internal', 'lan', 'home', 'invalid', 'test'], true);
    }
}
