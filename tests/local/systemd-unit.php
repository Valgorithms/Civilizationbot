<?php
declare(strict_types=1);
// SystemdUnitControl with an injected executor: argv shape, acceptance, status
// parsing and refusals. No systemctl, game or Discord.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/Local/SystemdUnitControl.php';
use Civ13\Local\SystemdUnitControl;
use function React\Promise\resolve;
function systemdCheck(bool $ok, string $what): void { if (!$ok) { throw new RuntimeException('systemd unit contract failed: '.$what); } }
if (PHP_OS_FAMILY === 'Windows') { echo "SKIP systemd unit adapter: Linux only\n"; return; }
$runtime = sys_get_temp_dir();
$calls = [];
$answers = [];
$executor = static function (array $argv, int $seconds) use (&$calls, &$answers) {
    $calls[] = [$argv, $seconds];
    return resolve(array_shift($answers));
};
$control = new SystemdUnitControl('civ13-tdm.service', $runtime, $executor);
$run = static function (string $action) use ($control): array {
    $value = null; $error = null;
    $control->request($action)->then(static function ($v) use (&$value) { $value = $v; }, static function ($e) use (&$error) { $error = $e; });
    return [$value, $error];
};
$answers[] = ['exit_code' => 0, 'stdout' => ''];
[$value, $error] = $run('restart');
systemdCheck($error === null && $value['accepted'] === true && $value['action'] === 'restart', 'restart accepted');
systemdCheck($calls[0][0] === ['/usr/bin/env', 'XDG_RUNTIME_DIR='.$runtime, '/usr/bin/systemctl', '--user', '--no-block', 'restart', 'civ13-tdm.service'], 'restart argv');
systemdCheck(count($calls[0][0]) <= 8 && $calls[0][1] <= 210, 'within AsyncProcess bounds');
$answers[] = ['exit_code' => 0, 'stdout' => "ActiveState=active\nSubState=running\nMainPID=4242\n"];
[$status, $error] = $run('status');
systemdCheck($error === null && $calls[1][0][4] === 'show', 'status uses show');
systemdCheck(SystemdUnitControl::describe($status) === 'Service civ13-tdm.service is active (running), DreamDaemon PID 4242.', 'status line: '.SystemdUnitControl::describe($status));
systemdCheck(SystemdUnitControl::describe(['unit' => 'u.service', 'ActiveState' => 'inactive', 'SubState' => 'dead', 'MainPID' => '0']) === 'Service u.service is inactive (dead).', 'stopped line');
$answers[] = ['exit_code' => 5, 'stdout' => ''];
[$value, $error] = $run('start');
systemdCheck($value === null && $error instanceof RuntimeException && str_contains($error->getMessage(), 'exit 5'), 'failed systemctl is not acceptance');
$answers[] = ['exit_code' => 0, 'stdout' => "garbage"];
[$value, $error] = $run('status');
systemdCheck($error instanceof UnexpectedValueException, 'status without ActiveState');
foreach (['mapswap', 'enable'] as $action) {
    $refused = false;
    try { $control->request($action); } catch (InvalidArgumentException) { $refused = true; }
    systemdCheck($refused, 'unsupported action '.$action);
}
foreach ([['civ13-tdm', $runtime], ['../x.service', $runtime], ['a.service; rm', $runtime], ['civ13-tdm.service', 'relative'], ['civ13-tdm.service', $runtime.'/missing-'.bin2hex(random_bytes(4))]] as [$unit, $dir]) {
    $refused = false;
    try { new SystemdUnitControl($unit, $dir, $executor); } catch (InvalidArgumentException) { $refused = true; }
    systemdCheck($refused, 'bad unit or runtime dir: '.$unit.' '.$dir);
}
systemdCheck(count($calls) === 4, 'refusals never reach systemctl');
$source = file_get_contents(__DIR__.'/../../src/Civ13/GameServer.php');
foreach (["return \$this->systemdRequest('start', \$message);", "return \$this->systemdRequest('stop', \$message);", "return \$this->systemdRequest('restart', \$message);"] as $needle) {
    systemdCheck(str_contains($source, $needle), 'GameServer routes '.$needle);
}
echo "PASS systemd unit adapter: argv shape, acceptance, status line, failures, refusals, GameServer routing\n";
