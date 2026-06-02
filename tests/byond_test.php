<?php

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

require_once __DIR__.'/../vendor/valzargaming/byond/src/ByondTrait.php';

class ByondClient
{
    use \Byond\ByondTrait;
}

$ckey = 'valzargaming';
echo "Fetching profile for {$ckey}\n";

$page = ByondClient::getProfilePage($ckey);
if ($page === false) {
    echo "Failed to fetch profile\n";
    exit(1);
}

echo 'Page length: '.strlen($page)."\n";
echo 'Is valid: '.(ByondClient::isValidProfilePage($page) ? 'yes' : 'no')."\n";

$key = ByondClient::parseKey($page);
echo 'Key: '.($key === false ? 'N/A' : $key)."\n";

$gender = ByondClient::parseGender($page);
echo 'Gender: '.($gender === false ? 'N/A' : $gender)."\n";

$joined = ByondClient::parseJoined($page);
echo 'Joined: '.($joined === false ? 'N/A' : $joined)."\n";
