<?php
declare(strict_types=1);
// Real local PHP child only: no bot bootstrap, credentials, network or game process.
require __DIR__.'/../../src/Civ13/PortableProfile.php';
require __DIR__.'/../../src/Civ13/PortableProcess.php';
use Civ13\PortableProcess;

$dir = sys_get_temp_dir().'/portable child & unicode-'.bin2hex(random_bytes(6));
mkdir($dir);
$process = null;
$pipes = [];
try {
    $helper = var_export(realpath(__DIR__.'/../../src/Civ13/PortableProcess.php'), true);
    file_put_contents($dir.'/bot.php', '<?php
require '.$helper.';
$lock = Civ13\\PortableProcess::lock(getcwd());
file_put_contents("ready.tmp", json_encode([getcwd(), $argv[1]]));
rename("ready.tmp", "ready.json");
fwrite(STDOUT, "stdout fixture\\n");
fwrite(STDERR, "stderr fixture\\n");
$deadline = microtime(true) + 15;
while (!is_file("stop.request")) {
    if (microtime(true) > $deadline) { exit(99); }
    usleep(10000);
}
unlink("stop.request");
fclose($lock);
exit(23);
');
    $literal = 'spaces & | %PATH% "quoted"';
    $argv = [...PortableProcess::argv(PHP_BINARY, $dir), $literal];
    $process = PortableProcess::start($argv, $dir, static function ($args, $descriptors, $cwd, $options) use (&$pipes) {
        // A minimal explicit environment prevents accidental inherited bot credentials.
        $environment = array_filter(['SystemRoot'=>getenv('SystemRoot'), 'LD_LIBRARY_PATH'=>getenv('LD_LIBRARY_PATH'), 'PHPRC'=>getenv('PHPRC'), 'PHP_INI_SCAN_DIR'=>getenv('PHP_INI_SCAN_DIR'), 'TEMP'=>sys_get_temp_dir(), 'TMP'=>sys_get_temp_dir()], static fn ($v) => $v !== false);
        return proc_open($args, $descriptors, $pipes, $cwd, $environment, $options);
    });
    if (!is_resource($process)) { throw new RuntimeException('Child failed to launch'); }
    fclose($pipes[0]);
    $deadline = microtime(true) + 10;
    while (!is_file($dir.'/ready.json') && microtime(true) < $deadline) { usleep(10000); }
    if (!is_file($dir.'/ready.json')) { throw new RuntimeException('Child readiness timed out'); }
    [$cwd, $received] = json_decode(file_get_contents($dir.'/ready.json'), true, 8, JSON_THROW_ON_ERROR);
    if (realpath($cwd) !== realpath($dir) || $received !== $literal) { throw new RuntimeException('Child path or literal argv corrupted'); }
    try { $unexpected = PortableProcess::lock($dir); fclose($unexpected); throw new LogicException('Duplicate process lock accepted'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Portable instance already running') { throw $error; } }
    PortableProcess::requestStop($dir);
    $exit = proc_close($process); $process = null;
    if ($exit !== 23 || is_file($dir.'/stop.request')) { throw new RuntimeException('Child exit/stop marker mismatch'); }
    if (!str_contains(file_get_contents($dir.'/stdout.log'), 'stdout fixture') || !str_contains(file_get_contents($dir.'/stderr.log'), 'stderr fixture')) { throw new RuntimeException('Child log redirection failed'); }
    $lock = PortableProcess::lock($dir); fclose($lock);
    echo "PASS real PHP child: literal argv, spaced path, log redirection, cross-process locking, stop and exit status\n";
} finally {
    // Only the exact child handle created by this test can be terminated on failure.
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (glob($dir.'/*') as $path) { unlink($path); }
    rmdir($dir);
}
