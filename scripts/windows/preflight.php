<?php
declare(strict_types=1);
require __DIR__.'/../../src/Civ13/PortableProfile.php';
use Civ13\PortableProfile;
// Deliberately read-only. Never includes bot.php or the configured environment file.
try {
    if (count($argv) !== 2) { throw new RuntimeException('Usage: php scripts/windows/preflight.php ABSOLUTE_PROFILE.json'); }
    $profile = PortableProfile::load($argv[1]);
    $errors = PortableProfile::preflight($profile, extension_loaded(...));
    echo json_encode(['schema'=>'civilizationbot.preflight.v1','ok'=>$errors===[],'errors'=>$errors], JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
    exit($errors === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Portable configuration failed validation'.PHP_EOL);
    exit(1);
}
