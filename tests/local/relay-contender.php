<?php

declare(strict_types=1);

/** Test-only observation of the owned PHP contender, without printing its environment. */
function relayContenderObserve(array $command, string $directory): array
{
    $process = null;
    $pipes = [];
    $stdout = $directory.'/contender.stdout';
    $stderr = $directory.'/contender.stderr';
    try {
        $environment = [];
        if (PHP_OS_FAMILY === 'Windows') {
            // Preserve the Windows runtime directory for PHP extension startup.
            $systemRoot = getenv('SystemRoot');
            if ($systemRoot === false || $systemRoot === '') { throw new RuntimeException('SystemRoot required for isolated Windows contender'); }
            $environment['SystemRoot'] = $systemRoot;
        }
        // File-backed output avoids a full stderr pipe blocking the stdout read.
        $process = proc_open($command, [0=>['pipe', 'r'], 1=>['file', $stdout, 'w'], 2=>['file', $stderr, 'w']], $pipes, null, $environment);
        if (!is_resource($process)) { throw new RuntimeException('Cannot launch isolated contender'); }
        fclose($pipes[0]);
        unset($pipes[0]);
        $exit = proc_close($process);
        $process = null;
        // Read at most 1 KiB plus a truncation sentinel, only after child exit.
        $out = file_get_contents($stdout, false, null, 0, 1025);
        $err = file_get_contents($stderr, false, null, 0, 1025);
        if ($out === false || $err === false) { throw new RuntimeException('Cannot read contender diagnostics'); }
        return ['exit_code'=>$exit, 'stdout'=>$out, 'stderr'=>$err];
    } finally {
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($process)) { proc_close($process); }
    }
}

function relayContenderCheck(array $observed): void
{
    if ($observed['exit_code'] === 0 && $observed['stdout'] === 'BUSY' && $observed['stderr'] === '') { return; }
    $diagnostic = ['exit_code'=>$observed['exit_code']];
    foreach (['stdout', 'stderr'] as $name) {
        // Base64 keeps arbitrary child bytes and terminal controls out of logs.
        $diagnostic[$name.'_prefix_base64'] = base64_encode(substr($observed[$name], 0, 1024));
        $diagnostic[$name.'_truncated'] = strlen($observed[$name]) > 1024;
    }
    throw new RuntimeException('Cross-process exclusion failed: '.json_encode($diagnostic, JSON_THROW_ON_ERROR));
}
