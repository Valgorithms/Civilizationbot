<?php
declare(strict_types=1);

// No Composer bootstrap, environment file, state lock, socket or process before inspection.
$codeRoot = dirname(__DIR__, 2);
require_once $codeRoot.'/src/Civ13/PortableProfile.php';
require_once $codeRoot.'/src/Civ13/ExecutableProfile.php';
require_once $codeRoot.'/src/Civ13/DefaultBranchGuard.php';
try {
    $executable = function_exists('micro_get_self_filename') ? micro_get_self_filename() : Phar::running(false);
    $manifest = json_decode(file_get_contents($codeRoot.'/package-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
    $plan = \Civ13\ExecutableProfile::inspect($executable, $codeRoot, $manifest, extension_loaded(...));
    if (getenv('CIVILIZATIONBOT_CHECK_ONLY') === '1') {
        // Explicit nonconnecting diagnostic: no credentials loaded, Git admission or state writes.
        fwrite(STDOUT, 'PASS packaged configuration discovery; no bot started; source '.$manifest['source_commit'].'; self-path='.(function_exists('micro_get_self_filename')?'micro':'phar').PHP_EOL);
        exit(0);
    }
    \Civ13\ExecutableProfile::admit($plan, \Civ13\DefaultBranchGuard::verify(...));
} catch (Throwable $error) {
    fwrite(STDERR, 'Civilizationbot startup refused: '.$error->getMessage().PHP_EOL);
    exit(1);
}
define('CIVILIZATIONBOT_PACKAGED_PROFILE', $plan['profile']);
define('CIVILIZATIONBOT_PACKAGED_SOURCE_ROOT', $plan['profile']['root']);
putenv('CIVILIZATIONBOT_PROFILE='.$plan['profile_path']);
require $codeRoot.'/bot.php';
