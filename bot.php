<?php
$command_symbol = '!s'; //Command prefix


ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); //Unlimited memory usage
define('MAIN_INCLUDED', 1); //Token and SQL credential files may be protected locally and require this to be defined to access
require getcwd(). '/token.php'; //$token
include getcwd() . '/vendor/autoload.php';

function execInBackground($cmd) {
    if (substr(php_uname(), 0, 7) == "Windows") {
        pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));
    } else exec($cmd . " > /dev/null &");
}

function execInBackgroundWindows($cmd) {
    pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));
}

function execInBackgroundLinux($cmd) {
    exec($cmd . " > /dev/null &");
}

/*
from __future__ import print_function
#from googletrans import Translator

import asyncio
import codecs
import random
import os
import psutil
import time
import socket
import subprocess
from operator import itemgetter
from pathlib import Path
from datetime import datetime
*/

$logger = new Monolog\Logger('New logger');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout'));
$loop = React\EventLoop\Factory::create();
use Discord\WebSockets\Intents;
$discord = new \Discord\Discord([
	'token' => "$token",
	/*'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
	],*/
    'loadAllMembers' => true,
    'storeMessages' => true,
	'logger' => $logger,
	'loop' => $loop,
	'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS, // default intents as well as guild members
]);

function portIsAvailable(int $port = 1714): bool
{
	$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

	if (socket_bind($s, "127.0.0.1", $port)) {
		socket_close($s);
		return true;
	}
	socket_close($s);
	return false;
}

function remove_prefix(string $text = '', string $prefix = ''): string
{
	if (str_starts_with($text, $prefix)) # only modify the text if it starts with the prefix
		$text = str_replace($prefix, '', $text);# remove one instance of prefix
	return $text;
}

function my_message($msg): bool
{
	return ($message->author->user->id == $discord->user->id);
}

function search_players(string $ckey): string
{
	if ($playerlogs = fopen('/home/1713/civ13-rp/SQL/playerlogs.txt', "r")) {
		while (($fp = fgets($playerlogs, 4096)) !== false) {
			if (trim(strtolower($fp)) == trim(strtolower($ckey)))
				return $ckey;
		}
		return 'None';
	} else return 'Unable to access playerlogs.txt!';
}

function on_ready($discord)
{
	echo 'Logged in as ' . $discord->user->username . "#" . $discord->user->discriminator . ' ' . $discord->id . PHP_EOL;
	echo('------' . PHP_EOL);
}

function on_message($message, $discord, $loop, $command_symbol = '!s')
{
	if ($message->guild->owner_id != '196253985072611328') return; //Only allow this in a guild that Taislin owns
	
	//Move this into a loop->timer so this isn't being called on every single message to reduce read/write overhead
	if ($ooc = fopen('/home/1713/civ13-rp/ooc.log', "r+")) {
		while (($fp = fgets($ooc, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if($target_channel = $message->guild->channels->get('name', 'ooc-nomads'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ooc, 0); //clear the file
		fclose($ooc);
	}
	if ($ahelp = fopen('/home/1713/civ13-rp/admin.log', "r+")) {
		while (($fp = fgets($ahelp, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if($target_channel = $message->guild->channels->get('name', 'ahelp-nomads'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ahelp, 0); //clear the file
		fclose($ahelp);
	}
	if ($ooctdm = fopen('/home/1713/civ13-tdm/ooc.log', "r+")) {
		while (($fp = fgets($ooctdm, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if($target_channel = $message->guild->channels->get('name', 'ooc-tdm'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ooctdm, 0); //clear the file
		fclose($ooctdm);
	}
	if ($ahelptdm = fopen('/home/1713/civ13-tdm/admin.log', "r+")) {
		while (($fp = fgets($ahelptdm, 4096)) !== false) {
			$fp = str_replace('\n', "", $fp);
			if($target_channel = $message->guild->channels->get('name', 'ahelp-tdm'))
				$target_channel->sendMessage($fp);
		}
		ftruncate($ahelptdm, 0); //clear the file
		fclose($ahelptdm);
	}
	
	if (str_starts_with($message->content, $command_symbol . ' ')) { //Add these as slash commands?
		$message_content = substr($message->content, strlen($command_symbol)+1);
		$message_content_lower = strtolower($message_content);
		if (str_starts_with($message_content_lower, 'ping')) {
			$message->channel->sendMessage('Pong!');
			return;
		}
		if (str_starts_with($message_content_lower, 'help')) {
			$message->channel->sendMessage('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostciv, killciv, restartciv, mapswap, hosttdm, killtdm, restarttdm, tdmmapswap');
			return;
		}
		
		if (str_starts_with($message_content_lower,'cpu')) {
			if (substr(php_uname(), 0, 7) == "Windows") {
				$p = shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select PercentProcessorTime"');
				$p = preg_replace('/\s+/', ' ', $p); //reduce spaces
				$p = str_replace("PercentProcessorTime", "", $p);
				$p = str_replace("--------------------", "", $p);
				$p = preg_replace('/\s+/', ' ', $p); //reduce spaces
				$load_array = explode(" ", $p);

				$x=0;
				foreach ($load_array as $line) {
					if ($line != " " && $line != "") {
						if ($x==0) {
							$load = "CPU Usage: $line%\n";
							break;
						}
						if ($x!=0) {
							//$load = $load . "Core $x: $line%\n"; //No need to report individual cores right now
						}
						$x++;
					}
				}
				$message->channel->sendMessage($load);
			} else { //Linux
				$cpu_load = '-1';
				if ($cpu_load_array = sys_getloadavg())
					$cpu_load = array_sum($cpu_load_array) / count($cpu_load_array);
				$message->channel->sendMessage('CPU Usage: ' . $cpu_load . "%");
			}
			return;
		}
		
		if (str_starts_with($message_content_lower, 'insult')) {
			$split_message = explode(' ', $message_content); //$split_target[1] is the target
			if ((count($split_message > 1)) && strlen($split_message[1] > 0) ) {
				$ckey = $split_message[1];
				/*
				insults = open('insult.txt').read().splitlines()
				insult = random.choice(insults)
				*/
				//$message->channel->sendMessage("$ckey, $insult");
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'ooc ')) {
			switch (strtolower($message->channel->name)) {
				case 'ooc-nomads':
					/*
					message.content = remove_prefix(message.content, 'ooc ')
					with open("/home/1713/civ13-rp/SQL/discord2ooc.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
						*/
					return;
				case 'ooc-tdm':
					/*
					message.content = remove_prefix(message.content, 'ooc ')
					with open("/home/1713/civ13-tdm/SQL/discord2ooc.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
					*/
					return;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'asay ')) {
			switch (strtolower($message->channel->name)) {
				case 'ahelp-nomads':
					/*
					message.channel.name.lower() == "ahelp-nomads":
					message.content = remove_prefix(message.content, 'asay ')
					with open("/home/1713/civ13-rp/SQL/discord2admin.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
					*/
					return;
				case 'ahelp-tdm':
					/*
					message.content = remove_prefix(message.content, 'asay ')
					with open("/home/1713/civ13-tdm/SQL/discord2admin.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(message.content))
						myfile.write("\n")
					*/
					return;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'dm ')) {
			switch (strtolower($message->channel->name)) {
				case 'ahelp-nomads':
					/*
					message.content = remove_prefix(message.content, 'dm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-rp/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
					*/
					return;
				case 'ahelp-tdm':
					/*
					message.content = remove_prefix(message.content, 'dm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-tdm/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
					*/
					return;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'pm ')) {
			switch (strtolower($message->channel->name)) {
				case 'ahelp-nomads':
					/*
					message.content = remove_prefix(message.content, 'pm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-rp/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
					*/
					return;
				case 'ahelp-tdm':
					/*
					message.content = remove_prefix(message.content, 'pm ')
					split_message = message.content.split(": ")
					with open("/home/1713/civ13-tdm/SQL/discord2dm.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1]))
						myfile.write("\n")
					*/
					return;
			}
			return;
		}
		if (str_starts_with($message_content_lower, 'ban ')) {
			$message_content = substr($message->content, 4);
			$split_message = explode('; ', $message_content); //$split_target[1] is the target
			if (!str_contains($message->content, 'Byond account too new, appeal on our discord')) {
				/*
				with open("/home/1713/civ13-rp/SQL/discord2ban.txt", "a") as myfile:
						myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1])+":::"+str(split_message[2]))
						myfile.write("\n")
				with open("/home/1713/civ13-tdm/SQL/discord2ban.txt", "a") as myfile:
					myfile.write(str(message.author)+":::"+str(split_message[0])+":::"+str(split_message[1])+":::"+str(split_message[2]))
					myfile.write("\n")
				*/
			}
			$result = '**' . $message->user->username . '#' . $message->user->discriminator . '**banned **' . split_message[0] . '** for **' . split_message[1] . '** with the reason **' . split_message[2] . '**.';
			$message->channel->sendMessage($result);
			return;
		}
		if (str_starts_with($message_content_lower, 'unban ')) {
			$message_content = substr($message->content, 6);
			$split_message = explode('; ', $message_content);
			/*
			with open("/home/1713/civ13-rp/SQL/discord2unban.txt", "a") as myfile:
				myfile.write(str(message.author)+":::"+str(split_message[0]))
				myfile.write("\n")
			with open("/home/1713/civ13-tdm/SQL/discord2unban.txt", "a") as myfile:
				myfile.write(str(message.author)+":::"+str(split_message[0]))
				myfile.write("\n")
			*/
			$result = '**' . $message->user->username . '** unbanned **' . $split_message[0] . '**.';
			/*
			list1 = "/home/1713/civ13-rp/SQL/bans.txt"
			open(list1, "a").close()
			f = open(list1, "r")
			lines = f.readlines()
			f.close()
			f = open(list1, "w")
			for line in lines:
				if not str(split_message[0]) in line:
					f.write(line)
			f.close()
			
			list2 = "/home/1713/civ13-tdm/SQL/bans.txt"
			open(list2, "a").close()
			f2 = open(list2, "r")
			lines2 = f2.readlines()
			f2.close()
			f2 = open(list2, "w")
			for line2 in lines2:
				if not str(split_message[0]) in line2:
					f2.write(line2)
			f2.close()
			*/
			$message->channel->sendMessage($result);
			return;
		}
		#whitelist
		if (str_starts_with($message_content_lower, 'whitelistme')) {
			$split_message = trim(substr($message->content, 11));
			if (strlen(split_message) > 0) { // if len(split_message) > 1 and len(split_message[1]) > 0:
				$ckey = split_message;
				$accepted = false;
				if ($author_member = $message->member) {
					foreach ($author_member->roles as $role) {
						switch ($role->name) {
							case 'Admiral':
							case 'Captain':
							case 'Lieutenant':
							case 'Brother At Arms':
							case 'Knight':
								$accepted = true;
								break;
						}
					}
					if ($accepted) {
						$whitelist_path = '/home/1713/civ13-rp/SQL/whitelist.txt';
						/*
						open(whitelist, "a").close()

						with open(whitelist, "r") as search:
							for line in search:
								line = line.rstrip()	# remove '\n' at end of line
								if line == ckey+"="+str(message.author):
									$message->channel->sendMessage("{} is already in the whitelist!".format(ckey))

								elif str(message.author) in line:
									$message->channel->sendMessage("Woah there, {}, you already whitelisted one key! Remove the old one first.".format(str(message.author).split("#")[0]))

							search.close()

						somefile = open(whitelist, "a")
						somefile.write(ckey+"="+str(message.author))
						somefile.write("\n")
						somefile.close()
						somefile2 = open("/home/1713/civ13-tdm/SQL/whitelist.txt", "a")
						somefile2.write(ckey+"="+str(message.author))
						somefile2.write("\n")
						somefile2.close()
						*/
						$message->channel->sendMessage("$ckey has been added to the whitelist.");
					} else $message->channel->sendMessage("Rejected! You need to have at least the [Brother At Arms] rank.");
				} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			} else $message->channel->sendMessage("Wrong format. Please try '!s whitelistme [ckey].'");
			return;
		}
		if (str_starts_with($message_content_lower, 'unwhitelistme')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
						case 'Footman':
						case 'Brother At Arms':
						case 'Knight':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					$removed = "N/A";
					/*
					wlist = "/home/1713/civ13-rp/SQL/whitelist.txt"

					open(wlist, "a").close()

					f = open(wlist, "r")
					lines = f.readlines()
					f.close()
					f = open(list, "w")
					for line in lines:
						if not str(message.author) in line:
							f.write(line)
						else:
							removed = line.split("=")[0]

					f.close()
					list2 = "/home/1713/civ13-tdm/SQL/whitelist.txt"

					open(list2, "a").close()

					f2 = open(list2, "r")
					lines2 = f2.readlines()
					f2.close()
					f2 = open(list2, "w")
					for line2 in lines2:
						if not str(message.author) in line2:
							f2.write(line2)
						else:
							removed = line2.split("=")[0]

					f2.close()
					*/
					$message->channel->sendMessage("Ckey $removed has been removed from the whitelist.");
				} else $message->channel->sendMessage("Rejected! You need to have at least the [Brother At Arms] rank.");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'hostciv')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					$message->channel->send("Please wait, updating the code...");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-rp/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-rp/civ13.dmb 1715 -trusted -webclient -logself &');
					$message->channel->send("Attempted to bring up Civilization 13 (Main Server) <byond://51.254.161.128:1715>");
					//time.sleep(10) # ditto
					//execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killsudos.py')
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'killciv')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 Server.");
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'restartciv')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 Server.");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-rp/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-rp/civ13.dmb 1715 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (Main Server) <byond://51.254.161.128:1715>");
					//time.sleep(10) # ditto
					//execInBackgroundLinux('sudo python3 /home/1713/civ13-rp/scripts/killsudos.py')
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'restarttdm')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 TDM Server.");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdmp/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-tdm/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-tdm/civ13.dmb 1714 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (TDM Server) <byond://51.254.161.128:1714>");
					//time.sleep(10) # ditto
					//execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killsudos.py')
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'mapswap')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Lieutenant':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					$split_message = explode("mapswap ", $message_content);
					if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
						$mapto = split_message[1];
						$mapto = strtoupper($mapto);
						$message->channel->sendMessage("Changing map to $mapto...");
						execInBackgroundLinux("sudo python3 /home/1713/civ13-rp/scripts/mapswap.py $mapto");
						$message->channel->sendMessage("Sucessfully changed map to $mapto.");
					}
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'hosttdm')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					$message->channel->sendMessage("Please wait, updating the code...");
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/updateserverabspaths.py');
					$message->channel->sendMessage("Updated the code.");
					execInBackgroundLinux('sudo rm -f /home/1713/civ13-tdm/serverdata.txt');
					execInBackgroundLinux('sudo DreamDaemon /home/1713/civ13-tdm/civ13.dmb 1714 -trusted -webclient -logself &');
					$message->channel->sendMessage("Attempted to bring up Civilization 13 (TDM Server) <byond://51.254.161.128:1714>");
					//time.sleep(10) # ditto
					//execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killsudos.py')
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'killtdm')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					execInBackgroundLinux('sudo python3 /home/1713/civ13-tdm/scripts/killciv13.py');
					$message->channel->sendMessage("Attempted to kill Civilization 13 (TDM Server).");
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		if (str_starts_with($message_content_lower, 'tdmmapswap')) {
			$accepted = false;
			if ($author_member = $message->member) {
				foreach ($author_member->roles as $role) {
					switch ($role->name) {
						case 'Admiral':
						case 'Captain':
						case 'Knight':
							$accepted = true;
							break;
					}
				}
				if ($accepted) {
					$split_message = explode("mapswap ", $message_content);
					if ( (count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
						$mapto = split_message[1];
						$mapto = strtoupper($mapto);
						$message->channel->sendMessage("Changing map to $mapto...");
						execInBackgroundLinux("sudo python3 /home/1713/civ13-tdm/scripts/mapswap.py $mapto");
						$message->channel->sendMessage("Sucessfully changed map to $mapto.");
					}
				} else $message->channel->sendMessage("Denied!");
			} else $message->channel->sendMessage('Error! Unable to get Discord Member class.');
			return;
		}
		
		/*			
		if (str_starts_with($message_content_lower,"bancheck")):
			split_message = message.content.split("bancheck ")
			if len(split_message) > 1 and len(split_message[1]) > 0:
				ckey = split_message[1]
				ckey = ckey.lower()
				ckey = ckey.replace('_','')
				ckey = ckey.replace(' ','')
				banreason = "unknown"
				found = 0
				filecheck1 = Path("/home/1713/civ13-rp/SQL/bans.txt")
				filecheck2 = Path("/home/1713/civ13-tdm/SQL/bans.txt")
				if (filecheck1.is_file()):
					open(filecheck1, "a").close()
					with open(filecheck1, "r") as search:
						for line in search:
							line = line.rstrip()	# remove '\n' at end of line
							line.replace("|||","")
							linesplit = line.split(";")
							if len(linesplit)>=8 and linesplit[8] == ckey:
								found = 1
								banreason = linesplit[3]
								bandate = linesplit[5]
								banner = linesplit[4]
								exp_date = (round(int(linesplit[6])/10))+946684800
								exp_date = datetime.utcfromtimestamp(exp_date).strftime("%Y-%m-%d %H:%M:%S")
								$message->channel->sendMessage("**{}** has been banned from *Nomads* on **{}** for **{}** by {}. Expires on {}.".format(ckey,bandate,banreason,banner,exp_date))
						search.close()
				if (filecheck2.is_file()):
					open(filecheck2, "a").close()
					with open(filecheck2, "r") as search:
						for line in search:
							line = line.rstrip()	# remove '\n' at end of line
							line.replace("|||","")
							linesplit = line.split(";")
							if len(linesplit)>=8 and linesplit[8] == ckey:
								found = 1
								banreason = linesplit[3]
								bandate = linesplit[5]
								banner = linesplit[4]
								exp_date = (round(int(linesplit[6])/10))+946684800
								exp_date = datetime.utcfromtimestamp(exp_date).strftime("%Y-%m-%d %H:%M:%S")
								$message->channel->sendMessage("**{}** has been banned from *TDM* on **{}** for **{}** by {}. Expires on {}.".format(ckey,bandate,banreason,banner,exp_date))
						search.close()
				if (found == 0):
					$message->channel->sendMessage("No bans were found for **{}**.".format(ckey))
			else:
				$message->channel->sendMessage("Wrong format. Please try '!s bancheck [ckey].'")


		if (str_starts_with($message_content_lower,'serverstatus')):
			_1714 = not portIsAvailable(1714)
			server_is_up = (_1714)
			if not server_is_up:
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="TDM Server Status",value="Offline", inline=False)
				yield from client.message.channel.send(embed=embed)
				return
			else:
				data = None;
				if _1714:
					if os.path.isfile('/home/1713/civ13-tdm/serverdata.txt') == True:
						data = codecs.open('/home/1713/civ13-tdm/serverdata.txt', encoding='utf-8').read()
				else:
					embed = discord.Embed(color=0x00ff00)
					embed.add_field(name="TDM Server Status",value="Offline", inline=False)
					$message->channel->sendMessage(embed=embed)
					return

				data = data.replace('<b>Address</b>: ', '')
				data = data.replace('<b>Map</b>: ', '')
				data = data.replace('<b>Gamemode</b>: ', '')
				data = data.replace('<b>Players</b>:','')
				data = data.replace('</b>','')
				data = data.replace('<b>','')
				data = data.split(";")
				#embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="TDM Server Status",value="Online", inline=False)
				embed.add_field(name="Address", value='<'+data[1]+'>', inline=False)
				embed.add_field(name="Map", value=data[2], inline=False)
				embed.add_field(name="Gamemode", value=data[3], inline=False)
				embed.add_field(name="Players", value=data[4], inline=False)


				$message->channel->sendMessage(embed=embed)
			_1715 = not portIsAvailable(1715)
			server_is_up = (_1715)
			if not server_is_up:
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="Nomads Server Status",value="Offline", inline=False)
				$message->channel->sendMessage(embed=embed)
				return
			else:
				data = None;
				if _1714:
					if os.path.isfile('/home/1713/civ13-rp/serverdata.txt') == True:
						data = codecs.open('/home/1713/civ13-rp/serverdata.txt', encoding='utf-8').read()
				else:
					embed = discord.Embed(color=0x00ff00)
					embed.add_field(name="Nomads Server Status",value="Offline", inline=False)
					$message->channel->sendMessage(embed=embed)
					return

				data = data.replace('<b>Address</b>: ', '')
				data = data.replace('<b>Map</b>: ', '')
				data = data.replace('<b>Gamemode</b>: ', '')
				data = data.replace('<b>Players</b>:','')
				data = data.replace('</b>','')
				data = data.replace('<b>','')
				data = data.split(";")
				#embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
				embed = discord.Embed(color=0x00ff00)
				embed.add_field(name="Nomads Server Status",value="Online", inline=False)
				embed.add_field(name="Address", value='<'+data[1]+'>', inline=False)
				embed.add_field(name="Map", value=data[2], inline=False)
				embed.add_field(name="Gamemode", value=data[3], inline=False)
				embed.add_field(name="Players", value=data[4], inline=False)


				$message->channel->sendMessage(embed=embed)
		*/
	}
}

$discord->once('ready', function ($discord) use ($loop, $command_symbol)
{
	on_ready($discord);
	
	$discord->on('message', function ($message) use ($discord, $loop, $command_symbol) { //Handling of a message
		on_message($message, $discord, $loop, $command_symbol);
	});
});

$discord->run();
