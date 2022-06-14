CivilizationBot
====
[![Civilization 13 Discord](https://discord.com/api/guilds/468979034571931648/widget.png?style=banner1)](https://discord.gg/hBEtg4x)

The official [Civ13](https://civ13.com) Discord bot built on DiscordPHP with documentation [available here](http://discord-php.github.io/DiscordPHP), albeit limited at the moment, as well as a class reference. Feel free to ask questions in the Discord server above.

## Before you start

Before you start using this Library, you **need** to know how PHP works, you need to know the language and you need to know how Event Loops and Promises work. This is a fundamental requirement before you start. Without this knowledge, you will only suffer.

## FAQ

1. Can I run CivilizationBot on a webserver (e.g. Apache, nginx)?
    - No, CivilizationBot will only run in CLI. If you want to have an interface for your bot you can integrate [react/http](https://github.com/ReactPHP/http) with your bot and run it through CLI.

## Getting Started

### Requirements

- PHP 7.4
	- We recommend PHP 8.0 as it will be the most stable and most performant.
	- x86 (32-bit) PHP requires [`ext-gmp` extension](https://www.php.net/manual/en/book.gmp.php) enabled for handling Permissions.
- Composer
- `ext-json`
- `ext-zlib`

### Windows and SSL

Unfortunately PHP on Windows does not have access to the Windows Certificate Store. This is an issue because TLS gets used and as such certificate verification gets applied (turning this off is **not** an option).

You will notice this issue by your script exiting immediately after one loop turn without any errors. Unfortunately there is for some reason no error or exception.

As such users of this library need to download a [Certificate Authority extract](https://curl.haxx.se/docs/caextract.html) from the cURL website.<br>
The path to the caextract must be set in the [`php.ini`](https://secure.php.net/manual/en/openssl.configuration.php) for `openssl.cafile`.

#### Recommended Extensions

- The latest PHP version.
- One of `ext-uv` (recommended), `ext-libev` or `ext-event` for a faster, and more performant event loop.
- `ext-mbstring` if handling non-english characters.
- `ext-gmp` if using 32-bit PHP.

#### Voice Requirements

- 64-bit Linux or Darwin based OS.
    - If you are running on Windows, you must be using PHP 8.0.
- `ext-sodium`
- FFmpeg

### Basic Example
TODO

See [examples folder](examples) for more.

## Documentation
TODO

## Contributing

We are open to contributions, just open a pull request and we will review it.

## License

MIT License, &copy; Valithor Obsidion and other contributers 2022-present.