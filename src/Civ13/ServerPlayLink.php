<?php

declare(strict_types=1);

namespace Civ13;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/** Explicit public joining metadata. Never derive this from a private game/control address. */
final class ServerPlayLink
{
    private function __construct(public readonly string $url, public readonly string $joinUri, private string $path, private string $webHost) {}

    public static function fromConfig(mixed $config, string $key, string $engine): ?self
    {
        if ($config === null || $config === []) { return null; }
        if (!is_array($config) || $engine !== 'byond' || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $key)) {
            throw new \InvalidArgumentException('Play requires a BYOND server and a safe server key');
        }
        $url = $config['url'] ?? null;
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $path = '/'.$key.'/play';
        $parts = is_string($url) && strlen($url) <= 512 && !preg_match('/[\x00-\x20\x7f\\\\]/', $url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !self::publicHost($parts['host'] ?? '')
            || ($parts['path'] ?? '') !== $path 
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !is_string($host) || !self::publicHost($host) || !is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Play requires an explicit public HTTPS /{key}/play URL and public BYOND host/port');
        }
        return new self($url, 'byond://'.strtolower($host).':'.$port, $path, strtolower($parts['host']));
    }

    /** Syntactic public address check only; operators must verify public DNS and destination ownership. */
    private static function publicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) !== false;
        }
        // No shorthand/octal/hex IPs, local names, IPv6 literals, userinfo or URI delimiters.
        if (strlen($host) > 253 || !preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD', $host)) { return false; }
        $suffix = strtolower(substr($host, strrpos($host, '.') + 1));
        return !in_array($suffix, ['localhost', 'local', 'internal', 'lan', 'home', 'invalid', 'test'], true);
    }

    /** Read-only landing page: no redirects, DNS/probes, auto-launch, caller-selected target or host control. */
    public function handle(ServerRequestInterface $request, string $name): Response
    {
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ];
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return new Response(405, $headers + ['Allow' => 'GET, HEAD'], 'Method not allowed');
        }
        if ($request->getUri()->getPath() !== $this->path || strtolower($request->getUri()->getHost()) !== $this->webHost) {
            return new Response(404, $headers, $request->getMethod() === 'HEAD' ? '' : 'Not found');
        }
        if ($request->getUri()->getQuery() !== '') {
            return new Response(400, $headers, $request->getMethod() === 'HEAD' ? '' : 'Query parameters are not supported');
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $escape($name);
        $uri = $escape($this->joinUri);
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Play '.$title.'</title><main><h1>Play '.$title.'</h1><p><a href="'.$uri.'">Open '.$title.' in BYOND</a></p>'
            .'<p>BYOND must be installed. Your browser may ask permission to open it. If nothing opens, copy this address into BYOND:</p>'
            .'<p><code>'.$uri.'</code></p><p>This link does not guarantee the server is online or bypass its access rules.</p>'
            .'<p><a href="https://www.byond.com/download/" rel="noreferrer">Download BYOND</a></p></main></html>';
        return new Response(200, $headers, $request->getMethod() === 'HEAD' ? '' : $html);
    }
}
