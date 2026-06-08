#!/usr/bin/env bash
#
# Group module migration orchestrator (Drupal 11 upgrade).
#
# Drives ONE step of the Group migration for ONE site, with a DB backup and a
# pre/post membership snapshot so the run is reversible and verifiable. The
# actual entity-data migration (group_content -> group_relationship) is done by
# Group's own update hooks via `drush updb`; this wrapper makes that safe:
# backup -> snapshot -> updb -> cache rebuild -> verify.
#
# It does NOT run `composer require` — bump the version in composer.json once,
# commit, then run this per site (each site has its own DB).
#
# Usage:
#   scripts/group_migration/migrate_group.sh <site> [step]
#     site : ddev site alias suffix (default|bangladesh|turkey|ecuador|
#            pacific_islands|somoa|zimbabwe)
#     step : 1to2 (default) | 2to3   — label only, for backup/snapshot naming
#
# Rollback:
#   scripts/group_migration/migrate_group.sh <site> --rollback <backup.sql.gz>
#
# Examples:
#   scripts/group_migration/migrate_group.sh bangladesh 1to2
#   scripts/group_migration/migrate_group.sh bangladesh --rollback artifacts/group_migration/bangladesh_1to2_pre.sql.gz

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ARTIFACTS="$REPO_ROOT/artifacts/group_migration"
# drush runs INSIDE the DDEV container; the repo mounts at /var/www/html, so any
# path the snapshot script WRITES must be container-absolute (host reads it back
# through the mount). See baseline_all_sites.sh for the same handling.
CONTAINER_ARTIFACTS="/var/www/html/artifacts/group_migration"
SNAPSHOT_PHP="scripts/group_migration/group_membership_snapshot.php"

SITE="${1:-}"
if [[ -z "$SITE" ]]; then
  echo "ERROR: site required. Usage: $0 <site> [step|--rollback file]" >&2
  exit 2
fi
DRUSH="ddev drush @ddev.${SITE}"

mkdir -p "$ARTIFACTS"

# --- Rollback path --------------------------------------------------------.
if [[ "${2:-}" == "--rollback" ]]; then
  DUMP="${3:-}"
  if [[ -z "$DUMP" || ! -f "$DUMP" ]]; then
    echo "ERROR: --rollback needs an existing dump file." >&2
    exit 2
  fi
  echo ">> ROLLBACK ${SITE} from ${DUMP}"
  read -r -p "Drop current DB for ${SITE} and restore ${DUMP}? [y/N] " ok
  [[ "$ok" == "y" || "$ok" == "Y" ]] || { echo "aborted."; exit 1; }
  gunzip -c "$DUMP" | $DRUSH sql-cli
  $DRUSH cr
  echo ">> rollback complete. Now also: git checkout composer.json composer.lock && ddev composer install"
  exit 0
fi

STEP="${2:-1to2}"
PRE_DUMP="$ARTIFACTS/${SITE}_${STEP}_pre.sql.gz"
# The pre-migration reference is the baseline captured on the OLD Group version
# (by baseline_all_sites.sh). We do NOT snapshot here: once the new Group code is
# installed, the entity type is `group_relationship` but its table doesn't exist
# until `updb` runs, so a live pre-snapshot is impossible. The baseline IS the
# pre-state, and verify() is version-agnostic (keys on gid:target_type:target_id).
BASELINE="$ARTIFACTS/${SITE}_baseline.json"
CBASELINE="$CONTAINER_ARTIFACTS/${SITE}_baseline.json"

if [[ ! -s "$BASELINE" ]]; then
  echo "!! No baseline snapshot at $BASELINE — run baseline_all_sites.sh on the" >&2
  echo "   OLD Group version first. Aborting before updb." >&2
  exit 1
fi
echo ">> [ref] Pre-migration reference = ${BASELINE}"

echo ">> [1/4] Backup DB for ${SITE} -> ${PRE_DUMP}"
$DRUSH sql-dump 2>/dev/null | gzip > "$PRE_DUMP"
if [[ ! -s "$PRE_DUMP" ]]; then
  echo "!! Backup dump is empty — aborting before updb." >&2
  exit 1
fi

echo ">> [2/4] Run database updates (Group entity migration runs here)"
$DRUSH updb -y

echo ">> [3/4] Rebuild cache"
$DRUSH cr

echo ">> [4/4] Verify memberships preserved (post-migration vs baseline)"
# `drush scr` always returns exit 1, which would trip `set -e` on assignment;
# tolerate it and gate on the GM-RESULT sentinel instead.
VERIFY_OUT="$($DRUSH scr "$SNAPSHOT_PHP" -- verify "$CBASELINE" 2>&1)" || true
echo "$VERIFY_OUT" | grep -E '^\[verify\]' || true
if echo "$VERIFY_OUT" | grep -q '^GM-RESULT: PASS'; then
  echo ">> SUCCESS: ${SITE} ${STEP} migration verified."
  echo "   Next: export ONLY group config YAML, run regression checklist, then cim."
else
  echo "!! VERIFY FAILED for ${SITE}. Membership/content drift detected." >&2
  echo "$VERIFY_OUT" >&2
  echo "!! Rollback: $0 ${SITE} --rollback ${PRE_DUMP}" >&2
  exit 1
fi
