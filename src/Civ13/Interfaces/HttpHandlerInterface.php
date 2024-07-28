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
    public function offsetGet(int|string $offset, ?string $name = null): mixed;
    public function offsetSet(int|string $offset, callable $callback, ?bool $whitelisted = false,  ?string $method = 'exact', ?string $description = ''): HttpHandler;

    public function handle(ServerRequestInterface $request): HttpResponse;
}