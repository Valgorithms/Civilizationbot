<?php
declare(strict_types=1);
// SystemdUnitControl with an injected executor: argv shape for system (sudo and
// root) and user scopes, acceptance, status parsing and refusals. No systemctl,
// sudo, game or Discord.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/Local/SystemdUnitControl.php';
use Civ13\Local\SystemdUnitControl;
use function React\Promise\resolve;
function systemdCheck(bool $ok, string $what): void { if (!$ok) { throw new RuntimeException('systemd unit contract failed: '.$what); } }
$calls = [];
$answers = [];
$executor = static function (array $argv, int $seconds) use (&$calls, &$answers) {
    $calls[] = [$argv, $seconds];
    return resolve(array_shift($answers));
};
$run = static function (SystemdUnitControl $control, string $action): array {
    $value = null; $error = null;
    $control->request($action)->then(static function ($v) use (&$value) { $value = $v; }, static function ($e) use (&$error) { $error = $e; });
    return [$value, $error];
};
// A non-root bot controls a system unit through sudo -n; status needs no sudo.
$control = new SystemdUnitControl('civ13-tdm.service', $executor, 'system', null, true);
$answers[] = ['exit_code' => 0, 'stdout' => ''];
[$value, $error] = $run($control, 'restart');
systemdCheck($error === null && $value['accepted'] === true && $value['action'] === 'restart', 'restart accepted');
systemdCheck($calls[0][0] === ['/usr/bin/sudo', '-n', '/usr/bin/systemctl', '--no-block', 'restart', 'civ13-tdm.service'], 'sudo restart argv');
systemdCheck(count($calls[0][0]) <= 8 && $calls[0][1] <= 210, 'within AsyncProcess bounds');
$answers[] = ['exit_code' => 0, 'stdout' => "ActiveState=active\nSubState=running\nMainPID=4242\n"];
[$status, $error] = $run($control, 'status');
systemdCheck($error === null && $calls[1][0] === ['/usr/bin/systemctl', 'show', '--property=ActiveState,SubState,MainPID', 'civ13-tdm.service'], 'status without sudo');
systemdCheck(SystemdUnitControl::describe($status) === 'Service civ13-tdm.service is active (running), DreamDaemon PID 4242.', 'status line: '.SystemdUnitControl::describe($status));
systemdCheck(SystemdUnitControl::describe(['unit' => 'u.service', 'ActiveState' => 'inactive', 'SubState' => 'dead', 'MainPID' => '0']) === 'Service u.service is inactive (dead).', 'stopped line');
$answers[] = ['exit_code' => 1, 'stdout' => ''];
[$value, $error] = $run($control, 'start');
systemdCheck($value === null && $error instanceof RuntimeException && str_contains($error->getMessage(), 'exit 1'), 'a refused sudo or failed systemctl is not acceptance');
$answers[] = ['exit_code' => 0, 'stdout' => "garbage"];
[$value, $error] = $run($control, 'status');
systemdCheck($error instanceof UnexpectedValueException, 'status without ActiveState');
// A root bot calls systemctl directly.
$root = new SystemdUnitControl('civ13-tdm.service', $executor, 'system', null, false);
systemdCheck($root->argv('stop') === ['/usr/bin/systemctl', '--no-block', 'stop', 'civ13-tdm.service'], 'root stop argv');
// User scope finds the user bus through XDG_RUNTIME_DIR.
$runtime = PHP_OS_FAMILY === 'Windows' ? '/' : sys_get_temp_dir(); // Windows CI has no POSIX temp path.
$user = new SystemdUnitControl('civ13-tdm.service', $executor, 'user', $runtime);
systemdCheck($user->argv('start') === ['/usr/bin/env', 'XDG_RUNTIME_DIR='.$runtime, '/usr/bin/systemctl', '--user', '--no-block', 'start', 'civ13-tdm.service'], 'user start argv');
foreach (['mapswap', 'enable', 'daemon-reload'] as $action) {
    $refused = false;
    try { $control->request($action); } catch (InvalidArgumentException) { $refused = true; }
    systemdCheck($refused, 'unsupported action '.$action);
}
foreach ([['civ13-tdm', 'system', null], ['../x.service', 'system', null], ['a.service; rm', 'system', null], ['civ13-tdm.service', 'machine', null],
          ['civ13-tdm.service', 'user', 'relative'], ['civ13-tdm.service', 'user', '/missing-civ13-'.bin2hex(random_bytes(4))]] as [$unit, $scope, $dir]) {
    $refused = false;
    try { new SystemdUnitControl($unit, $executor, $scope, $dir); } catch (InvalidArgumentException) { $refused = true; }
    systemdCheck($refused, "bad unit, scope or runtime dir: {$unit} {$scope}");
}
systemdCheck(count($calls) === 4, 'refusals never reach systemctl');
$source = file_get_contents(__DIR__.'/../../src/Civ13/GameServer.php');
foreach (["return \$this->systemdRequest('start', \$message);", "return \$this->systemdRequest('stop', \$message);", "return \$this->systemdRequest('restart', \$message);",
          "reject(new \\RuntimeException('systemd control unavailable: '"] as $needle) {
    systemdCheck(str_contains($source, $needle), 'GameServer routes '.$needle);
}
echo "PASS systemd unit adapter: sudo/root/user argv, acceptance, status line, failures, refusals, GameServer routing\n";
