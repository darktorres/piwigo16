<img src="https://piwigo.org/plugins/piwigo-piwigodotorg/images/piwigo.org.svg" width="200" alt="Piwigo logo">

Manage your photo library. Piwigo is open source photo gallery software for the web. Designed for organisations, teams and individuals.

![screenshot](https://piwigo.org/screenshots/github-screenshot-2.10.jpg)

The [piwigo.org](https://piwigo.org) website introduces you to Piwigo. You'll find a demo, forums, wiki and news.

## Requirements

- A webserver (Apache or nginx recommended)
- PHP 7.4+. Piwigo can run with PHP 7.0+ but these end-of-life versions are no longer maintained and may expose your site to security vulnerabilities.
- MySQL 5 or greater or MariaDB equivalent
- ImageMagick (recommended) or PHP GD

## Quick start install

### NetInstall

- Download the [NetInstall script](https://piwigo.org/download/dlcounter.php?code=netinstall)
- Transfer the script to your web space with any FTP client
- Open the script in you web browser (for example <http://example.com/piwigo-netinstall.php>) and follow the steps

[More information](https://piwigo.org/guides/install/netinstall)

### Manual

- Download the [latest stable version](https://piwigo.org/download/dlcounter.php?code=latest) and unzip it
- Transfer everything to your web space with any FTP client
- Open your website (for example <http://example.com/piwigo>) and follow the steps

[More information](https://piwigo.org/guides/install/manual)

If you do not have your own server, consider the [piwigo.com](https://piwigo.com/) hosting solution.

## Configuration

Configuration values live in the `conf` database table and are read through typed
accessors on `Piwigo\Config\Config`. The full key list is in
[`docs/config-reference.md`](docs/config-reference.md) (285 keys, generated from
`Config::SCHEMA`).

### Database credentials via .env

Fresh installs write `PIWIGO_DB_*` to a `.env` file at the repository root.
`Piwigo\Config\ConfigLoader` reads it on every request and applies the values
to `$conf` before the DB connection is opened.

```bash
# .env (gitignored; written by install.php on a fresh install)
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
`local/.installed`, touched by `install.php` after a successful fresh install.

## Contributing

Piwigo is widely driven by its community; if you want to improve the code, fork this repo and submit your changes to the `master` branch. See our [Contribution guide](https://github.com/Piwigo/Piwigo/blob/master/docs/CONTRIBUTING.md).

## License

Piwigo is released under the GPL v2 license. See our [Copying details](https://github.com/Piwigo/Piwigo/blob/master/COPYING.txt).
