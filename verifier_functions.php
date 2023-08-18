<?php
use Civ13\Civ13;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

$civ_listeners = function (Civ13 $civ13): void // Handles Verified and Veteran cache and lists lists
{ // on ready
    $civ13->discord->on('message', function (Message $message) use ($civ13): void
    {
        if ($message->channel_id == $civ13->verifier_feed_channel_id) $civ13->getVerified(); // Other bots should webhook to this channel to trigger a refresh
    });
    
    $civ13->discord->on('GUILD_MEMBER_ADD', function (Member $member) use ($civ13): void
    {
        $civ13->getVerified();
        if (isset($civ13->timers["add_{$member->id}"])) {
            $civ13->discord->getLoop()->cancelTimer($civ13->timers["add_{$member->id}"]);
            unset($civ13->timers["add_{$member->id}"]);
        }
        $civ13->timers["add_{$member->id}"] = $civ13->discord->getLoop()->addTimer(8640, function () use ($civ13, $member): ?PromiseInterface
        { // Kick member if they have not verified
            $civ13->getVerified();
            if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return null; // Guild not found (bot not in guild)
            if (! $member_future = $guild->members->get('id', $member->id)) return null; // Member left before timer was up
            if ($civ13->getVerifiedItem($member)) return null; // Don't kick if they have been verified
            if ($member_future->roles->has($civ13->role_ids['infantry']) || $member_future->roles->has($civ13->role_ids['veteran'])) return null; // Don't kick if they have a verified role
            return $guild->members->kick($member_future, 'Not verified');
        });
    });
    
    $civ13->discord->on('GUILD_MEMBER_REMOVE', function (Member $member) use ($civ13): void
    {
        $civ13->getVerified();
        if ($member->roles->has($civ13->role_ids['veteran'])) $civ13->whitelistUpdate();
        $faction_roles = [
            'red',
            'blue',
        ];
        foreach ($faction_roles as $role_id) 
            if ($member->roles->has($civ13->role_ids[$role_id])) { $civ13->factionlistUpdate(); break;}
        $admin_roles = [
            'Owner',
            'Chief Technical Officer',
            'Head Admin',
            'Manager',
            'High Staff',
            'Supervisor',
            'Event Admin',
            'Admin',
            'Moderator',
            'Mentor',
            'veteran',
            'infantry',
            'banished',
            'paroled',
        ];
        foreach ($admin_roles as $role) 
            if ($member->roles->has($civ13->role_ids[$role])) { $civ13->adminlistUpdate(); break;}
    });
    
    $civ13->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old) use ($civ13): void
    {
        if ($member->roles->has($civ13->role_ids['veteran']) !== $member_old->roles->has($civ13->role_ids['veteran'])) $civ13->whitelistUpdate();
        if ($member->roles->has($civ13->role_ids['infantry']) !== $member_old->roles->has($civ13->role_ids['infantry'])) $civ13->getVerified();
        $faction_roles = [
            'red',
            'blue',
        ];
        foreach ($faction_roles as $role) 
            if ($member->roles->has($civ13->role_ids[$role]) !== $member_old->roles->has($civ13->role_ids[$role])) { $civ13->factionlistUpdate(); break;}
        $admin_roles = [
            'Owner',
            'Chief Technical Officer',
            'Head Admin',
            'Manager',
            'High Staff',
            'Supervisor',
            'Event Admin',
            'Admin',
            'Moderator',
            'Mentor',
            'veteran',
            'infantry',
            'banished',
            'paroled',
        ];
        foreach ($admin_roles as $role) 
            if ($member->roles->has($civ13->role_ids[$role]) !== $member_old->roles->has($civ13->role_ids[$role])) { $civ13->adminlistUpdate(); break;}
    });
};

// a) They have completed the #get-approved process
// b) They have been registered for a while (current undisclosed period of time)
// c) They have been a regular player (have played for an undisclosed period of time)
// d) They have not received any bans on any of the Civ13.com servers (Partially implemented, not currently tracking bans for all time, only active bans)
// e) They are currently in the Civ13 discord server
// f) They have not received any infractions in the Civ13 discord. (NYI)
// g) They have been *recently* active on any of the Civ13.com servers (Determined by admin review)
$promotable_check = function (Civ13 $civ13, string $identifier): bool
{
    if (! $civ13->verified && ! $civ13->getVerified()) return false; // Unable to get info from DB
    if (! $item = $civ13->getVerifiedMemberItems()->get('ss13', htmlspecialchars($identifier)) ?? $civ13->getVerifiedMemberItems()->get('discord', str_replace(['<@', '<@!', '>'], '', $identifier))) return false; // a&e, ckey and/or discord id exists in DB and member is in the Discord server
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; // b, 1 year
    if (($item['seen_tdm'] + $item['seen_nomads'] + $item['seen_pers'])<100) return false; // c, 100 seen
    if ($civ13->bancheck($item['ss13'])) return false; // d, must not have active ban
    return true;
};
$mass_promotion_check = function (Civ13 $civ13) use ($promotable_check): array|false
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return false;
    if (! $members = $guild->members->filter(function (Member $member) use ($civ13) { return $member->roles->has($civ13->role_ids['infantry']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($civ13, $member->id)) $promotables[] = [(string) $member, $member->displayname, $civ13->verified->get('discord', $member->id)['ss13']];
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