#!/usr/bin/env bash
#
# Pre-upgrade Group baseline for ALL Bebbo sites.
#
# For every site this script produces, under artifacts/group_migration/:
#   <site>_baseline.json          - version-agnostic snapshot (groups, memberships,
#                                    per-row relations) from group_membership_snapshot.php
#   <site>_group_tables_pre.sql.gz - raw dump of ALL group* tables (restore source)
#
# It then TRIPLE-CHECKS the snapshot against the raw database so a miscount is
# impossible to miss:
#   relations: entity-API total  ==  raw SQL COUNT(*)  ==  per-row array length
#   members:   snapshot count     ==  raw SQL membership rows
#   groups:    snapshot count     ==  raw SQL COUNT(*) FROM groups
#
# Any mismatch => that site is marked FAIL and the script exits non-zero at the end.
# This is the PRE-upgrade baseline only (Group 1.x, table group_content_field_data).
#
# Usage:
#   scripts/group_migration/baseline_all_sites.sh                 # all sites
#   scripts/group_migration/baseline_all_sites.sh bebbo bangla    # subset

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
SNAP_PHP="scripts/group_migration/group_membership_snapshot.php"
OUT_DIR="$REPO_ROOT/artifacts/group_migration"

DEFAULT_SITES=(bebbo bangla ec pakistan tr ws zw)
SITES=("$@")
[ ${#SITES[@]} -eq 0 ] && SITES=("${DEFAULT_SITES[@]}")

mkdir -p "$OUT_DIR"
cd "$REPO_ROOT" || exit 2

# The repo root is mounted into DDEV at /var/www/html. drush runs INSIDE the
# container, so the snapshot file path it writes must be container-absolute;
# the host reads the same file back through the mount at $OUT_DIR.
CONTAINER_OUT="/var/www/html/artifacts/group_migration"

OVERALL=0
SUMMARY=()

for SITE in "${SITES[@]}"; do
  ALIAS="@ddev.$SITE"
  JSON="$OUT_DIR/${SITE}_baseline.json"
  CJSON="$CONTAINER_OUT/${SITE}_baseline.json"
  DUMP="$OUT_DIR/${SITE}_group_tables_pre.sql.gz"
  echo "================================================================"
  echo "SITE: $SITE  ($ALIAS)"
  echo "================================================================"

  # 1. Snapshot (entity-API view) -------------------------------------------
  # `drush scr` exit code is unreliable; gate on the script's sentinel line.
  SNAP_OUT="$(ddev drush "$ALIAS" scr "$SNAP_PHP" -- snapshot "$CJSON" 2>&1)"
  echo "$SNAP_OUT" | grep -E '^\[snapshot\]'
  if ! echo "$SNAP_OUT" | grep -q '^GM-RESULT: PASS'; then
    echo "  !! snapshot FAILED for $SITE"
    echo "$SNAP_OUT" | grep -iE 'error|warning' | head
    SUMMARY+=("$SITE: SNAPSHOT-FAILED")
    OVERALL=1
    continue
  fi

  # 2. Raw SQL dump of every group* table (restore source) -------------------
  TABLES="$(ddev drush "$ALIAS" sqlq "SHOW TABLES LIKE 'group%';" 2>/dev/null | tr -d '\r' | grep -v '^$' | paste -sd, -)"
  if [ -z "$TABLES" ]; then
    echo "  !! no group* tables found for $SITE"
    SUMMARY+=("$SITE: NO-TABLES")
    OVERALL=1
    continue
  fi
  if ! ddev drush "$ALIAS" sql-dump --tables-list="$TABLES" 2>/dev/null | gzip > "$DUMP"; then
    echo "  !! sql-dump FAILED for $SITE"
    SUMMARY+=("$SITE: DUMP-FAILED")
    OVERALL=1
    continue
  fi
  echo "  [dump] $(echo "$TABLES" | tr ',' '\n' | wc -l | tr -d ' ') tables -> $DUMP"

  # 3. Triple cross-check: snapshot vs raw DB --------------------------------
  # Independent raw-SQL counts (Group 1.x table names).
  # Base vs data: a healthy entity has one row in each. If they differ, the
  # base table holds orphaned relation shells (no field data) — a real DB
  # integrity problem, NOT a snapshot bug.
  SQL_BASE=$(ddev drush "$ALIAS" sqlq "SELECT COUNT(*) FROM group_content;" 2>/dev/null | tr -d '[:space:]')
  SQL_REL=$(ddev drush "$ALIAS" sqlq "SELECT COUNT(*) FROM group_content_field_data;" 2>/dev/null | tr -d '[:space:]')
  SQL_MEM=$(ddev drush "$ALIAS" sqlq "SELECT COUNT(*) FROM group_content_field_data WHERE type LIKE '%group_membership%';" 2>/dev/null | tr -d '[:space:]')
  SQL_GRP=$(ddev drush "$ALIAS" sqlq "SELECT COUNT(*) FROM groups;" 2>/dev/null | tr -d '[:space:]')

  # Snapshot counts (entity-API total + per-row array length).
  read -r J_REL J_ROWS J_MEM J_GRP < <(python3 - "$JSON" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
print(d.get("relation_total", -1),
      len(d.get("relations", [])),
      d.get("membership_count", -1),
      d.get("group_count", -1))
PY
)

  echo "  relations : entity-API=$J_REL  per-row=$J_ROWS  raw-SQL(data)=$SQL_REL  raw-SQL(base)=$SQL_BASE"
  echo "  members   : snapshot=$J_MEM  raw-SQL=$SQL_MEM"
  echo "  groups    : snapshot=$J_GRP  raw-SQL=$SQL_GRP"

  OK=1
  # entity-API and per-row must always agree (both read the entity layer).
  if [ "$J_REL" != "$J_ROWS" ]; then
    echo "  !! SNAPSHOT INTERNAL MISMATCH (entity-API != per-row)"; OK=0
  fi
  # Base table should equal data table on a healthy site.
  if [ "$SQL_BASE" != "$SQL_REL" ]; then
    echo "  !! DB INTEGRITY: group_content base=$SQL_BASE has $((SQL_BASE - SQL_REL)) orphan row(s) with no field_data"; OK=0
  fi
  # entity-API counts the base table, so it matching base (not data) is expected
  # when orphans exist; only flag if it matches NEITHER.
  if [ "$J_REL" != "$SQL_BASE" ] && [ "$J_REL" != "$SQL_REL" ]; then
    echo "  !! RELATION COUNT MISMATCH (entity-API agrees with neither base nor data)"; OK=0
  fi
  if [ "$J_MEM" != "$SQL_MEM" ]; then
    echo "  !! MEMBERSHIP COUNT MISMATCH"; OK=0
  fi
  if [ "$J_GRP" != "$SQL_GRP" ]; then
    echo "  !! GROUP COUNT MISMATCH"; OK=0
  fi

  if [ "$OK" -eq 1 ]; then
    echo "  [check] PASS  (rel=$J_REL members=$J_MEM groups=$J_GRP — all three sources agree)"
    SUMMARY+=("$SITE: PASS rel=$J_REL members=$J_MEM groups=$J_GRP")
  else
    echo "  [check] FAIL  (sources disagree — do NOT trust this baseline)"
    SUMMARY+=("$SITE: CHECK-FAILED")
    OVERALL=1
  fi
done

echo "================================================================"
echo "BASELINE SUMMARY"
echo "================================================================"
for line in "${SUMMARY[@]}"; do echo "  $line"; done
echo "artifacts: $OUT_DIR"
[ "$OVERALL" -eq 0 ] && echo "ALL SITES OK" || echo "SOME SITES FAILED — see above"
exit $OVERALL
