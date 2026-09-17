#!/usr/bin/env bash
#
# Install the laravel-audit-skills into the agent of your choice.
#
#   ./install.sh                      # Claude Code, personal scope (~/.claude/skills)
#   ./install.sh --target project     # current repo (.claude/skills) - commit to share with your team
#   ./install.sh --agent codex        # Codex CLI (~/.codex/skills)
#   ./install.sh --agent cursor       # Cursor (~/.cursor/skills)
#   ./install.sh --agent gemini       # Gemini CLI (~/.gemini/skills)
#   ./install.sh --agent opencode     # OpenCode (~/.config/opencode/skills)
#   ./install.sh --skill laravel-query-audit
#   ./install.sh --dry-run
#   ./install.sh --uninstall
#
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/skills"

AGENT="claude"
TARGET="personal"
ONLY_SKILL=""
DRY_RUN=0
UNINSTALL=0
FORCE=0

die() { printf '\033[31merror\033[0m  %s\n' "$*" >&2; exit 1; }
info() { printf '  %s\n' "$*"; }
ok() { printf '\033[32m  ok\033[0m    %s\n' "$*"; }

usage() {
    sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 0
}

while [ $# -gt 0 ]; do
    case "$1" in
        --agent)     AGENT="${2:-}"; shift 2 ;;
        --target)    TARGET="${2:-}"; shift 2 ;;
        --skill)     ONLY_SKILL="${2:-}"; shift 2 ;;
        --dry-run)   DRY_RUN=1; shift ;;
        --uninstall) UNINSTALL=1; shift ;;
        --force)     FORCE=1; shift ;;
        -h|--help)   usage ;;
        *)           die "unknown option '$1' (try --help)" ;;
    esac
done

case "$AGENT" in
    claude)   PERSONAL="$HOME/.claude/skills";            PROJECT=".claude/skills" ;;
    codex)    PERSONAL="$HOME/.codex/skills";             PROJECT=".codex/skills" ;;
    cursor)   PERSONAL="$HOME/.cursor/skills";            PROJECT=".cursor/skills" ;;
    gemini)   PERSONAL="$HOME/.gemini/skills";            PROJECT=".gemini/skills" ;;
    opencode) PERSONAL="$HOME/.config/opencode/skills";   PROJECT=".opencode/skills" ;;
    *) die "unknown agent '$AGENT' (claude, codex, cursor, gemini, opencode)" ;;
esac

case "$TARGET" in
    personal) DEST="$PERSONAL" ;;
    project)  DEST="$PROJECT" ;;
    *) die "unknown target '$TARGET' (personal, project)" ;;
esac

[ -d "$SRC_DIR" ] || die "no skills/ directory next to install.sh"

skills=()
if [ -n "$ONLY_SKILL" ]; then
    [ -d "$SRC_DIR/$ONLY_SKILL" ] || die "no such skill '$ONLY_SKILL'"
    skills=("$ONLY_SKILL")
else
    for d in "$SRC_DIR"/*/; do
        [ -f "${d}SKILL.md" ] && skills+=("$(basename "$d")")
    done
fi

[ ${#skills[@]} -gt 0 ] || die "no skills found to install"

printf '\n\033[1mlaravel-audit-skills\033[0m -> %s (%s, %s scope)\n\n' "$DEST" "$AGENT" "$TARGET"

if [ "$UNINSTALL" -eq 1 ]; then
    for s in "${skills[@]}"; do
        if [ -d "$DEST/$s" ]; then
            [ "$DRY_RUN" -eq 1 ] && info "would remove $DEST/$s" || { rm -rf "${DEST:?}/$s"; ok "removed $s"; }
        else
            info "not installed: $s"
        fi
    done
    printf '\nDone.\n'
    exit 0
fi

for s in "${skills[@]}"; do
    if [ -d "$DEST/$s" ] && [ "$FORCE" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
        info "skipped $s (already installed - pass --force to overwrite)"
        continue
    fi
    if [ "$DRY_RUN" -eq 1 ]; then
        info "would install $s -> $DEST/$s"
        continue
    fi
    mkdir -p "$DEST"
    rm -rf "${DEST:?}/$s"
    cp -R "$SRC_DIR/$s" "$DEST/$s"
    find "$DEST/$s/scripts" -name '*.sh' -exec chmod +x {} + 2>/dev/null || true
    ok "installed $s"
done

if [ "$DRY_RUN" -eq 1 ]; then
    printf '\nDry run - nothing was written.\n\n'
    exit 0
fi

cat <<'DONE'

Installed. Try it:

  "audit this codebase for N+1 queries"
  "run a security audit on app/Http/Controllers"
  "this export runs out of memory - find the problem"

DONE
