<?php

declare(strict_types=1);
require __DIR__.'/relay-contender.php';

function contenderCheck(bool $ok, string $reason): void { if (!$ok) { throw new RuntimeException($reason); } }
$root = sys_get_temp_dir().'/relay-diagnostic-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$cases = [
    ['fwrite(STDOUT,"BUSY");', 0, 'BUSY', '', false],
    ['fwrite(STDOUT,"DELIVERED");', 0, 'DELIVERED', '', true],
    ['fwrite(STDOUT,"ERROR");', 0, 'ERROR', '', true],
    ['fwrite(STDOUT,"BUSY"); exit(23);', 23, 'BUSY', '', true],
    ['fwrite(STDOUT,"BUSY"); fwrite(STDERR,"warning");', 0, 'BUSY', 'warning', true],
    ['fwrite(STDOUT,str_repeat("x",8192)); fwrite(STDERR,str_repeat("y",8192));', 0, str_repeat('x',1025), str_repeat('y',1025), true],
    ['fwrite(STDOUT,"\x00\xff\x1b[31m");', 0, "\x00\xff\x1b[31m", '', true],
];
try {
    foreach ($cases as [$source, $exit, $out, $err, $shouldFail]) {
        $result = relayContenderObserve([PHP_BINARY, '-n', '-r', $source], $root);
        contenderCheck($result === ['exit_code'=>$exit, 'stdout'=>$out, 'stderr'=>$err], 'Owned-child observation changed');
        $failed = false;
        try { relayContenderCheck($result); }
        catch (RuntimeException $error) {
            $failed = true;
            $prefix = 'Cross-process exclusion failed: ';
            contenderCheck(str_starts_with($error->getMessage(), $prefix), 'Failure classification lost');
            contenderCheck(strlen($error->getMessage()) < 3000, 'Diagnostic output exceeded bound');
            $data = json_decode(substr($error->getMessage(), strlen($prefix)), true, 8, JSON_THROW_ON_ERROR);
            contenderCheck($data['exit_code'] === $exit, 'Exit code lost');
            foreach (['stdout'=>$out, 'stderr'=>$err] as $name=>$bytes) {
                contenderCheck(base64_decode($data[$name.'_prefix_base64'], true) === substr($bytes, 0, 1024), 'Child bytes lost');
                contenderCheck($data[$name.'_truncated'] === (strlen($bytes) > 1024), 'Truncation marker wrong');
            }
        }
        contenderCheck($failed === $shouldFail, 'Exclusion assertion weakened');
    }
    echo "PASS seven owned-child diagnostic cases; strict assertion, exit, output bounds, binary encoding\n";
} finally {
    foreach (glob($root.'/*') as $path) { unlink($path); }
    rmdir($root);
}
