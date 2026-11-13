<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/src/Civ13/Social/LobbyRoster.php';

use Civ13\Social\LobbyRoster;
use Discord\Repository\LobbyRepository;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

// Real DiscordPHP parent signatures, but no parent constructor, Discord client,
// credentials, cache, HTTP transport, bot bootstrap, or external effects.
final class FakeLobbies extends LobbyRepository
{
    public array $calls = [];
    public array $pending = [];
    public function __construct() {}
    public function removeMember($lobby, $user): PromiseInterface
    {
        return $this->record('remove', $lobby, $user);
    }
    public function addMember($lobby, $user, $data = []): PromiseInterface
    {
        return $this->record('add', $lobby, $user);
    }
    private function record(string $operation, string $lobby, string $user): PromiseInterface
    {
        $this->calls[] = [$operation, $lobby, $user];
        $deferred = new Deferred();
        $this->pending[] = $deferred;
        return $deferred->promise();
    }
}

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    ++$checks;
    if (!$value) { throw new RuntimeException($message); }
}
function invalid(callable $call): void
{
    try { $call(); } catch (InvalidArgumentException $e) { check(true, 'rejected'); return; }
    throw new RuntimeException('Expected invalid input rejection.');
}

check(LobbyRoster::plan(['1', '2', '2'], ['2', '3']) === ['remove' => ['1'], 'add' => ['3']], 'diff and deduplication');
check(LobbyRoster::plan([], []) === ['remove' => [], 'add' => []], 'empty room');
check(LobbyRoster::plan(['18446744073709551615'], ['18446744073709551615'])['add'] === [], 'no numeric coercion');
foreach ([[''], ['0'], ['01'], [1], ['1e3'], ['-1'], ['1\n'], ['123456789012345678901'], ['x' => '1']] as $bad) {
    invalid(fn () => LobbyRoster::plan([], $bad));
}
invalid(fn () => LobbyRoster::plan([], array_map('strval', range(1, 26))));

$repo = new FakeLobbies();
$service = new LobbyRoster($repo);
$result = null;
$service->apply('99', ['1', '2'], ['3'])->then(function ($value) use (&$result) { $result = $value; });
check($repo->calls === [['remove', '99', '1']], 'first revoke only');
try { $service->apply('99', [], ['4']); throw new RuntimeException('concurrent apply allowed'); }
catch (LogicException $e) { check(true, 'concurrent update rejected'); }
$repo->pending[0]->resolve(null);
check(count($repo->calls) === 2 && $repo->calls[1][0] === 'remove', 'second revoke before grants');
$repo->pending[1]->resolve(null);
check($repo->calls[2] === ['add', '99', '3'] && $result === null, 'grant waits for all revokes');
$repo->pending[2]->resolve(null);
check($result === ['remove' => ['1', '2'], 'add' => ['3']], 'async result retains accepted operations');

$error = null;
$service->apply('99', ['3'], ['4'])->then(null, function ($e) use (&$error) { $error = $e; });
$failure = new RuntimeException('synthetic removal failure');
$repo->pending[3]->reject($failure);
check($error === $failure && count($repo->calls) === 4, 'failed revoke stops grant and preserves rejection');
$done = null;
$service->apply('99', ['3'], ['3'])->then(function ($value) use (&$done) { $done = $value; });
check($done === ['remove' => [], 'add' => []], 'failure releases in-flight guard and no-op avoids HTTP');
invalid(fn () => $service->apply('bad', [], []));
check(count($repo->calls) === 4, 'invalid lobby avoids effects');
echo "Lobby roster: {$checks} checks passed; no live Discord requests.\n";
