#!/usr/bin/env bash
# PreToolUse hook (Bash matcher): soft reminder after `composer normalize`
# (non-dry-run). composer normalize sorts extra.patches's package keys,
# which changes composer.lock's content-hash -- the fix is `composer
# update --lock` (0 installs/updates/removals, only the content-hash line
# moves), never a plain `composer update` (would re-resolve dev-* pins).

cmd=$(jq -r '.tool_input.command // empty')
[ -z "$cmd" ] && exit 0

segments() {
    printf '%s\n' "$cmd" | sed -E 's/(&&|\|\||;|\|)/\n/g'
}

while IFS= read -r seg; do
    [ -z "$seg" ] && continue
    trimmed=$(printf '%s' "$seg" | sed -E 's/^[[:space:]]+//')
    if printf '%s' "$trimmed" | grep -qE '^composer[[:space:]]+normalize([[:space:]]|$)' \
        && ! printf '%s' "$trimmed" | grep -qE -- '--dry-run'; then
        jq -n '{
            hookSpecificOutput: {
                hookEventName: "PreToolUse",
                permissionDecision: "allow",
                permissionDecisionReason: "composer normalize sorts extra.patches package keys, which changes composer.lock content-hash -- composer validate will report the lock file as not up to date afterward. Follow with composer update --lock (0 installs/updates/removals expected, only the content-hash line moves), never a plain composer update.",
                additionalContext: "composer normalize sorts extra.patches package keys, which changes composer.lock content-hash -- composer validate will report the lock file as not up to date afterward. Follow with composer update --lock (0 installs/updates/removals expected, only the content-hash line moves), never a plain composer update."
            }
        }'
        exit 0
    fi
done < <(segments)

exit 0
