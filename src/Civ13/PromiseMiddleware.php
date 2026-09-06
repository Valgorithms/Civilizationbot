<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13;

use React\Promise\PromiseInterface;

class PromiseMiddleware
{
    /**
     * @param \Closure|null $onFulfilledDefault Default fulfilment handler.
     * @param \Closure|null $onRejectedDefault  Default rejection handler.
     */
    public function __construct(
        public \Closure|null $onFulfilledDefault,
        public \Closure|null $onRejectedDefault
    ) {
    }

    /** Attaches handlers to `$promise`, using the configured defaults for any that are null. */
    public function then(PromiseInterface $promise, callable|null $onFulfilled = null, callable|null $onRejected = null): PromiseInterface
    {
        return $promise->then($onFulfilled ?? $this->onFulfilledDefault, $onRejected ?? $this->onRejectedDefault);
    }

    /** Alias for {@see then()}. */
    public function __invoke(PromiseInterface $promise, callable|null $onFulfilled = null, callable|null $onRejected = null): PromiseInterface
    {
        return $this->then($promise, $onFulfilled, $onRejected);
    }
}
