<?php

declare(strict_types=1);

// Offline check for Civ13\SystemDns: no real shell, no network.
namespace Civ13\ServerDirectory {
    function shell_exec(string $command): ?string
    {
        if (str_contains($command, 'wmic') || !str_contains($command, 'Get-DnsClientServerAddress')) {
            throw new \RuntimeException('Unexpected resolver discovery command');
        }
        $GLOBALS['systemDnsFixtureCalls'] = ($GLOBALS['systemDnsFixtureCalls'] ?? 0) + 1;
        return "192.0.2.1\n";
    }
}

namespace React\Dns\Config {
    function shell_exec(string $command): never
    {
        throw new \LogicException('Implicit ReactPHP Windows DNS discovery reached');
    }
}

namespace {
$loader = require $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../src/Civ13/', true);

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

$check(SystemDns::connectorConfig(static fn (string $command): ?string => null)->nameservers === ['8.8.8.8'],
    'HTTP connector fallback stays equivalent to ReactPHP when discovery is empty');
$check(SystemDns::connectorConfig(static fn (string $command): string => "192.0.2.1\r\n192.0.2.2")->nameservers === ['192.0.2.1', '192.0.2.2'],
    'HTTP connector receives all discovered nameservers in order');

// Construct real HTTP clients without issuing requests. The configured
// resolver branch avoids ReactPHP's implicit DNS discovery on Windows.
$loop = new React\EventLoop\StreamSelectLoop();
set_error_handler(static function (int $no, string $message) use ($check): bool {
    $check(false, "HTTP client construction emitted $message");
    return true;
});
$check(SystemDns::browser($loop, ['dns' => false]) instanceof React\Http\Browser, 'explicit DNS option bypasses discovery');
$check(($GLOBALS['systemDnsFixtureCalls'] ?? 0) === 0, 'explicit options did not run the Windows discovery fixture');
$check(SystemDns::browser($loop) instanceof React\Http\Browser, 'ordinary HTTP Browser constructs with explicit DNS');
$authenticated = new ReflectionMethod(Civ13\HostedVerificationSource::class, 'authenticatedBrowser');
$check($authenticated->invoke(null, $loop) instanceof React\Http\Browser, 'authenticated verification Browser constructs without implicit DNS');
restore_error_handler();
if (DIRECTORY_SEPARATOR === '\\') {
    $check(($GLOBALS['systemDnsFixtureCalls'] ?? 0) === 1, 'HTTP clients share one PowerShell discovery');
    $caught = false;
    try {
        new React\Http\Browser(null, $loop);
    } catch (LogicException $error) {
        $caught = $error->getMessage() === 'Implicit ReactPHP Windows DNS discovery reached';
    }
    $check($caught, 'negative control detects an unconfigured ReactPHP Browser');
}

exit($failures === 0 ? 0 : 1);
}
