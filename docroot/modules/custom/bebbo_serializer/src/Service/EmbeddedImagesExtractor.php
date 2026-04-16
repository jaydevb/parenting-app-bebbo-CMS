<?php

namespace Drupal\bebbo_serializer\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;

/**
 * Extracts embedded image URLs from body HTML.
 *
 * Parses <drupal-media> tags (loads media entities by UUID, applies
 * the content_1200xh_ image style, converts to WebP) and also
 * extracts URLs from plain <img> tags as a fallback.
 */
class EmbeddedImagesExtractor {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Constructs an EmbeddedImagesExtractor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * Extract image URLs from drupal-media and img tags in body HTML.
   *
   * Parses <drupal-media> UUIDs, loads media entities, applies
   * content_1200xh_ image style, and converts to WebP. Also extracts
   * URLs from plain <img> tags for legacy content.
   *
   * @param string $html
   *   The raw body HTML content.
   *
   * @return string[]
   *   Array of image URLs (styled WebP for media, raw src for img tags).
   */
  public function extractImageUrls(string $html): array {
    if (empty($html)) {
      return [];
    }

    $hasDrupalMedia = strpos($html, '<drupal-media') !== FALSE;
    $hasImgTags = stripos($html, '<img') !== FALSE;

    if (!$hasDrupalMedia && !$hasImgTags) {
      return [];
    }

    $urls = [];

    // Extract from <drupal-media> tags first.
    if ($hasDrupalMedia) {
      $urls = $this->extractFromDrupalMedia($html);
    }

    // Extract from plain <img> tags as well.
    if ($hasImgTags) {
      $imgUrls = $this->extractFromImgTags($html);
      $urls = array_merge($urls, $imgUrls);
    }

    return $urls;
  }

  /**
   * Extract image URLs from <drupal-media> tags.
   *
   * @param string $html
   *   The raw body HTML content.
   *
   * @return string[]
   *   Array of styled WebP image URLs.
   */
  protected function extractFromDrupalMedia(string $html): array {
    // Extract UUIDs from <drupal-media> tags in body order.
    preg_match_all(
      '/data-entity-uuid="([a-f0-9\-]+)"/i',
      $html,
      $matches
    );

    // Preserve body order: keep first occurrence of each UUID.
    $orderedUuids = [];
    foreach ($matches[1] ?? [] as $uuid) {
      if (!isset($orderedUuids[$uuid])) {
        $orderedUuids[$uuid] = TRUE;
      }
    }

    if (empty($orderedUuids)) {
      return [];
    }

    // Load media entities by UUID.
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $mediaEntities = $mediaStorage->loadByProperties([
      'uuid' => array_keys($orderedUuids),
    ]);

    if (empty($mediaEntities)) {
      return [];
    }

    // Build UUID → entity map for ordered lookup.
    $uuidMap = [];
    foreach ($mediaEntities as $media) {
      if ($media instanceof MediaInterface) {
        $uuidMap[$media->uuid()] = $media;
      }
    }

    // Load image style once.
    $loadedStyle = $this->entityTypeManager
      ->getStorage('image_style')
      ->load('content_1200xh_');
    $imageStyle = $loadedStyle instanceof ImageStyle ? $loadedStyle : NULL;

    // Build URLs in the same order as they appear in the body.
    $urls = [];
    foreach (array_keys($orderedUuids) as $uuid) {
      $media = $uuidMap[$uuid] ?? NULL;
      if (!$media) {
        continue;
      }

      // Only process image media.
      if ($media->bundle() !== 'image' || !$media->hasField('field_media_image')) {
        continue;
      }

      $file = $media->get('field_media_image')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }

      $uri = $file->getFileUri();
      $styledUrl = $imageStyle
        ? $imageStyle->buildUrl($uri)
        : $this->fileUrlGenerator->generateAbsoluteString($uri);

      // Convert to WebP.
      $url = preg_replace(
        '/\.(jpg|jpeg|png)(\?.*)?$/i',
        '.webp$2',
        $styledUrl
      ) ?? $styledUrl;

      // Store as relative path (strip scheme + host).
      $url = $this->toRelativePath($url);

      $urls[] = $url;
    }

    return $urls;
  }

  /**
   * Extract image URLs from plain <img> tags.
   *
   * @param string $html
   *   The raw body HTML content.
   *
   * @return string[]
   *   Array of image URLs (relative paths).
   */
  protected function extractFromImgTags(string $html): array {
    preg_match_all(
      '/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
      $html,
      $matches
    );

    if (empty($matches[1])) {
      return [];
    }

    $urls = [];
    $seen = [];
    foreach ($matches[1] as $src) {
      // Skip duplicates.
      if (isset($seen[$src])) {
        continue;
      }
      $seen[$src] = TRUE;

      // Store as relative path (strip scheme + host).
      $url = $this->toRelativePath($src);

      // Convert jpg/jpeg/png (any case) to WebP, but preserve .gif as-is.
      $url = preg_replace(
        '/\.(jpg|jpeg|png)(\?.*)?$/i',
        '.webp$2',
        $url
      ) ?? $url;

      $urls[] = $url;
    }

    return $urls;
  }

  /**
   * Convert an absolute URL to a relative path.
   *
   * @param string $url
   *   The URL to convert.
   *
   * @return string
   *   The relative path portion of the URL.
   */
  protected function toRelativePath(string $url): string {
    $parsed = parse_url($url);
    if (!empty($parsed['path'])) {
      $result = $parsed['path'];
      if (!empty($parsed['query'])) {
        $result .= '?' . $parsed['query'];
      }
      return $result;
    }
    return $url;
  }

}
