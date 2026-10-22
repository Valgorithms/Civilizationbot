<?php

declare(strict_types=1);

namespace Civ13\ServerDirectory;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class Endpoint
{
    public function __construct(private Directory $directory)
    {
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $errorBody = static fn (string $code): string => $request->getMethod() === 'HEAD' ? '' : json_encode(['error' => $code], JSON_THROW_ON_ERROR);
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return new Response(405, $headers + ['Allow' => 'GET, HEAD'], $errorBody('method_not_allowed'));
        }
        if ($request->getUri()->getPath() !== Directory::PATH || $request->getUri()->getHost() !== Directory::AUTHORITY) {
            return new Response(404, $headers, $errorBody('not_found'));
        }
        // Match raw canonical query bytes: no decoding, repeated parameters,
        // extra fields, URLs, ports or client-supplied probe destinations.
        $provider = match ($request->getUri()->getQuery()) {
            'provider=civ13.com' => 'civ13.com',
            'provider=valzargaming.com' => 'valzargaming.com',
            default => null,
        };
        if ($provider === null) {
            return new Response(400, $headers, $errorBody('invalid_provider_query'));
        }
        if (!$this->directory->hasProvider($provider)) {
            return new Response(404, $headers, $errorBody('provider_unconfigured'));
        }
        if (($snapshot = $this->directory->snapshot($provider)) === null) {
            return new Response(503, $headers + ['Retry-After' => (string) Directory::REFRESH_SECONDS], $errorBody('directory_unavailable'));
        }
        return new Response(200, $headers, $request->getMethod() === 'HEAD' ? '' : json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
