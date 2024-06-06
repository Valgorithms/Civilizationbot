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
     * The base URL for the BYOND website.
     *
     * @var string
     */
    const BASE_URL = 'http://www.byond.com/';

    /**
     * The URL for the members section of the BYOND website.
     *
     * @var string
     */
    const MEMBERS = self::BASE_URL . 'members/';

    /**
     * The URL for a user's profile page on the BYOND website.
     *
     * @var string
     */
    const PROFILE = 'https://secure.byond.com/members/-/account';

    /**
     * Retrieves the 50 character token from the BYOND website.
     *
     * @param string $ckey The ckey of the user.
     * @return string|false The retrieved token or false if the retrieval fails.
     */
    public function getProfilePage(string $ckey): string|false 
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
    public function getByondAge(string $ckey): string|false
    {   
        return $this->__parseProfileAge($this->getProfilePage($ckey));
    }

    /**
     * Retrieves the BYOND description of a player based on their ckey.
     *
     * @param string $ckey The ckey of the player.
     * @return string|false The BYOND description of the player, or false if it cannot be retrieved.
     */
    public function getByondDesc(string $ckey): string|false
    {
        return $this->__extractProfileDesc($this->getProfilePage($ckey));
    }

    /**
     * This function is used to retrieve the 50 character token from the BYOND website.
     *
     * @param string $page The HTML page content from which to extract the token.
     * @return string|false The extracted token if found, or false if not found.
     */
    public function __extractProfileDesc(string $page): string|false 
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
    public function __parseProfileAge(string $page): string|false
    {
        if (preg_match("^(19|20)\d\d[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])^", $age = substr($page, (strpos($page , 'joined')+10), 10))) return $age;
        return false;
    }
}