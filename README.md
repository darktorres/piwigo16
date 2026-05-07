<img src="https://piwigo.org/plugins/piwigo-piwigodotorg/images/piwigo.org.svg" width="200" alt="Piwigo logo">

Manage your photo library. Piwigo is open source photo gallery software for the web. Designed for organisations, teams and individuals.

![screenshot](https://piwigo.org/screenshots/github-screenshot-2.10.jpg)

The [piwigo.org](https://piwigo.org) website introduces you to Piwigo. You'll find a demo, forums, wiki and news.

## Requirements

- A webserver (Apache or nginx recommended)
- PHP 8.5+
- MariaDB 10.3+ or MySQL 8.0+
- ImageMagick (recommended) or PHP GD

## Install

See [INSTALL.md](INSTALL.md) for tarball, dev-clone, and release-build instructions.

## Configuration

Configuration values live in the `conf` database table and are read through typed
accessors on `Piwigo\Config\Config`. The full key list is in
[`docs/CONFIG-REFERENCE.md`](docs/CONFIG-REFERENCE.md) (287 keys, generated from
`Config::SCHEMA`).

### Database credentials via .env

Fresh installs write `PIWIGO_DB_*` to a `.env` file at the repository root.
`Piwigo\Config\ConfigLoader` reads it on every request and applies the values
to `$conf` before the DB connection is opened.

```bash
# .env (gitignored; written by index.php?/install on a fresh install)
PIWIGO_DB_HOST=db.example.com
PIWIGO_DB_USER=piwigo
PIWIGO_DB_PASSWORD=secret
PIWIGO_DB_BASE=piwigo
PIWIGO_DB_PREFIX=piwigo_
```

`.env.local` is reserved for the test runner — it must NOT hold runtime DB
credentials, since the test suite drops and recreates `PIWIGO_DB_BASE`.

### Install detection

`Piwigo\Core\InstallSentinel::isInstalled()` is the authoritative answer to
"is Piwigo installed on this filesystem?". The signal is an empty stamp file at
`local/.installed`, touched by `index.php?/install` after a successful fresh install.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for code style, dependency, and config-schema guidelines.

## License

Piwigo is released under the GPL v2 license. See [COPYING.txt](COPYING.txt) and [LICENSE.txt](LICENSE.txt).
