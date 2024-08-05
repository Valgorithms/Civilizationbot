<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Civ13\HttpHandler;
use Handler\HandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

interface HttpHandlerInterface extends HandlerInterface
{
    public function handle(ServerRequestInterface $request): HttpResponse;
    public function validate(callable $callback): callable;

    public function generateHelp(): string;
    public function whitelist(string $ip): bool;
    public function unwhitelist(string $ip): bool;
    public function setRateLimit(string $endpoint, int $limit, int $window): HttpHandler;
    public function isGlobalRateLimited(string $ip): ?int;
}