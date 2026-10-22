<?php
declare(strict_types=1);
// Inert command/interaction/HTTP consumers; no bot, network, process or real lists.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Discord\Builders\MessageBuilder;
use function React\Promise\resolve;

final class AdminConsumerBot extends \Civ13\Civ13
{
    public Deferred $operation;
    public int $requests = 0;
    public bool $unavailable = false;
    public function adminlistUpdate(?array $required_roles = null): PromiseInterface
    {
        ++$this->requests;
        if ($this->unavailable) { throw new RuntimeException('Synthetic writer unavailable'); }
        return $this->operation->promise();
    }
}
final class AdminConsumerMessage extends \Discord\Parts\Channel\Message
{
    public array $reactions = [];
    public function react($emoticon): PromiseInterface
    {
        $this->reactions[] = $emoticon;
        return resolve(null);
    }
}
final class AdminConsumerInteraction extends \Discord\Parts\Interactions\Interaction
{
    public Deferred $ack;
    public array $privateAcks = [];
    public array $replies = [];
    public function acknowledgeWithResponse(bool $ephemeral = false): PromiseInterface
    {
        $this->privateAcks[] = $ephemeral;
        return $this->ack->promise();
    }
    public function updateOriginalResponse(MessageBuilder $builder): PromiseInterface
    {
        $this->replies[] = $builder->getContent();
        return resolve(null);
    }
}
function consumerCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$bot = (new ReflectionClass(AdminConsumerBot::class))->newInstanceWithoutConstructor();
$handler = new \Monolog\Handler\TestHandler();
$bot->logger = new \Monolog\Logger('offline-admin-consumers', [$handler]);
$panel = (new ReflectionClass(\Civ13\Panels::class))->newInstanceWithoutConstructor();
$panel->civ13 = $bot; $panel->logger = $bot->logger;
$panelAction = new ReflectionMethod($panel, 'updateAdmins');
$http = (new ReflectionClass(\Civ13\HttpServiceManager::class))->newInstanceWithoutConstructor();
$http->civ13 = $bot; $http->logger = $bot->logger;
$httpAction = new ReflectionMethod($http, 'queueAdminListUpdate');

foreach (['success', 'rejected', 'unavailable'] as $outcome) {
    $bot->operation = new Deferred(); $bot->requests = 0; $bot->unavailable = $outcome === 'unavailable';
    $message = (new ReflectionClass(AdminConsumerMessage::class))->newInstanceWithoutConstructor();
    $settled = false;
    (new \Civ13\MessageCommand\Commands\AdminListUpdate($bot))($message, 'updateadmins', [])->then(static function () use (&$settled) { $settled = true; });
    consumerCheck($bot->requests === 1, 'Message command omitted the actual aggregate');
    if ($outcome !== 'unavailable') {
        consumerCheck(!$settled && $message->reactions === [], 'Message command acknowledged pending replacements');
        $outcome === 'success' ? $bot->operation->resolve(null) : $bot->operation->reject(new RuntimeException('Synthetic replacement failure'));
    }
    consumerCheck($settled && $message->reactions === [$outcome === 'success' ? '👍' : '❌'], 'Message reaction lost terminal outcome');

    $bot->operation = new Deferred(); $bot->requests = 0;
    $interaction = (new ReflectionClass(AdminConsumerInteraction::class))->newInstanceWithoutConstructor();
    $interaction->ack = new Deferred(); $settled = false;
    $panelAction->invoke($panel, $interaction)->then(static function () use (&$settled) { $settled = true; });
    consumerCheck($interaction->privateAcks === [true] && $bot->requests === 0, 'Panel wrote before private acknowledgement');
    $interaction->ack->resolve(null);
    if ($outcome !== 'unavailable') {
        consumerCheck(!$settled && $interaction->replies === [], 'Panel acknowledged pending replacements');
        $outcome === 'success' ? $bot->operation->resolve(null) : $bot->operation->reject(new RuntimeException('Synthetic replacement failure'));
    }
    consumerCheck($settled && count($interaction->replies) === 1 && str_contains($interaction->replies[0], $outcome === 'success' ? 'updated' : 'failed'), 'Panel lost replacement result');

    $bot->operation = new Deferred(); $bot->requests = 0; $handler->clear();
    $response = $httpAction->invoke($http);
    consumerCheck($bot->requests === 1 && $response->getStatusCode() === ($outcome === 'unavailable' ? 503 : 202), 'HTTP upgraded queue acceptance to success');
    if ($outcome !== 'unavailable') {
        consumerCheck(str_contains((string) $response->getBody(), 'completion has not been observed'), 'HTTP implied completed replacement');
        $outcome === 'success' ? $bot->operation->resolve(null) : $bot->operation->reject(new RuntimeException('Synthetic replacement failure'));
    }
    consumerCheck($handler->hasErrorRecords() === ($outcome !== 'success'), 'HTTP replacement failure was not observed');
}
foreach ([false, true] as $failure) {
    $bot->operation = new Deferred(); $bot->unavailable = false;
    $failure ? $bot->operation->reject(new RuntimeException('Synthetic immediate rejection')) : $bot->operation->resolve(null);
    $response = $httpAction->invoke($http);
    consumerCheck($response->getStatusCode() === ($failure ? 503 : 200), 'HTTP lost an already-observed terminal result');
}
$bot->requests = 0; $bot->unavailable = false;
$interaction = (new ReflectionClass(AdminConsumerInteraction::class))->newInstanceWithoutConstructor();
$interaction->ack = new Deferred(); $rejected = false;
$panelAction->invoke($panel, $interaction)->then(null, static function () use (&$rejected) { $rejected = true; });
$interaction->ack->reject(new RuntimeException('Synthetic expired interaction'));
consumerCheck($rejected && $bot->requests === 0 && $interaction->replies === [], 'Failed panel acknowledgement wrote lists');
echo "PASS admin-list consumers: pending/delayed success, rejection, unavailable writer, private acknowledgement, HTTP queue/failure semantics\n";
