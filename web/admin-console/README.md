# Mobile admin Overview: fixture-only preview

This is a non-routed, read-only UI prototype. It does not connect to Civilizationbot,
Discord, BYOND, a supervisor or any live API. It is not an installed PWA or a
completed mobile administration app. All six servers and timestamps are synthetic.

Open `web/admin-console/index.html` directly in a supported local browser. Assets
are local with no CDN, packages, service worker, web manifest or external fonts.
The page CSP rejects network connections. No HTTP listener is registered, and
this directory is intentionally outside the bot's `html/` auto-discovery path.
Do not copy it there or publish it as a working admin service.

`console.js` renders `civ13.admin-status.v1` projections returned by an injected
adapter with `read(): Promise<Array<Row>>`. Each row wraps `{name, observed_at,
projection}`. `observed_at` is fixture display metadata, not a new PHP field or
an authorization/freshness assertion. The UI labels the age as age **at capture**:
these snapshots do not become live evidence when reloaded. Source normalization,
expiry, Discord authorization and target membership are backend responsibilities.

The renderer uses DOM text nodes, refuses denied/unknown-schema projections,
suppresses stale/unknown/unreachable metrics, keeps zero distinct from missing,
and never enables the three action buttons, even if supplied capability flags
claim otherwise. It does not contain an action handler or transport. Unexpected
adapter failure clears current cards; a late older response cannot replace a
newer one. Reload and filtering operate only on injected fixtures.

The layout provides semantic headings and definition lists, an accessible state
filter and live result summary, keyboard focus styles, a skip link, linked reasons
for disabled controls, and responsive single-column cards below 650px. These are
implemented features, not a claim that real-browser accessibility/layout QA passed.

## Offline checks

```
node --check web/admin-console/console.js
node --check web/admin-console/fixtures.js
node --check web/admin-console/demo.js
node tests/admin-console/ui-dom.mjs
```

The dependency-free DOM double runs 32 behavior assertions using the same test
script as `tests/admin-console/ui.html`. It is **not** a browser engine and does
not validate CSS, CSP loading, real DOM compatibility or accessibility behavior.
The admin-console workflow includes these Node 22 checks without npm installation.

The separate `browser-contracts` CI job uses an existing vendor Google Chrome on
Ubuntu 24.04. It explicitly fails if `/usr/bin/google-chrome` is absent; no browser
or driver is installed/downloaded. It retains the browser sandbox and opens no
debug listener: the dependency-free Node runner communicates over a private
DevTools pipe to its own child process. Run on a supported Linux executor with
`CHROMIUM_BIN=/path/to/existing/chrome node tests/admin-console/ui.mjs`.

The runner executes the existing ui.html tests on a real DOM, then navigates the
unchanged index.html with its real CSP/styles/scripts at 320/390/1280px. Checks
cover overflow, grid columns, labels/landmarks/focus, 44px control targets,
zero-player state, unavailable actions, timestamps, filtering and reloading.
Each navigation waits for its matching loader's load event. These are basic
checks, not a screen-reader audit or proof of a live app. Content HTTP(S)/WebSocket
requests are blocked, browser background networking is disabled, and all test
pages/resources are local fixture files. No real credentials or server data.

One owned browser/process group has a 20-second total deadline and is cleaned up
on success/failure. A missing browser or timeout fails explicitly. Results, bounded
stderr and 390/1280px fixture screenshots are retained as internal CI QA artifacts
for seven days, not published as a Site or delivered to the user. Browser/visual
acceptance remains unverified until the new job actually executes successfully;
manual screenshot/accessibility review is separate from passing DOM assertions.

Preparation verification: Node syntax and the 32 DOM-double assertions passed.
Real Chromium did not run successfully: local process-socket creation was denied;
a requested environment escalation failed in bwrap setup. No browser screenshots
or visual acceptance are claimed. No denied path was read or changed.

The separately reviewed PHP head `7df4239c6983a0951c2108876dbafc5d32aa3c48`
passed 37 contracts on PHP 8.3–8.5 / Ubuntu and Windows. That evidence does not
cover this subsequent UI patch.
