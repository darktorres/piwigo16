#!/usr/bin/env bash
# PostToolUse hook (Edit|Write matcher): soft reminders on newly-written
# content, both non-blocking (the write already happened) -- these are
# heuristics with real false-positive potential (limited to the diff/
# content actually visible to this hook, not full-file context), so they
# inject context for the model to weigh rather than failing the turn.
#
#   - a bare `putenv('VAR')` (no '=value', i.e. an unset) with no
#     `getenv(` capture visible in the same edited content -- recurred
#     across 5 waves in one session before being fixed; the risk is a
#     blind unset instead of save-then-restore, which wipes a real env
#     var process-wide for every LATER test sharing the same --parallel
#     worker, not just the test that did it.
#   - an edit to composer.json touching extra.patches -- composer's own
#     patches.lock.json gates whether a patch applies in both directions
#     and goes stale the moment extra.patches changes; the fix is
#     `composer patches-relock` (always) then `composer patches-repatch`
#     (when a patch was added/changed).

payload=$(cat)
file_path=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // empty')
[ -z "$file_path" ] && exit 0

content=$(printf '%s' "$payload" | jq -r '.tool_input.new_string // .tool_input.content // empty')
[ -z "$content" ] && exit 0

remind() {
    jq -n --arg reason "$1" '{
        hookSpecificOutput: {
            hookEventName: "PostToolUse",
            additionalContext: $reason
        }
    }'
}

case "$file_path" in
    *.php)
        if printf '%s' "$content" | grep -qE "putenv\('[A-Z_]+'\)" \
            && ! printf '%s' "$content" | grep -q 'getenv('; then
            remind "This edit adds a bare putenv('VAR') (no '=value', i.e. an unset) with no getenv( capture visible in the same change. If this is meant to simulate 'this env var isn't set' for a test, it must save the ORIGINAL value first (\$original = getenv('VAR')) and restore it in the matching afterEach/finally -- a blind unset wipes the var process-wide for every LATER test sharing the same --parallel worker, not just this one (recurred across 5 waves in one session before being fixed here). If getenv( already appears elsewhere in this file outside this diff, this reminder is a false positive -- ignore it."
        fi
        ;;
esac

case "$file_path" in
    */composer.json|composer.json)
        if printf '%s' "$content" | grep -q 'patches'; then
            remind "This edit to composer.json's extra.patches was just made. patches.lock.json (separate from composer.lock) gates whether a patch applies in both directions and goes stale the moment extra.patches changes -- a plain composer update <pkg> or reinstall won't pick up the change if the package's own version isn't moving. Run 'composer patches-relock' now, then 'composer patches-repatch' if a patch was added or its content changed."
        fi
        ;;
esac

exit 0
