<?php

$verify_new = function (\Civ13\Civ13 $civ13, string $ckey, string $discord): bool
{
    if (! $browser_post = $civ13->functions['misc']['browser_post']) return false;
    $browser_post($civ13, 'http://www.valzargaming.com/verified/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true);
    return true;
};

//a) They have completed the get-approved process.
//b) They have been registered  for at least a current undisclosed period of time.
//c) They have been active on the server (currently undisclosed period of time).
//d) They have not received any bans on any of the Civ13.com servers. (NYI, not currently tracking bans for all time, only active bans)
//e) They have not received any infractions in the Civ13 discord. (NYI)
$promotable_check = function (\Civ13\Civ13 $civ13, string $identifier): bool
{
    if (! $civ13->verified && ! $civ13->getVerified()) return false; //Unable to get info from DB
    if (! $discord2ckey_slash = $civ13->functions['misc']['discord2ckey_slash']) return false;
    if (! $bancheck = $civ13->functions['misc']['bancheck']) return false;    
    if (! $item = $civ13->verified->get('ss13', htmlspecialchars($identifier)) ?? $civ13->verified->get('discord', $identifier)) return false; //a, ckey and/or discord id exists in DB
    if (($item['seen_tdm'] + $item['seen_nomads'] + $item['seen_pers'])<100) return false; //b, 100 seen
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; //c, 1 year
    if ($bancheck($civ13, $item['ss13'])) return false; //d, must not have active ban
    return true;
};