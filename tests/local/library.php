<?php
declare(strict_types=1);
// Explicit checkout dependency path; no bot bootstrap or ambient .env.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/RestartPolicy.php';
use Civ13\RestartPolicy;
use function React\Promise\resolve;
function assertLocal(bool $ok): void { if (!$ok) { throw new RuntimeException('Restart contract failed'); } }
$stops = 0; $spawns = 0;
$stop = static function () use (&$stops): void { $stops++; };
$spawn = static function ($file) use (&$spawns) { $spawns++; return resolve($file); };
$policy = new RestartPolicy('supervised');
$policy->restart(null, $stop, $spawn);
$policy->restart(null, $stop, $spawn);
assertLocal($stops === 1 && $spawns === 0);
try { $policy->restart('bot.php', $stop, $spawn); throw new RuntimeException('Override allowed'); } catch (InvalidArgumentException) {}
$legacy = new RestartPolicy();
$legacy->restart('local.php', $stop, $spawn)->then(static fn ($file) => assertLocal($file === 'local.php'));
assertLocal($stops === 2 && $spawns === 1);
$retry = new RestartPolicy('supervised');
try { $retry->restart(null, static fn () => throw new RuntimeException('Injected stop failure'), $spawn); } catch (RuntimeException) {}
$retry->restart(null, $stop, $spawn);
assertLocal($stops === 3 && $spawns === 1);
echo "PASS injected restart policy: graceful-only, duplicate suppression, legacy delegation, failed-stop retry\n";
