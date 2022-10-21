<?php

$whitelist_update = function (\Civ13\Civ13 $civ13, array $whitelists): bool
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    foreach ($whitelists as $whitelist) {
        if (! $file = fopen($whitelist, 'a')) continue;
        ftruncate($file, 0); //Clear the file
        foreach ($civ13->verified as $item) {
            if (! $member = $guild->members->get('id', $item['discord'])) continue;
            if (! $member->roles->has($civ13->role_ids['veteran'])) continue;
            fwrite($file, $item['ss13'] . ' = ' . $item['discord'] . PHP_EOL); //ckey = discord
        }
        fclose($file);
    }
    return true;
};

$civ_listeners = function (\Civ13\Civ13 $civ13) use ($whitelist_update): void //Handles Verified and Veteran cache and lists lists
{
    $civ13->discord->on('message', function ($message) use ($civ13) {
        if ($message->channel_id == $civ13->verifier_feed_channel_id) return $civ13->getVerified();
    });
    
    $civ13->discord->on('GUILD_MEMBER_ADD', function (\Discord\Parts\User\Member $member) use ($civ13): void
    {
        $civ13->timers["add_{$member->id}"] = $civ13->discord->getLoop()->addTimer(8640, function() use ($civ13, $member) { //Kick member if they have not verified
            if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return;
            if (! $member_future = $guild->members->get('id', $member->id)) return;
            if ($member_future->roles->has($civ13->role_ids['infantry']) || $member_future->roles->has($civ13->role_ids['veteran'])) return;
            return $guild->members->kick($member_future, 'Not verified');
        });
    });
    
    $civ13->discord->on('GUILD_MEMBER_REMOVE', function (\Discord\Parts\User\Member $member) use ($civ13, $whitelist_update): void
    {
         if ($member->roles->has($civ13->role_ids['veteran'])) $whitelist_update($civ13, [$civ13->files['nomads_whitelist'], $civ13->files['tdm_whitelist']]);
    });
    
    $civ13->discord->on('GUILD_MEMBER_UPDATE', function (\Discord\Parts\User\Member $member, \Discord\Discord $discord, ?\Discord\Parts\User\Member $member_old) use ($civ13, $whitelist_update): void
    {
        if ($member->roles->has($civ13->role_ids['veteran']) && ! $member_old->roles->has($civ13->role_ids['veteran'])) $whitelist_update($civ13, [$civ13->files['nomads_whitelist'], $civ13->files['tdm_whitelist']]);
        if (! $member->roles->has($civ13->role_ids['veteran']) && $member_old->roles->has($civ13->role_ids['veteran'])) $whitelist_update($civ13, [$civ13->files['nomads_whitelist'], $civ13->files['tdm_whitelist']]);
        if ($member->roles->has($civ13->role_ids['infantry']) && ! $member_old->roles->has($civ13->role_ids['infantry'])) $civ13->getVerified();;
        if (! $member->roles->has($civ13->role_ids['infantry']) && $member_old->roles->has($civ13->role_ids['infantry'])) $civ13->getVerified();;
    });
};

$verify_new = function (\Civ13\Civ13 $civ13, string $ckey, string $discord): bool
{
    if (! $browser_call = $civ13->functions['misc']['browser_call']) return false;
    if ($browser_call($civ13, 'http://www.valzargaming.com/verified/', 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true)) return true; //Check result, then add to $civ13->verified cache
    return false;
    
};

//a) They have completed the #get-approved process
//b) They have been registered for a while (current undisclosed period of time)
//c) They have been a regular player (have played for an undisclosed period of time)
//d) They have not received any bans on any of the Civ13.com servers (Particully implemented, not currently tracking bans for all time, only active bans)
//e) They are currently Civ13 discord server
//f) They have not received any infractions in the Civ13 discord. (NYI)
//g) They have been *recently* active on any of the Civ13.com servers (Determined by admin review)
$promotable_check = function (\Civ13\Civ13 $civ13, string $identifier): bool
{
    if (! $civ13->verified && ! $civ13->getVerified()) return false; //Unable to get info from DB
    if (! $bancheck = $civ13->functions['misc']['bancheck']) return false;
    if (! $item = $civ13->verified->get('ss13', htmlspecialchars($identifier)) ?? $civ13->verified->get('discord', str_replace(['<@', '<@!', '>'], '', $identifier))) return false; //a&e, ckey and/or discord id exists in DB and member is in the Discord server
    if (($item['seen_tdm'] + $item['seen_nomads'] + $item['seen_pers'])<100) return false; //b, 100 seen
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; //c, 1 year
    if ($bancheck($civ13, $item['ss13'])) return false; //d, must not have active ban
    return true;
};
$mass_promotion_check = function (\Civ13\Civ13 $civ13, $message) use ($promotable_check): array|false
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($civ13) { return $member->roles->has($civ13->role_ids['infantry']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($civ13, $member->id)) $promotables[] = [(string) $member, $member->displayname, $civ13->verified->get('discord', $member->id)['ss13']];
    return $promotables;
};
$mass_promotion_loop = function (\Civ13\Civ13 $civ13) use ($promotable_check): bool // Not implemented
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
$mass_promotion_timer = function (\Civ13\Civ13 $civ13) use ($mass_promotion_loop): void //Not implemented
{
    $civ13->timers['mass_promotion_timer'] = $civ13->disacord->getLoop()->addPeriodicTimer(86400, function () use ($mass_promotion_loop) { $mass_promotion_loop; });
};