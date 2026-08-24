#!/usr/bin/env bash
# PreToolUse hook (Bash matcher): guards against several recurring,
# memory-documented tool-invocation mistakes on this project.
#
# HARD BLOCKS (exit 2 -- the command never runs):
#   - a `timeout N ...` wrapper on any command segment: the harness already
#     auto-backgrounds anything over ~120s, so a guessed external timeout
#     is dead weight at best and SIGKILLs a legitimately-long run at worst
#     (recurred 4+ times).
#   - a bare `vendor/bin/pest` invocation: this project's composer test:*
#     scripts run tools/reimport-fixture.sh first (fixture-dependent
#     suites need it to be self-contained/reproducible) -- a raw pest call
#     skips that and can pass/fail against stale DB/cache/asset state.
#   - a comma-joined `--exclude-group`/`--group` value: Pest's flag
#     doesn't reliably split commas across mixed tagging styles (a PHPUnit
#     attribute vs. a Pest fluent ->group() call) -- repeat the flag once
#     per group instead. Recurred, once destructively regenerating a
#     committed test fixture with nobody noticing.
#   - launching a second heavy verification tool (Psalm/PHPStan/ECS/
#     composer test*/pest) while one is still running: confirmed 7+ times
#     to silently under-report counts, cascade unrelated failures, or get
#     a process OOM-killed -- not just slower.
#
# SOFT WARNINGS (allowed through, with context injected back for the
# model to weigh -- these have legitimate exceptions the memory itself
# documents, so a hard block would be wrong some of the time):
#   - `phpstan analyse --level=N` when phpstan.neon already sets a level.
#   - `phpstan clear-result-cache` as a troubleshooting reflex (the one
#     confirmed-legitimate case is a composer extension add/remove).
#   - `git log --all`: this repo has a sibling 16.x-rewrite branch with
#     similar-sounding commits that are NOT ancestors of the current
#     branch -- a real false-positive risk when checking "did X land".

cmd=$(jq -r '.tool_input.command // empty')
[ -z "$cmd" ] && exit 0

block() {
    echo "BLOCKED: $1" >&2
    exit 2
}

warn() {
    jq -n --arg reason "$1" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: "allow",
            permissionDecisionReason: $reason,
            additionalContext: $reason
        }
    }'
    exit 0
}

# Split into segments on shell control operators for per-segment leading-word checks.
segments() {
    printf '%s\n' "$cmd" | sed -E 's/(&&|\|\||;|\|)/\n/g'
}

while IFS= read -r seg; do
    [ -z "$seg" ] && continue
    trimmed=$(printf '%s' "$seg" | sed -E 's/^[[:space:]]+//')

    if printf '%s' "$trimmed" | grep -qE '^timeout[[:space:]]'; then
        block "a 'timeout N' wrapper on a command segment. The harness already auto-backgrounds anything over ~120s; a guessed external timeout never fires under normal conditions (dead weight) and SIGKILLs a legitimately long-running command instead of letting it finish when it would fire. If this command is known to run long, rely on the harness's own auto-backgrounding (or pass run_in_background explicitly at the tool-call level) instead of a shell timeout wrapper."
    fi

    if printf '%s' "$trimmed" | grep -qE '(^|/)vendor/bin/pest([[:space:]]|$)'; then
        block "a raw vendor/bin/pest invocation. This project's composer test:*/test scripts run tools/reimport-fixture.sh first, which fixture-dependent suites need to be self-contained and reproducible (clears cache pools, combined CSS/JS bundles, regenerates fixture photos) -- calling pest directly skips all of that, so a pass/fail result may reflect stale DB/cache/asset state rather than current code. Check composer.json's scripts block for the matching test:* entry instead."
    fi

    if printf '%s' "$trimmed" | grep -qE -- '--(exclude-group|group)(=|[[:space:]])[^[:space:]]*,'; then
        block "a comma-joined --exclude-group/--group value. Pest does not reliably split commas across mixed tagging styles (a PHPUnit attribute vs. a Pest fluent ->group() call) -- confirmed to silently exclude zero of the intended groups in the worst case. Pass the flag once per group name instead (--exclude-group=a --exclude-group=b), never comma-joined."
    fi
done < <(segments)

# Concurrent heavy-tool check: look for an already-running process matching
# any of this project's heavy verification tools, SCOPED TO THIS WORKTREE
# (matched via the candidate process's own cwd, not just its command line) --
# a sibling worktree's own concurrent Psalm/PHPStan/etc run is that other
# session's business, not a reason to block work here. Uses real process
# state (not a lock file) so it correctly reflects backgrounded runs too.
project_dir=$(pwd -P)
if printf '%s' "$cmd" | grep -qE '(^|[[:space:]/])(vendor/bin/(psalm|phpstan|pest|ecs)|composer[[:space:]]+(test|analyse)(:[A-Za-z-]+)?)([[:space:]]|$)'; then
    running=$(pgrep -f 'vendor/bin/(psalm|phpstan|pest|ecs)\b|composer (test|analyse)(:[A-Za-z-]+)?\b' 2>/dev/null | while read -r pid; do
        [ "$pid" = "$$" ] && continue
        pid_cwd=$(readlink -f "/proc/$pid/cwd" 2>/dev/null)
        [ -z "$pid_cwd" ] && continue
        case "$pid_cwd" in
            "$project_dir"|"$project_dir"/*) ;;
            *) continue ;;
        esac
        cmdline=$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)
        [ -z "$cmdline" ] && continue
        echo "$pid $cmdline"
    done)
    if [ -n "$running" ]; then
        block "another heavy verification tool is already running in this worktree (matched: $(printf '%s' "$running" | head -1)). Running Psalm/PHPStan/ECS/composer test*/pest concurrently in the SAME worktree has repeatedly (7+ confirmed incidents) caused silent undercounting, cascades of unrelated failures, or an OOM-killed process -- not just slower runs. Wait for the running one to finish (its own completion notification, not a poll loop) before starting this one."
    fi
fi

if printf '%s' "$cmd" | grep -qE '(^|[[:space:]/])phpstan[[:space:]]+analyse[^|;&]*--level='; then
    warn "phpstan.neon already declares parameters.level -- passing --level=N on the CLI here is redundant (this project's own convention: never pass it when the config already sets it)."
fi

if printf '%s' "$cmd" | grep -qE '(^|[[:space:]/])phpstan[[:space:]]+clear-result-cache([[:space:]]|$)'; then
    warn "clear-result-cache is documented on this project as a troubleshooting reflex to avoid -- the one confirmed-legitimate case is right after a composer extension add/remove. For a scoped run that 'looks wrong', the usual real cause is analysing a PARTIAL file list (collector-based rules need the full configured paths: in one run) -- rerun with no path arguments first, don't clear the cache."
fi

if printf '%s' "$cmd" | grep -qE '(^|[[:space:]])git[[:space:]]+log[[:space:]]+(.*[[:space:]])?--all([[:space:]]|$)'; then
    warn "git log --all crosses into this repo's sibling 16.x-rewrite branch, which has its own commits on similar-sounding topics that are NOT ancestors of the current branch -- a real false-positive risk when checking whether specific work has landed. Prefer plain 'git log' (defaults to HEAD), or follow any --all hit with 'git merge-base --is-ancestor <sha> HEAD' before citing it as evidence."
fi

exit 0
