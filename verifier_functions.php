<?php

$verify_new = function (\Civ13\Civ13 $civ13, string $ckey, string $discord): bool
{
    if (! $browser_post = $civ13->functions['misc']['browser_post']) return false;
    $browser_post($civ13, 'http://www..valzargaming.com/verified/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true);
}