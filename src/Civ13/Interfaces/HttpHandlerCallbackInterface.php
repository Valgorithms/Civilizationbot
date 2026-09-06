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

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response as HttpResponse;

/**
 * The shape of a single HTTP route callback registered on the
 * {@see HttpHandlerInterface}: it receives the incoming PSR-7 request, the
 * matched `$endpoint` string and whether the caller's IP is whitelisted, and
 * returns the ReactPHP HTTP {@see HttpResponse} to send back.
 *
 * @see \Civ13\HttpHandler Where these callbacks are stored, matched and invoked
 */
interface HttpHandlerCallbackInterface
{
    public function __invoke(ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse;
}
