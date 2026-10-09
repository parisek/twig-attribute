# Contributing

Thank you for your help. This guide is short. [AGENTS.md](AGENTS.md) has the full project notes.

## Set up

```bash
composer install
```

PHP ^8.3 is required.

## Checks

CI runs these commands. Run them before you open a PR.

```bash
composer test                       # PHPUnit
composer phpstan                    # static analysis
composer cs                         # PER-CS dry run
composer validate --strict
composer audit --abandoned=report
composer normalize --dry-run
```

`composer cs` covers `AttributeExtension.php` and `tests/` only. The vendored Drupal classes in `src/` keep Drupal's 2-space style on purpose. Do not reformat them.

## Pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages and PR titles.
- Add an entry under `## [Unreleased]` in `CHANGELOG.md` for every change that affects behavior. Use the [Keep a Changelog](https://keepachangelog.com/) categories.
- Maintainers squash-merge PRs. The merge commit subject ends with `(#N)`.
- Do not stamp or tag a release by hand. The `Stamp Release` workflow does it. See "Release process" in `AGENTS.md`.

## Security

Do not report vulnerabilities in a public issue. Read [SECURITY.md](SECURITY.md).

## AI agents

Read [AGENTS.md](AGENTS.md) before you start.
