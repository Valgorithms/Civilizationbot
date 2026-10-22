<?php
declare(strict_types=1);
namespace Civ13\Safehouse;
use React\Promise\PromiseInterface;
interface AsyncGateway
{
    public function submit(array $request): PromiseInterface;
}
