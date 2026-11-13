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

/**
 * Verification from inside the game.
 *
 * A player runs `/approveme` without a ckey and gets a short single-use code that
 * only they can see. They type it into the game's "Verify Discord" verb. The game
 * already knows their BYOND key (BYOND signed them in), so the code proves the
 * Discord side and the game proves the ckey; no BYOND profile token is needed.
 *
 * The game POSTs `ckey` + `code` to its own bot (`/webhook/<server>/discord_verify`,
 * key in a header, nothing secret in the URL). The primary bot redeems it here. A desktop
 * bot with a read-only verifier cannot register anyone, so it forwards the request, sealed
 * with INGAME_VERIFY_TOKEN ({@see seal()}), to the primary bot's `/verified/ingame` relay
 * endpoint and hands the sealed answer back to the game.
 *
 * Codes live only in memory: a bot restart simply asks players for a new code.
 */
final class InGameVerification
{
    public const CODE_LIFETIME = 600; // seconds
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I
    public const CODE_LENGTH = 8;
    public const MAX_CODES = 2000;
    public const MAX_FAILURES = 5; // per ckey per FAILURE_WINDOW
    public const FAILURE_WINDOW = 600;

    /** @var array<string,array{discord:string,expires:int}> code => owner */
    private array $codes = [];
    /** @var array<string,list<int>> ckey => failure times */
    private array $failures = [];
    /** @var \Closure():int */
    private \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** A fresh code for this Discord user; any older code of theirs stops working. */
    public function issue(string $discord_id): string
    {
        if (preg_match('/^[0-9]{17,20}$/D', $discord_id) !== 1) {
            throw new \InvalidArgumentException('Discord ID expected');
        }
        $this->prune();
        foreach ($this->codes as $code => $owner) {
            if ($owner['discord'] === $discord_id) {
                unset($this->codes[$code]);
            }
        }
        if (count($this->codes) >= self::MAX_CODES) {
            array_shift($this->codes); // oldest first
        }
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (isset($this->codes[$code]));
        $this->codes[$code] = ['discord' => $discord_id, 'expires' => ($this->clock)() + self::CODE_LIFETIME];

        return self::display($code);
    }

    /** `ABCDEFGH` -> `ABCD-EFGH`, the way players see it. */
    public static function display(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /** Players may type the code with spaces, dashes or lower case. Null when it can't be a code. */
    public static function normalize(mixed $code): ?string
    {
        if (!is_string($code) || strlen($code) > 32) {
            return null;
        }
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        $alphabet = preg_quote(self::CODE_ALPHABET, '/');

        return preg_match('/^['.$alphabet.']{'.self::CODE_LENGTH.'}$/D', $code) === 1 ? $code : null;
    }

    /** Whether this ckey has used up its wrong guesses for now. */
    public function locked(string $ckey): bool
    {
        $this->prune();

        return count($this->failures[$ckey] ?? []) >= self::MAX_FAILURES;
    }

    /**
     * Takes the code out of circulation and returns its Discord ID, or null when it is
     * unknown or expired (that counts as a wrong guess for the ckey).
     */
    public function consume(string $ckey, string $code): ?string
    {
        $this->prune();
        if ($this->locked($ckey)) {
            return null;
        }
        $code = self::normalize($code);
        if ($code === null || !isset($this->codes[$code])) {
            $this->failures[$ckey][] = ($this->clock)();

            return null;
        }
        $discord_id = $this->codes[$code]['discord'];
        unset($this->codes[$code], $this->failures[$ckey]);

        return $discord_id;
    }

    /** Number of live codes, for tests and status output. */
    public function count(): int
    {
        $this->prune();

        return count($this->codes);
    }

    private function prune(): void
    {
        $now = ($this->clock)();
        foreach ($this->codes as $code => $owner) {
            if ($owner['expires'] <= $now) {
                unset($this->codes[$code]);
            }
        }
        foreach ($this->failures as $ckey => $times) {
            $times = array_values(array_filter($times, static fn (int $time): bool => $time > $now - self::FAILURE_WINDOW));
            if ($times) {
                $this->failures[$ckey] = $times;
            } else {
                unset($this->failures[$ckey]);
            }
        }
    }

    /**
     * Where a read-only desktop bot forwards in-game registrations: the primary bot's relay
     * endpoint beside its public verified list. HTTPS when the profile names an HTTPS source.
     */
    public static function relayUrl(string $verify_url): ?string
    {
        if (!PortableProfile::hostedVerificationUrl($verify_url)) {
            return null;
        }

        return rtrim($verify_url, '/').'/ingame';
    }

    public const SEAL_WINDOW = 60; // seconds a sealed relay message stays valid
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /**
     * Relay messages between a desktop bot and the primary bot are sealed with AES-256-GCM
     * (OpenSSL, already required by the portable profile) under a key derived from INGAME_VERIFY_TOKEN: the code is never readable
     * on the wire, a changed byte is rejected, and each message carries its direction and time.
     * An answer also carries the nonce of the request it answers, so it can't be swapped.
     */
    public static function seal(string $secret, string $direction, array $payload, ?string $answers = null, ?int $now = null): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $plain = json_encode(['d' => $direction, 't' => $now ?? time(), 'r' => $answers, 'p' => $payload], JSON_THROW_ON_ERROR);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::sealKey($secret), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);
        if (!is_string($cipher)) {
            throw new \RuntimeException('Could not seal the relay message');
        }

        return json_encode(['v' => 1, 'n' => base64_encode($nonce), 'c' => base64_encode($cipher.$tag)], JSON_THROW_ON_ERROR);
    }

    /** @return array{payload: array, nonce: string}|null null for anything forged, stale, mismatched or malformed */
    public static function open(string $secret, string $direction, string $body, ?string $answers = null, ?int $now = null): ?array
    {
        if ($secret === '' || strlen($body) > 4096) {
            return null;
        }
        $outer = json_decode($body, true, 3);
        if (!is_array($outer) || ($outer['v'] ?? null) !== 1 || !is_string($outer['n'] ?? null) || !is_string($outer['c'] ?? null)) {
            return null;
        }
        $nonce = base64_decode($outer['n'], true);
        $cipher = base64_decode($outer['c'], true);
        if (!is_string($nonce) || strlen($nonce) !== self::NONCE_BYTES || !is_string($cipher) || strlen($cipher) < self::TAG_BYTES) {
            return null;
        }
        $plain = openssl_decrypt(substr($cipher, 0, -self::TAG_BYTES), 'aes-256-gcm', self::sealKey($secret), OPENSSL_RAW_DATA, $nonce, substr($cipher, -self::TAG_BYTES));
        $inner = is_string($plain) ? json_decode($plain, true, 6) : null;
        if (!is_array($inner) || ($inner['d'] ?? null) !== $direction || !is_int($inner['t'] ?? null)
            || abs(($now ?? time()) - $inner['t']) > self::SEAL_WINDOW || !is_array($inner['p'] ?? null)
            || ($inner['r'] ?? null) !== $answers) {
            return null;
        }

        return ['payload' => $inner['p'], 'nonce' => $outer['n']];
    }

    /** The base64 nonce of a sealed message, which its answer must name. */
    public static function nonceOf(string $sealed): string
    {
        return (string) (json_decode($sealed, true, 3)['n'] ?? '');
    }

    private static function sealKey(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, 'civ13 in-game verification relay v1');
    }

    /** The player-facing reply to `/approveme` without a ckey. */
    public static function codeMessage(string $code): string
    {
        $minutes = intdiv(self::CODE_LIFETIME, 60);

        return "Your in-game verification code is `$code` (it works once, for the next $minutes minutes).".PHP_EOL
            .'1. Join any Civ13 server with the BYOND account you want to link.'.PHP_EOL
            .'2. Open the **OOC** tab and click **Verify Discord**.'.PHP_EOL
            .'3. Type this code when it asks.'.PHP_EOL
            .'Never give this code to anyone else: whoever types it in links their BYOND account to your Discord.'.PHP_EOL
            .'Prefer the BYOND profile method instead? Use `/approveme ckey:<your BYOND key>`.';
    }
}
