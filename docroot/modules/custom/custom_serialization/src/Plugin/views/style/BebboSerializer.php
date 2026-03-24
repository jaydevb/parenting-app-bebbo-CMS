<?php

namespace Drupal\custom_serialization\Plugin\views\style;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\file\FileInterface;
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
      'weekly_overview_export'  => $this->transformPregnancyWeekly($rows),
      'guide_rest_export'       => $this->transformGuide($rows),
      'vaccination_rest_export' => $this->transformVaccinations($rows),
      default => $rows,
    };
  }

  /**
   * Transforms rows for the pregnancy weekly overview display.
   *
   * Resolves media fields to WebP URLs, casts numeric fields, and converts
   * related_articles to a deduplicated int array.
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

    $this->resolveMediaToWebp($rows, ['featured_image_1', 'featured_image_2']);

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id', 'prental_age']);
      $this->castToFloat($row, ['average_height', 'average_weight']);
      $this->toIntArray($row, ['related_articles']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the guide REST export display.
   *
   * Casts id to int and converts multi-value reference fields to int arrays.
   * Removes related_articles when empty.
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

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id']);
      $this->toIntArray($row, ['child_age', 'related_articles', 'related_games']);

      // Remove related_articles when empty (display-specific rule).
      if (empty($row['related_articles'])) {
        unset($row['related_articles']);
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the vaccination REST export display.
   *
   * Casts numeric fields to int, converts pinned_article to a deduplicated
   * int array, and decodes HTML entities in title.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformVaccinations(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id', 'growth_period', 'pinned_video_article', 'old_calendar', 'pinned_article']);
      $this->toIntArray($row, ['related_articles']);
      $this->decodeHtmlEntities($row, ['title']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Casts the given row fields to integers.
   *
   * Missing or empty values default to 0.
   *
   * @param array $row
   *   A single row (passed by reference).
   * @param array $fields
   *   Field names to cast.
   */
  private function castToInt(array &$row, array $fields): void {
    foreach ($fields as $field) {
      $row[$field] = (int) ($row[$field] ?? 0);
    }
  }

  /**
   * Rounds the given row fields to floats with a fixed number of decimals.
   *
   * Missing or empty values default to 0.0.
   *
   * @param array $row
   *   A single row (passed by reference).
   * @param array $fields
   *   Field names to cast.
   * @param int $decimals
   *   Number of decimal places (default 2).
   */
  private function castToFloat(array &$row, array $fields, int $decimals = 2): void {
    foreach ($fields as $field) {
      $row[$field] = round((float) ($row[$field] ?? 0), $decimals);
    }
  }

  /**
   * Converts row fields to deduplicated integer arrays.
   *
   * Handles both raw arrays (raw_output:true) and comma-separated strings
   * (raw_output:false) from the Views row plugin.
   *
   * @param array $row
   *   A single row (passed by reference).
   * @param array $fields
   *   Field names to convert.
   */
  private function toIntArray(array &$row, array $fields): void {
    foreach ($fields as $field) {
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

  /**
   * Decodes HTML entities in the given row fields.
   *
   * @param array $row
   *   A single row (passed by reference).
   * @param array $fields
   *   Field names to decode.
   */
  private function decodeHtmlEntities(array &$row, array $fields): void {
    foreach ($fields as $field) {
      if (!empty($row[$field])) {
        $row[$field] = htmlspecialchars_decode((string) $row[$field], ENT_QUOTES | ENT_HTML5);
      }
    }
  }

  /**
   * Resolves media ID fields to styled WebP URLs across all rows.
   *
   * Batch-collects media IDs, loads entities in a single query, builds
   * an ID-to-URL map with the content_1200xh_ image style, and replaces
   * each media ID with the corresponding absolute WebP URL.
   *
   * @param array $rows
   *   All rows (passed by reference).
   * @param array $fields
   *   Field names containing media IDs.
   */
  private function resolveMediaToWebp(array &$rows, array $fields): void {
    // 1. Collect every media ID across all rows in one pass.
    $mediaIds = array_unique(array_filter(
      array_merge(...array_map(
        fn($row) => array_map(fn($f) => $row[$f] ?? NULL, $fields),
        $rows
      )),
      'is_numeric'
    ));

    if (empty($mediaIds)) {
      return;
    }

    // 2. Load image style once; fallback to raw URL if missing.
    $loadedStyle = $this->entityTypeManager
      ->getStorage('image_style')
      ->load('content_1200xh_');
    $imageStyle = $loadedStyle instanceof ImageStyle ? $loadedStyle : NULL;

    // 3. Batch-load media entities and build ID → WebP URL map.
    $urlMap = [];
    foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($mediaIds) as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }
      $file = $media->get('field_media_image')->entity;
      if ($file instanceof FileInterface) {
        $uri = $file->getFileUri();
        $styledUrl = $imageStyle
          ? $imageStyle->buildUrl($uri)
          : $this->fileUrlGenerator->generateAbsoluteString($uri);
        $urlMap[$media->id()] = preg_replace(
          '/\.(jpg|jpeg|png)(\?.*)?$/i',
          '.webp$2',
          $styledUrl
        ) ?? $styledUrl;
      }
    }

    // 4. Replace media IDs with URLs in each row.
    foreach ($rows as &$row) {
      foreach ($fields as $field) {
        $row[$field] = $urlMap[(int) ($row[$field] ?? 0)] ?? NULL;
      }
    }
    unset($row);
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
