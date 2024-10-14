<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

class IPToCountryResolver
{
    public bool $online = false;

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
     * @param string $ip The IP address to resolve.
     * @return string The country code, region, and city of the IP address in the format 'CC->REGION->CITY'.
     */
    public static function Online(string $ip): array
    {
        // TODO: Add caching and error handling for 429s
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/php/$ip?fields=21757750"); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        if (! $array = @unserialize($response)) return []; // If the request timed out or if the service 429'd us
        assert(is_array($array));
        if (! isset($array['status']) || $array['status'] !== 'success') return [];
        return $array;
        //if ($json['status'] === 'success') return $json;
    }

    /**
     * Resolves the country associated with the given IP address using an offline database.
     *
     * @param string $ip The IP address to resolve.
     * @return string The country associated with the IP address, or 'unknown' if not found.
     */
    public static function Offline(string $ip): string
    {
        /** @var string[][] */
        $ranges = [];
        $numbers = explode('.', $ip);
        if (! include('ip_files/'.$numbers[0].'.php')) return 'unknown'; // $ranges is defined in the included file
        $code = ($numbers[0] << 24) | ($numbers[1] << 16) | ($numbers[2] << 8) | $numbers[3];
        $country = array_reduce(array_keys($ranges), fn($carry, $key) => ($key <= $code && $ranges[$key][0] >= $code) ? [...$carry, $ranges[$key][1]] : $carry, []);
        return reset($country) ?: 'unknown';
    }

    public function __invoke(string $ip): string
    {
        return $this->online ? self::Online($ip)['region'] ?? 'unknown' : self::Offline($ip);
    }
}