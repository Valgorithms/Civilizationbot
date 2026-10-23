<?php
declare(strict_types=1);
require __DIR__.'/../../packaging/windows/Builder.php';
use Civ13\Packaging\Builder;

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

function probe(string $archive, string $cwd, bool $dropHandle): array
{
    $environment = ['CIVILIZATIONBOT_FIXTURE_DROP_ARCHIVE'=>$dropHandle ? '1' : '0'];
    foreach (['SystemRoot', 'PATH', 'TEMP', 'TMP'] as $key) {
        if (getenv($key) !== false) { $environment[$key] = getenv($key); }
    }
    $pipes = [];
    // The .exe fixture contains only a synthetic PHAR and is interpreted by existing PHP CLI.
    // No native runtime, bot code, credentials, Composer or network is used.
    $process = proc_open([PHP_BINARY, $archive], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $cwd, $environment, ['bypass_shell'=>true]);
    check(is_resource($process), 'PHAR lifetime child unavailable');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $start = hrtime(true);
    try {
        do {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if ((hrtime(true)-$start)/1e9 > 5 || strlen($out)+strlen($err) > 65536) {
                proc_terminate($process);
                throw new RuntimeException('PHAR lifetime child exceeded its bound');
            }
            if ($status['running']) { usleep(10000); }
        } while ($status['running']);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    return [$status['exitcode'], $out, $err];
}

$root = sys_get_temp_dir().'/civilizationbot-phar-lifetime-'.bin2hex(random_bytes(6));
mkdir($root);
try {
    $stage = $root.'/app';
    mkdir($stage.'/packaging/windows', 0700, true);
    mkdir($stage.'/src', 0700, true);
    $helper = '<?php final class CachedArchiveFixture {}';
    file_put_contents($stage.'/src/Fixture.php', $helper);
    file_put_contents($stage.'/package-manifest.json', json_encode(['fixture'=>'synthetic'], JSON_THROW_ON_ERROR));
    file_put_contents($stage.'/packaging/windows/entry.php', <<<'PHP'
<?php
// This is a synthetic entry, not Civilizationbot's bootstrap or diagnostic.
if (getenv('CIVILIZATIONBOT_FIXTURE_DROP_ARCHIVE') === '1') {
    unset($GLOBALS['__civilizationbotExecutableArchive']);
}
$archive = Phar::running(false);
$directory = realpath(dirname($archive));
if (dirname(realpath($archive)) !== $directory || is_link($archive)) {
    throw new RuntimeException('Fixture replacement boundary refused');
}
$saved = $directory.'/original.phar';
// Simulate a reopen losing micro's logical SFX view. POSIX can rename an open inode;
// Windows retains the original path while the held archive stream locks the file.
if (@rename($archive, $saved)) {
    $payload = file_get_contents($saved);
    if (file_put_contents($archive, str_repeat('X', 65536).$payload) !== 65536+strlen($payload)) {
        throw new RuntimeException('Synthetic prefix replacement failed');
    }
}
try {
    $codeRoot = dirname(__DIR__, 2);
    require $codeRoot.'/src/Fixture.php';
    $data = json_decode(file_get_contents($codeRoot.'/package-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
    if (!class_exists('CachedArchiveFixture', false) || $data !== ['fixture'=>'synthetic']) {
        throw new RuntimeException('Synthetic member contents changed');
    }
    echo 'PASS cached backing stream'.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
PHP);
    foreach ([false, true] as $dropHandle) {
        $directory = $root.'/'.($dropHandle ? 'negative' : 'retained');
        mkdir($directory);
        $phar = $directory.'/application.phar';
        Builder::archive($stage, $phar);
        $archive = $directory.'/synthetic.exe';
        check(rename($phar, $archive), 'Cannot name synthetic PHAR fixture');
        [$exit, $out, $err] = probe($archive, $directory, $dropHandle);
        if ($dropHandle) {
            check($exit === 1 && trim($err) === 'Syntax error' && $out === str_repeat('X', strlen($helper)), 'Dropped-handle control did not reproduce the native wrong-offset PHP/JSON failure');
        } else {
            check($exit === 0 && trim($out) === 'PASS cached backing stream' && $err === '', 'Retained backing stream did not preserve nested PHP/JSON reads: '.$err);
        }
    }
    echo "PASS PHAR backing-stream lifetime; dropped-handle control reproduces wrong-prefix output and JSON failure\n";
} finally {
    $base = realpath($root);
    $remove = static function (string $path) use (&$remove, $base): void {
        $real = realpath($path);
        if ($real === false || ($real !== $base && !str_starts_with($real, $base.DIRECTORY_SEPARATOR)) || is_link($path)) {
            throw new RuntimeException('PHAR fixture cleanup boundary refused');
        }
        foreach (new DirectoryIterator($path) as $file) {
            if ($file->isDot()) { continue; }
            if ($file->isDir()) { $remove($file->getPathname()); } else { unlink($file->getPathname()); }
        }
        rmdir($path);
    };
    $remove($root);
}
