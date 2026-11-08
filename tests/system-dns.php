<?php

declare(strict_types=1);

// Offline check for Civ13\SystemDns: no real shell, no network.
require __DIR__ . '/../vendor/autoload.php';

use Civ13\ServerDirectory\SystemDns;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$output = "fec0:0:0:ffff::1\r\nfec0:0:0:ffff::2\r\n2001:4860:4860::8888\r\n192.168.0.1\r\n8.8.8.8\r\n192.168.0.1\r\nnot-an-ip\r\n";
$check(SystemDns::parse($output) === ['192.168.0.1', '8.8.8.8', '2001:4860:4860::8888'], 'IPv4 first, duplicates and fec0:: placeholders dropped');
$check(SystemDns::parse('') === [], 'empty output gives no nameservers');

// shell_exec() returns null when the command is missing (the WMIC case); no warning may be raised.
set_error_handler(static function (int $no, string $message) use ($check): bool {
    $check(false, "no PHP warning or deprecation: $message");
    return true;
});
$config = SystemDns::load(static fn (string $command): ?string => null);
restore_error_handler();
$check($config->nameservers === [], 'missing command gives an empty Config');

$seen = '';
$config = SystemDns::load(static function (string $command) use (&$seen): string {
    $seen = $command;
    return "1.1.1.1\n";
});
$check($config->nameservers === ['1.1.1.1'], 'PowerShell output becomes the nameserver list');
$check(!str_contains($seen, 'wmic') && str_contains($seen, 'Get-DnsClientServerAddress'), 'asks PowerShell, not WMIC');

exit($failures === 0 ? 0 : 1);
