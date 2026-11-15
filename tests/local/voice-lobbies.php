<?php
declare(strict_types=1);
// Launcher voice lobby contracts. No bootstrap, .env, sockets or Discord client:
// every Discord effect is a recorded closure that resolves on demand.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);

use Civ13\VoiceLobbies;
use React\Promise\Deferred;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { if (! (error_reporting() & $severity)) { return true; } throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$checks = 0;
function check(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($why); } }
function idToken(string $sub): string { $b = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '='); return $b('{"alg":"none"}').'.'.$b(json_encode(['sub' => $sub])).'.sig'; }

$now = 1000;
$mints = []; $creates = []; $updates = [];
$voice = new VoiceLobbies(
    mint: static function (string $ckey) use (&$mints) { $d = new Deferred(); $mints[] = [$ckey, $d]; return $d->promise(); },
    createLobby: static function (array $metadata) use (&$creates) { $d = new Deferred(); $creates[] = [$metadata, $d]; return $d->promise(); },
    updateMembers: static function (string $lobby, array $members) use (&$updates) { $d = new Deferred(); $updates[] = [$lobby, $members, $d]; return $d->promise(); },
    applicationId: '1562126764849897544',
    clock: static function () use (&$now): int { return $now; },
);
$keyA = str_repeat('a', 40); $keyB = str_repeat('b', 40);

// Parsing the game's roster.
$parsed = VoiceLobbies::parseRoster(['v' => 1, 'members' => [['ckey' => 'alice', 'key' => $keyA, 'team' => 'GERMAN'], ['ckey' => 'bob', 'key' => $keyB, 'team' => '']]]);
check($parsed === ['alice' => ['key' => $keyA, 'team' => 'GERMAN'], 'bob' => ['key' => $keyB, 'team' => '']], 'roster parsed');
check(VoiceLobbies::parseRoster(['v' => 1, 'members' => []]) === [], 'an empty roster is a roster');
foreach ([null, [], ['v' => 2, 'members' => []], ['v' => 1, 'members' => ['x' => []]],
    ['v' => 1, 'members' => [['ckey' => 'Alice', 'key' => $keyA]]],
    ['v' => 1, 'members' => [['ckey' => 'alice', 'key' => 'short']]],
    ['v' => 1, 'members' => [['ckey' => 'alice', 'key' => $keyA, 'team' => "a\nb"]]],
    ['v' => 1, 'members' => array_fill(0, VoiceLobbies::MAX_MEMBERS + 1, ['ckey' => 'a', 'key' => $keyA])]] as $bad) {
    check(VoiceLobbies::parseRoster($bad) === null, 'bad roster rejected: '.json_encode($bad === null ? null : array_slice((array) $bad, 0, 2)));
}

// First roster: mints both, knows no IDs yet, makes no lobby before an ID exists.
check($voice->roster('tdm', $parsed) === [], 'no IDs before minting');
check(array_column($mints, 0) === ['alice', 'bob'], 'each ckey minted once');
check(count($creates) === 0, 'no empty lobby is created');
check($voice->token(VoiceLobbies::tokenId($keyA)) === null, 'no token before minting');
$voice->roster('tdm', $parsed);
check(count($mints) === 2, 'a second roster does not mint again while minting');

$mints[0][1]->resolve(['access_token' => 'tok-alice', 'expires_in' => 604800, 'id_token' => idToken('100000000000000001')]);
check(count($creates) === 2, 'region and team lobbies are created once alice has an ID');
check($creates[0][0] === ['civ13_server' => 'tdm', 'civ13_kind' => 'region'], 'region lobby metadata');
check($creates[1][0] === ['civ13_server' => 'tdm', 'civ13_kind' => 'team', 'civ13_team' => 'GERMAN'], 'team lobby metadata');
$creates[0][1]->resolve('900000000000000001');
check(count($updates) === 1 && $updates[0][0] === '900000000000000001' && $updates[0][1] === [['id' => '100000000000000001']], 'alice added to the region lobby');
$creates[1][1]->resolve('900000000000000002');
check(count($updates) === 2 && $updates[1][0] === '900000000000000002', 'alice added to her team lobby');
$updates[0][2]->resolve(null); $updates[1][2]->resolve(null);
check($voice->memberIds('region:tdm') === ['100000000000000001'], 'region membership recorded');

$mints[1][1]->resolve(['access_token' => 'tok-bob', 'expires_in' => 604800, 'id_token' => idToken('100000000000000002')]);
check(count($updates) === 3 && $updates[2][1] === [['id' => '100000000000000002']], 'bob joins the region only (no team)');
$updates[2][2]->resolve(null);
check($voice->roster('tdm', $parsed) === ['alice' => '100000000000000001', 'bob' => '100000000000000002'], 'the game gets both IDs');
check(count($updates) === 3, 'an unchanged roster sends nothing');

// The launcher's sealed token.
$sealed = json_decode($voice->token(VoiceLobbies::tokenId($keyA)), true);
check(is_array($sealed) && $sealed['v'] === 1 && is_string($sealed['sealed']) && !str_contains($sealed['sealed'], 'tok-alice'), 'the token is sealed');
$opened = json_decode(VoiceLobbies::open($keyA, VoiceLobbies::tokenId($keyA), $sealed['sealed']), true);
check($opened['access_token'] === 'tok-alice' && $opened['user_id'] === '100000000000000001' && $opened['application_id'] === '1562126764849897544' && $opened['server'] === 'tdm' && $opened['expires_in'] === 604800, 'it opens with the key');
check(VoiceLobbies::open($keyB, VoiceLobbies::tokenId($keyA), $sealed['sealed']) === null, 'another key cannot open it');
check(VoiceLobbies::open($keyA, VoiceLobbies::tokenId($keyB), $sealed['sealed']) === null, 'it is bound to its token ID');
check($voice->token(str_repeat('0', 64)) === null && $voice->token('not hex') === null, 'unknown IDs get nothing');

// Leaving: kept for the grace period, then removed.
$onlyAlice = ['alice' => $parsed['alice']];
$voice->roster('tdm', $onlyAlice);
check(count($updates) === 3, 'bob is kept while within the grace period');
check($voice->token(VoiceLobbies::tokenId($keyB)) === null, 'but his launcher key stops working at once');
$now += VoiceLobbies::REMOVE_GRACE;
$voice->roster('tdm', $onlyAlice);
check(count($updates) === 4 && $updates[3][1] === [['id' => '100000000000000002', 'remove_member' => true]], 'bob is removed after the grace period');
$updates[3][2]->resolve(null);

// A team change moves alice; an emptied team lobby is emptied, not left stale.
$voice->roster('tdm', ['alice' => ['key' => $keyA, 'team' => 'SOVIET']]);
$teamCreates = array_values(array_filter($creates, static fn ($c) => ($c[0]['civ13_team'] ?? '') === 'SOVIET'));
check(count($teamCreates) === 1, 'the new team gets its lobby');
$last = end($updates);
check($last[0] === '900000000000000002' && $last[1] === [['id' => '100000000000000001', 'remove_member' => true]], 'she leaves the old team lobby');

// A failed mint leaves room to try again; a response without a user ID is ignored.
$voice->roster('tdm', ['carol' => ['key' => str_repeat('c', 40), 'team' => '']]);
$carol = end($mints); $carol[1]->reject(new RuntimeException('rate limited'));
$voice->roster('tdm', ['carol' => ['key' => str_repeat('c', 40), 'team' => '']]);
$again = end($mints); check($again !== $carol && $again[0] === 'carol', 'minting is retried after a failure');
$again[1]->resolve(['access_token' => 'x', 'expires_in' => 10]);
check($voice->token(VoiceLobbies::tokenId(str_repeat('c', 40))) === null, 'no user ID, no token');

check(VoiceLobbies::userIdFromIdToken(idToken('123456789012345678')) === '123456789012345678', 'id_token sub read');
check(VoiceLobbies::userIdFromIdToken('a.b') === null && VoiceLobbies::userIdFromIdToken(idToken('nope')) === null && VoiceLobbies::userIdFromIdToken(null) === null, 'bad id_tokens rejected');

echo "voice lobby contracts: {$checks} checks passed\n";
