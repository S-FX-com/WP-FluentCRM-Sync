# Claude Code Instructions – WP-FluentCRM-Sync

## Plugin Version

**Increment the plugin version before every commit.** Both locations must be updated together:

1. The `Version:` header in `fluentcrm-wp-sync.php` (line ~6)
2. The `FCRM_WP_SYNC_VERSION` constant in `fluentcrm-wp-sync.php` (line ~19)

### Versioning scheme (semver)

| Change type | Bump |
|---|---|
| Bug fix | Patch — `1.1.1` → `1.1.2` |
| New feature, backward-compatible | Minor — `1.1.x` → `1.2.0` |
| Breaking change | Major — `1.x.x` → `2.0.0` |

Current version: **1.5.2**

### Example workflow

```bash
# 1. Edit code
# 2. Bump version in fluentcrm-wp-sync.php (header + constant)
# 3. Stage everything including fluentcrm-wp-sync.php
git add fluentcrm-wp-sync.php <other changed files>
# 4. Commit
git commit -m "..."
# 5. Push
git push -u origin <branch>
```

### Releases are automatic

When a PR is merged to `main`, the GitHub Actions workflow
`.github/workflows/release.yml` reads the `Version:` header and automatically
creates a tagged GitHub Release (e.g. `v1.5.1`) if one doesn't exist yet.
**No manual release creation is needed.**

The WordPress auto-updater in the plugin calls the GitHub
`/releases/latest` API, which only sees full (non-pre-release, non-draft)
releases. The workflow always creates full releases, so the updater will
pick up new versions as soon as the PR lands on `main`.

## Git branch

Always develop on `claude/add-field-mapping-support-kBFeU` (or the branch
specified in the current session's system prompt). Never push to `main` directly.

## Code conventions

- PHP 7.4+ syntax; WordPress coding standards
- All user-facing strings must be wrapped in `esc_html_e()` / `esc_html__()`
- AJAX handlers: always call `check_ajax_referer()` + `current_user_can( 'manage_options' )`
- Never use `echo` for unescaped output
