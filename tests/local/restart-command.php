<?php
declare(strict_types=1);

// Captures production slash listeners and interaction replies; no bot bootstrap,
// command registration, process, game, credentials or Discord transport.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);

use Discord\Builders\MessageBuilder;
use Discord\Helpers\RegisteredCommand;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class RestartDiscordFixture extends \Discord\Discord
{
    public array $commandsCaptured = [];
    public function listenCommand($names, ?callable $callback = null, ?callable $autocomplete_callback = null): RegisteredCommand
    {
        $this->commandsCaptured[is_array($names) ? implode(' ', $names) : $names] = $callback;
        return (new ReflectionClass(RegisteredCommand::class))->newInstanceWithoutConstructor();
    }
}

final class RestartServerFixture extends \Civ13\GameServer
{
    public Deferred $operation;
    public int $requests = 0;
    public bool $unavailable = false;
    public function Restart(?\Discord\Parts\Channel\Message $message = null, bool $notify = true): PromiseInterface
    {
        ++$this->requests;
        if ($this->unavailable) { throw new RuntimeException('Synthetic unavailable adapter'); }
        return $this->operation->promise();
    }
}

final class RestartInteractionFixture extends \Discord\Parts\Interactions\Interaction
{
    public Deferred $ack;
    public array $acknowledgements = [];
    public array $replies = [];
    public function __isset(string $name): bool { return $name === 'data'; }
    public function __get(string $name)
    {
        if ($name !== 'data') { throw new LogicException('Unexpected interaction field'); }
        return (object) ['options' => ['server' => (object) ['value' => 'fixture']]];
    }
    public function acknowledgeWithResponse(bool $ephemeral = false): PromiseInterface
    {
        $this->acknowledgements[] = $ephemeral;
        return $this->ack->promise();
    }
    public function updateOriginalResponse(MessageBuilder $builder): PromiseInterface
    {
        $this->replies[] = $builder->getContent();
        return resolve(null);
    }
}

$bot = (new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
$discord = (new ReflectionClass(RestartDiscordFixture::class))->newInstanceWithoutConstructor();
$slash = (new ReflectionClass(\Civ13\Slash::class))->newInstanceWithoutConstructor();
$slash->civ13 = $bot; $slash->discord = $discord;
(new ReflectionMethod($slash, '__declareListeners'))->invoke($slash);
$previousProfile = getenv('CIVILIZATIONBOT_PROFILE');
putenv('CIVILIZATIONBOT_PROFILE=offline-restart-command');
try {
    foreach (['byond', 'opendream'] as $engine) {
        foreach (['success', 'rejected', 'unavailable'] as $outcome) {
            $server = (new ReflectionClass(RestartServerFixture::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty($server, 'name'))->setValue($server, 'Fixture');
            $server->engine = $engine; $server->operation = new Deferred(); $server->unavailable = $outcome === 'unavailable';
            $bot->enabled_gameservers = ['fixture' => $server];
            $interaction = (new ReflectionClass(RestartInteractionFixture::class))->newInstanceWithoutConstructor();
            $interaction->ack = new Deferred(); $settled = false;
            $discord->commandsCaptured['restart_server']($interaction)->then(static function () use (&$settled) { $settled = true; });
            if ($interaction->acknowledgements !== [true] || $server->requests !== 0 || $interaction->replies !== []) { throw new RuntimeException('Restart ran before private acknowledgement'); }
            $interaction->ack->resolve(null);
            if ($server->requests !== 1 || ($outcome !== 'unavailable' && ($settled || $interaction->replies !== []))) { throw new RuntimeException('Restart reported unsettled control as completed'); }
            if ($outcome === 'success') { $server->operation->resolve(['running' => true]); }
            elseif ($outcome === 'rejected') { $server->operation->reject(new RuntimeException('Synthetic operation rejected')); }
            if (!$settled || count($interaction->replies) !== 1) { throw new RuntimeException('Restart lost terminal control outcome'); }
            $reply = $interaction->replies[0];
            if ($outcome === 'success') {
                $expected = $engine === 'byond' ? 'save-safe restart and readiness' : 'does not establish gameplay readiness';
                if (!str_contains($reply, $expected)) { throw new RuntimeException('Restart confused native readiness and OpenDream process control'); }
            } elseif (!str_contains(strtolower($reply), $outcome === 'unavailable' && $engine === 'byond' ? 'unavailable' : ($engine === 'byond' ? 'rejected' : 'failed'))) { throw new RuntimeException('Restart failure became a success reply'); }
        }
    }
    echo "PASS production restart slash route: private acknowledgement first, deferred completion, engine-specific readiness, rejection and unavailable adapters\n";
} finally { $previousProfile === false ? putenv('CIVILIZATIONBOT_PROFILE') : putenv('CIVILIZATIONBOT_PROFILE='.$previousProfile); }
