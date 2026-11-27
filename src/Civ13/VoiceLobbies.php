<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13;

use React\Promise\PromiseInterface;

/**
 * Launcher voice chat, bot side (Civ13 issue #5122 Phase 3; game side is
 * code/modules/voice/voice_chat.dm, design in Civ13 docs/launcher-voice-plan.md).
 *
 * Each game world POSTs a roster of its voice players ({ckey, key, team}) to
 * `/webhook/<server>/voice_roster`. For every ckey this service:
 * - mints a Discord provisional account keyed on the ckey with the bot token
 *   (DiscordPHP SessionManager::createProvisionalAccount), so players need no
 *   Discord login, and answers the game with each ckey's Discord user ID;
 * - keeps one Discord lobby per world (the region call) and one per team, with
 *   members set from the roster. A member who leaves the roster is removed
 *   after REMOVE_GRACE seconds, so a reconnect or a missed POST does not
 *   churn the lobby. A silent world also loses its presence leases;
 *   the event loop reconciles Discord membership without another POST.
 *
 * The launcher fetches its access token from the public `/voice/token`
 * endpoint by showing `sha256("civ13-voice-token:" + key)`. The answer is
 * sealed with AES-256-GCM under HKDF-SHA256(key, "civ13-voice-seal"), so the
 * plain-HTTP web API only ever carries sealed bytes. The raw key reaches the
 * bot only in the roster, over https or loopback.
 *
 * Every Discord effect is an injected closure, so the class runs offline in
 * tests. Nothing is stored on disk: a bot restart mints fresh tokens.
 */
final class VoiceLobbies
{
    public const REMOVE_GRACE = 30;
    // The game normally posts every 5 seconds. Allow several missed heartbeats.
    public const ROSTER_LEASE = 45;
    public const SWEEP_INTERVAL = 5;
    public const MAX_MEMBERS = 500; // per world roster
    public const TOKEN_MARGIN = 86400; // re-mint a token with less than a day left
    public const IDLE_TIMEOUT = 600;
    public const SEAL_INFO = 'civ13-voice-seal';
    public const TOKEN_LABEL = 'civ13-voice-token:';

    /** @var array<string,array{user_id:string,access_token:string,expires_at:int}> ckey => token */
    private array $tokens = [];
    /** @var array<string,true> ckeys being minted */
    private array $minting = [];
    /** @var array<string,array{server:string,ckey:string,key:string}> token id => owner */
    private array $keys = [];
    /** @var array<string,array<string,array{team:string,seen:int,gone?:int}>> server => ckey => presence */
    private array $rosters = [];
    /** @var array<string,string|false> lobby name => Discord lobby ID (false while creating) */
    private array $lobbies = [];
    /** @var array<string,array<string,true>> lobby name => user IDs Discord has as members */
    private array $members = [];
    /** @var array<string,true> lobby names with a membership request in flight */
    private array $syncing = [];
    /** @var \Closure():int */
    private \Closure $clock;

    /**
     * @param \Closure(string $ckey): PromiseInterface $mint resolves {access_token, expires_in, id_token}
     * @param \Closure(array $metadata): PromiseInterface $createLobby resolves the new lobby's ID
     * @param \Closure(string $lobby, list<array> $members): PromiseInterface $updateMembers bulk add/remove, at most 25
     */
    public function __construct(
        private \Closure $mint,
        private \Closure $createLobby,
        private \Closure $updateMembers,
        private string $applicationId,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(hrtime(true) / 1_000_000_000);
    }

    /** The roster body the game sends, normalised; null when it is not one. */
    public static function parseRoster(mixed $data): ?array
    {
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !is_array($data['members'] ?? null) || !array_is_list($data['members'])) {
            return null;
        }
        if (count($data['members']) > self::MAX_MEMBERS) {
            return null;
        }
        $members = [];
        foreach ($data['members'] as $member) {
            if (!is_array($member)) {
                return null;
            }
            $ckey = $member['ckey'] ?? null;
            $key = $member['key'] ?? null;
            $team = $member['team'] ?? '';
            if (!is_string($ckey) || preg_match('/^[a-z0-9]{1,30}$/D', $ckey) !== 1) {
                return null;
            }
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{32,64}$/D', $key) !== 1) {
                return null;
            }
            if (!is_string($team) || strlen($team) > 64 || preg_match('/[\x00-\x1F\x7F]/', $team) === 1) {
                return null;
            }
            $members[$ckey] = ['key' => $key, 'team' => $team];
        }

        return $members;
    }

    /** What the launcher shows instead of its key. */
    public static function tokenId(string $key): string
    {
        return hash('sha256', self::TOKEN_LABEL.$key);
    }

    /**
     * Takes one world's roster, starts any minting and lobby work it needs, and
     * returns the Discord user IDs known so far for its ckeys.
     *
     * @param  array<string,array{key:string,team:string}> $members from {@see parseRoster()}
     * @return array<string,string>                        ckey => Discord user ID
     */
    public function roster(string $server, array $members): array
    {
        $now = ($this->clock)();
        // PHP turns numeric array keys ("123") into integers; ckeys stay strings.
        $members = array_combine(array_map('strval', array_keys($members)), array_values($members)) ?: [];
        foreach ($this->keys as $id => $owner) {
            if ($owner['server'] === $server && (!isset($members[$owner['ckey']]) || $members[$owner['ckey']]['key'] !== $owner['key'])) {
                unset($this->keys[$id]);
            }
        }
        $presence = $this->rosters[$server] ?? [];
        foreach ($members as $ckey => $member) {
            $ckey = (string) $ckey;
            $this->keys[self::tokenId($member['key'])] = ['server' => $server, 'ckey' => $ckey, 'key' => $member['key']];
            $presence[$ckey] = ['team' => $member['team'], 'seen' => $now];
        }
        // The grace period runs from the first roster without the player.
        foreach (array_keys($presence) as $ckey) {
            if (isset($members[(string) $ckey])) {
                continue;
            }
            $presence[$ckey]['gone'] ??= $now;
            if (!self::presenceIsActive($presence[$ckey], $now)) {
                unset($presence[$ckey]);
            }
        }
        $this->rosters[$server] = $presence;
        foreach (array_keys($members) as $ckey) {
            $this->ensureToken((string) $ckey);
        }
        $this->syncServer($server);

        $ids = [];
        foreach ($members as $ckey => $member) {
            $ckey = (string) $ckey;
            if (isset($this->tokens[$ckey])) {
                $ids[$ckey] = $this->tokens[$ckey]['user_id'];
            }
        }

        return $ids;
    }

    /** Expire silent worlds and retry membership reconciliation without roster traffic. */
    public function sweep(): void
    {
        $now = ($this->clock)();
        foreach ($this->rosters as $server => $presence) {
            $this->rosters[$server] = array_filter($presence, static fn (array $entry): bool => self::presenceIsActive($entry, $now));
        }
        foreach ($this->keys as $id => $owner) {
            if (!isset($this->rosters[$owner['server']][$owner['ckey']])) {
                unset($this->keys[$id]);
            }
        }
        foreach (array_keys($this->rosters) as $server) {
            $this->syncServer($server);
        }
    }

    /** @param array{team:string,seen:int,gone?:int} $entry */
    private static function presenceIsActive(array $entry, int $now): bool
    {
        return $entry['seen'] > $now - self::ROSTER_LEASE
            && (!isset($entry['gone']) || $entry['gone'] > $now - self::REMOVE_GRACE);
    }

    /**
     * The launcher's sealed token answer for a token ID, or null when the ID
     * is unknown or its token is not ready yet.
     */
    public function token(string $id): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $id) !== 1 || !isset($this->keys[$id])) {
            return null;
        }
        $owner = $this->keys[$id];
        $now = ($this->clock)();
        $presence = $this->rosters[$owner['server']][$owner['ckey']] ?? null;
        if ($presence === null || !self::presenceIsActive($presence, $now)) {
            unset($this->keys[$id]);

            return null;
        }
        $token = $this->tokens[$owner['ckey']] ?? null;
        if ($token === null || $token['expires_at'] <= $now) {
            $this->ensureToken($owner['ckey']);

            return null;
        }
        $payload = json_encode([
            'v' => 1,
            'application_id' => $this->applicationId,
            'server' => $owner['server'],
            'user_id' => $token['user_id'],
            'access_token' => $token['access_token'],
            'expires_in' => $token['expires_at'] - $now,
        ], JSON_THROW_ON_ERROR);

        return json_encode(['v' => 1, 'sealed' => self::seal($owner['key'], $id, $payload)], JSON_THROW_ON_ERROR);
    }

    /** AES-256-GCM under HKDF-SHA256(key, SEAL_INFO); AAD is the token ID. base64(nonce|ciphertext|tag). */
    public static function seal(string $key, string $id, string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::sealKey($key), OPENSSL_RAW_DATA, $nonce, $tag, $id, 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Voice token sealing failed');
        }

        return base64_encode($nonce.$ciphertext.$tag);
    }

    /** The inverse of {@see seal()}, for tests and tooling. Null when it does not open. */
    public static function open(string $key, string $id, string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', self::sealKey($key), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16), $id);

        return $plain === false ? null : $plain;
    }

    private static function sealKey(string $key): string
    {
        return hash_hkdf('sha256', $key, 32, self::SEAL_INFO);
    }

    /** The Discord user ID inside a token response's id_token (its `sub`), or null. */
    public static function userIdFromIdToken(mixed $idToken): ?string
    {
        if (!is_string($idToken) || substr_count($idToken, '.') !== 2) {
            return null;
        }
        $part = explode('.', $idToken)[1];
        $json = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        $claims = $json === false ? null : json_decode($json, true);
        $sub = is_array($claims) ? ($claims['sub'] ?? null) : null;

        return is_string($sub) && preg_match('/^[0-9]{17,20}$/D', $sub) === 1 ? $sub : null;
    }

    /** Lobby names for one world, for tests and status. */
    public function lobbyIds(): array
    {
        return array_filter($this->lobbies, 'is_string');
    }

    public function memberIds(string $lobby): array
    {
        return array_map('strval', array_keys($this->members[$lobby] ?? []));
    }

    private function ensureToken(string $ckey): void
    {
        $token = $this->tokens[$ckey] ?? null;
        if (isset($this->minting[$ckey]) || ($token !== null && $token['expires_at'] > ($this->clock)() + self::TOKEN_MARGIN)) {
            return;
        }
        $this->minting[$ckey] = true;
        ($this->mint)($ckey)->then(function ($response) use ($ckey): void {
            unset($this->minting[$ckey]);
            $response = (array) $response;
            $user = self::userIdFromIdToken($response['id_token'] ?? null);
            $access = $response['access_token'] ?? null;
            $expires = (int) ($response['expires_in'] ?? 0);
            if ($user === null || !is_string($access) || $access === '' || $expires <= 0) {
                return;
            }
            $this->tokens[$ckey] = ['user_id' => $user, 'access_token' => $access, 'expires_at' => ($this->clock)() + $expires];
            foreach (array_keys($this->rosters) as $server) {
                if (isset($this->rosters[$server][$ckey])) {
                    $this->syncServer($server);
                }
            }
        }, function () use ($ckey): void {
            unset($this->minting[$ckey]);
        });
    }

    /** Desired members of every lobby belonging to one world. */
    private function desired(string $server): array
    {
        $want = ['region:'.$server => []];
        $now = ($this->clock)();
        foreach ($this->rosters[$server] ?? [] as $ckey => $presence) {
            if (!self::presenceIsActive($presence, $now)) {
                continue;
            }
            $user = $this->tokens[$ckey]['user_id'] ?? null;
            if ($user === null) {
                continue;
            }
            $want['region:'.$server][$user] = true;
            if ($presence['team'] !== '') {
                $want['team:'.$server.':'.$presence['team']][$user] = true;
            }
        }
        foreach (array_keys($this->lobbies) as $name) {
            if (str_starts_with($name, 'team:'.$server.':') && !isset($want[$name])) {
                $want[$name] = []; // empty a team nobody is on any more
            }
        }

        return $want;
    }

    private function syncServer(string $server): void
    {
        foreach ($this->desired($server) as $name => $users) {
            $this->syncLobby($server, $name, $users);
        }
    }

    private function syncLobby(string $server, string $name, array $users): void
    {
        if (isset($this->syncing[$name])) {
            return;
        }
        if (!isset($this->lobbies[$name])) {
            if (!$users) {
                return;
            }
            $this->lobbies[$name] = false;
            $kind = str_starts_with($name, 'team:') ? 'team' : 'region';
            $metadata = ['civ13_server' => $server, 'civ13_kind' => $kind];
            if ($kind === 'team') {
                $metadata['civ13_team'] = substr($name, strlen('team:'.$server.':'));
            }
            ($this->createLobby)($metadata)->then(function ($id) use ($server, $name): void {
                if (!is_string($id) || $id === '') {
                    unset($this->lobbies[$name]);

                    return;
                }
                $this->lobbies[$name] = $id;
                $this->members[$name] = [];
                $this->syncServer($server);
            }, function () use ($name): void {
                unset($this->lobbies[$name]);
            });

            return;
        }
        $lobby = $this->lobbies[$name];
        if ($lobby === false) {
            return;
        }
        $have = $this->members[$name] ?? [];
        $changes = [];
        // Revocations should not sit behind a batch of new admissions.
        foreach ($have as $user => $_) {
            if (!isset($users[$user])) {
                $changes[] = ['id' => (string) $user, 'remove_member' => true];
            }
        }
        foreach ($users as $user => $_) {
            if (!isset($have[$user])) {
                $changes[] = ['id' => (string) $user];
            }
        }
        if (!$changes) {
            return;
        }
        $batch = array_slice($changes, 0, 25);
        $this->syncing[$name] = true;
        ($this->updateMembers)($lobby, $batch)->then(function ($confirmed = null) use ($server, $name, $batch): void {
            unset($this->syncing[$name]);
            // Discord can leave out an addition it refused; only confirmed
            // additions count, so the rest are tried again at reconciliation.
            $added = is_array($confirmed) ? array_flip(array_map('strval', $confirmed)) : null;
            $progress = false;
            foreach ($batch as $change) {
                if ($change['remove_member'] ?? false) {
                    unset($this->members[$name][$change['id']]);
                    $progress = true;
                } elseif ($added === null || isset($added[$change['id']])) {
                    $this->members[$name][$change['id']] = true;
                    $progress = true;
                }
            }
            if ($progress) {
                $this->syncServer($server); // the next batch, if any
            }
        }, function ($error = null) use ($name): void {
            unset($this->syncing[$name]); // the next reconciliation tries again
            if ($error instanceof \Discord\Http\Exceptions\NotFoundException) {
                // Discord deleted the lobby (idle timeout); make a new one next time.
                unset($this->lobbies[$name], $this->members[$name]);
            }
        });
    }
}
