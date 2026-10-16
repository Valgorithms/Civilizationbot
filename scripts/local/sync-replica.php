<?php
declare(strict_types=1);
// Offline import of a signed export obtained through an operator-approved read-only transport.
require __DIR__.'/../../src/Civ13/Local/Profile.php';
require __DIR__.'/../../src/Civ13/Local/VerificationReplica.php';
use Civ13\Local\Profile;
use Civ13\Local\VerificationReplica;
if ($argc !== 3) { fwrite(STDERR, "Usage: php scripts/local/sync-replica.php profile.json signed-export.json\n"); exit(2); }
try {
    $profile = Profile::load($argv[1]);
    $public = base64_decode(trim(file_get_contents($profile['public_key_file']) ?: ''), true);
    $replica = new VerificationReplica($profile['state_directory'], $public ?: '', $profile['authority'], static fn () => time());
    if (!is_file($argv[2]) || filesize($argv[2]) > VerificationReplica::MAX_BYTES * 2) { throw new RuntimeException('Input unavailable'); }
    $replica->sync(static fn (): string => file_get_contents($argv[2]) ?: throw new RuntimeException('Input unavailable'));
    echo "PASS authenticated replica published; authority remains official.\n";
} catch (Throwable) { fwrite(STDERR, "Replica import rejected; previous snapshot preserved.\n"); exit(1); }
