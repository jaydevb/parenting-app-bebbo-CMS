<?php

namespace Drupal\custom_bebbo\Encoder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

/**
 * Encoder for the pregnancy_weekly_overview (and future Bebbo) REST exports.
 *
 * Responsible only for row-level data transformation:
 * - Resolves media IDs to absolute file URLs and converts to WebP.
 * - Casts string field values to their correct scalar types.
 *
 * The response wrapper (status / total / langcode / datetime) is handled by
 * BebboSerializer, which is the correct layer for that concern.
 *
 * NOTE: The encoder receives the full envelope array
 * {status, total, langcode, datetime, data:[...rows]}
 * built by BebboSerializer. Row transformation operates on envelope['data'].
 */
class BebboEncoder implements EncoderInterface {

  const FORMAT = 'bebbo_json';

  /**
   * Constructs a BebboEncoder instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function encode($data, string $format, array $context = []): string {
    $view = $context['views_style_plugin'] ?? NULL;
    $viewId = $view?->view?->id();

    return match ($viewId) {
      'pregnancy_weekly_overview' => $this->encodePregnancyWeekly($data),
      // Add a new case here for each new content type view.
      default => json_encode($data),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding(string $format): bool {
    return self::FORMAT === $format;
  }

  /**
   * Transforms rows for the pregnancy_weekly_overview view.
   *
   * Receives the full response envelope built by BebboSerializer and
   * transforms only the rows inside envelope['data'].
   *
   * @param array $envelope
   *   Full response envelope with a 'data' key containing raw view rows.
   *
   * @return string
   *   JSON-encoded envelope with transformed rows.
   */
  private function encodePregnancyWeekly(array $envelope): string {
    $rows = $envelope['data'] ?? [];

    if (!empty($rows)) {
      // Field names as output by the View (match exactly).
      $mediaFields = ['featured_image_1', 'featured_image_2'];

      // --- 1. Batch-collect all media IDs in a single pass ---
      $mediaIds = array_unique(array_filter(
        array_merge(...array_map(
          fn($row) => array_map(fn($f) => $row[$f] ?? NULL, $mediaFields),
          $rows
        )),
        'is_numeric'
      ));

      // --- 2. Batch-load media entities and resolve to absolute WebP URLs ---
      $urlMap = [];
      if ($mediaIds) {
        foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($mediaIds) as $media) {
          if (!$media instanceof MediaInterface) {
            continue;
          }
          $file = $media->get('field_media_image')->entity;
          if ($file) {
            $raw = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
            // Convert jpg/jpeg/png extension to .webp (matches CustomSerializer behaviour).
            $urlMap[$media->id()] = $this->toWebp($raw);
          }
        }
      }

      // --- 3. Transform each row ---
      foreach ($rows as &$row) {
        // Media ID → WebP URL (NULL when media is missing or unpublished).
        foreach ($mediaFields as $field) {
          $row[$field] = $urlMap[(int) ($row[$field] ?? 0)] ?? NULL;
        }

        // String → int  (field name matches the View field alias exactly).
        $row['prental_age']      = (int) ($row['prental_age'] ?? 0);

        // String → 2-decimal float string.
        $row['average_height']   = number_format((float) ($row['average_height'] ?? 0), 2, '.', '');
        $row['average_weight']   = number_format((float) ($row['average_weight'] ?? 0), 2, '.', '');

        // Comma-separated string → deduplicated int array.
        $row['related_articles'] = array_values(array_unique(array_map(
          'intval',
          array_filter(
            array_map('trim', explode(',', $row['related_articles'] ?? '')),
            'is_numeric'
          )
        )));
      }
      unset($row);

      $envelope['data'] = $rows;
    }

    return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Converts a jpg/jpeg/png image URL to its WebP equivalent.
   *
   * Mirrors the logic in CustomSerializerHelper::convertToWebp().
   *
   * @param string $url
   *   The original image URL.
   *
   * @return string
   *   URL with the extension replaced by .webp, or the original if no match.
   */
  private function toWebp(string $url): string {
    return preg_replace('/\.(jpg|jpeg|png)(\?.*)?$/i', '.webp$2', $url) ?? $url;
  }

}
