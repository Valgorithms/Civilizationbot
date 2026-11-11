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

namespace Civ13;

class IPToCountryResolver
{
    public bool $online = true;

    /**
     * @param bool $online Whether {@see __invoke()} should use the online ip-api
     *                     lookup rather than the bundled offline database.
     */
    public function __construct(bool $online = false)
    {
        $this->online = $online;
    }

    /**
     * This function is used to get the country code of an IP address using the ip-api API.
     * The site will return a JSON object with the country code, region, and city of the IP address.
     * The site will return a status of 429 if the request limit is exceeded (45 requests per minute).
     * Returns a string in the format of 'CC->REGION->CITY'.
     *
     * {
     *   "query": "24.48.0.1",
     *   "status": "success",
     *   "country": "Canada",
     *   "countryCode": "CA",
     *   "region": "QC",
     *   "regionName": "Quebec",
     *   "city": "Montreal",
     *   "zip": "H1L",
     *   "lat": 45.6026,
     *   "lon": -73.5167,
     *   "timezone": "America/Toronto",
     *   "isp": "Le Groupe Videotron Ltee",
     *   "org": "Videotron Ltee",
     *   "as": "AS5769 Videotron Ltee",
     *   "asname": "VIDEOTRON",
     *   "reverse": "modemcable001.0-48-24.mc.videotron.ca",
     *   "mobile": false,
     *   "proxy": false,
     *   "hosting": false
     *}
     *
     * @param  string $ip The IP address to resolve.
     * @return array  An array containing the country code, region, and city of the IP address.
     */
    public static function Online(string $ip): array
    {
        // TODO: Add error handling for 429s
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        } // Never place anything but an IP address in the lookup URL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://ip-api.com/json/'.rawurlencode($ip).'?fields=21757750');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);

        return self::parseResponse($response);
    }

    /**
     * Decodes an ip-api JSON reply. Returns an empty array for a failed request,
     * a rate-limit or error reply, or anything that is not a successful lookup.
     *
     * The reply arrives over plain HTTP, so it is only ever decoded as JSON data
     * and never passed to unserialize().
     */
    public static function parseResponse(string|bool|null $response): array
    {
        if (! is_string($response) || $response === '') {
            return [];
        }
        $array = json_decode($response, true);
        if (! is_array($array) || ($array['status'] ?? null) !== 'success') {
            return [];
        }

        return $array;
    }

    /**
     * Resolves the country associated with the given IP address using an offline database.
     *
     * @param  string $ip The IP address to resolve.
     * @return string The country associated with the IP address, or 'unknown' if not found.
     */
    public static function Offline(string $ip): string
    {
        /** @var string[][] */
        $ranges = [];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return 'unknown';
        } // The first octet names the file to include, so only a real IPv4 address may reach it
        $numbers = array_map('intval', explode('.', $ip));
        if (! @include('ip_files/'.$numbers[0].'.php')) {
            return 'unknown';
        } // $ranges is defined in the included file
        $code = ($numbers[0] << 24) | ($numbers[1] << 16) | ($numbers[2] << 8) | $numbers[3];
        $country = array_reduce(array_keys($ranges), fn ($carry, $key) => ($key <= $code && $ranges[$key][0] >= $code) ? [...$carry, $ranges[$key][1]] : $carry, []);

        return reset($country) ?: 'unknown';
    }

    /**
     * Resolves `$ip` per the `online` flag: the ip-api `countryCode` (online) or
     * the offline database's `CC->REGION->CITY` string.
     */
    public function __invoke(string $ip): array|string
    {
        return $this->online ? self::Online($ip)['countryCode'] ?? 'unknown' : self::Offline($ip);
    }
}
