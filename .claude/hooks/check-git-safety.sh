#!/usr/bin/env bash
# PreToolUse hook (Bash matcher): blocks recurring, memory-documented git
# safety mistakes on this project. All hard blocks (exit 2).
#
#   - `git stash` (any mutating subcommand: push/pop/apply/drop/clear/
#     branch, or no subcommand at all) run without the user having been
#     asked first. Recurred 6+ times ("this time it's safe because it's
#     scoped/brief" is exactly the trap -- there is no safe exception).
#     `git stash list`/`git stash show` (read-only) are NOT blocked.
#   - a commit subject whose first word (after a `type(scope): ` or
#     `type: ` prefix, or as the literal first word if no such prefix) is
#     capitalized/PascalCase/upper-case -- commitlint's subject-case rule
#     only checks the first word, but that's enough to fail the commit.
#   - an unescaped-looking `\$` inside a git-commit heredoc using a QUOTED
#     delimiter (`<<'EOF'`) -- in a quoted heredoc bash preserves `\$`
#     literally, shipping a stray backslash into the commit message.
#     Recurred twice in the same session even right after being named.

cmd=$(jq -r '.tool_input.command // empty')
[ -z "$cmd" ] && exit 0

block() {
    echo "BLOCKED: $1" >&2
    exit 2
}

segments() {
    printf '%s\n' "$cmd" | sed -E 's/(&&|\|\||;|\|)/\n/g'
}

while IFS= read -r seg; do
    [ -z "$seg" ] && continue
    trimmed=$(printf '%s' "$seg" | sed -E 's/^[[:space:]]+//')

    # git stash: block unless the subcommand is list/show (read-only).
    if printf '%s' "$trimmed" | grep -qE '^git[[:space:]]+stash([[:space:]]|$)'; then
        subcmd=$(printf '%s' "$trimmed" | awk '{print $3}')
        case "$subcmd" in
            list|show) ;;
            *)
                block "a 'git stash' call (subcommand: '${subcmd:-<none, defaults to push>}'). Standing rule: never git stash without asking the user first, even for a quick 'is this pre-existing' check, even when it looks scoped/brief -- there is no safe exception (recurred 6+ times). Use a non-mutating alternative instead: 'git show HEAD:path' / 'git diff HEAD -- path' to read old content, or 'git worktree add' for a genuinely clean-tree comparison. If a stash is genuinely needed, ask the user first."
                ;;
        esac
    fi

    # commit subject case: check the first -m value only (the subject).
    if printf '%s' "$trimmed" | grep -qE '^git[[:space:]]+commit\b'; then
        subject=$(printf '%s' "$trimmed" | grep -oP "(?<=-m[[:space:]]\")[^\"]*" | head -1)
        if [ -z "$subject" ]; then
            subject=$(printf '%s' "$trimmed" | grep -oP "(?<=-m[[:space:]]')[^']*" | head -1)
        fi
        if [ -n "$subject" ]; then
            after_prefix=$(printf '%s' "$subject" | sed -E 's/^[a-z]+(\([a-zA-Z0-9_-]+\))?!?:[[:space:]]*//')
            first_word=$(printf '%s' "$after_prefix" | awk '{print $1}')
            if printf '%s' "$first_word" | grep -qE '^[A-Z]'; then
                block "a commit subject whose first word ('$first_word') is capitalized. commitlint's subject-case rule only checks the FIRST WORD after 'type(scope): ' -- a subject starting with a capitalized/PascalCase/upper-case word fails the commit-msg hook (a PascalCase class name mid-subject is fine). Rephrase so the subject starts with a lowercase word and move the capitalized token later in the sentence."
            fi
        fi
    fi
done < <(segments)

# Heredoc \$ check: only for a git-commit heredoc using a quoted delimiter.
if printf '%s' "$cmd" | grep -qE 'git[[:space:]]+commit\b' && printf '%s' "$cmd" | grep -qE "<<[[:space:]]*'[A-Za-z_]+'"; then
    delim=$(printf '%s' "$cmd" | grep -oP "<<[[:space:]]*'\K[A-Za-z_]+(?=')" | head -1)
    if [ -n "$delim" ]; then
        body=$(printf '%s' "$cmd" | awk -v d="$delim" '
            found_start && $0 == d { exit }
            found_start { print }
            $0 ~ ("<<[[:space:]]*." d ".") { found_start = 1 }
        ')
        if printf '%s' "$body" | grep -qF '\$'; then
            block "a literal '\\\$' inside a quoted heredoc (<<'$delim') feeding a git commit message. A quoted heredoc delimiter already disables variable expansion, so bash preserves '\\\$' literally -- the backslash ships into the commit message as noise. Write \$_SESSION / \$obj->x plainly inside <<'$delim'; reserve backslash-escaping for the unquoted <<EOF form, which shouldn't be used for commit messages anyway."
        fi
    fi
fi

exit 0
