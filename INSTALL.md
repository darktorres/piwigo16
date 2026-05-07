# Installing Piwigo

## Tarball install (end-users)

The release tarball ships with `vendor/` pre-populated for **production dependencies only** — no phpstan, rector, phpunit, or pint. You do not need Composer to run Piwigo from the tarball.

1. Download and unpack the tarball.
2. Point your web server at the unpacked directory.
3. Navigate to `index.php?/install` in your browser and follow the wizard.

## Developer clone

A fresh `git clone` requires Composer to install all dependencies (including dev tools):

```bash
git clone https://github.com/darktorres/Piwigo.git
cd Piwigo
composer install
```

For production-only deps (no dev tools, classmap-authoritative):

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

## Building a release tarball

```bash
git archive --format=tar.gz HEAD -o piwigo-release.tar.gz
```

`git archive` applies the `.gitattributes` `export-ignore` rules automatically, so the
tarball excludes `tests/`, `docs/`, `tools/`, `.github/`, and dev-only `vendor/` entries
(`phpstan`, `rector`, `phpunit`, `laravel/pint`, `symfony/process`).

Verify the tarball excludes dev tooling before shipping:

```bash
tar -tzf piwigo-release.tar.gz | grep -E '^vendor/(phpstan|rector|phpunit|laravel)/' && echo FAIL || echo OK
```

## Requirements

- PHP 8.5+
- Extensions: `mysqli`, `mbstring`, `gd`, `exif`
- MariaDB 10.3+ or MySQL 8.0+
- A writable `_data/` directory at the gallery root (created automatically on first run)
