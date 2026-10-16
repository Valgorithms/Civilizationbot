<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/Local/VerificationReplica.php';
use Civ13\Local\VerificationReplica;
// Synthetic temp fixture only; no endpoints or credentials from the environment.
[$script, $directory, $input, $public, $mode] = $argv;
$replica = new VerificationReplica($directory, base64_decode($public, true), 'official-test', static fn () => 1000, $mode === 'crash' ? static function (): void { exit(17); } : null);
$replica->import(file_get_contents($input));
echo "published\n";
