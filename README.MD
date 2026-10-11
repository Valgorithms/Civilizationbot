# Civilizationbot

Civilizationbot is [Civ13](https://civ13.com)'s official Discord bot designed to manage and interact with the Civ13 game servers. It provides various functionalities, including server management, player verification, chat moderation, and more.

## Features

- Server management and monitoring
- Player verification and role assignment
- Chat moderation and relay
- Interaction commands and slash commands
- Automated status updates

## Installation

Requires PHP 8.3 or later. The DiscordPHP voice dependency already requires
PHP 8.3, and bot source uses PHP 8.3 syntax. `react/http` must be at least 1.11.1
to include the response-header and chunked-parser denial-of-service fixes.

To install Civilizationbot, follow these steps:

1. Clone the repository:
    ```
    sh git clone https://github.com/VZGCoders/Civilizationbot.git
    ```
2. Navigate to the project directory:
    ```sh
    cd Civilizationbot
    ```
3. Install dependencies using Composer:
    ```sh
    composer install
    ```
4. Configure the bot by editing the `bot.php` and creating a `.env` file with your Discord bot token, server details, and other settings.

## Usage

To run the bot, use the following command:
```sh
php bot.php
```

## Status Changer

The bot includes a feature to automatically change its status on Discord. This is handled by the `$status_changer_random` function.

## `$status_changer_random` Function

The `$status_changer_random` function is responsible for updating the bot's status with a random message from a predefined list. It reads the status messages from a file and sets the bot's status accordingly.

## Usage

The function is defined in `variable_functions.php` and is used as follows:

```php
<?php
$status_changer_random = function (Civ13 $civ13): PromiseInterface
{
    if (! $civ13::status) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning($err = 'status is not defined');
        return reject(new \LogicException($err));
    }
    if (! $status_array = file($civ13::status, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning($err = 'unable to open file `' . $civ13::status . '`');
        return reject(new FileNotFoundException($err));
    }
    list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
    if (! $status) return reject(new \Exception('status must not be empty'));
    $activity = new Activity($civ13->discord, [ // Discord status            
        'name' => $status,
        'type' => (int) $type, // 0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
    ]);
    $civ13->statusChanger($activity, $state);
    return resolve(null);
};
```

To configure the status changer, ensure that the `status` file is correctly set in the configuration and contains the status messages in the following format:

```
Playing Civ13; 0; online
Streaming Civ13; 1; online
Listening to Civ13; 2; online
Watching Civ13; 3; online
Custom Status; 4; online
```

Each line represents a status message with the format: `status; type; state`.

## Timer

The status changer is periodically triggered by a timer. The timer is set up as follows:

```php
<?php
$status_changer_timer = function (Civ13 $civ13) use ($status_changer_random): void
{
    if (! isset($civ13->timers['status_changer_timer'])) $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, fn() => $status_changer_random($civ13));
};
```

This sets up a timer to change the bot's status every 120 seconds.

## Contributing

### Offline verifier integration

`vzgcoders/verifier-server` is pinned to the reviewed upstream commit in
`composer.json`. This repository intentionally ignores `composer.lock`; preserve
local locks and any intentional path repositories. In an existing installation,
update only that package with `composer update vzgcoders/verifier-server`.

Run `php tests/verifier-integration.php` after installing dependencies. The runner
uses temporary filesystem storage and injected endpoint setup, bypassing the
usual test bootstrap and all `.env` loading. It exercises the bot's in-process
registration/unverify calls against the installed verifier, plus form decoding,
status codes, authorization, duplicate rejection, Discord-only deletion, lookup
invalidation and restart persistence. It never starts the bot, listens on a
socket or sends a live Discord/HTTP request. CI resolves dependencies separately
for PHP 8.3, 8.4 and 8.5; the installed verifier reference must match the manifest pin.

### Dependency and HTTP checks

For an existing local lock, update the patched HTTP package and pinned verifier
with `composer update react/http vzgcoders/verifier-server`; preserve any local
path repositories. Then run `composer check-platform-reqs`,
`composer audit --locked`, and `php tests/http-dependency.php`.
The HTTP runner uses injected in-memory connections and streams, with bounded
malformed inputs and legitimate response controls; it never opens a socket.
CI resolves the full dependency set, including development tools, on each
supported runtime, checks platform requirements and audit results, parses all
bot source, and runs the HTTP, verifier and disabled-economy offline suites.
The ignored-lock convention is unchanged. Updating deployment dependencies and
restarting the live bot are separate operational actions.

Contributions are welcome! Please open an issue or submit a pull request on GitHub.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

## Contact

For support or inquiries, please contact Valithor Obsidion at valithor@valzargaming.com.
