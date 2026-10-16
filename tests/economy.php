<?php

declare(strict_types=1);

// Deliberately bypass tests/bootstrap.php: no .env, Discord, HTTP, or game bootstrap.
require __DIR__.'/../src/Civ13/Economy/Ledger.php';
require __DIR__.'/../src/Civ13/Economy/AccountView.php';

use Civ13\Economy\Ledger;
use Civ13\Economy\AccountView;

if (($argv[1] ?? '') === 'worker') {
    try {
        (new Ledger($argv[2]))->apply('test', $argv[3], 'player', -70, 'purchase');
        exit(0);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Insufficient balance.') {
            exit(3);
        }
        throw $error;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(Closure $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        check($error->getMessage() === $message, 'Unexpected rejection: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

$path = tempnam(sys_get_temp_dir(), 'civ-economy-');
try {
    $ledger = new Ledger($path);
    $receipt = $ledger->apply('test', 'round-1:player:extraction', 'player', 100, 'fixture extraction');
    check($receipt === $ledger->apply('test', 'round-1:player:extraction', 'player', 100, 'fixture extraction'), 'Replay changed receipt');
    rejects(fn () => $ledger->apply('test', 'round-1:player:extraction', 'player', 200, 'fixture extraction'), 'Event ID reused with a different payload.');
    rejects(fn () => $ledger->apply('test', 'overspend', 'player', -101, 'purchase'), 'Insufficient balance.');
    check(count($ledger->history('test', 'player')) === 1, 'Rejected event persisted');
    $view = new AccountView($ledger, fn ($realm, $id) => $id === 'verified' ? 'player' : null);
    rejects(fn () => $view->read('test', 'unknown'), 'A verified game account is required.');
    check($view->read('test', 'verified')['balance'] === 100, 'Verified read failed');
    check($ledger->balance('other-game', 'player') === 0, 'Realm isolation failed');
    unset($view, $ledger);
    $reopened = new Ledger($path);
    check($reopened->balance('test', 'player') === 100, 'Restart lost balance');
    unset($reopened);
    $workers = [];
    foreach (['purchase-1', 'purchase-2'] as $event) {
        $workers[] = proc_open([PHP_BINARY, __FILE__, 'worker', $path, $event], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
    }
    $results = array_map('proc_close', $workers);
    sort($results);
    check($results === [0, 3], 'Concurrent spend did not produce one success and one insufficient balance');
    $ledger = new Ledger($path);
    check($ledger->balance('test', 'player') === 30, 'Concurrent spend balance incorrect');
    $ledger->apply('overflow', 'max', 'player', PHP_INT_MAX, 'fixture');
    rejects(fn () => $ledger->apply('overflow', 'extra', 'player', 1, 'fixture'), 'Balance overflow.');
    unset($ledger);
    echo "Economy offline checks passed (replay, conflict, rollback, identity, realm, restart, concurrent spend, overflow).\n";
} finally {
    unset($ledger, $view, $reopened);
    gc_collect_cycles();
    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
