#!/usr/bin/env bash
#
# Locate pass for the laravel-query-audit skill.
#
# Emits CANDIDATES, not findings. Every hit still has to be read and confirmed
# by the skill - this script exists so that reading budget is spent only where
# there might be something.
#
#   ./scan-queries.sh [path] [--json] [--rules Q-ALL,Q-LOOP-QUERY] [--quiet]
#
# Exit codes: 0 candidates found or none; 2 bad usage; 3 path is not a Laravel app.
#
set -euo pipefail

ROOT="."
FORMAT="text"
ONLY_RULES=""
QUIET=0

usage() {
    sed -n '3,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --json)  FORMAT="json"; shift ;;
        --rules) ONLY_RULES="${2:-}"; shift 2 ;;
        --quiet) QUIET=1; shift ;;
        -h|--help) usage 0 ;;
        -*) printf 'unknown option %s\n' "$1" >&2; usage 2 ;;
        *)  ROOT="$1"; shift ;;
    esac
done

[ -d "$ROOT" ] || { printf 'not a directory: %s\n' "$ROOT" >&2; exit 2; }

if [ ! -d "$ROOT/app" ] && [ ! -d "$ROOT/routes" ] && [ ! -d "$ROOT/resources" ]; then
    printf 'No app/, routes/ or resources/ under %s - is this a Laravel codebase?\n' "$ROOT" >&2
    exit 3
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ---------------------------------------------------------------------------
# File discovery. Vendor, build output, tests and seeders are excluded: a
# seeder calling Model::all() is doing its job.
# ---------------------------------------------------------------------------
collect() {
    find "$ROOT" \
        \( -path '*/vendor' -o -path '*/node_modules' -o -path '*/storage' \
           -o -path '*/bootstrap/cache' -o -path '*/public/build' \
           -o -path '*/tests' -o -path '*/database/seeders' \
           -o -path '*/database/factories' -o -path '*/.git' \) -prune -o \
        -type f -name "$1" -print 2>/dev/null | sort
}

collect '*.php' | grep -v '\.blade\.php$' > "$WORK/php.txt" || true
collect '*.blade.php'                     > "$WORK/blade.txt" || true

: > "$WORK/hits.tsv"

# ---------------------------------------------------------------------------
# PHP: statement-level rules with loop-depth tracking.
#
# Lines are joined into logical statements so that a chain broken across five
# lines is judged as one thing. Brace depth is tracked so that "inside a loop"
# means inside a loop, not merely after one.
# ---------------------------------------------------------------------------
read -r -d '' PHP_AWK <<'AWKEOF' || true
# POSIX ERE only - mawk has no \b. Word boundaries are spelled out.
function emit(rule, severity, msg,    snippet) {
    snippet = raw
    gsub(/[ \t]+/, " ", snippet)
    sub(/^ /, "", snippet); sub(/ $/, "", snippet)
    if (length(snippet) > 120) snippet = substr(snippet, 1, 117) "..."
    printf "%s\t%s\t%s\t%d\t%s\t%s\n", rule, severity, FILENAME, stmt_line, msg, snippet
}
function count_char(s, c,    n, i) {
    n = 0
    for (i = 1; i <= length(s); i++) if (substr(s, i, 1) == c) n++
    return n
}
# Blank out string literals so that braces and keywords inside them - notably
# "{$var}" interpolation - do not corrupt depth tracking or fire rules.
function strip_strings(s) {
    gsub(/"([^"\\]|\\.)*"/, "\"\"", s)
    gsub(/'"'"'([^'"'"'\\]|\\.)*'"'"'/, "''", s)
    return s
}
FNR == 1 { depth = 0; loops = 0; delete loop_at; stmt = ""; raw = ""; stmt_line = 0 }
{
    code = $0
    sub(/\/\/.*$/, "", code)
    if (code ~ /^[ \t]*\*/) code = ""
    if (code ~ /^[ \t]*#/) code = ""
    logic = strip_strings(code)

    # --- loop bookkeeping -------------------------------------------------
    opens  = count_char(logic, "{")
    closes = count_char(logic, "}")
    starts_loop = (logic ~ /(^|[^A-Za-z0-9_])(foreach|while|for)[ \t]*\(/ || \
                   logic ~ /->[ \t]*(each|map|filter|transform|eachById)[ \t]*\(/)

    depth = depth + opens - closes
    for (d in loop_at) if (d + 0 > depth) { delete loop_at[d]; loops-- }
    if (starts_loop && opens > 0) { loop_at[depth] = 1; loops++ }
    in_loop = (loops > 0)

    # --- statement accumulation -------------------------------------------
    if (stmt == "") { stmt_line = FNR; stmt = logic; raw = code }
    else            { stmt = stmt " " logic; raw = raw " " code }

    if (logic !~ /[;{}]/) next

    s = stmt
    stmt = ""

    if (s ~ /^[ \t]*$/) next

    # Q-ALL: Model::all() - the whole table, no pagination, no limit.
    if (s ~ /(^|[^A-Za-z0-9_$>])[A-Z][A-Za-z0-9_]*::all\(\)/)
        emit("Q-ALL", "high", "Model::all() loads the whole table; no pagination, no limit")

    # Q-UNBOUNDED-GET: ->get() with nothing narrowing it.
    if (s ~ /->get\(\)/ && s !~ /where|limit|take|paginate|find|chunk|cursor|lazy|scope|latest\(|oldest\(|forPage/)
        emit("Q-UNBOUNDED-GET", "medium", "->get() with no where, limit or pagination")

    # Q-LOOP-QUERY: a query executed inside a loop body.
    if (in_loop && (s ~ /->(get|first|firstOrFail|count|sum|exists|pluck|value)\(\)/ || \
                    s ~ /(^|[^A-Za-z0-9_])DB::table\(/ || \
                    s ~ /(^|[^A-Za-z0-9_$>])[A-Z][A-Za-z0-9_]*::(find|findOrFail|where|firstWhere)\(/))
        emit("Q-LOOP-QUERY", "high", "query executed inside a loop - one round trip per iteration")

    # Q-LOOP-RELATION: a relation chain read inside a loop body.
    if (in_loop && s ~ /\$[A-Za-z0-9_]+->[a-z][A-Za-z0-9_]*->[a-zA-Z]/ && s !~ /->count\(\)/)
        emit("Q-LOOP-RELATION", "high", "relation walked inside a loop - lazy loads once per iteration")

    # Q-COUNT-HYDRATE: counting a relation by loading every row of it.
    if (s ~ /->[a-z][A-Za-z0-9_]*->count\(\)/ || s ~ /count\([ \t]*\$[A-Za-z0-9_]+->[a-z]/)
        emit("Q-COUNT-HYDRATE", "medium", "counts a relation by hydrating it; withCount() does this in SQL")

    # Q-PHP-AGGREGATE: summing or grouping in PHP inside a loop.
    if (in_loop && s ~ /->/ && (s ~ /\+=/ || s ~ /\?\?[ \t]*0[ \t]*\)[ \t]*\+/))
        emit("Q-PHP-AGGREGATE", "medium", "aggregates in PHP what SQL can GROUP BY")
}
AWKEOF

if [ -s "$WORK/php.txt" ]; then
    # shellcheck disable=SC2046
    awk "$PHP_AWK" $(cat "$WORK/php.txt") >> "$WORK/hits.tsv" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Blade: relation chains walked inside a loop.
# ---------------------------------------------------------------------------
read -r -d '' BLADE_AWK <<'AWKEOF' || true
function emit(rule, severity, msg,    snippet) {
    snippet = $0
    gsub(/[ \t]+/, " ", snippet)
    gsub(/^ | $/, "", snippet)
    if (length(snippet) > 120) snippet = substr(snippet, 1, 117) "..."
    printf "%s\t%s\t%s\t%d\t%s\t%s\n", rule, severity, FILENAME, FNR, msg, snippet
}
FNR == 1 { loops = 0 }
{
    line = $0
    if (line ~ /\{\{--/) next                       # blade comment

    if (loops > 0 && line ~ /@(foreach|forelse)[ \t]*\([ \t]*\$[A-Za-z0-9_]+->[a-z]/) {
        emit("Q-BLADE-NESTED-LOOP", "medium", "nested loop over a relation of the outer row")
    }

    if (loops > 0 && line ~ /\$[A-Za-z0-9_]+->[a-z][A-Za-z0-9_]*->count\(\)/) {
        emit("Q-COUNT-HYDRATE", "high", "counts a relation inside a loop by hydrating every row")
    } else if (loops > 0 && line ~ /\$[A-Za-z0-9_]+->[a-z][A-Za-z0-9_]*->[a-z]/) {
        emit("Q-BLADE-RELATION", "high", "relation walked inside a loop - lazy loads once per row")
    }

    n = gsub(/@(foreach|forelse)[ \t]*\(/, "&")
    loops += n
    n = gsub(/@(endforeach|endforelse)/, "&")
    loops -= n
    if (loops < 0) loops = 0
}
AWKEOF

if [ -s "$WORK/blade.txt" ]; then
    # shellcheck disable=SC2046
    awk "$BLADE_AWK" $(cat "$WORK/blade.txt") >> "$WORK/hits.tsv" 2>/dev/null || true
fi

# ---------------------------------------------------------------------------
# Indexes: a column is worth flagging when it is unindexed AND either looks
# like a foreign key or is actually filtered/sorted on somewhere in the app.
# ---------------------------------------------------------------------------
: > "$WORK/used.txt"
if [ -s "$WORK/php.txt" ]; then
    xargs -a "$WORK/php.txt" grep -hoE "(->|::)(where|orWhere|whereIn|whereNot|orderBy|orderByDesc|latest|oldest|groupBy|having)\([\"'][A-Za-z0-9_]+[\"']" 2>/dev/null \
        | grep -oE "[\"'][A-Za-z0-9_]+[\"']$" | tr -d "\"'" | sort -u > "$WORK/used.txt" || true
fi

MIGRATIONS="$ROOT/database/migrations"
if [ -d "$MIGRATIONS" ]; then
    for file in "$MIGRATIONS"/*.php; do
        [ -f "$file" ] || continue

        grep -oE "\\\$table->(index|unique|primary)\(\[?[\"'][A-Za-z0-9_]+" "$file" 2>/dev/null \
            | grep -oE "[A-Za-z0-9_]+$" | sort -u > "$WORK/indexed.txt" || : > "$WORK/indexed.txt"

        grep -nE "\\\$table->[a-zA-Z]+\([\"'][A-Za-z0-9_]+[\"']" "$file" 2>/dev/null | while IFS= read -r entry; do
            lineno="${entry%%:*}"
            body="${entry#*:}"
            column="$(printf '%s' "$body" | grep -oE "\([\"'][A-Za-z0-9_]+[\"']" | head -1 | tr -d "(\"'")"
            [ -n "$column" ] || continue

            case "$body" in
                *'->index()'*|*'->unique()'*|*'->primary()'*|*'->constrained'*) continue ;;
            esac
            grep -qx "$column" "$WORK/indexed.txt" && continue

            reason=""
            case "$column" in
                *_id) reason="foreign key column with no index" ;;
            esac
            if [ -z "$reason" ] && grep -qx "$column" "$WORK/used.txt"; then
                reason="filtered or sorted on in application code, but not indexed"
            fi
            [ -n "$reason" ] || continue

            snippet="$(printf '%s' "$body" | sed 's/^[[:space:]]*//;s/[[:space:]]\{1,\}/ /g')"
            printf 'Q-MISSING-INDEX\tmedium\t%s\t%s\t%s\t%s\n' "$file" "$lineno" "$reason" "$snippet" >> "$WORK/hits.tsv"
        done
    done
fi

# ---------------------------------------------------------------------------
# Filter, sort, render.
# ---------------------------------------------------------------------------
if [ -n "$ONLY_RULES" ]; then
    awk -F'\t' -v wanted="$ONLY_RULES" '
        BEGIN { n = split(wanted, parts, ","); for (i = 1; i <= n; i++) keep[parts[i]] = 1 }
        $1 in keep
    ' "$WORK/hits.tsv" > "$WORK/filtered.tsv"
    mv "$WORK/filtered.tsv" "$WORK/hits.tsv"
fi

sort -t"$(printf '\t')" -k3,3 -k4,4n -o "$WORK/hits.tsv" "$WORK/hits.tsv"
TOTAL="$(wc -l < "$WORK/hits.tsv" | tr -d ' ')"

if [ "$FORMAT" = "json" ]; then
    awk -F'\t' -v root="$ROOT" '
        BEGIN { printf "{\n  \"root\": \"%s\",\n  \"candidates\": [\n", root }
        {
            gsub(/\\/, "\\\\", $6); gsub(/"/, "\\\"", $6)
            gsub(/\\/, "\\\\", $5); gsub(/"/, "\\\"", $5)
            if (NR > 1) printf ",\n"
            printf "    {\"rule\": \"%s\", \"severity\": \"%s\", \"file\": \"%s\", \"line\": %s, \"message\": \"%s\", \"snippet\": \"%s\"}",
                   $1, $2, $3, $4, $5, $6
        }
        END { printf "\n  ],\n  \"total\": %d\n}\n", NR }
    ' "$WORK/hits.tsv"
    exit 0
fi

if [ "$QUIET" -eq 1 ]; then
    cat "$WORK/hits.tsv"
    exit 0
fi

if [ "$TOTAL" -eq 0 ]; then
    printf '\nNo query candidates found under %s.\n\n' "$ROOT"
    exit 0
fi

printf '\n\033[1m%s query candidate(s)\033[0m under %s\n' "$TOTAL" "$ROOT"
printf '\033[2mCandidates, not findings. Read each one before reporting it.\033[0m\n'

awk -F'\t' '
    function colour(sev) {
        if (sev == "high") return "\033[31m"
        if (sev == "medium") return "\033[33m"
        return "\033[2m"
    }
    $3 != current {
        current = $3
        printf "\n\033[4m%s\033[0m\n", current
    }
    {
        printf "  %s%-8s\033[0m %-20s line %-5s %s\n", colour($2), $2, $1, $4, $5
        printf "           \033[2m%s\033[0m\n", $6
    }
' "$WORK/hits.tsv"

printf '\n\033[2mRule reference: references/n-plus-one-patterns.md, references/indexing-rules.md\033[0m\n\n'
