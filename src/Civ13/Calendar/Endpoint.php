<?php
declare(strict_types=1);
namespace Civ13\Calendar;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class Endpoint
{
    public const PATH = '/calendar/v1/snapshot';
    private \Closure $snapshot;
    public function __construct(private string $authority, callable $snapshot) { $this->snapshot = \Closure::fromCallable($snapshot); }
    public function handle(ServerRequestInterface $request): Response
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'Access-Control-Allow-Origin' => 'https://civ13.github.io'];
        $status = 200; $body = ['error' => 'calendar_unavailable'];
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) { $status = 405; $headers['Allow'] = 'GET, HEAD'; }
        elseif ($request->getUri()->getAuthority() !== $this->authority || $request->getUri()->getPath() !== self::PATH || $request->getUri()->getQuery() !== '') { $status = 404; }
        else {
            try { $body = ($this->snapshot)(); if ($body === null || ($body['state'] ?? 'stale') === 'stale') { $status = 503; } }
            catch (\Throwable) { $status = 503; $body = ['error' => 'calendar_unavailable']; }
        }
        $bytes = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($bytes) > 2097152) { $status = 503; $bytes = '{"error":"calendar_unavailable"}'; }
        return new Response($status, $headers, $request->getMethod() === 'HEAD' ? '' : $bytes);
    }
}
