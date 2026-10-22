<?php
declare(strict_types=1);
namespace Civ13\Safehouse;

/** Implementations authenticate the bot service independently of discord_id. */
interface Transport
{
    /** @return array<string,mixed> Throws on timeout; never invents a settlement. */
    public function exchange(array $request): array;
}
