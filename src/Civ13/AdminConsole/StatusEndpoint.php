<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/** Prepared endpoint only: deliberately NOT registered with any router/listener. */
final class StatusEndpoint
{
    public const PATH = '/admin/v1/servers';

    /**
     * session(request): authenticated Discord ID, null if no valid session, or throw
     * if the real provider is unavailable. This seam installs no OAuth/session flow.
     * No headers, IP whitelist or request fields are treated as identity here.
     */
    public function __construct(private ReadOnlyStatusService $service, private ?\Closure $session = null)
    {
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'Vary' => 'Cookie, Authorization', 'X-Content-Type-Options' => 'nosniff'];
        $respond = static fn (int $status, array $body, array $extra = []): Response => new Response(
            $status, $headers + $extra,
            $request->getMethod() === 'HEAD' ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        if ($request->getUri()->getPath() !== self::PATH) { return $respond(404, ['error' => 'not_found']); }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $respond(405, ['error' => 'method_not_allowed'], ['Allow' => 'GET, HEAD']);
        }
        if ($request->getUri()->getQuery() !== '') { return $respond(400, ['error' => 'query_not_supported']); }
        if ($this->session === null) { return $respond(503, ['error' => 'authentication_unavailable']); }
        try {
            $actor = ($this->session)($request);
            if ($actor === null) { return $respond(401, ['error' => 'authentication_required']); }
            if (!ReadOnlyStatusService::actor($actor)) { return $respond(503, ['error' => 'authentication_unavailable']); }
            $result = $this->service->read($actor);
            return $respond($result['status'], $result['body']);
        } catch (\Throwable) {
            return $respond(503, ['error' => 'status_unavailable']);
        }
    }
}
