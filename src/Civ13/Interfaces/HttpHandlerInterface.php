<?php

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\Interfaces;

use Civ13\HttpHandler;
use Handler\HandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

/**
 * Contract for the bot's built-in HTTP server router: a {@see HandlerInterface}
 * collection of {@see HttpHandlerCallbackInterface} routes plus IP
 * whitelisting, per-endpoint rate limiting and a generated help page. `handle()`
 * resolves an incoming request to a route and returns its {@see HttpResponse}.
 *
 * @see \Civ13\HttpHandler The concrete implementation
 * @see \Civ13\HttpServiceManager Registers the routes on the handler
 * @see \Handler\HandlerInterface The base handler-collection contract
 */
interface HttpHandlerInterface extends HandlerInterface
{
    /** Resolves `$request` to a registered route and returns its response. */
    public function handle(ServerRequestInterface $request): HttpResponse;

    /** Wraps `$callback` so it only runs when the route's stored checks pass. */
    public function validate(callable $callback): callable;

    /** A generated listing of every registered route, for a `/help` endpoint. */
    public function generateHelp(): string;

    /** Adds `$ip` to the whitelist; returns whether it was newly added. */
    public function whitelist(string $ip): bool;

    /** Removes `$ip` from the whitelist; returns whether it was present. */
    public function unwhitelist(string $ip): bool;

    /** Sets a per-endpoint rate limit of `$limit` requests per `$window` seconds. */
    public function setRateLimit(string $endpoint, int $limit, int $window): HttpHandler;

    /** Seconds remaining on the global rate limit for `$ip`, or null when not limited. */
    public function isGlobalRateLimited(string $ip): ?int;
}
