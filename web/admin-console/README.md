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

On a supported executor with an existing Chromium installation, optional browser
execution is `CHROMIUM_BIN=/path/to/chromium node tests/admin-console/ui.mjs` (set
the equivalent environment variable on Windows), or open `tests/admin-console/ui.html`
in a local browser. It uses local files and a temporary profile; no server or
browser download is required. The runner does not disable Chromium's sandbox.
Browser availability/environment errors are failures, never reported as a pass.
Also inspect index.html at 320/390/768px, keyboard navigation, text zoom and denied,
stale, failed/recovered and repeated reload flows before claiming visual acceptance.

Preparation verification: Node syntax and the 32 DOM-double assertions passed.
Real Chromium did not run successfully: local process-socket creation was denied;
a requested environment escalation failed in bwrap setup. No browser screenshots
or visual acceptance are claimed. No denied path was read or changed.

The separately reviewed PHP head `7df4239c6983a0951c2108876dbafc5d32aa3c48`
passed 37 contracts on PHP 8.3–8.5 / Ubuntu and Windows. That evidence does not
cover this subsequent UI patch.
