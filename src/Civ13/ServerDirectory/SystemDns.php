<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\ServerDirectory;

use React\Dns\Config\Config;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Socket\Connector;

/**
 * Reads the system DNS servers without React's WMIC lookup.
 *
 * react/dns reads Windows nameservers with `wmic`, which current Windows no
 * longer ships; shell_exec() then returns null and PHP 8.1+ prints a
 * preg_match_all() deprecation on every startup. This asks PowerShell instead
 * and returns an empty Config (never null) when nothing can be read.
 */
final class SystemDns
{
    private static ?Config $cached = null;

    /** Build an HTTP client without asking react/socket to run its WMIC lookup. */
    public static function browser(LoopInterface $loop, array $options = []): Browser
    {
        return new Browser(new Connector($options + ['dns' => self::connectorConfig()], $loop), $loop);
    }

    /** Preserve react/socket's normal fallback if no system DNS servers are found. */
    public static function connectorConfig(?callable $shell = null): Config
    {
        $config = self::load($shell);
        if (!$config->nameservers) {
            $config->nameservers[] = '8.8.8.8';
        }
        return $config;
    }

    /** @param null|callable(string): (string|false|null) $shell injected by tests */
    public static function load(?callable $shell = null): Config
    {
        if ($shell === null && self::$cached !== null) {
            return clone self::$cached;
        }
        if (DIRECTORY_SEPARATOR !== '\\' && $shell === null) {
            return clone (self::$cached = Config::loadSystemConfigBlocking());
        }
        $command = 'powershell -NoProfile -NonInteractive -Command "(Get-DnsClientServerAddress).ServerAddresses" 2>NUL';
        $output = $shell !== null ? $shell($command) : (function_exists('shell_exec') ? shell_exec($command) : null);
        $config = new Config();
        $config->nameservers = self::parse(is_string($output) ? $output : '');
        if ($shell === null) {
            self::$cached = clone $config;
        }
        return $config;
    }

    /**
     * One address per line; IPv4 first, without duplicates or the fec0::
     * placeholders Windows lists for adapters with no DNS server.
     *
     * @return list<string>
     */
    public static function parse(string $output): array
    {
        $v4 = $v6 = [];
        foreach (preg_split('/\s+/', trim($output)) ?: [] as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $v4[$address] = true;
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                && !preg_match('/^(fec0:|fe80:)/i', $address)) {
                $v6[$address] = true;
            }
        }
        return array_keys($v4 + $v6);
    }
}
