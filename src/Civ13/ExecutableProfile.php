<?php
declare(strict_types=1);
namespace Civ13;

/** Configuration discovery for the immutable PHPacker application; never reads credentials. */
final class ExecutableProfile
{
    public static function discover(string $executable): string
    {
        $executable = realpath($executable);
        if ($executable === false || !is_file($executable)) { throw new \RuntimeException('Cannot locate the packaged executable'); }
        $profile = dirname($executable).'/civilizationbot.json';
        if (!is_file($profile) || !is_readable($profile)) {
            throw new \RuntimeException('Place an operator-configured civilizationbot.json beside the executable; use config/examples/windows-test1714.json and a separate local application environment file. No working-directory or production configuration is used.');
        }
        return $profile;
    }

    public static function inspect(string $executable, string $codeRoot, array $manifest, callable $extension): array
    {
        if (($manifest['schema'] ?? null) !== 'civilizationbot.executable.v1'
            || !preg_match('/^[a-f0-9]{40}$/D', $manifest['source_commit'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['composer_lock_sha256'] ?? '')) {
            throw new \RuntimeException('Packaged source provenance is missing; rebuild with composer phpacker');
        }
        $path = self::discover($executable);
        $profile = PortableProfile::load($path);
        $errors = PortableProfile::preflight($profile, $extension, PHP_VERSION, $codeRoot);
        if ($errors !== []) { throw new \RuntimeException(implode('; ', $errors)); }
        return ['profile_path'=>$path, 'profile'=>$profile, 'manifest'=>$manifest];
    }

    /** Keep the existing fresh-main admission policy and bind bundled source to that checkout. */
    public static function admit(array $plan, callable $guard): void
    {
        $root = $plan['profile']['root'];
        $receipt = $guard($root);
        if (($receipt['commit'] ?? null) !== $plan['manifest']['source_commit']) {
            throw new \RuntimeException('Executable source differs from verified main; rebuild from that exact main commit. No source update was performed.');
        }
        $lock = $root.'/composer.lock';
        if (!is_file($lock) || hash_file('sha256', $lock) !== $plan['manifest']['composer_lock_sha256']) {
            throw new \RuntimeException('Executable dependency lock differs from the admitted checkout; rebuild with its reviewed lock');
        }
    }
}
