<?php

/**
 * @file
 * Snapshot & verify Group memberships/content across the Group 1.x -> 2.x/3.x
 * migration.
 *
 * The Group module's own update hooks (drush updb) perform the entity rename
 * group_content -> group_relationship and migrate stored data. This script does
 * NOT migrate data. It captures a verifiable, version-agnostic snapshot of:
 *   - every Group (gid, bundle, label, languages)
 *   - every membership relation (gid, member uid, group_roles)  <-- the user data
 *   - every content relation count per group+type
 * before migration, and re-checks the SAME facts after migration so a failed or
 * partial data migration is caught instead of silently breaking country access.
 *
 * It works on both Group 1.x (entity type "group_content") and 2.x/3.x
 * (entity type "group_relationship") by auto-detecting whichever exists.
 *
 * Usage:
 *   # BEFORE migration (on Group 1.x):
 *   ddev drush @ddev.SITE scr scripts/group_migration/group_membership_snapshot.php -- snapshot /tmp/group_snapshot_SITE.json
 *
 *   # AFTER migration (on Group 2.x/3.x):
 *   ddev drush @ddev.SITE scr scripts/group_migration/group_membership_snapshot.php -- verify   /tmp/group_snapshot_SITE.json
 *
 * Exit code: 0 = OK / match. 1 = drift or error (use in CI / migration gate).
 */

use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Resolve the active Group relation entity type id for this Group version.
 */
function gm_relation_entity_type_id(): string {
  $etm = \Drupal::entityTypeManager();
  if ($etm->hasDefinition('group_relationship')) {
    // Group 2.x / 3.x.
    return 'group_relationship';
  }
  if ($etm->hasDefinition('group_content')) {
    // Group 1.x.
    return 'group_content';
  }
  throw new \RuntimeException('Neither group_relationship nor group_content entity type exists. Is the Group module installed?');
}

/**
 * Build the snapshot of all groups, memberships and content relations.
 */
function gm_build_snapshot(): array {
  $etm = \Drupal::entityTypeManager();
  $relation_type = gm_relation_entity_type_id();

  $groups = [];
  $memberships = [];
  $content_counts = [];
  // Per-row record of EVERY relation (memberships + content), for exact
  // diffing and restore. Keyed list, version-agnostic.
  $relation_rows = [];
  $relation_total = 0;

  // 1. Groups.
  foreach ($etm->getStorage('group')->loadMultiple() as $group) {
    /** @var \Drupal\group\Entity\GroupInterface $group */
    if (!$group instanceof GroupInterface) {
      continue;
    }
    $languages = [];
    if ($group->hasField('field_language') && !$group->get('field_language')->isEmpty()) {
      $languages = array_column($group->get('field_language')->getValue(), 'value');
    }
    $groups[(int) $group->id()] = [
      'id' => (int) $group->id(),
      'bundle' => $group->bundle(),
      'label' => $group->label(),
      'languages' => $languages,
    ];
  }

  // 2. All relations (memberships + content), classified by target entity type.
  $relations = $etm->getStorage($relation_type)->loadMultiple();
  foreach ($relations as $relation) {
    $relation_total++;
    // getGroup() / getEntity() exist in both 1.x and 2.x/3.x.
    $group = method_exists($relation, 'getGroup') ? $relation->getGroup() : NULL;
    $target = method_exists($relation, 'getEntity') ? $relation->getEntity() : NULL;

    // Roles attached to this relation (only meaningful for memberships).
    $roles = [];
    if ($relation->hasField('group_roles') && !$relation->get('group_roles')->isEmpty()) {
      $roles = array_column($relation->get('group_roles')->getValue(), 'target_id');
      sort($roles);
    }

    if ($group === NULL || $target === NULL) {
      // Orphaned relation — record it so verify can flag it.
      $gid = $group ? (int) $group->id() : 0;
      $content_counts[$gid]['__orphan__'] = ($content_counts[$gid]['__orphan__'] ?? 0) + 1;
      $relation_rows[] = [
        'rid' => (int) $relation->id(),
        'gid' => $gid,
        'bundle' => $relation->bundle(),
        'target_type' => '__orphan__',
        'target_id' => 0,
        'langcode' => $relation->language()->getId(),
        'roles' => $roles,
      ];
      continue;
    }
    $gid = (int) $group->id();

    // Per-row record of this relation — the exact, restorable map.
    $relation_rows[] = [
      'rid' => (int) $relation->id(),
      'gid' => $gid,
      'bundle' => $relation->bundle(),
      'target_type' => $target->getEntityTypeId(),
      'target_id' => (int) $target->id(),
      'langcode' => $relation->language()->getId(),
      'roles' => $roles,
    ];

    if ($target instanceof UserInterface) {
      // Membership relation — this is the user data we must preserve.
      $memberships[] = [
        'gid' => $gid,
        'uid' => (int) $target->id(),
        'roles' => $roles,
      ];
    }
    else {
      // Content relation — count per group + target entity type.
      $key = $target->getEntityTypeId();
      $content_counts[$gid][$key] = ($content_counts[$gid][$key] ?? 0) + 1;
    }
  }

  // Stable ordering so two per-row snapshots diff cleanly.
  usort($relation_rows, function ($a, $b) {
    return [$a['gid'], $a['target_type'], $a['target_id'], $a['rid']]
      <=> [$b['gid'], $b['target_type'], $b['target_id'], $b['rid']];
  });

  // Stable ordering so two snapshots diff cleanly.
  usort($memberships, function ($a, $b) {
    return [$a['gid'], $a['uid']] <=> [$b['gid'], $b['uid']];
  });
  ksort($groups);
  ksort($content_counts);

  return [
    'relation_entity_type' => $relation_type,
    'group_count' => count($groups),
    'membership_count' => count($memberships),
    'relation_total' => $relation_total,
    'groups' => $groups,
    'memberships' => $memberships,
    'content_counts' => $content_counts,
    'relations' => $relation_rows,
  ];
}

/**
 * Write snapshot to a JSON file.
 */
function gm_cmd_snapshot(string $path): int {
  $snapshot = gm_build_snapshot();
  file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  printf(
    "[snapshot] entity=%s groups=%d memberships=%d relations=%d -> %s\n",
    $snapshot['relation_entity_type'],
    $snapshot['group_count'],
    $snapshot['membership_count'],
    $snapshot['relation_total'],
    $path
  );
  return 0;
}

/**
 * Compare a fresh snapshot against the saved one. Non-zero exit on any drift.
 */
function gm_cmd_verify(string $path): int {
  if (!is_file($path)) {
    fwrite(STDERR, "[verify] ERROR: baseline snapshot not found: $path\n");
    return 1;
  }
  $before = json_decode(file_get_contents($path), TRUE);
  $after = gm_build_snapshot();

  $errors = [];

  // Group count must match exactly.
  if ($before['group_count'] !== $after['group_count']) {
    $errors[] = sprintf('group count changed: %d -> %d', $before['group_count'], $after['group_count']);
  }

  // Membership count must match exactly.
  if ($before['membership_count'] !== $after['membership_count']) {
    $errors[] = sprintf('membership count changed: %d -> %d', $before['membership_count'], $after['membership_count']);
  }

  // Total relation count must match exactly.
  $before_total = $before['relation_total'] ?? NULL;
  if ($before_total !== NULL && $before_total !== $after['relation_total']) {
    $errors[] = sprintf('relation total changed: %d -> %d', $before_total, $after['relation_total']);
  }

  // Every (gid, uid) membership in the baseline must still exist, with the same roles.
  $index = function (array $memberships): array {
    $out = [];
    foreach ($memberships as $m) {
      $out[$m['gid'] . ':' . $m['uid']] = $m['roles'];
    }
    return $out;
  };
  $before_idx = $index($before['memberships']);
  $after_idx = $index($after['memberships']);

  foreach ($before_idx as $key => $roles) {
    if (!array_key_exists($key, $after_idx)) {
      $errors[] = "membership LOST: gid:uid=$key";
      continue;
    }
    if ($after_idx[$key] !== $roles) {
      $errors[] = sprintf('membership roles changed for %s: [%s] -> [%s]', $key, implode(',', $roles), implode(',', $after_idx[$key]));
    }
  }
  foreach ($after_idx as $key => $roles) {
    if (!array_key_exists($key, $before_idx)) {
      $errors[] = "membership ADDED (unexpected): gid:uid=$key";
    }
  }

  // Content relation counts per group must match.
  foreach ($before['content_counts'] as $gid => $types) {
    foreach ($types as $type => $count) {
      $now = $after['content_counts'][$gid][$type] ?? 0;
      if ($now !== $count) {
        $errors[] = "content count drift gid=$gid type=$type: $count -> $now";
      }
    }
  }

  // Per-row relation check (exact): every baseline relation must still exist,
  // keyed on the version-stable tuple gid:target_type:target_id. The 'rid' and
  // 'bundle' may change across the entity rename, so they are NOT part of the
  // key — only the actual group<->entity link and (for memberships) its roles.
  $row_index = function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
      $key = $r['gid'] . ':' . $r['target_type'] . ':' . $r['target_id'];
      // Multiple relations can share a key (rare); track count + roles.
      $out[$key]['count'] = ($out[$key]['count'] ?? 0) + 1;
      $out[$key]['roles'] = $r['roles'];
    }
    return $out;
  };
  $before_rows = $row_index($before['relations'] ?? []);
  $after_rows = $row_index($after['relations'] ?? []);
  foreach ($before_rows as $key => $info) {
    if (!isset($after_rows[$key])) {
      $errors[] = "relation LOST: $key";
      continue;
    }
    if ($after_rows[$key]['count'] !== $info['count']) {
      $errors[] = sprintf('relation count changed for %s: %d -> %d', $key, $info['count'], $after_rows[$key]['count']);
    }
    if ($after_rows[$key]['roles'] !== $info['roles']) {
      $errors[] = sprintf('relation roles changed for %s: [%s] -> [%s]', $key, implode(',', $info['roles']), implode(',', $after_rows[$key]['roles']));
    }
  }
  foreach ($after_rows as $key => $info) {
    if (!isset($before_rows[$key])) {
      $errors[] = "relation ADDED (unexpected): $key";
    }
  }

  if ($errors) {
    fwrite(STDERR, "[verify] FAIL (" . count($errors) . " issue(s)):\n");
    foreach ($errors as $e) {
      fwrite(STDERR, "  - $e\n");
    }
    return 1;
  }

  printf(
    "[verify] OK  entity %s -> %s, groups=%d memberships=%d relations=%d all preserved.\n",
    $before['relation_entity_type'],
    $after['relation_entity_type'],
    $after['group_count'],
    $after['membership_count'],
    $after['relation_total']
  );
  return 0;
}

// --- Entry point ---------------------------------------------------------.
// `drush scr` passes everything after `--` in $extra.
$args = $extra ?? [];
$command = $args[0] ?? '';
$path = $args[1] ?? '';

if (!in_array($command, ['snapshot', 'verify'], TRUE) || $path === '') {
  fwrite(STDERR, "Usage: drush scr group_membership_snapshot.php -- {snapshot|verify} /path/to/snapshot.json\n");
  // Note: `drush scr` does NOT propagate PHP exit codes — it reports status 1
  // for ANY exit(), including exit(0). So callers MUST parse the sentinel line
  // below ("GM-RESULT: PASS|FAIL") instead of trusting the drush exit status.
  print "GM-RESULT: FAIL\n";
  return;
}

try {
  $code = $command === 'snapshot' ? gm_cmd_snapshot($path) : gm_cmd_verify($path);
}
catch (\Throwable $e) {
  fwrite(STDERR, '[' . $command . '] ERROR: ' . $e->getMessage() . "\n");
  $code = 1;
}

// Machine-readable gate result. Callers grep this; the drush exit code is
// unreliable for `scr`.
print 'GM-RESULT: ' . ($code === 0 ? 'PASS' : 'FAIL') . "\n";
