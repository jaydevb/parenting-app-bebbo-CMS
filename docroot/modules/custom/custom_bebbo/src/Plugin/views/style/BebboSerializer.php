<?php

namespace Drupal\custom_bebbo\Plugin\views\style;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\rest\Plugin\views\style\Serializer;
use Drupal\rest\Plugin\views\display\RestExport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Views style plugin that wraps Bebbo REST export rows in a standard envelope.
 *
 * Response shape:
 * @code
 * {
 *   "status":   200,
 *   "total":    <count of view results>,
 *   "langcode": "<language from URL arg or current language>",
 *   "datetime": "<Y-m-d H:i in UTC>",
 *   "data":     [ ...rows transformed by BebboEncoder... ]
 * }
 * @endcode
 *
 * Row transformation (media resolution, type casting) is delegated to
 * BebboEncoder via Symfony's Serializer component, keeping the two
 * responsibilities cleanly separated.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "bebbo_serializer",
 *   title = @Translation("Bebbo serializer"),
 *   help = @Translation("Wraps Bebbo REST export data in a standard envelope with status, total, langcode, and datetime."),
 *   display_types = {"data"}
 * )
 */
class BebboSerializer extends Serializer {

  /**
   * The current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

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
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    SerializerInterface $serializer,
    array $serializer_formats,
    array $serializer_format_providers,
    CurrentPathStack $current_path,
    RequestStack $request_stack,
    LanguageManagerInterface $language_manager,
    EntityTypeManagerInterface $entity_type_manager,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $serializer,
      $serializer_formats,
      $serializer_format_providers,
    );
    $this->currentPath = $current_path;
    $this->requestStack = $request_stack;
    $this->languageManager = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('serializer'),
      $container->getParameter('serializer.formats'),
      $container->getParameter('serializer.format_providers'),
      $container->get('path.current'),
      $container->get('request_stack'),
      $container->get('language_manager'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render(): string {
    // Generate timestamp once — used by both the results and
    // no-results code paths. Matches CustomSerializer's
    // helper->getCurrentTimestamp('Asia/Kolkata').
    $timestamp = (new DrupalDateTime('now', 'Asia/Kolkata'))->format('Y-m-d H:i');

    // Collect rows via the parent row plugin.
    $rows = [];
    foreach ($this->view->result as $rowIndex => $row) {
      $this->view->row_index = $rowIndex;
      $rendered = $this->view->rowPlugin->render($row);
      // Normalise Drupal Markup objects → plain PHP values.
      $rows[] = json_decode(json_encode($rendered), TRUE);
    }
    unset($this->view->row_index);

    // Early return when there are no results.
    // Skips expensive work (media batch load, entity count query).
    // Returns a minimal envelope — no total, langcode, or data to report.
    if (empty($rows)) {
      return $this->serializer->serialize(
        [
          'status'   => 204,
          'message'  => 'No Records Found',
          'datetime' => $timestamp,
        ],
        'json',
        ['views_style_plugin' => $this],
      );
    }

    // Has results path — transform rows then build the full envelope.
    // Dispatch by display ID so multiple displays under the same view
    // (bebbo_v2_apis) each get their own transformation.
    $rows     = $this->transformRows($this->view->current_display, $rows);
    $langcode = $this->resolveLangcode();
    $total    = $this->countPublishedNodes();

    return $this->serializer->serialize(
      [
        'status'   => 200,
        'total'    => $total,
        'langcode' => $langcode,
        'datetime' => $timestamp,
        'data'     => $rows,
      ],
      $this->getOutputFormat(),
      ['views_style_plugin' => $this],
    );
  }

  /**
   * Resolves the response language code.
   *
   * Checks view arguments first (URL path segment such as /en or /ro),
   * then falls back to the currently active site language.
   *
   * @return string
   *   A BCP-47 language tag, e.g. "en" or "ro".
   */
  private function resolveLangcode(): string {
    // View arguments carry URL path segments (set via contextual filter).
    if (!empty($this->view->args[0])) {
      $candidate = $this->view->args[0];
      if ($this->languageManager->getLanguage($candidate) !== NULL) {
        return $candidate;
      }
    }

    return $this->languageManager->getCurrentLanguage()->getId();
  }

  /**
   * Counts published nodes for the view's base content type.
   *
   * Falls back gracefully when the content type cannot be determined.
   *
   * @return int
   *   Total published node count for the content type.
   */
  private function countPublishedNodes(): int {
    // Derive the content type from the view's base table name.
    // Views built on "node_field_data" expose the bundle filter arg.
    // We look up the content type from the view's filter configuration.
    $bundle = $this->getBundleFromView();

    if (!$bundle) {
      // Cannot determine bundle — return the count of rendered rows instead.
      return count($this->view->result);
    }

    try {
      return (int) $this->entityTypeManager
        ->getStorage('node')
        ->getQuery()
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    catch (\Exception) {
      return count($this->view->result);
    }
  }

  /**
   * Reads the node bundle from the view's filter configuration.
   *
   * @return string|null
   *   The machine name of the content type, or NULL if undetermined.
   */
  private function getBundleFromView(): ?string {
    $filters = $this->view->display_handler->getOption('filters') ?? [];
    foreach ($filters as $filter) {
      if (($filter['field'] ?? '') === 'type' && !empty($filter['value'])) {
        return (string) array_key_first($filter['value']);
      }
    }
    return NULL;
  }

  /**
   * Dispatches row transformation to a display-specific method.
   *
   * Uses display ID (not view ID) so multiple REST export displays under
   * the same view (bebbo_v2_apis) can each have their own transformation.
   * Add a new case here for each new REST export display.
   *
   * @param string $displayId
   *   The machine name of the active view display.
   * @param array $rows
   *   Raw rows from the view row plugin.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformRows(string $displayId, array $rows): array {
    return match ($displayId) {
      'weekly_overview_export' => $this->transformPregnancyWeekly($rows),
      'guide_rest_export'      => $this->transformGuide($rows),
      // Add new display cases here for each new REST export display.
      default => $rows,
    };
  }

  /**
   * Transforms rows for the pregnancy_weekly_overview view.
   *
   * - Resolves featured_image_1 / featured_image_2 media IDs to absolute
   *   WebP URLs via a single batch entity load (no N+1 queries).
   * - Casts prental_age to int.
   * - Casts average_height / average_weight to 2-decimal float strings.
   * - Converts related_articles comma string to a deduplicated int array.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformPregnancyWeekly(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    $mediaFields = ['featured_image_1', 'featured_image_2'];

    // 1. Collect every media ID across all rows in one pass.
    $mediaIds = array_unique(array_filter(
      array_merge(...array_map(
        fn($row) => array_map(fn($f) => $row[$f] ?? NULL, $mediaFields),
        $rows
      )),
      'is_numeric'
    ));

    // 2. Batch-load all media entities once → build ID → WebP URL map.
    // Load image style once (same style used by CustomSerializer).
    // buildUrl() produces the resized derivative URL; the WebP module then
    // generates the .webp file alongside it on first request.
    // Falls back to the raw file URL if the image style is missing.
    $loadedStyle = $this->entityTypeManager
      ->getStorage('image_style')
      ->load('content_1200xh_');
    $imageStyle = $loadedStyle instanceof ImageStyle ? $loadedStyle : NULL;

    $urlMap = [];
    if ($mediaIds) {
      foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($mediaIds) as $media) {
        if (!$media instanceof MediaInterface) {
          continue;
        }
        $file = $media->get('field_media_image')->entity;
        if ($file) {
          $uri = $file->getFileUri();
          // Build the styled URL (e.g. /styles/content_1200xh_/public/…).
          // Fallback to raw absolute URL if image style is unavailable.
          $styledUrl = $imageStyle
            ? $imageStyle->buildUrl($uri)
            : $this->fileUrlGenerator->generateAbsoluteString($uri);
          // Convert jpg/jpeg/png → .webp
          // (mirrors CustomSerializerHelper::convertToWebp).
          $urlMap[$media->id()] = preg_replace('/\.(jpg|jpeg|png)(\?.*)?$/i', '.webp$2', $styledUrl) ?? $styledUrl;
        }
      }
    }

    // 3. Apply per-row field transformations.
    foreach ($rows as &$row) {
      // Media ID → WebP URL. NULL when media is missing or unpublished.
      foreach ($mediaFields as $field) {
        $row[$field] = $urlMap[(int) ($row[$field] ?? 0)] ?? NULL;
      }

      $row['id']             = (int) ($row['id'] ?? 0);
      $row['prental_age']    = (int) ($row['prental_age'] ?? 0);
      $row['average_height'] = round((float) ($row['average_height'] ?? 0), 2);
      $row['average_weight'] = round((float) ($row['average_weight'] ?? 0), 2);

      // raw_output:true on a multi-value entity reference field delivers an
      // array of target IDs directly. raw_output:false delivers a rendered
      // comma-separated string of labels. Handle both so the code is robust.
      $rawArticles = $row['related_articles'] ?? [];
      if (is_array($rawArticles)) {
        $articleIds = $rawArticles;
      }
      else {
        $articleIds = array_filter(
          array_map('trim', explode(',', (string) $rawArticles)),
          'is_numeric'
        );
      }
      $row['related_articles'] = array_values(array_unique(array_map('intval', $articleIds)));
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the guide_rest_export display.
   *
   * - Casts id to int.
   * - Converts child_age, related_articles, related_games to deduplicated
   *   int arrays. Handles both raw array (raw_output:true) and
   *   comma-separated string (raw_output:false) from the view row plugin.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformGuide(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    // Fields that must become deduplicated int arrays.
    $multiIntFields = ['child_age', 'related_articles', 'related_games'];

    foreach ($rows as &$row) {
      $row['id'] = (int) ($row['id'] ?? 0);

      foreach ($multiIntFields as $field) {
        $raw = $row[$field] ?? [];
        if (is_array($raw)) {
          $ids = $raw;
        }
        else {
          $ids = array_filter(
            array_map('trim', explode(',', (string) $raw)),
            'is_numeric'
          );
        }
        $row[$field] = array_values(array_unique(array_map('intval', $ids)));
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * Determines the serialization format for the current display.
   *
   * @return string
   *   Format string, e.g. "bebbo_json" or "json".
   */
  private function getOutputFormat(): string {
    if (!empty($this->view->live_preview)) {
      return 'json';
    }
    if ($this->displayHandler instanceof RestExport) {
      return $this->displayHandler->getContentType();
    }
    return !empty($this->options['formats']) ? reset($this->options['formats']) : 'json';
  }

}
