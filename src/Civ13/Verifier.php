<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\PromiseInterface;

class Verifier
{
    public Civ13 $civ13;
    public readonly string $verify_url;
    public Collection $verified; // This probably needs a default value for Collection, maybe make it a Repository instead?
    public Collection $pending;
    public array $provisional = []; // Allow provisional registration if the website is down, then try to verify when it comes back up

    public function __construct(Civ13 &$civ13, array $options = [])
    {
        $this->civ13 = $civ13;
        $this->resolveOptions($options);
        $this->verify_url = $options['verify_url'];
        $this->afterConstruct();
    }

    public function resolveOptions(&$options)
    {
        if (! isset($options['verify_url'])) $options['verify_url'] = 'http://valzargaming.com:8080/verified/';
    }

    public function afterConstruct()
    {
        $this->civ13->discord->once('ready', function () {
            $this->verified = $this->getVerified();
            $this->civ13->discord->on('GUILD_MEMBER_ADD', function (Member $member) {
                $this->getVerified();
                if (! $this->civ13->shard) {
                    $this->joinRoles($member);
                    if (isset($this->civ13->timers["add_{$member->id}"])) {
                        $this->civ13->discord->getLoop()->cancelTimer($this->civ13->timers["add_{$member->id}"]);
                        unset($this->civ13->timers["add_{$member->id}"]);
                    }
                    $this->civ13->timers["add_{$member->id}"] = $this->civ13->discord->getLoop()->addTimer(8640, function () use ($member): ?PromiseInterface
                    { // Kick member if they have not verified
                        $this->getVerified();
                        if (! $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) return null; // Guild not found (bot not in guild)
                        if (! $member_future = $guild->members->get('id', $member->id)) return null; // Member left before timer was up
                        if ($this->getVerifiedItem($member)) return null; // Don't kick if they have been verified
                        if (
                            $member_future->roles->has($this->civ13->role_ids['infantry']) ||
                            $member_future->roles->has($this->civ13->role_ids['veteran']) ||
                            $member_future->roles->has($this->civ13->role_ids['banished']) ||
                            $member_future->roles->has($this->civ13->role_ids['permabanished'])
                        ) return null; // Don't kick if they have an verified or banned role
                        return $guild->members->kick($member_future, 'Not verified');
                    });
                }
            });

            $this->civ13->discord->on('GUILD_MEMBER_REMOVE', function (Member $member): void
            {
                $this->getVerified();
                if ($member->roles->has($this->civ13->role_ids['veteran'])) $this->civ13->whitelistUpdate();
                foreach ($faction_roles = ['red', 'blue'] as $role_id) if ($member->roles->has($this->civ13->role_ids[$role_id])) { $this->civ13->factionlistUpdate(); break;}
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
                foreach ($admin_roles as $role) if ($member->roles->has($this->civ13->role_ids[$role])) { $this->civ13->adminlistUpdate(); break; }
            });

            $this->civ13->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old): void
            {
                if (! $member_old) { // Not enough information is known about the change, so we will update everything
                    $this->getVerified();
                    $this->civ13->whitelistUpdate();
                    $this->civ13->factionlistUpdate();
                    $this->civ13->adminlistUpdate();
                    return;
                }
                if ($member->roles->has($this->civ13->role_ids['veteran']) !== $member_old->roles->has($this->civ13->role_ids['veteran'])) $this->civ13->whitelistUpdate();
                elseif ($member->roles->has($this->civ13->role_ids['infantry']) !== $member_old->roles->has($this->civ13->role_ids['infantry'])) $this->getVerified();
                foreach ($faction_roles = ['red', 'blue'] as $role) 
                    if ($member->roles->has($this->civ13->role_ids[$role]) !== $member_old->roles->has($this->civ13->role_ids[$role]))
                        { $this->civ13->factionlistUpdate(); break;}
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
                    if ($member->roles->has($this->civ13->role_ids[$role]) !== $member_old->roles->has($this->civ13->role_ids[$role]))
                        { $this->civ13->adminlistUpdate(); break;}
            });
        });
    }

    public function verifierStatusChannelUpdate(bool $status): ?PromiseInterface
    {
        if (! $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['verifier-status'])) return null;
        [$verifier_name, $reported_status] = explode('-', $channel->name);
        $status = $this->civ13->verifier_online
            ? 'online'
            : 'offline';
        if ($reported_status != $status) {
            //if ($status === 'offline') $msg .= PHP_EOL . "Verifier technician <@{$this->technician_id}> has been not
            if ($channel->name === "{$verifier_name}-{$status}") return null;
            $channel->name = "{$verifier_name}-{$status}";
            $success = function ($result) use ($channel, $status) {
                $this->civ13->loop->addTimer(2, function () use ($channel, $status): void
                {
                    $channel_new = $this->civ13->discord->getChannel($channel->id);
                    $this->civ13->sendMessage($channel_new, "Verifier is now **{$status}**.");
                });
            };
            return $this->civ13->then($channel->guild->channels->save($channel), $success);
        }
        return null;
    }

    /**
     * This function takes a member and checks if they have previously been verified
     * If they have, it will assign them the appropriate roles
     * If they have not, it will send them a message indicating that they need to verify if the 'welcome_message' is set
     *
     * @param Member $member The member to check and assign roles to
     * @return PromiseInterface|null Returns null if the member is softbanned, otherwise returns a PromiseInterface
     */
    public function joinRoles(Member $member): ?PromiseInterface
    {
        if ($member->guild_id === $this->civ13->civ13_guild_id && $item = $this->verified->get('discord', $member->id)) {
            if (! isset($item['ss13'])) $this->civ13->logger->warning("Verified member `{$member->id}` does not have an SS13 ckey assigned to them.");
            else {
                if (($item['ss13'] && isset($this->civ13->softbanned[$item['ss13']])) || isset($this->civ13->softbanned[$member->id])) return null;
                $banned = $this->civ13->bancheck($item['ss13'], true);
                $paroled = isset($this->civ13->paroled[$item['ss13']]);
                if ($banned && $paroled) return $member->setroles([$this->civ13->role_ids['infantry'], $this->civ13->role_ids['banished'], $this->civ13->role_ids['paroled']], "bancheck join {$item['ss13']}");
                if ($banned) return $member->setroles([$this->civ13->role_ids['infantry'], $this->civ13->role_ids['banished']], "bancheck join {$item['ss13']}");
                if ($paroled) return $member->setroles([$this->civ13->role_ids['infantry'], $this->civ13->role_ids['paroled']], "parole join {$item['ss13']}");
                return $member->setroles([$this->civ13->role_ids['infantry']], "verified join {$item['ss13']}");
            }
        }
        if (isset($this->civ13->welcome_message, $this->civ13->channel_ids['get-approved']) && $this->civ13->welcome_message && $member->guild_id === $this->civ13->civ13_guild_id)
            if ($channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['get-approved']))
                return $this->civ13->sendMessage($channel, "<@{$member->id}>, " . $this->civ13->welcome_message);
        return null;
    }

    /*
     * This function is used to generate a token that can be used to verify a BYOND account
     * The token is generated by generating a random string of 50 characters from the set of all alphanumeric characters
     * The token is then stored in the pending collection, which is a collection of arrays with the keys 'discord', 'ss13', and 'token'
     * The token is then returned to the user
     */
    public function generateToken(string $ckey, string $discord_id, string $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', int $length = 50): string
    {
        if ($item = $this->pending->get('ss13', $ckey)) return $item['token'];
        $token = '';
        for ($i = 0; $i < $length; $i++) $token .= $charset[random_int(0, strlen($charset) - 1)];
        $this->pending->pushItem(['discord' => $discord_id, 'ss13' => $ckey, 'token' => $token]);
        return $token;
    }
    /**
     * This function is used to verify a BYOND account.
     * 
     * The function first checks if the discord_id is in the pending collection.
     * If the discord_id is not in the pending collection, the function returns false.
     * 
     * The function then attempts to retrieve the 50 character token from the BYOND website.
     * If the token found on the BYOND website does not match the token in the pending collection, the function returns false.
     * 
     * If the token matches, the function returns true.
     * 
     * @param string $discord_id The Discord ID of the user to verify.
     * @return bool Returns true if the token matches, false otherwise.
     */
    public function checkToken(string $discord_id): bool
    { // Check if the user set their token
        if (! $item = $this->pending->get('discord', $discord_id)) return false; // User is not in pending collection (This should never happen and is probably a programming error)
        if (! $page = $this->civ13->byond->getProfilePage($item['ss13'])) return false; // Website could not be retrieved or the description wasn't found
        if ($item['token'] != $this->civ13->byond->__extractProfileDesc($page)) return false; // Token does not match the description
        return true; // Token matches
    }

    /**
     * This function is used to check if the user has verified their account.
     * If they have not, it checks to see if they have ever played on the server before.
     * If they have not, it sends a message stating that they need to join the server first.
     * It will send a message to the user with instructions on how to verify.
     * If they have, it will check if they have the verified role, and if not, it will add it.
     *
     * @param string $ckey The ckey of the user.
     * @param string $discord_id The Discord ID of the user.
     * @param Member|null $m The Discord member object (optional).
     * @return string The verification status message.
     */
    public function process(string $ckey, string $discord_id, ?Member $m = null): string
    {
        $ckey = $this->civ13->sanitizeInput($ckey);
        if (! isset($this->civ13->permitted[$ckey]) && $this->civ13->permabancheck($ckey)) {
            if ($m && ! $m->roles->has($this->civ13->role_ids['permabanished'])) $m->addRole($this->civ13->role_ids['permabanished'], "permabancheck $ckey");
            return 'This account needs to appeal an existing ban first.';
        }
        if (isset($this->civ13->softbanned[$ckey]) || isset($this->civ13->softbanned[$discord_id])) {
            if ($m && ! $m->roles->has($this->civ13->role_ids['permabanished'])) $m->addRole($this->civ13->role_ids['permabanished'], "permabancheck $ckey");
            return 'This account is currently under investigation.';
        }
        if ($this->verified->has($discord_id)) { $member = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)->members->get('id', $discord_id); if (! $member->roles->has($this->civ13->role_ids['infantry'])) $member->setRoles([$this->civ13->role_ids['infantry']], "approveme join $ckey"); return 'You are already verified!';}
        if ($this->verified->has($ckey)) return "`$ckey` is already verified! If this is your account, contact {<@{$this->civ13->technician_id}>} to delete this entry.";
        if (! $this->pending->get('discord', $discord_id)) {
            if (! $age = $this->civ13->getByondAge($ckey)) return "Byond account `$ckey` does not exist!";
            if (! isset($this->civ13->permitted[$ckey]) && ! $this->civ13->checkByondAge($age)) {
                $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => $reason = "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                $msg = $this->civ13->ban($arr, null, [], true);
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $msg);
                return $reason;
            }
            $found = false;
            $file_contents = '';
            foreach ($this->civ13->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                if (file_exists($settings['basedir'] . Civ13::playerlogs) && $fc = @file_get_contents($settings['basedir'] . Civ13::playerlogs)) $file_contents .= $fc;
                else $this->civ13->logger->warning('unable to open `' . $settings['basedir'] . Civ13::playerlogs . '`');
            }
            foreach (explode('|', $file_contents) as $line) if (explode(';', trim($line))[0] === $ckey) { $found = true; break; }
            if (! $found) return "Byond account `$ckey` has never been seen on the server before! You'll need to join one of our servers at least once before verifying."; 
            return 'Login to your profile at ' . $this->civ13->byond::PROFILE . ' and enter this token as your description: `' . $this->generateToken($ckey, $discord_id) . PHP_EOL . '`Use the command again once this process has been completed.';
        }
        return $this->new($discord_id)['error']; // ['success'] will be false if verification cannot proceed or true if succeeded but is only needed if debugging, ['error'] will contain the error/success message and will be messaged to the user
    }
    /**
     * This function is called when a user still needs to set their token in their BYOND description and call the approveme prompt.
     * It will check if the token is valid, then add the user to the verified list.
     *
     * @param string $discord_id The Discord ID of the user to verify.
     * @return array An array with the verification result. The array contains the following keys:
     *   - 'success' (bool): Indicates whether the verification was successful.
     *   - 'error' (string): If 'success' is false, this contains the error message.
     */
    public function new(string $discord_id): array // ['success' => bool, 'error' => string]
    { // Attempt to verify a user
        if (! $item = $this->pending->get('discord', $discord_id)) return ['success' => false, 'error' => "This error should never happen. If this error persists, contact <@{$this->civ13->technician_id}>."];
        if (! $this->checkToken($discord_id)) return ['success' => false, 'error' => "You have not set your description yet! It needs to be set to `{$item['token']}`"];
        $ckeyinfo = $this->civ13->ckeyinfo($item['ss13']);
        if (($ckeyinfo['altbanned'] || count($ckeyinfo['discords']) > 1) && ! isset($this->civ13->permitted[$item['ss13']])) { // TODO: Add check for permaban
            // TODO: add to pending list?
            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "<@&{$this->civ13->role_ids['High Staff']}>, {$item['ss13']} has been flagged as needing additional review. Please `permit` the ckey after reviewing if they should be allowed to complete the verification process.");
            return ['success' => false, 'error' => "Your ckey `{$item['ss13']}` has been flagged as needing additional review. Please wait for a staff member to assist you."];
        }
        return $this->verify($item['ss13'], $discord_id);
    }
    /**
     * This function allows a ckey to bypass the verification process entirely.
     * NOTE: This function is only authorized to be used by the database administrator.
     *
     * @param string $ckey The ckey to register.
     * @param string $discord_id The Discord ID associated with the ckey.
     * @return array An array containing the success status and error message (if any).
     */
    public function register(string $ckey, string $discord_id): array // ['success' => bool, 'error' => string]
    {
        $this->civ13->permitCkey($ckey, true);
        return $this->verify($ckey, $discord_id);
    }
    /**
     * This function is called when a user has already set their token in their BYOND description and called the approveme prompt.
     * If the Discord ID or ckey is already in the SQL database, it will return an error message stating that the ckey is already verified.
     * Otherwise, it will add the user to the SQL database and the verified list, remove them from the pending list, and give them the verified role.
     *
     * @param string $ckey The ckey of the user.
     * @param string $discord_id The Discord ID of the user.
     * @param bool $provisional (Optional) Whether the registration is provisional or not. Default is false.
     * @return array An array with 'success' (bool) and 'error' (string) keys indicating the success status and error message, if any.
     */
    public function verify(string $ckey, string $discord_id, bool $provisional = false): array // ['success' => bool, 'error' => string]
    { // Send $_POST information to the website. Only call this function after the getByondDesc() verification process has been completed!
        $success = false;
        $error = '';

        // Bypass remote registration and skip straight to provisional if the remote webserver is not configured
        if (
            (! isset($this->verify_url) || ! $this->verify_url) // The website URL is not configured
            && ! $provisional // This is not revisiting a previous provisional registration
        ) {
            if (! isset($this->provisional[$ckey])) {
                $this->provisional[$ckey] = $discord_id;
                $this->civ13->VarSave('provisional.json', $this->provisional);
            }
            if ($this->provisionalRegistration($ckey, $discord_id)) $error = "Provisionally registered `$ckey` with Discord ID <@$discord_id>.";
            return ['success' => $success, 'error' => $error];
        }
       
        $http_status = 0; // Don't try to curl if the webserver is down
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->verify_url,
            CURLOPT_HTTPHEADER => ['Content-Type' => 'application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Civ13',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['token' => $this->civ13->civ_token, 'ckey' => $ckey, 'discord' => $discord_id]),
            CURLOPT_TIMEOUT => 5, // Set a timeout of 5 seconds
            CURLOPT_CONNECTTIMEOUT => 2, // Set a connection timeout of 2 seconds
        ]);
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
        curl_close($ch);
        switch ($http_status) {
            case 200: // Verified
                $success = true;
                $error = "`$ckey` - ({$this->civ13->ages[$ckey]}) has been verified and registered to <@$discord_id>";
                $this->pending->offsetUnset($discord_id);
                $this->getVerified(false);
                if (! $member = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)->members->get('id', $discord_id)) return ['success' => false, 'error' => "($ckey - {$this->civ13->ages[$ckey]}) was verified but the member couldn't be found in the server."];
                $channel = isset($this->civ13->channel_ids['staff_bot']) ? $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot']) : null;
                if (isset($this->civ13->panic_bans[$ckey])) {
                    $this->civ13->__panicUnban($ckey);
                    $error .= ' and the panic bunker ban removed.';
                    if (! $member->roles->has($this->civ13->role_ids['infantry'])) $member->addRole($this->civ13->role_ids['infantry'], "approveme verified ($ckey)");
                    if ($channel) $this->civ13->sendMessage($channel, "Verified and removed the panic bunker ban from $member ($ckey - {$this->civ13->ages[$ckey]}).");
                } elseif ($this->civ13->bancheck($ckey, true)) {
                    if (! $member->roles->has($this->civ13->role_ids['infantry'])) $member->setroles([$this->civ13->role_ids['infantry'], $this->civ13->role_ids['banished']], "approveme verified ($ckey)");
                    if ($channel) $this->civ13->sendMessage($channel, "Added the banished role to $member ($ckey - {$this->civ13->ages[$ckey]}).");
                } else {
                    if (! $member->roles->has($this->civ13->role_ids['infantry'])) $member->addRole($this->civ13->role_ids['infantry'], "approveme verified ($ckey)");
                    if ($channel) $this->civ13->sendMessage($channel, "Verified $member. ($ckey - {$this->civ13->ages[$ckey]})");
                }
                break;
            case 403: // Already registered
                $error = "Either Byond account `$ckey` or <@$discord_id> has already been verified."; // This should have been caught above. Need to run getVerified() again?
                $this->getVerified(false);
                // Check if the user is already verified and add the role if it's missing
                if (! $guild = $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) break;
                if (! $members = $guild->members->filter(function (Member $member) {
                    return ! $member->roles->has($this->civ13->role_ids['veteran'])
                        && ! $member->roles->has($this->civ13->role_ids['infantry'])
                        && ! $member->roles->has($this->civ13->role_ids['banished'])
                        && ! $member->roles->has($this->civ13->role_ids['permabanished'])
                        && ! $member->roles->has($this->civ13->role_ids['dungeon']);
                })) break;
                if (! $member = $members->get('id', $discord_id)) break;
                if (! $m = $this->getVerifiedMember($member)) break;
                $m->addRole($this->civ13->role_ids['infantry'], "approveme verified ($ckey)");
                break;
            case 404:
                $error = 'The website could not be found or is misconfigured. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                break;
            case 502: // NGINX's PHP-CGI workers are unavailable
                $error = 'The website\'s PHP-CGI workers are currently unavailable. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                break;
            case 503: // Database unavailable
                $error = 'The website timed out while attempting to process the request because the database is currently unreachable. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                break;
            case 504: // Gateway timeout
                $error = 'The website timed out while attempting to process the request. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                break;
            case 0: // The website is down, so allow provisional registration, then try to verify when it comes back up
                $this->verifierStatusChannelUpdate($this->civ13->verifier_online = false);
                $error = 'The website could not be reached. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                if (! $provisional) {
                    if (! isset($this->provisional[$ckey])) {
                        $this->provisional[$ckey] = $discord_id;
                        $this->civ13->VarSave('provisional.json', $this->provisional);
                    }
                    if ($this->provisionalRegistration($ckey, $discord_id)) $error = "The website could not be reached. Provisionally registered `$ckey` with Discord ID <@$discord_id>.";
                    else $error .= ' Provisional registration is already pending and a new provisional role will not be provided at this time.' . PHP_EOL . $error;
                }
                break;
            default:
                $error = "There was an error attempting to process the request: [$http_status] $result" . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                break;
        }
        if (isset($ch)) curl_close($ch);
        return ['success' => $success, 'error' => $error];
    }
    /**
     * Removes a ckey from the verified list and sends a DELETE request to a website.
     *
     * @param string $id The ckey to be removed.
     * @return array An array with the success status and a message.
     *               ['success' => bool, 'message' => string]
     */
    public function unverify(string $id): array // ['success' => bool, 'message' => string]
    {
        if ( ! $verified_array = $this->civ13->VarLoad('verified.json')) {
            $this->civ13->logger->warning('Unable to load the verified list.');
            return ['success' => false, 'message' => 'Unable to load the verified list.'];
        }

        $removed = array_filter($verified_array, function ($value) use ($id) {
            return $value['ss13'] === $id || $value['discord'] === $id;
        });

        if (! $removed) {
            $this->civ13->logger->info("Unable to find `$id` in the verified list.");
            return ['success' => false, 'message' => "Unable to find `$id` in the verified list."];
        }

        $verified_array = array_values(array_diff_key($verified_array, $removed));
        $this->verified = new Collection($verified_array, 'discord');   
        $this->civ13->VarSave('verified.json', $verified_array);

         // Send $_POST information to the website.
        $message = '';
        if (isset($this->verify_url) && $this->verify_url) { // Bypass webserver deregistration if not configured
            $http_status = 0; // Don't try to curl if the webserver is down
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->verify_url,
                CURLOPT_HTTPHEADER => ['Content-Type' => 'application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'Civ13',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['method' => 'DELETE', 'token' => $this->civ13->civ_token, 'ckey' => $id, 'discord' => $id]),
                CURLOPT_TIMEOUT => 5, // Set a timeout of 5 seconds
                CURLOPT_CONNECTTIMEOUT => 2, // Set a connection timeout of 2 seconds
            ]);
            $result = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
            curl_close($ch);
            switch ($http_status) {
                case 200: // Verified
                    if (! $member = $this->getVerifiedMember($id)) $message = "`$id` was unverified but the member couldn't be found in the server.";
                    if ($member && ($member->roles->has($this->civ13->role_ids['infantry']) || $member->roles->has($this->civ13->role_ids['veteran']))) $member->setRoles([], "unverified ($id)");
                    if ($channel = isset($this->civ13->channel_ids['staff_bot']) ? $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot']) : null) $this->civ13->sendMessage($channel, "Unverified `$id`.");
                    $this->getVerified(false);
                    break;
                case 403: // Already registered
                    $message = "ID `$id` was not already verified."; // This should have been caught above. Need to run getVerified() again?
                    $this->getVerified(false);
                    break;
                case 404:
                    $message = 'The website could not be found or is misconfigured. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                    break;
                case 405: // Method not allowed
                    $message = "The method used to access the website is not allowed. Please check the configuration of the website." . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>. Reason: $result";
                    break;
                case 502: // NGINX's PHP-CGI workers are unavailable
                    $message = "The website's PHP-CGI workers are currently unavailable. Please try again later." . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                    break;
                case 503: // Database unavailable
                    $message = 'The website timed out while attempting to process the request because the database is currently unreachable. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                    break;
                case 504: // Gateway timeout
                    $message = 'The website timed out while attempting to process the request. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                    break;
                case 0: // The website is down, so allow provisional registration, then try to verify when it comes back up
                    $this->verifierStatusChannelUpdate($this->civ13->verifier_online = false);
                    $message = 'The website could not be reached. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                    break;
                default:
                    $message = "There was an error attempting to process the request: [$http_status] $result" . PHP_EOL . "If this error persists, contact <@{$this->civ13->technician_id}>.";
                    break;
            }
            if (isset($ch)) curl_close($ch);
        }
        
        $removed_items = implode(PHP_EOL, array_map(fn($item) => json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $removed));
        if ($removed_items) $message .= PHP_EOL . 'Removed from the verified list: ```json' . PHP_EOL . $removed_items . PHP_EOL . '```' . PHP_EOL . $message;
        if ($message) $this->civ13->logger->info($message);
        return ['success' => true, 'message' => $message];
    }

    /**
     * This function is called when a user has set their token in their BYOND description and attempts to verify.
     * It is also used to handle errors coming from the webserver.
     * If the website is down, it will add the user to the provisional list and set a timer to try to verify them again in 30 minutes.
     * If the user is allowed to be granted a provisional role, it will return true.
     *
     * @param string $ckey The BYOND ckey of the user.
     * @param string $discord_id The Discord ID of the user.
     * @return bool Returns true if the user is allowed to be granted a provisional role, false otherwise.
     */
    public function provisionalRegistration(string $ckey, string $discord_id): bool
    {
        $provisionalRegistration = function (string $ckey, string $discord_id) use (&$provisionalRegistration) {
            if ($this->verified->get('discord', $discord_id)) { // User already verified, this function shouldn't be called (may happen anyway because of the timer)
                unset($this->provisional[$ckey]);
                return false;
            }

            $result = [];
            if (isset($this->verify_url) && $this->verify_url) $result = $this->verify($ckey, $discord_id, true);
            if (isset($result['success']) && $result['success']) {
                unset($this->provisional[$ckey]);
                $this->civ13->VarSave('provisional.json', $this->provisional);
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "Successfully verified Byond account `$ckey` with Discord ID <@$discord_id>.");
                return false;
            }
            if (isset($result['error']) && $result['error']) {
                if (str_starts_with($result['error'], 'The website') || (! isset($this->verify_url) || ! $this->verify_url)) { // The website URL is not configured or the website could not be reached
                    if ($member = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)->members->get('id', $discord_id))
                    if ((isset($this->verify_url) && $this->verify_url)) {
                        if (! isset($this->civ13->timers['provisional_registration_'.$discord_id])) $this->civ13->timers['provisional_registration_'.$discord_id] = $this->civ13->discord->getLoop()->addTimer(1800, function () use ($provisionalRegistration, $ckey, $discord_id) { $provisionalRegistration($ckey, $discord_id); });
                        if (! $member->roles->has($this->civ13->role_ids['infantry']) && isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>: {$result['error']}" . PHP_EOL . 'Providing provisional verification role and trying again in 30 minutes... ');
                    }
                    if (! $member->roles->has($this->civ13->role_ids['infantry'])) $member->setRoles([$this->civ13->role_ids['infantry']], "Provisional verification `$ckey`");
                    return true;
                }
                if ($member = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)->members->get('id', $discord_id))
                    if ($member->roles->has($this->civ13->role_ids['infantry']))
                        $member->setRoles([], 'Provisional verification failed');
                unset($this->provisional[$ckey]);
                $this->civ13->VarSave('provisional.json', $this->provisional);
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>: {$result['error']}");
                return false;
            }
            // The code should only get this far if $result['error'] wasn't set correctly. This should never happen and is probably a programming error.
            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, "Something went wrong trying to process the provisional registration for Byond account `$ckey` with Discord ID <@$discord_id>. If this error persists, contact <@{$this->civ13->technician_id}>.");
            return false;
        };
        return $provisionalRegistration($ckey, $discord_id);
    }

    /**
     * Checks if the input is verified.
     *
     * @param string $input The input to be checked.
     * @return bool Returns true if the input is verified, false otherwise.
     */
    public function isVerified(string $input): bool
    {
        return $this->verified->get('ss13', $input) ?? (is_numeric($input) && ($this->verified->get('discord', $input)));
    }
    /*
     * This function is used to refresh the bot's cache of verified users
     * It is called when the bot starts up, and when the bot receives a GUILD_MEMBER_ADD event
     * It is also called when the bot receives a GUILD_MEMBER_REMOVE event
     * It is also called when the bot receives a GUILD_MEMBER_UPDATE event, but only if the user's roles have changed
     */
    /**
     * Retrieves verified users from a JSON file or an API endpoint and returns them as a Collection.
     *
     * @param bool $reload Whether to force a reload of the data from the cached data (JSON file) if the API endpoint is unreachable.
     *
     * @return Collection The verified users as a Collection.
     */
    public function getVerified(bool $initialize = true): Collection
    {
        $http_response_header = null;
        if (! $json = @file_get_contents($this->verify_url, false, stream_context_create(['http' => ['connect_timeout' => 5]]))) {
            $this->verifierStatusChannelUpdate($this->civ13->verifier_online = false);
        } else {
            $header = implode(' ', $http_response_header); // This is populated invisibly by file_get_contents
            $this->verifierStatusChannelUpdate($this->civ13->verifier_online = strpos($header, '502') === false);
        }
        if ($verified_array = $json ? json_decode($json, true) ?? [] : []) { // If the API endpoint is reachable, use the data from the API endpoint
            $this->civ13->VarSave('verified.json', $verified_array);
            return $this->verified = new Collection($verified_array, 'discord');
        }
        if ($initialize) { // If the API endpoint is unreachable, use the data from the file cache
            if (! $verified_array = $this->civ13->VarLoad('verified.json') ?? []) $this->civ13->VarSave('verified.json', $verified_array);
            return $this->verified = new Collection($verified_array, 'discord');
        }
        return $this->verified ?? new Collection($verified_array, 'discord'); 
    }
    /**
     * This function is used to get a verified item from a ckey or Discord ID.
     * If the user is verified, it will return an array containing the verified item.
     * It will return false if the user is not verified.
     *
     * @param Member|User|array|string $input The input value to search for the verified item.
     * @return array|null The verified item as an array, or null if not found.
     */
    public function getVerifiedItem(Member|User|array|string $input): ?array
    {
        if (is_string($input)) {
            if (! $input = $this->civ13->sanitizeInput($input)) return null;
            if (is_numeric($input) && $item = $this->verified->get('discord', $input)) return $item;
            if ($item = $this->verified->get('ss13', $input)) return $item;
        }
        if (($input instanceof Member || $input instanceof User) && ($item = $this->verified->get('discord', $input->id))) return $item;
        if (is_array($input)) {
            if (! isset($input['discord']) && ! isset($input['ss13'])) return null;
            if (isset($input['discord']) && is_numeric($input['discord']) && $item = $this->verified->get('discord', $this->civ13->sanitizeInput($input['discord']))) return $item;
            if (isset($input['ss13']) && is_string($input['ss13']) && $item = $this->verified->get('ss13', $this->civ13->sanitizeInput($input['ss13']))) return $item;
        }

        return null;
    }
    /**
     * Fetches the bot's cache of verified members that are currently found in the Civ13 Discord server.
     * If the bot is not in the Civ13 Discord server, it will return the bot's cache of verified members.
     *
     * @return Collection The collection of verified member items.
     */
    public function getVerifiedMemberItems(): Collection
    {
        if ($guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) return $this->verified->filter(function($v) use ($guild) { return $guild->members->has($v['discord']); });
        return $this->verified;
    }
    /**
     * This function is used to get a Member object from a ckey or Discord ID.
     * It will return false if the user is not verified, if the user is not in the Civ13 Discord server, or if the bot is not in the Civ13 Discord server.
     *
     * @param Member|User|array|string|null $input The input parameter can be a Member object, User object, an array, a string, or null.
     * @return Member|null The Member object if found, or null if not found or not verified.
     */
    public function getVerifiedMember(Member|User|array|string|null $input): ?Member
    {
        if (! $input) return null;
        if (! $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) return null;

        // Get Discord ID
        $id = null;
        if ($input instanceof Member || $input instanceof User) $id = $input->id;
        elseif (is_string($input)) {
            if (is_numeric($input = $this->civ13->sanitizeInput($input))) $id = $input;
            elseif ($item = $this->verified->get('ss13', $input)) $id = $item['discord'];
        } elseif (is_array($input)) {
            if (isset($input['discord'])) {
                if (is_numeric($discordId = $this->civ13->sanitizeInput($input['discord']))) $id = $discordId;
            } elseif (isset($input['ss13'])) {
                if ($item = $this->verified->get('ss13', $this->civ13->sanitizeInput($input['ss13']))) $id = $item['discord'];
            }
        }
        if (! $id || ! $this->isVerified($id)) return null;
        return $guild->members->get('id', $id);
    }
}