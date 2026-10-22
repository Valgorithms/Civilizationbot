<?php
declare(strict_types=1);
// Deliberately bypass tests/bootstrap.php: it reads the real .env.
foreach (['Transport', 'Client', 'Controller', 'AsyncSpool'] as $class) {
    require __DIR__.'/../../src/Civ13/Safehouse/'.$class.'.php';
}
use Civ13\Safehouse\Client;
use Civ13\Safehouse\Controller;
use Civ13\Safehouse\Transport;
use Civ13\Safehouse\AsyncSpool;
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
        return array_merge($request, ['status' => 'committed', 'reason' => 'committed', 'request_digest' => $this->mismatch ? 'wrong' : $request['request_digest'], 'data' => ['item_id' => 'nested-1', 'fir' => true, 'condition' => 0.75]]);
    }
};
try {
    $insurance = ['account_revision' => 4, 'mail_revision' => 9, 'completed_generation' => 5, 'expiration' => 'to be implemented', 'entries' => [['item_id' => 'return_1', 'type' => '/obj/item', 'name' => null, 'condition' => ['health' => 2, 'maxhealth' => null], 'found_in_raid' => true, 'returned_generation' => 5, 'eligible_generation' => 6, 'state' => 'pending']], 'next_cursor' => 'opaque.cursor'];
    check(Controller::insuranceProjection($insurance) === $insurance, 'Pending eligibility was inferred locally');
    check(Controller::prepare('insurance1', '123', 'insurance.inspect', '{"cursor":null}', null)['payload'] === ['cursor' => null], 'Initial insurance cursor changed');
    check(Controller::prepare('insurance2', '123', 'insurance.inspect', '{"cursor":"opaque.cursor"}', 4)['payload']['cursor'] === 'opaque.cursor', 'Opaque cursor changed');
    foreach (['{"cursor":""}', '{"cursor":"é"}', '{"cursor":0}', '{"cursor":null,"page":0}'] as $invalid) { rejects(fn () => Controller::prepare('insurance3', '123', 'insurance.inspect', $invalid, null)); }
    rejects(fn () => Controller::prepare('insurance4', '123', 'insurance.claim', '{"item_id":"return_1"}', 4));
    foreach (['mail_revision' => 16777216, 'account_revision' => 1000001, 'expiration' => 'tomorrow', 'next_cursor' => ''] as $field => $value) { $bad = $insurance; $bad[$field] = $value; rejects(fn () => Controller::insuranceProjection($bad)); }
    $bad = $insurance; $bad['entries'] = array_fill(0, 9, $insurance['entries'][0]); rejects(fn () => Controller::insuranceProjection($bad));
    foreach (['state' => 'claimable', 'found_in_raid' => 1, 'name' => str_repeat('😀', 33), 'eligible_generation' => -1, 'proof' => 'secret'] as $field => $value) { $bad = $insurance; $bad['entries'][0][$field] = $value; rejects(fn () => Controller::insuranceProjection($bad)); }
    $bad = $insurance; $bad['entries'][0]['condition']['health'] = INF; rejects(fn () => Controller::insuranceProjection($bad));
    echo "PASS insurance pending projection, opaque paging, premature claim rejection and counter/catalog/condition limits\n";
    $signingFixture = json_decode(file_get_contents(__DIR__.'/signing-fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    check(AsyncSpool::seal($signingFixture['exact_body'], 'request', 1000, 1120, $signingFixture['synthetic_key']) === $signingFixture['envelope'], 'Independent Python HMAC fixture mismatch');
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
    foreach (['stash.inspect', 'room.inspect'] as $inspection) {
        foreach (['{}', '{"page":8}', '{"page":-1}', '{"page":"0"}', '{"page":0,"extra":1}'] as $invalidPage) { rejects(fn () => Controller::prepare('invalid-page', '123', $inspection, $invalidPage, null)); }
        check(Controller::prepare('valid-page', '123', $inspection, '{"page":0}', null)['payload'] === ['page' => 0], 'Inspection page changed');
    }
    foreach ([['trade.quote', '{"sale_kind":"player_purchase","item_ids":["root"]}', 1], ['trade.quote', '{"sale_kind":"server_buyback","item_ids":["root","root"]}', 1], ['trade.quote', '{"sale_kind":"server_buyback","item_ids":["root"]}', null], ['link.complete', '{"challenge_id":"id","proof":"proof","ckey":"victim"}', null], ['link.complete', '{"challenge_id":"id"}', null], ['trade.confirm', '{"confirmation_token":"token","price":1}', 1], ['trade.status', '{"operation_request_id":"id","operation_request_digest":"bad"}', null], ['stash.inspect', '{"page":0}', 1000001]] as [$action, $payload, $revision]) {
        rejects(fn () => Controller::prepare('bad-contract', '123', $action, $payload, $revision));
    }
    check(Controller::prepare('link-proof', '123', 'link.complete', '{"challenge_id":"game-origin","proof":"game-proof"}', null)['payload'] === ['challenge_id' => 'game-origin', 'proof' => 'game-proof'], 'Game-origin link proof altered');
    check(Controller::prepare('detail', '123', 'stash.detail', '{"item_id":"root","page":31}', null)['payload'] === ['item_id' => 'root', 'page' => 31], 'Detail schema changed');
    check(Controller::prepare('browse', '123', 'market.browse', '{"page":15}', null)['action'] === 'market.browse', 'Browse missing');
    check(Controller::prepare('room-quote', '123', 'room.quote', '{"operation":"upgrade","item_ids":["part"]}', 7)['payload'] === ['item_ids' => ['part'], 'operation' => 'upgrade'], 'Room quote mutated selectors');
    foreach ([['stash.detail', '{"item_id":"root","page":32}', null], ['market.browse', '{"page":16}', null], ['market.browse', '{"page":"1"}', null], ['room.quote', '{"operation":"upgrade","item_ids":["part"]}', null], ['room.quote', '{"operation":"downgrade","item_ids":["part"]}', 1], ['room.quote', '{"operation":"upgrade","item_ids":["part","part"]}', 1], ['room.quote', '{"operation":"upgrade","item_ids":["part"],"required_parts":5}', 1]] as [$action, $payload, $revision]) { rejects(fn () => Controller::prepare('bad-addition', '123', $action, $payload, $revision)); }
    rejects(fn () => $controller->handle('i3', '123', 'trade.confirm', '{"confirmation_token":"token"}', null));
    rejects(fn () => $controller->handle('i3', '123', 'trade.quote', '{"price":1}', 4));
    check($controller->handle('i3', '123', 'stash.inspect', '{"page":1}', null)['ephemeral'], 'Private response not ephemeral');
    check(count(Controller::schema()['options'][0]['choices']) === count(Client::ACTIONS), 'Schema/router mismatch');
    $canonical = $client->request('ordered', '123', 'trade.quote', ['z' => 2, 'a' => ['q' => 2, 'b' => 1]], 4);
    check($client->request('ordered', '123', 'trade.quote', ['a' => ['b' => 1, 'q' => 2], 'z' => 2], 4) === $canonical, 'Object order changed identity');
    rejects(fn () => $client->request('large', '123', 'stash.inspect', ['value' => str_repeat('x', 9000)], null));
    // Separate native PHP processes exercise the OS lock and restart after a
    // committed mock-game effect followed by process death before reply storage.
    $spawn = function (string $path, string $mode): array {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/worker.php', $path, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Worker did not start');
        fclose($pipes[0]);
        return [$process, $pipes];
    };
    $finish = function (array $worker, int $expected): void {
        [$process, $pipes] = $worker;
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === $expected && $error === '', 'Worker failed: '.$error.$output);
    };
    foreach (['parallel', 'crash'] as $case) { mkdir($directory.'/'.$case, 0700); }
    $one = $spawn($directory.'/parallel', 'normal');
    $two = $spawn($directory.'/parallel', 'normal');
    $finish($one, 0); $finish($two, 0);
    check(count(file($directory.'/parallel/deliveries')) === 1, 'Concurrent terminal duplicate reached transport');
    $finish($spawn($directory.'/crash', 'crash'), 23);
    $finish($spawn($directory.'/crash', 'normal'), 0);
    check(count(file($directory.'/crash/deliveries')) === 2, 'Crash did not replay');
    check(count(file($directory.'/crash/settlements')) === 1, 'Mock game settled twice');
    check(file($directory.'/crash/deliveries')[0] === file($directory.'/crash/deliveries')[1], 'Crash replay changed bytes');
    mkdir($directory.'/spool', 0700);
    $key = str_repeat('synthetic-test-key-', 3);
    $now = 1000;
    $clock = function () use (&$now): int { return $now; };
    $spool = new AsyncSpool($directory.'/spool', $key, true, 1, $clock);
    $request = $original;
    $fileKey = $spool->enqueue($request);
    $published = file_get_contents($directory.'/spool/'.$fileKey.'.request');
    check($spool->enqueue($request) === $fileKey && file_get_contents($directory.'/spool/'.$fileKey.'.request') === $published, 'Spool replay changed bytes/signature');
    check($spool->poll($fileKey)['status'] === 'pending', 'Missing reply claimed success');
    rejects(fn () => $spool->enqueue(array_merge($request, ['discord_id' => '999'])));
    try { $spool->enqueue(array_merge($request, ['request_id' => 'queue-full'])); throw new LogicException('Queue bound missing'); } catch (RuntimeException $e) { check($e->getMessage() === 'Queue capacity reached', 'Unexpected queue exception'); }
    $reply = array_merge($request, ['status' => 'committed', 'reason' => 'committed', 'data' => []]);
    $replyPath = $directory.'/spool/'.$fileKey.'.reply';
    $sealed = AsyncSpool::seal(json_encode($reply), 'reply', 1000, 1120, $key);
    check(strlen(AsyncSpool::seal(str_repeat('x', 8192), 'reply', 1000, 1120, $key)['body']) === 10924, 'Exact byte boundary rejected');
    rejects(fn () => AsyncSpool::seal(str_repeat('x', 8193), 'reply', 1000, 1120, $key));
    rejects(fn () => AsyncSpool::seal(str_repeat('🌍', 2049), 'reply', 1000, 1120, $key));
    foreach (['reason', 'data'] as $field) {
        foreach (['missing', null, 1, true] as $invalid) {
            $bad = $reply;
            if ($invalid === 'missing') { unset($bad[$field]); } else { $bad[$field] = $invalid; }
            rejects(fn () => Client::validateReply($bad, $request));
            file_put_contents($replyPath, json_encode(AsyncSpool::seal(json_encode($bad), 'reply', 1000, 1120, $key)));
            rejects(fn () => $spool->poll($fileKey));
        }
    }
    $bad = $reply; $bad['reason'] = []; rejects(fn () => Client::validateReply($bad, $request));
    $bad = $reply; $bad['data'] = 'scalar'; rejects(fn () => Client::validateReply($bad, $request));
    file_put_contents($replyPath, json_encode($sealed)."\n");
    check($spool->poll($fileKey)['status'] === 'committed', 'Native single-LF reply rejected');
    file_put_contents($replyPath, json_encode($sealed)."\r\n"); check($spool->poll($fileKey)['status'] === 'committed', 'Bounded historical CRLF rejected');
    $oversizedBody = base64_encode(str_repeat('x', 8193));
    $oversized = ['protocol' => AsyncSpool::IPC_PROTOCOL, 'issued_at' => 1000, 'expires_at' => 1120, 'body' => $oversizedBody, 'mac' => hash_hmac('sha256', AsyncSpool::IPC_PROTOCOL."\nreply\n1000\n1120\n".$oversizedBody, $key)];
    file_put_contents($replyPath, json_encode($oversized)); rejects(fn () => $spool->poll($fileKey));
    file_put_contents($replyPath, str_repeat('x', 12002));
    try { $spool->poll($fileKey); throw new LogicException('Oversized wire accepted'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'oversized'), 'Wrong file bound rejection'); }
    file_put_contents($replyPath, json_encode($sealed));
    $markerPath = $replyPath.'.pending';
    file_put_contents($markerPath, '');
    check($spool->poll($fileKey)['reason'] === 'reply_write_pending', 'Empty marker allowed success');
    file_put_contents($replyPath, '{partial');
    check($spool->poll($fileKey)['status'] === 'pending', 'Partial marked reply claimed success');
    file_put_contents($markerPath, '{invalid-marker');
    check($spool->poll($fileKey)['status'] === 'pending', 'Invalid marker allowed success');
    unlink($markerPath);
    try { $spool->poll($fileKey); throw new LogicException('Unmarked partial reply accepted'); } catch (JsonException $e) {}
    file_put_contents($replyPath, json_encode($sealed));
    file_put_contents($markerPath, 'crash-after-complete-write');
    check($spool->poll($fileKey)['status'] === 'pending', 'Complete reply bypassed crash marker');
    check($spool->enqueue($request) === $fileKey, 'Marked reply changed immutable replay');
    check(is_file($markerPath), 'Bot removed native recovery marker');
    unlink($markerPath);
    check($spool->poll($fileKey)['status'] === 'committed', 'Authenticated reply not accepted');
    $writerPipes = [];
    $writer = proc_open([PHP_BINARY, __DIR__.'/reply-worker.php', $replyPath, base64_encode(json_encode($sealed))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $writerPipes);
    check(is_resource($writer), 'Reply writer unavailable');
    check(trim(fgets($writerPipes[1])) === 'partial', 'Writer partial stage missing');
    check($spool->poll($fileKey)['status'] === 'pending', 'Concurrent partial publication accepted');
    fwrite($writerPipes[0], "continue\n"); fflush($writerPipes[0]);
    check(trim(fgets($writerPipes[1])) === 'complete-marked', 'Writer complete stage missing');
    check($spool->poll($fileKey)['status'] === 'pending', 'Concurrent complete marked publication accepted');
    fwrite($writerPipes[0], "continue\n"); fflush($writerPipes[0]);
    check(trim(fgets($writerPipes[1])) === 'released', 'Writer release missing');
    check($spool->poll($fileKey)['status'] === 'committed', 'Released authenticated reply unavailable');
    fclose($writerPipes[0]); fclose($writerPipes[1]);
    check(stream_get_contents($writerPipes[2]) === '', 'Reply worker diagnostics'); fclose($writerPipes[2]);
    check(proc_close($writer) === 0, 'Reply worker failed');
    $tampered = $sealed; $tampered['mac'] = str_repeat('0', 64);
    file_put_contents($replyPath, json_encode($tampered));
    rejects(fn () => $spool->poll($fileKey));
    $tampered = $sealed; $tampered['body'] = base64_encode(json_encode(array_merge($reply, ['discord_id' => '999'])));
    file_put_contents($replyPath, json_encode($tampered));
    rejects(fn () => $spool->poll($fileKey));
    file_put_contents($replyPath, json_encode(AsyncSpool::seal(json_encode(array_merge($reply, ['discord_id' => '999'])), 'reply', 1000, 1120, $key)));
    rejects(fn () => $spool->poll($fileKey));
    file_put_contents($replyPath, json_encode(AsyncSpool::seal(json_encode($reply), 'reply', 800, 920, $key)));
    check($spool->poll($fileKey)['status'] === 'pending', 'Expired reply claimed success');
    rejects(fn () => $spool->poll('../escape'));
    $now = 1300;
    $recoverySpool = new AsyncSpool($directory.'/spool', $key, true, 2, $clock);
    $recoveryId = $recoverySpool->recover($fileKey, 'status-after-expiry');
    $recoveryEnvelope = json_decode(file_get_contents($directory.'/spool/'.$recoveryId.'.request'), true);
    $recoveryRequest = json_decode(base64_decode($recoveryEnvelope['body']), true);
    check($recoveryEnvelope['issued_at'] === 1300 && $recoveryRequest['action'] === 'trade.status' && $recoveryRequest['payload']['operation_request_id'] === $request['request_id'] && $recoveryRequest['payload']['operation_request_digest'] === $request['request_digest'], 'Recovery reissued economic operation');
    check(file_get_contents($directory.'/spool/'.$fileKey.'.request') === $published, 'Recovery overwrote immutable operation');
    rejects(fn () => $recoverySpool->recover($fileKey, $request['request_id']));
    // Linux CI can create symlinks without Windows Developer Mode privileges.
    if (PHP_OS_FAMILY !== 'Windows') {
        check(symlink($directory.'/journal', $markerPath), 'Marker symlink fixture unavailable');
        try { $spool->poll($fileKey); throw new LogicException('Marker symlink accepted'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Symlink'), 'Wrong marker symlink rejection'); }
        unlink($markerPath);
        unlink($replyPath);
        $linkPath = $replyPath;
        check(symlink($directory.'/journal', $linkPath), 'Symlink fixture unavailable');
        try { $spool->poll($fileKey); throw new LogicException('Symlink accepted'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Symlink'), 'Wrong reply symlink rejection'); }
        unlink($linkPath);
        $requestLink = $directory.'/spool/'.str_repeat('b', 64).'.request';
        check(symlink($directory.'/journal', $requestLink), 'Request symlink fixture unavailable');
        try { $spool->poll(str_repeat('b', 64)); throw new LogicException('Request symlink accepted'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Symlink'), 'Wrong symlink rejection'); }
        unlink($requestLink);
    }
    echo "PASS bounded authenticated spool, byte-identical publication/replay, actor/body/MAC negative controls and reply expiry\n";
    echo "PASS disabled default, restart replay, terminal deduplication, actor/payload binding, response negative control, confirmation gates, private schema\n";
    echo "PASS native cross-process lock, crash after game commit, exact replay and mock game single settlement\n";
} finally {
    foreach (glob($directory.'/*') as $file) {
        if (is_dir($file)) {
            foreach (glob($file.'/*') as $child) { unlink($child); }
            rmdir($file);
        } else { unlink($file); }
    }
    rmdir($directory);
}
