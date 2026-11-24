<?php
declare(strict_types=1);
// Offline: the log level never blocks boot and defaults to info.
require __DIR__.'/../../src/Civ13/LogLevelSetting.php';
use Civ13\LogLevelSetting;
function check(bool $ok, string $name): void { if (!$ok) { throw new RuntimeException($name); } }
check(LogLevelSetting::resolve(null, false) === ['info', null], 'default is info');
check(LogLevelSetting::resolve(null, '') === ['info', null], 'empty env is info');
foreach (LogLevelSetting::NAMES as $name) {
    check(LogLevelSetting::resolve($name, false) === [$name, null], 'profile '.$name);
    check(LogLevelSetting::resolve(null, strtoupper($name)) === [$name, null], 'env '.$name);
}
check(LogLevelSetting::resolve('warning', 'debug') === ['warning', null], 'profile wins over env');
check(LogLevelSetting::resolve(' Error ', false) === ['error', null], 'trim and case');
foreach (['verbose', 7, true, ['debug']] as $bad) {
    [$level, $warning] = LogLevelSetting::resolve($bad, 'debug');
    check($level === 'info' && is_string($warning) && str_contains($warning, 'profile log_level'), 'bad profile falls back to info');
}
[$level, $warning] = LogLevelSetting::resolve(null, 'loud');
check($level === 'info' && str_contains((string) $warning, 'LOG_LEVEL'), 'bad env falls back to info');
echo "log level: ok\n";
