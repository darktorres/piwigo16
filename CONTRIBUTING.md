# Contributing

## Style

PHP code follows **PSR-12**, enforced by [Laravel Pint](https://laravel.com/docs/pint) with the project rules in `pint.json` (`single_quote`, `ordered_imports`, `no_unused_imports`, `trailing_comma_in_multiline`).

Format the entire tree:

```bash
vendor/bin/pint
```

Format only files changed since the last commit (fast, recommended while iterating):

```bash
vendor/bin/pint --dirty
```

Check without rewriting (this is what CI runs):

```bash
vendor/bin/pint --test
```

CI rejects any push or pull request that fails `vendor/bin/pint --test`.

### Optional pre-commit hook

Drop this in `.git/hooks/pre-commit` and `chmod +x` it to format staged work before each commit:

```bash
#!/bin/sh
exec vendor/bin/pint --dirty
```

### Editor setup

`.editorconfig` at the repo root pins LF line endings, UTF-8, 4-space PHP indentation, and trailing-whitespace trimming. Most editors pick this up automatically; if yours doesn't, install the EditorConfig plugin.
