<?php

declare(strict_types=1);

// Inert competing-writer fixture only. Never loads bot.php, .env, or any supervisor.
if ($argc !== 4 || !in_array($argv[3], ['a', 'b'], true)) { exit(99); }
$directory = realpath($argv[2]);
$temporary = realpath(sys_get_temp_dir());
if ($directory === false || !str_starts_with($directory, $temporary.DIRECTORY_SEPARATOR)
    || !str_starts_with(basename(dirname($directory)), 'native lifecycle & fixture-')) { exit(99); }
$loader = require $argv[1];
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
$fixture = json_decode(file_get_contents($directory.'/writer-fixture.json'), true, 16, JSON_THROW_ON_ERROR);
$adapter = new Civ13\NativeLifecycle($fixture['settings'], $fixture['server'], $fixture['repository'], new React\EventLoop\StreamSelectLoop());
file_put_contents($directory.'/ready-'.$argv[3], 'ready');
$deadline = microtime(true) + 5;
while (!is_file($directory.'/writers-go')) {
    if (microtime(true) > $deadline) { exit(98); }
    usleep(1000);
    clearstatcache();
}
try { $adapter->request('start'); exit(0); }
catch (RuntimeException) { exit(75); }
