<?php

declare(strict_types=1);

namespace Drupal\bebbo_api_security\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Database operations for device registration, tokens, challenges, and logs.
 */
class DeviceRegistryService {

  /**
   * Constructs a DeviceRegistryService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly LoggerInterface $logger,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Insert a new device record.
   *
   * @return int
   *   The auto-increment ID of the inserted row.
   */
  public function registerDevice(array $fields): int {
    return (int) $this->database->insert('bebbo_api_devices')
      ->fields($fields)
      ->execute();
  }

  /**
   * Fetch a device by device_id.
   */
  public function getDevice(string $device_id): ?object {
    return $this->database->select('bebbo_api_devices', 'd')
      ->fields('d')
      ->condition('d.device_id', $device_id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * Fetch a device by Apple App Attest key ID.
   */
  public function getDeviceByAppleKeyId(string $key_id): ?object {
    return $this->database->select('bebbo_api_devices', 'd')
      ->fields('d')
      ->condition('d.apple_key_id', $key_id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * Update device fields.
   */
  public function updateDevice(string $device_id, array $fields): void {
    $this->database->update('bebbo_api_devices')
      ->fields($fields)
      ->condition('device_id', $device_id)
      ->execute();
  }

  /**
   * Set device status to revoked.
   */
  public function revokeDevice(string $device_id): void {
    $this->updateDevice($device_id, [
      'status' => 'revoked',
      'updated' => time(),
    ]);
  }

  /**
   * Insert a security event into the audit log.
   */
  public function logSecurityEvent(string $event_type, ?string $device_id, string $ip, ?array $details = NULL): void {
    $this->database->insert('bebbo_api_security_log')
      ->fields([
        'device_id' => $device_id,
        'event_type' => $event_type,
        'details' => $details ? json_encode($details) : NULL,
        'ip_address' => $ip,
        'created' => time(),
      ])
      ->execute();
  }

  /**
   * Purge expired challenges, revoked tokens, and old log entries.
   *
   * @return array
   *   Counts of purged rows keyed by type.
   */
  public function purgeExpired(): array {
    $now = time();
    $stats = [];

    $stats['challenges'] = $this->database->delete('bebbo_api_challenges')
      ->condition('expires', $now, '<')
      ->execute();

    $config = $this->configFactory->get('bebbo_api_security.settings');
    $retention_days = (int) $config->get('revoked_token_retention_days') ?: 7;
    $stats['tokens_revoked'] = $this->database->delete('bebbo_api_refresh_tokens')
      ->condition('revoked', 1)
      ->condition('created', $now - ($retention_days * 86400), '<')
      ->execute();

    $stats['tokens_expired'] = $this->database->delete('bebbo_api_refresh_tokens')
      ->condition('expires', $now, '<')
      ->execute();

    $max_entries = (int) $config->get('security_log_max_entries') ?: 10000;
    $max_id = $this->database->select('bebbo_api_security_log', 'l')
      ->fields('l', ['id'])
      ->orderBy('id', 'DESC')
      ->range($max_entries, 1)
      ->execute()
      ->fetchField();

    $stats['logs'] = 0;
    if ($max_id) {
      $stats['logs'] = $this->database->delete('bebbo_api_security_log')
        ->condition('id', $max_id, '<=')
        ->execute();
    }

    return $stats;
  }

}
