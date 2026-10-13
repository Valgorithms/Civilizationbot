# CivilizationBot working guide

Read the relevant skill in [.agents/skills/CATALOG.md](.agents/skills/CATALOG.md) before changing its runtime boundary. Work from this repository, preserve intentional Composer path repositories, and use isolated worktrees for concurrent work.

Tests must use temporary storage and injected Discord, HTTP, file and process effects. Do not bootstrap `bot.php`, load `.env`, register commands, launch game servers or send Discord messages for offline verification. Separate local credentials and destinations from the official installation; a verification replica is never authority.

Feature branches have corresponding PRs. Check the exact head, actual tests and configuration migration before release. Developer documentation publication has a separate workflow from bot deployment; a merged PR or published API site does not activate the bot.
