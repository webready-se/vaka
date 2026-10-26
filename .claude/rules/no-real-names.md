# No real names in git or on GitHub

**The rule:** nothing that names a real person, customer, site, domain, server, IP, Slack channel, webhook or service account goes into this repository: not in code, tests, fixtures, docs, screenshots, commit messages, PR or issue titles and texts, comments or releases. Use the reserved examples (`shop.example`, `example.com`, `203.0.113.10`, "Customers", "Agency") instead.

**Why:** the repository will be public, and git keeps everything: a name in an old commit message or a PR text is as public as one in the code, and taking it out later means rewriting history. It slips in most easily in text written while looking at production data ("the dashboard said ... for <real domain>"): describe the case with example names instead.

**How it is enforced** (so it does not depend on remembering):

- `.private/real-names.txt` (gitignored) lists the real names: the sites from the real site list, servers, IPs, customers and services. Keep it up to date when sites or servers change.
- `scripts/hooks/pre-commit` stops a commit that adds a line with one of them; `scripts/hooks/commit-msg` stops a commit message with one. Both use `scripts/hooks/real-names`, and `composer install` turns them on (`core.hooksPath`).
- `.claude/hooks/no-real-names.sh` (PreToolUse) stops `gh pr|issue create/edit/comment/review` and `gh release create/edit` when the text, inline or in `--body-file`, contains one. Local paths in the command are not checked (they never reach GitHub); give `--body-file` a path without shell variables, so the hook can read it.
- Without `.private/real-names.txt` (a fresh clone, a contributor) nothing is checked: the list is what is private.

When a hook stops you, rewrite the text with example names. Never bypass it with `--no-verify`, and never weaken the list to get a commit through.
