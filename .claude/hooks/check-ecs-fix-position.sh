#!/usr/bin/env bash
# PreToolUse hook (Bash matcher): blocks any ecs/ecs-check invocation where
# --fix is not the LAST whitespace-separated token of its own command
# segment. Known upstream ECS CLI arg-parsing bug: any --flag immediately
# followed by a non-dash token is greedily consumed as that flag's value,
# so `ecs check --fix somefile.php` parses as --fix=somefile.php -- the
# path silently vanishes from the positional list and --fix never resolves
# to a clean boolean, so the fix is computed and printed (looks like a
# normal run) but never actually written to disk. Recurred 4+ times on
# this project despite being documented in memory each time.

cmd=$(jq -r '.tool_input.command // empty')
[ -z "$cmd" ] && exit 0

strip_trailing_redirections() {
    local s="$1"
    local prev=""
    while [ "$s" != "$prev" ]; do
        prev="$s"
        s=$(printf '%s' "$s" | sed -E 's/[[:space:]]+([0-9]*>{1,2}&[0-9]+|[0-9]*>{1,2}[[:space:]]*[^[:space:]]+|[0-9]*<[[:space:]]*[^[:space:]]+)[[:space:]]*$//')
    done
    printf '%s' "$s"
}

bad=0
while IFS= read -r seg; do
    [ -z "$seg" ] && continue
    # Only treat "ecs" as a real invocation when it's the command word
    # starting this segment (optionally after env-var assignments) --
    # NOT merely mentioned somewhere in the segment's text (e.g. inside a
    # commit message or comment quoting an example ecs command).
    if printf '%s' "$seg" | grep -qE '^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*[[:space:]]+)*(vendor/bin/ecs|ecs)([[:space:]]|$)' \
        && printf '%s' "$seg" | grep -q -- '--fix'; then
        trimmed=$(strip_trailing_redirections "$seg")
        last=$(printf '%s' "$trimmed" | awk '{print $NF}')
        if [ "$last" != "--fix" ]; then
            bad=1
        fi
    fi
done < <(printf '%s\n' "$cmd" | sed -E 's/(&&|\|\||;|\|)/\n/g')

if [ "$bad" = "1" ]; then
    echo "BLOCKED: --fix is not the last token of this ecs invocation. Known upstream ECS CLI bug: any --flag immediately followed by a non-dash token (like a file path) is greedily consumed as that flag's value, so '--fix somefile.php' parses as --fix=somefile.php -- the path silently vanishes and --fix never resolves to a boolean, so nothing gets written even though a diff is printed (looks like a normal run). Move --fix to the very end of the command, after every file/path argument, and retry." >&2
    exit 2
fi

exit 0
