<?php
use Civ13\Civ13;
use Discord\Parts\User\Member;

// a) They have completed the #get-approved process
// b) They have been registered for a while (current undisclosed period of time)
// c) They have been a regular player (have played for an undisclosed period of time)
// d) They have not received any bans on any of the Civ13.com servers (Partially implemented, not currently tracking bans for all time, only active bans)
// e) They are currently in the Civ13 discord server
// f) They have not received any infractions in the Civ13 discord. (NYI)
// g) They have been *recently* active on any of the Civ13.com servers (Determined by admin review)
$promotable_check = function (Civ13 $civ13, string $identifier): bool
{
    if (! $civ13->verifier->verified && ! $civ13->verifier->getVerified()) return false; // Unable to get info from DB
    if (! $item = $civ13->verifier->getVerifiedMemberItems()->get('ss13', htmlspecialchars($identifier)) ?? $civ13->verifier->getVerifiedMemberItems()->get('discord', str_replace(['<@', '<@!', '>'], '', $identifier))) return false; // a&e, ckey and/or discord id exists in DB and member is in the Discord server
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; // b, 1 year
    //if (($item['seen_tdm'] + $item['seen_nomads'] + $item['seen_pers'])<100) return false; // c, 100 seen (These fields have been deprecated in the DB and are no longer used for tracking player activity)
    if ($civ13->bancheck($item['ss13'])) return false; // d, must not have active ban
    return true;
};
$mass_promotion_check = function (Civ13 $civ13) use ($promotable_check): array|false
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    if (! $members = $guild->members->filter(function (Member $member) use ($civ13) { return $member->roles->has($civ13->role_ids['infantry']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($civ13, $member->id)) $promotables[] = [(string) $member, $member->username ?? $member->displayname, $civ13->verifier->get('discord', $member->id)['ss13']];
    return $promotables;
};
$mass_promotion_loop = function (Civ13 $civ13) use ($promotable_check): bool // Not implemented
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    if (! $members = $guild->members->filter(function (Member $member) use ($civ13) { return $member->roles->has($civ13->role_ids['infantry']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($civ13, $member->id)) $promotables[] = $member;
    foreach ($promotables as $promoted) { // Promote eligible members
        $role_ids = [$civ13->role_ids['veteran']];
        foreach ($promoted->roles as $role) if ($role->id != $civ13->role_ids['infantry']) $role_ids[] = $role->id;
        $promoted->setRoles($role_ids);
    }
    return true;
};
$mass_promotion_timer = function (Civ13 $civ13) use ($mass_promotion_loop): void // Not implemented
{
    if (! isset($civ13->timers['mass_promotion_timer'])) $civ13->timers['mass_promotion_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(86400, function () use ($mass_promotion_loop) { $mass_promotion_loop; });
};