<?php
namespace Byond;

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

/**
 * This class provides functionality related to BYOND (Build Your Own Net Dream) game development.
 * It can be used to interact with BYOND servers and retrieve information about players and their accounts.
 */
class Byond
{
    /**
     * The Unix timestamp representing the BYOND epoch.
     * The BYOND epoch is the starting point for measuring time in the BYOND game engine.
     * It is defined as January 1, 2000, 00:00:00 UTC.
     * This constant represents the BYOND epoch as a Unix timestamp.
     */
    const int BYOND_EPOCH_AS_UNIX_TS = 946684800;

    /**
     * The base URL for the BYOND website.
     *
     * @var string
     */
    const string BASE_URL = 'http://www.byond.com/';

    /**
     * The URL for the members section of the BYOND website.
     *
     * @var string
     */
    const string MEMBERS = self::BASE_URL . 'members/';

    /**
     * The URL for a user's profile page on the BYOND website.
     *
     * @var string
     */
    const string PROFILE = 'https://secure.byond.com/members/-/account';

    /**
     * Converts a BYOND timestamp to a Unix timestamp.
     *
     * @param int $byond_timestamp_ds The BYOND timestamp in deciseconds.
     * @return int The converted Unix timestamp.
     */

    /**
     * Used to search through bans that are stored within CentCom.
     *
     * @var string
     */
    const string CENTCOM_URL = 'https://centcom.melonmesa.com';

    public static function convertToUnixFromByond(int $byond_timestamp): int
    {
        return ($byond_timestamp * 0.1) + self::BYOND_EPOCH_AS_UNIX_TS;
    }

    /**
     * Converts a Byond timestamp to a Unix timestamp and returns it in ISO 8601 format.
     *
     * @param int $byond_timestamp The Byond timestamp to convert.
     * @return string The converted timestamp in ISO 8601 format.
     */
    public static function convertToTimestampFromByond(int $byond_timestamp): string
    {
        return date('c', self::convertToUnixFromByond($byond_timestamp));
    }

    /**
     * Converts a Unix timestamp to a BYOND timestamp in deciseconds.
     *
     * @param int $unix_timestamp The Unix timestamp to convert.
     * @return int The converted BYOND timestamp in deciseconds.
     */
    public static function convertToByondFromUnix(int $unix_timestamp): int
    {
        return round(($unix_timestamp - self::BYOND_EPOCH_AS_UNIX_TS) * 10);
    }

    /**
     * Converts a timestamp in ISO 8601 format to a BYOND timestamp in deciseconds.
     *
     * @param string $iso_timestamp The timestamp in ISO 8601 format to convert.
     * @return int The converted BYOND timestamp in deciseconds.
     */
    public static function convertToByondFromTimestamp(string $iso_timestamp): int
    {
        return self::convertToByondFromUnix(strtotime($iso_timestamp));
    }

    public static function bansearch_centcom(string $ckey, bool $prettyprint = true): string|false
    {
        $json = false;
        $url = self::CENTCOM_URL . '/ban/search/' . $ckey;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        curl_close($ch);
        if (! $response) return false;
        if (! $json = $prettyprint ? json_encode(json_decode($response), JSON_PRETTY_PRINT) : $response) return false;
        return $json;
    }

    /**
     * Retrieves the 50 character token from the BYOND website.
     *
     * @param string $ckey The ckey of the user.
     * @return string|false The retrieved token or false if the retrieval fails.
     */
    public static function getProfilePage(string $ckey): string|false 
    { // Get the 50 character token from the desc. User will have needed to log into https://secure.byond.com/members/-/account and added the generated token to their description first!
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::MEMBERS.urlencode($ckey).'?format=text');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return the page as a string
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $page = curl_exec($ch);
        curl_close($ch);
        if ($page) return $page;
        return false;        
    }

    /**
     * Retrieves the BYOND age of a player based on their ckey.
     *
     * @param string $ckey The ckey of the player.
     * @return string|false The BYOND age of the player, or false if it cannot be retrieved.
     */
    public static function getByondAge(string $ckey): string|false
    {   
        return self::__parseProfileAge(self::getProfilePage($ckey));
    }

    /**
     * Retrieves the BYOND description of a player based on their ckey.
     *
     * @param string $ckey The ckey of the player.
     * @return string|false The BYOND description of the player, or false if it cannot be retrieved.
     */
    public static function getByondDesc(string $ckey): string|false
    {
        return self::__extractToken(self::getProfilePage($ckey));
    }

    /**
     * This function is used to retrieve the 50 character token from the BYOND website.
     *
     * @param string $page The HTML page content from which to extract the token.
     * @return string|false The extracted token if found, or false if not found.
     */
    public static function __extractToken(string $page): string|false 
    {
        if ($desc = substr($page, (strpos($page , 'desc')+8), 50)) return $desc; // PHP versions older than 8.0.0 will return false if the desc isn't found, otherwise an empty string will be returned
        return false;
    }

    /**
     * This function is used to parse a BYOND account's age.
     *
     * @param string $page The page content to parse.
     * @return string|false The parsed age as a string, or false if the age cannot be parsed.
     */
    public static function __parseProfileAge(string $page): string|false
    {
        if (preg_match("^(19|20)\d\d[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])^", $age = substr($page, (strpos($page , 'joined')+10), 10))) return $age;
        return false;
    }
}