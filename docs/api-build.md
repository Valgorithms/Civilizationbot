# Versioned public API documentation

phpDocumentor 3.10 defaults diagram rendering to disabled but its standard template still links the unavailable diagram. The finalizer explicitly omits that optional page and its links. Class references and searchable API details remain available; source-derived UML is not sent to an external renderer.

The v5 reference uses actual phpDocumentor 3.10.0 and reads only `src/` through `phpdoc.dist.xml`. It does not bootstrap PHP or load vendor, configuration, credentials or runtime data. The existing Pages root is preserved; v5 publishes under `https://valgorithms.github.io/Civilizationbot/v5/`.

The source marker is `Civ13::VERSION = 5.0.0-dev`. The checker reads that marker
as text, requires the phpDocumentor version to match, and writes both `version`
and `release_status: development` into `source.json`. The `v5/` path identifies
the documentation series and does not imply a stable release. Its source commit
must match the intended publication; older manifests remain historical evidence.

Download the official `https://github.com/phpDocumentor/phpDocumentor/releases/download/v3.10.0/phpDocumentor.phar`. Check SHA256 `fe1e7c23ba3329aa6f19ac3c807446159a431a195ec5d9163b0c281a15105207` before execution. With PHP and Node installed:

```text
php phpDocumentor.phar --config phpdoc.dist.xml --no-interaction
node scripts/docs/finalize.mjs
node scripts/docs/verify.mjs EXACT_40_CHARACTER_SOURCE_COMMIT
```

The output checker validates local generated links/assets, required useful search symbols, publication boundaries and a `source.json` manifest. Build from a clean exact commit for publication; a successful local build does not prove the public site. The docs workflow pins the same PHAR hash and publishes only `v5/`, preserving other Pages contents. After publication verify public `source.json`, index, GameServer/OpenDreamControl API pages and search JS against the exact build; inspect actual search behavior separately from index presence.

Developer docs publication does not deploy the bot or game. The release ledger retains unfinished gameplay and service acceptance gates.

The separate `Offline API documentation` PR workflow checks out the exact PR
head and runs the pinned build/finalizer/checker on a standard Ubuntu runner
with read-only repository permission. It has no publication step. Its green
result establishes a source build and local generated-link/search checks;
public Pages publication and browser/readback acceptance require their own
receipt from the existing publication workflow.
