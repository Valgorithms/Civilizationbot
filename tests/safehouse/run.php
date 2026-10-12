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
        return array_merge($request, ['status' => 'committed', 'request_digest' => $this->mismatch ? 'wrong' : $request['request_digest'], 'data' => ['item_id' => 'nested-1', 'fir' => true, 'condition' => 0.75]]);
    }
};
try {
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
    $reply = array_merge($request, ['status' => 'committed']);
    $replyPath = $directory.'/spool/'.$fileKey.'.reply';
    $sealed = AsyncSpool::seal(json_encode($reply), 'reply', 1000, 1120, $key);
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
