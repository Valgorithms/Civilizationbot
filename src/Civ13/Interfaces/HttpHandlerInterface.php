<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

interface HttpHandlerInterface extends HandlerInterface
{
    public function handle(ServerRequestInterface $request): HttpResponse;
}