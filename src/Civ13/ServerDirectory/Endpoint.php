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
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return new Response(405, $headers + ['Allow' => 'GET, HEAD'], '{"error":"method_not_allowed"}');
        }
        if ($request->getUri()->getPath() !== Directory::PATH || !$this->directory->hasProvider($request->getUri()->getHost())) {
            return new Response(404, $headers, '{"error":"not_found"}');
        }
        if ($request->getUri()->getQuery() !== '') {
            return new Response(400, $headers, '{"error":"query_parameters_not_supported"}');
        }
        if (($snapshot = $this->directory->snapshot($request->getUri()->getHost())) === null) {
            return new Response(503, $headers + ['Retry-After' => (string) Directory::REFRESH_SECONDS], $request->getMethod() === 'HEAD' ? '' : '{"error":"directory_unavailable"}');
        }
        return new Response(200, $headers, $request->getMethod() === 'HEAD' ? '' : json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
