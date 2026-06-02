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
