<?php

$verify_refresh = function (\Civ13\Civ13 $civ13)
{
    $this->discord->on('message', function ($message) {
        if ($message->channel_id == $civ13->verifier_feed_channel_id) $civ13->getVerified();
    });
}

$verify_new = function (\Civ13\Civ13 $civ13, string $ckey, string $discord): bool
{
    if (! $browser_post = $civ13->functions['misc']['browser_post']) return false;
    $browser_post($civ13, 'http://www.valzargaming.com/verified/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true);
    //Check result, then add to $civ13->verified cache
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
    if (! $bancheck = $civ13->functions['misc']['bancheck']) return false;
    if (! $item = $civ13->verified->get('ss13', htmlspecialchars($identifier)) ?? $civ13->verified->get('discord', str_replace(['<@', '<@!', '>'], '', $identifier))) return false; //a, ckey and/or discord id exists in DB
    if (($item['seen_tdm'] + $item['seen_nomads'] + $item['seen_pers'])<100) return false; //b, 100 seen
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; //c, 1 year
    if ($bancheck($civ13, $item['ss13'])) return false; //d, must not have active ban
    return true;
};

$mass_promotion_loop = function (\Civ13\Civ13 $civ13) use ($promotable_check)
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($civ13) { return $member->roles->has($civ13->role_ids['infantry']); } )) return false;;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($civ13, $member->id)) $promotables[] = $member;
    foreach ($promotables as $promoted) { //Promote eligible members
        $role_ids = [$civ13->role_ids['veteran']];
        foreach ($promoted->roles as $role) if ($role->id != $civ13->role_ids['infantry']) $role_ids[] = $role->id;
        $promoted->setRoles($role_ids);
    }
    return true;
};

$mass_promotion_check = function (\Civ13\Civ13 $civ13, $message) use ($promotable_check)
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($civ13) { return $member->roles->has($civ13->role_ids['infantry']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($civ13, $member->id)) $promotables[] = [(string) $member, $member->displayname, $civ13->verified->get('discord', $member->id)['ss13']];
    return $promotables;
};

$mass_promotion_timer = function (\Civ13\Civ13 $civ13) use ($mass_promotion_loop)
{
    $civ13->timers['mass_promotion_timer'] = $civ13->disacord->getLoop()->addPeriodicTimer(86400, function () use ($mass_promotion_loop) { $mass_promotion_loop; });
};