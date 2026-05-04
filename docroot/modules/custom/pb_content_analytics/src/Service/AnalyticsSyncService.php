<?php

namespace Drupal\pb_content_analytics\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for syncing content analytics data from BigQuery.
 */
class AnalyticsSyncService {

  /**
   * Maps BigQuery content_type values to Drupal bundle names.
   */
  private const CONTENT_TYPE_MAP = [
    'article' => 'article',
    'video_article' => 'video_article',
    'course' => 'course',
    'activities' => 'activities',
  ];

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an AnalyticsSyncService.
   */
  public function __construct(
    ClientInterface $http_client,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
  ) {
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Returns TRUE if the analytics feature is enabled in settings.
   */
  public function isSyncEnabled(): bool {
    return (bool) $this->configFactory
      ->get('pb_content_analytics.settings')
      ->get('enabled');
  }

  /**
   * Returns TRUE if auto-sync (cron) is enabled in settings.
   */
  public function isAutoSyncEnabled(): bool {
    return (bool) $this->configFactory
      ->get('pb_content_analytics.settings')
      ->get('auto_sync_enabled');
  }

  /**
   * Returns the configured sync frequency: 'daily' or 'weekly'.
   */
  public function getSyncFrequency(): string {
    return $this->configFactory
      ->get('pb_content_analytics.settings')
      ->get('sync_frequency') ?: 'weekly';
  }

  /**
   * Fetches analytics data from the BigQuery HTTP endpoint.
   *
   * @return array<string, array<string, mixed>>
   *   Decoded JSON response keyed by node ID string.
   *
   * @throws \RuntimeException
   *   On HTTP or JSON decode failure.
   */
  public function fetchFromBigQuery(): array {
    $config = $this->configFactory->get('pb_content_analytics.settings');
    $url = $config->get('api_url');
    $api_key = $config->get('api_key');

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['X-API-Key' => $api_key],
        'timeout' => 30,
      ]);

      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
      }

      return is_array($data) ? $data : [];
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('BigQuery HTTP request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Processes a batch of analytics data, updating node field tables directly.
   *
   * @param array<string, array<string, mixed>> $data
   *   Node analytics keyed by node ID string.
   * @param callable|null $progressCallback
   *   Optional callback receiving [$processed, $total, $updated, $skipped].
   *
   * @return array{processed: int, updated: int, skipped: int}
   *   Sync counts.
   */
  public function processBatch(array $data, ?callable $progressCallback = NULL): array {
    $total = count($data);
    $processed = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($data as $nid_string => $item) {
      $nid = (int) $nid_string;

      // Map content type; skip unknown types.
      $api_type = $item['content_type'] ?? '';
      if (!isset(self::CONTENT_TYPE_MAP[$api_type])) {
        $skipped++;
        $processed++;
        if ($progressCallback) {
          ($progressCallback)([$processed, $total, $updated, $skipped]);
        }
        continue;
      }
      $bundle = self::CONTENT_TYPE_MAP[$api_type];

      // Verify nid exists with the expected bundle.
      $exists = $this->database->select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('n.nid', $nid)
        ->condition('n.type', $bundle)
        ->execute()
        ->fetchField();

      if (!$exists) {
        $skipped++;
        $processed++;
        if ($progressCallback) {
          ($progressCallback)([$processed, $total, $updated, $skipped]);
        }
        continue;
      }

      // Skip if BigQuery data is not newer than stored value.
      $stored_updated = $this->database->select('node__field_analytics_updated', 'au')
        ->fields('au', ['field_analytics_updated_value'])
        ->condition('au.entity_id', $nid)
        ->execute()
        ->fetchField();

      $bq_updated = $item['last_updated'] ?? '';
      $bq_ts = $bq_updated ? (int) strtotime($bq_updated) : 0;
      if ($stored_updated && $bq_ts <= (int) $stored_updated) {
        $skipped++;
        $processed++;
        if ($progressCallback) {
          ($progressCallback)([$processed, $total, $updated, $skipped]);
        }
        continue;
      }

      // Direct DB update on field tables.
      $this->updateNodeFieldTables($nid, (int) ($item['total_likes'] ?? 0), (int) ($item['total_reads'] ?? 0), $bq_updated);

      Cache::invalidateTags(['node:' . $nid]);

      $updated++;
      $processed++;
      if ($progressCallback) {
        ($progressCallback)([$processed, $total, $updated, $skipped]);
      }
    }

    return [
      'processed' => $processed,
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

  /**
   * Writes like count, read count, and analytics updated timestamp directly.
   */
  private function updateNodeFieldTables(int $nid, int $likes, int $reads, string $updated_value): void {
    // field_analytics_updated is a timestamp field — stores a Unix integer.
    // BigQuery returns ISO 8601 strings; convert to int before direct DB write.
    try {
      $updated_ts = (new \DateTime($updated_value, new \DateTimeZone('UTC')))->getTimestamp();
    }
    catch (\Exception $e) {
      $updated_ts = time();
    }

    $field_updates = [
      'node__field_like_count' => ['field_like_count_value' => $likes],
      'node__field_read_count' => ['field_read_count_value' => $reads],
      'node__field_analytics_updated' => ['field_analytics_updated_value' => $updated_ts],
    ];
    $revision_updates = [
      'node_revision__field_like_count' => ['field_like_count_value' => $likes],
      'node_revision__field_read_count' => ['field_read_count_value' => $reads],
      'node_revision__field_analytics_updated' => ['field_analytics_updated_value' => $updated_ts],
    ];

    foreach (array_merge($field_updates, $revision_updates) as $table => $fields) {
      // Upsert: update if row exists, insert otherwise.
      $exists = $this->database->select($table, 't')
        ->fields('t', ['entity_id'])
        ->condition('t.entity_id', $nid)
        ->execute()
        ->fetchField();

      if ($exists) {
        $this->database->update($table)
          ->fields($fields)
          ->condition('entity_id', $nid)
          ->execute();
      }
      else {
        // Non-translatable fields store one row per entity using the default
        // language langcode. Always insert with default_langcode = 1.
        $langcode = $this->database->select('node_field_data', 'n')
          ->fields('n', ['langcode'])
          ->condition('n.nid', $nid)
          ->condition('n.default_langcode', 1)
          ->execute()
          ->fetchField() ?: 'en';

        $vid = $this->database->select('node', 'n')
          ->fields('n', ['vid'])
          ->condition('n.nid', $nid)
          ->execute()
          ->fetchField();

        $insert_fields = array_merge($fields, [
          'bundle' => $this->getBundle($nid),
          'deleted' => 0,
          'entity_id' => $nid,
          'revision_id' => $vid ?: $nid,
          'langcode' => $langcode,
          'delta' => 0,
        ]);
        $this->database->insert($table)
          ->fields($insert_fields)
          ->execute();
      }
    }
  }

  /**
   * Retrieves the bundle (content type) for a given node ID.
   */
  private function getBundle(int $nid): string {
    return $this->database->select('node', 'n')
      ->fields('n', ['type'])
      ->condition('n.nid', $nid)
      ->execute()
      ->fetchField() ?: '';
  }

  /**
   * Logs a sync result row and prunes to the 30 most recent rows.
   *
   * @param array{processed: int, updated: int, skipped: int} $stats
   *   Sync counts.
   * @param string $triggered_by
   *   Either 'cron' or 'manual'.
   * @param string|null $error
   *   Error message if sync failed.
   */
  public function logSyncResult(array $stats, string $triggered_by, ?string $error = NULL): void {
    $this->database->insert('pb_analytics_sync_log')
      ->fields([
        'sync_time' => date('c'),
        'status' => $error ? 'failure' : 'success',
        'nodes_processed' => $stats['processed'] ?? 0,
        'nodes_updated' => $stats['updated'] ?? 0,
        'nodes_skipped' => $stats['skipped'] ?? 0,
        'error_message' => $error,
        'triggered_by' => $triggered_by,
      ])
      ->execute();

    // Prune to the 30 most recent rows.
    $ids = $this->database->select('pb_analytics_sync_log', 'l')
      ->fields('l', ['id'])
      ->orderBy('id', 'DESC')
      ->execute()
      ->fetchCol();

    if (count($ids) > 30) {
      $to_delete = array_slice($ids, 30);
      $this->database->delete('pb_analytics_sync_log')
        ->condition('id', $to_delete, 'IN')
        ->execute();
    }
  }

  /**
   * Returns the most recent sync log row, or NULL if none exists.
   *
   * @return array<string, mixed>|null
   *   Associative array of the log row, or NULL if no rows exist.
   */
  public function getLastSyncLog(): ?array {
    $row = $this->database->select('pb_analytics_sync_log', 'l')
      ->fields('l')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Returns the most recent successful sync log row, or NULL if none.
   *
   * @return array<string, mixed>|null
   *   Associative array of the log row, or NULL if no successful sync exists.
   */
  public function getLastSuccessfulSyncLog(): ?array {
    $row = $this->database->select('pb_analytics_sync_log', 'l')
      ->fields('l')
      ->condition('l.status', 'success')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

}
