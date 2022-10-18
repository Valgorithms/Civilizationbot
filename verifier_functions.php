<?php

$verify_new = function (\Civ13\Civ13 $civ13, string $ckey, string $discord): bool
{
    if (! $browser_post = $civ13->functions['misc']['browser_post']) return false;
    $browser_post($civ13, 'http://www.valzargaming.com/verified/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true);
    return true;
};

$promotable_check = function (\Civ13\Civ13 $civ13, string $identifier): bool
{
    //a) They have completed the get-approved process.
    //b) They have been registered  for at least a current undisclosed period of time.
    //c) They have been active on the server (currently undisclosed period of time).
    //d) They have not received any bans on any of the Civ13.com servers. (NYI, not currently tracking bans for all time, only active bans)
    //e) They have not received any infractions in the Civ13 discord. (NYI)
    if (! $discord2ckey_slash = $civ13->functions['misc']['discord2ckey_slash']) return false;
    if (! $bancheck = $civ13->functions['misc']['bancheck']) return false;
    preg_match('/<#([0-9]*)>/', $identifier, $matches);
    if (! is_numeric($id = $matches[1])) $ckey = $identifier;
    elseif (!$ckey = $discord2ckey_slash($civ13, $id)[1]) return false; //a, ckey exist in DB
    foreach ($civ13->verified as $arr) if ($arr['ss13'] == $ckey) { //a, ckey exists in DB
        if (($arr['seen_tdm'] + $arr['seen_nomads'] + $arr['seen_pers'])<100) return false; //b, 100 seen
        if (strtotime($arr['create_time']) < strtotime('-1 year')) return false; //c, 1 year
        break;
    }
    if ($bancheck($civ13, $ckey)) return false; //d, must not have active ban
    return true;
};