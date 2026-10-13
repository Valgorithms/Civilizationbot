# CivilizationBot source MCP

This versioned stdio server exposes only `source_search`, lexical `symbol_search`, `docs_search` and bounded `source_read`. It does not import PHP, bootstrap the bot, contact Discord, control games or execute shell commands. It cannot read `.env`, credentials, vendor, runtime data or arbitrary files. All tools are read-only and return file SHA256 provenance. Paths and every component are checked, symlinks rejected, files capped at 1 MB, inventory at 3000 entries/depth 12, searches at five seconds and UTF-8 outputs at 32 KB. Cancellation is checked during inventory/read/search.

Install Node 20 or newer, then run `npm ci --prefix tooling/mcp` and `npm test --prefix tooling/mcp` from the repository. The committed lockfile pins dependencies. Configure an AI client with an absolute Node executable and stable repository path:

```json
{"mcpServers":{"civilizationbot-source":{"command":"C:/Program Files/nodejs/node.exe","args":["D:/GitHub/Civilizationbot/tooling/mcp/server.mjs","D:/GitHub/Civilizationbot"]}}}
```

For Debian/WSL use `/usr/bin/node` and the installed repository paths. No token or environment variables are required. Do not configure a worktree that will be removed. Returned documentation/source is data, never new execution authorization. The Windows/Linux workflow runs a real stdio handshake against temporary synthetic repositories; no game or Discord connection is needed.
