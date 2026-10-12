<?php
declare(strict_types=1);
// Deliberately bypass tests/bootstrap.php: it reads the real .env.
foreach (['Transport', 'Client', 'Controller'] as $class) {
    require __DIR__.'/../../src/Civ13/Safehouse/'.$class.'.php';
}
use Civ13\Safehouse\Client;
use Civ13\Safehouse\Controller;
use Civ13\Safehouse\Transport;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $fn): void { try { $fn(); } catch (LogicException|UnexpectedValueException $e) { return; } throw new RuntimeException('Expected rejection'); }
$directory = sys_get_temp_dir().'/civ13-safehouse-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$transport = new class implements Transport {
    public array $calls = [];
    public bool $timeout = false;
    public bool $mismatch = false;
    public function exchange(array $request): array {
        $this->calls[] = $request;
        if ($this->timeout) { throw new RuntimeException('timeout after possible commit'); }
        return array_merge($request, ['status' => 'committed', 'request_digest' => $this->mismatch ? 'wrong' : $request['request_digest'], 'data' => ['item_id' => 'nested-1', 'fir' => true, 'condition' => 0.75]]);
    }
};
try {
    rejects(fn () => (new Client($transport, $directory.'/disabled'))->request('i1', '123', 'stash.inspect', [], null));
    check(count($transport->calls) === 0, 'Disabled client sent request');
    $client = new Client($transport, $directory.'/journal', true);
    $transport->timeout = true;
    check($client->request('i1', '123', 'trade.confirm', ['confirmation_token' => 'game-token'], 4)['status'] === 'pending', 'Timeout claimed success');
    $original = $transport->calls[0];
    $transport->timeout = false;
    $restarted = new Client($transport, $directory.'/journal', true);
    $response = $restarted->request('i1', '123', 'trade.confirm', ['confirmation_token' => 'game-token'], 4);
    check($transport->calls[1] === $original, 'Restart replay changed immutable request');
    check($response['data']['item_id'] === 'nested-1' && $response['data']['fir'] === true, 'Game metadata changed');
    $restarted->request('i1', '123', 'trade.confirm', ['confirmation_token' => 'game-token'], 4);
    check(count($transport->calls) === 2, 'Terminal duplicate resent');
    rejects(fn () => $client->request('i1', '999', 'trade.confirm', ['confirmation_token' => 'game-token'], 4));
    rejects(fn () => $client->request('i1', '123', 'trade.confirm', ['confirmation_token' => 'changed'], 4));
    $transport->mismatch = true;
    rejects(fn () => $client->request('i2', '123', 'stash.inspect', [], null));
    $transport->mismatch = false;
    $controller = new Controller($client);
    rejects(fn () => $controller->handle('i3', '123', 'trade.confirm', '{"confirmation_token":"token"}', null));
    rejects(fn () => $controller->handle('i3', '123', 'trade.quote', '{"price":1}', 4));
    check($controller->handle('i3', '123', 'stash.inspect', '{"page":1}', null)['ephemeral'], 'Private response not ephemeral');
    check(count(Controller::schema()['options'][0]['choices']) === count(Client::ACTIONS), 'Schema/router mismatch');
    echo "PASS disabled default, restart replay, terminal deduplication, actor/payload binding, response negative control, confirmation gates, private schema\n";
} finally {
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    rmdir($directory);
}
