<?php

namespace Drupal\bebbo_serializer\Plugin\views\style;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\language_visibility_control\LanguageVisibilityService;
use Drupal\rest\Plugin\views\style\Serializer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Views style plugin for the V1 API performance port.
 *
 * Produces the same response envelope as the legacy custom_serialization
 * style, but reuses the V2 performance machinery (pre-computed fields, batch
 * media resolution, shared transform helpers). Unlike BebboSerializer (V2),
 * the output format is core "json" — NOT "bebbo_json" — so slashes and
 * unicode stay escaped, matching the byte-for-byte output the mobile app
 * already receives from the V1 endpoints.
 *
 * Response shape:
 * @code
 * {
 *   "status":   200,
 *   "total":    <count of view results>,
 *   "langcode": "<language from URL arg or current language>",
 *   "datetime": "<Y-m-d H:i>",
 *   "data":     [ ...rows transformed per display... ]
 * }
 * @endcode
 *
 * Per-display transformation is added incrementally as each V1 endpoint is
 * migrated (see docs/BEBBO_V1_API_AUDIT.md §9, Phases 1-7). Until a display
 * has a case in transformRows(), rows pass through unchanged.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "bebbo_v1_serializer",
 *   title = @Translation("Bebbo V1 serializer"),
 *   help = @Translation("Wraps V1 REST export data in the standard envelope using plain json (escaped slashes/unicode) for byte parity with the legacy V1 APIs."),
 *   display_types = {"data"}
 * )
 */
class BebboV1Serializer extends Serializer {

  use BebboSerializerHelpers;

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The language visibility service.
   *
   * @var \Drupal\language_visibility_control\LanguageVisibilityService
   */
  protected LanguageVisibilityService $languageVisibilityService;

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
    Connection $database,
    LanguageVisibilityService $language_visibility_service,
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
    $this->database = $database;
    $this->languageVisibilityService = $language_visibility_service;
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
      $container->get('database'),
      $container->get('language_visibility_control.service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render(): string {
    // Generate timestamp once — used by both the results and no-results
    // code paths. Matches CustomSerializer's
    // getCurrentTimestamp('Asia/Kolkata').
    $timestamp = (new DrupalDateTime('now', 'Asia/Kolkata'))->format('Y-m-d H:i');

    // Validate language visibility (skip for country-groups listing).
    $displayId = $this->view->current_display;
    if ($displayId !== 'v1_country_listing_rest_export') {
      $error = $this->checkLanguageVisibility();
      if ($error) {
        return $this->serializer->serialize(
          $error + ['datetime' => $timestamp],
          'json',
          ['views_style_plugin' => $this],
        );
      }
    }

    // Collect rows via the parent row plugin.
    $rows = [];
    foreach ($this->view->result as $rowIndex => $row) {
      $this->view->row_index = $rowIndex;
      $rendered = $this->view->rowPlugin->render($row);
      $rows[] = $this->normalizeMarkup($rendered);
    }
    unset($this->view->row_index);

    // Early return when there are no results — minimal envelope.
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

    // Has results path — transform rows then build the envelope. Dispatch by
    // display ID so multiple displays under bebbo_v1_apis each get their own
    // transformation.
    $rows     = $this->transformRows($displayId, $rows);
    $langcode = $this->resolveLangcode();

    // Taxonomy endpoints return {status, langcode, datetime, data} — no total.
    if ($displayId === 'v1_vocabulary_rest_export' || $displayId === 'v1_terms_rest_export') {
      return $this->serializer->serialize(
        [
          'status'   => 200,
          'langcode' => $langcode,
          'datetime' => $timestamp,
          'data'     => $rows,
        ],
        'json',
        ['views_style_plugin' => $this],
      );
    }

    // Standard deviation returns {status, langcode, data} — no total/datetime.
    if ($displayId === 'v1_standard_deviation_rest_export') {
      return $this->serializer->serialize(
        [
          'status'   => 200,
          'langcode' => $langcode,
          'data'     => $rows,
        ],
        'json',
        ['views_style_plugin' => $this],
      );
    }

    // Archive returns a grouped structure — total is the sum of all IDs.
    if ($displayId === 'v1_archive_rest_export') {
      $total = array_sum(array_map('count', $rows));
    }
    else {
      // Use the view's own total_rows (set by the pager during query
      // execution) so the count respects every applied filter. Falls back to
      // counting transformed rows when total_rows is unavailable.
      $total = (int) ($this->view->total_rows ?? count($rows));
    }

    return $this->serializer->serialize(
      [
        'status'   => 200,
        'total'    => $total,
        'langcode' => $langcode,
        'datetime' => $timestamp,
        'data'     => $rows,
      ],
      'json',
      ['views_style_plugin' => $this],
    );
  }

  /**
   * Validates that the requested language is visible in at least one group.
   *
   * @return array|null
   *   Error array with 'status' and 'message' keys, or NULL if valid.
   */
  private function checkLanguageVisibility(): ?array {
    $requested_language = $this->view->args[0] ?? $this->languageManager->getCurrentLanguage()->getId();

    $languages = array_keys($this->languageManager->getLanguages());
    if (!in_array($requested_language, $languages)) {
      return ['status' => 400, 'message' => 'Request language is wrong'];
    }

    $groups = $this->entityTypeManager
      ->getStorage('group')
      ->loadByProperties(['type' => 'country']);

    foreach ($groups as $group) {
      $visible = $this->languageVisibilityService->getVisibleLanguages($group);
      if (in_array($requested_language, $visible)) {
        return NULL;
      }
    }

    return ['status' => 403, 'message' => 'Language not available'];
  }

  /**
   * Dispatches row transformation to a display-specific method.
   *
   * A case is added here for each V1 endpoint as it is migrated. Displays
   * without a case pass rows through unchanged.
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
      'v1_articles_rest_export' => $this->transformArticles($rows),
      default => $rows,
    };
  }

  /**
   * Transforms rows for the V1 article REST export display.
   *
   * Mirrors the legacy V1 Article API output: casts numeric fields, converts
   * multi-value references to int arrays, normalizes embedded_images to a
   * string array, decodes HTML entities in the title, and assembles
   * cover_image from the view-provided {mid, url, name, alt} fields (with
   * WebP conversion). Unlike V2, it omits love_count/read_count/
   * target_audience and leaves do_not_feature as the raw string the legacy
   * API returned.
   *
   * @param array $rows
   *   Raw rows from the view row plugin.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformArticles(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    $request = $this->requestStack->getCurrentRequest();
    $emptyMedia = ['url' => '', 'name' => '', 'alt' => ''];

    foreach ($rows as &$row) {
      $this->castToInt($row, [
        'id', 'field_type_of_article', 'category', 'subcategory',
        'child_gender', 'parent_gender', 'premature',
      ]);
      $this->toIntArray($row, [
        'child_age', 'keywords', 'related_articles', 'related_video_articles',
      ]);
      $this->toStringArray($row, ['embedded_images']);
      $this->decodeHtmlEntities($row, ['title']);

      // Legacy V1 returned summary as plain text (all tags stripped) and an
      // empty meta_keywords as "" rather than a boolean false.
      if (array_key_exists('summary', $row)) {
        $row['summary'] = html_entity_decode(
          trim(strip_tags((string) $row['summary'])),
          ENT_QUOTES | ENT_HTML5,
          'UTF-8'
        );
      }
      $row['meta_keywords'] = (string) ($row['meta_keywords'] ?? '');

      $mid = (int) ($row['cover_image_mid'] ?? 0);
      $url = (string) ($row['cover_image_url'] ?? '');

      if ($url !== '') {
        $url = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url) ?? $url;
        if (!str_starts_with($url, 'http') && $request !== NULL) {
          $url = $request->getSchemeAndHttpHost() . $url;
        }
      }

      $row['cover_image'] = $mid > 0
        ? [
          'url' => $url,
          'name' => (string) ($row['cover_image_name'] ?? ''),
          'alt' => htmlspecialchars_decode((string) ($row['cover_image_alt'] ?? ''), ENT_QUOTES | ENT_HTML5),
        ]
        : $emptyMedia;

      unset($row['cover_image_mid'], $row['cover_image_url'], $row['cover_image_name'], $row['cover_image_alt']);
    }
    unset($row);

    return $rows;
  }

}
