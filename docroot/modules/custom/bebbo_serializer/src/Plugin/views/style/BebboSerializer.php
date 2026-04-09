<?php

namespace Drupal\bebbo_serializer\Plugin\views\style;

use Drupal\Core\Database\Connection;
use Drupal\file\FileInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\group\Entity\Group;
use Drupal\language_visibility_control\LanguageVisibilityService;
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
    // Skips expensive work (media batch load).
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
    $displayId = $this->view->current_display;
    $rows      = $this->transformRows($displayId, $rows);
    $langcode  = $this->resolveLangcode();

    // Taxonomy endpoints return {status, langcode, datetime, data} — no total.
    if ($displayId === 'vocabulary_rest_export' || $displayId === 'terms_rest_export') {
      return $this->serializer->serialize(
        [
          'status'   => 200,
          'langcode' => $langcode,
          'datetime' => $timestamp,
          'data'     => $rows,
        ],
        $this->getOutputFormat(),
        ['views_style_plugin' => $this],
      );
    }

    // Standard deviation returns a non-standard envelope: {status, langcode,
    // data} — no total, no datetime.
    if ($displayId === 'standard_deviation_rest_export') {
      return $this->serializer->serialize(
        [
          'status'   => 200,
          'langcode' => $langcode,
          'data'     => $rows,
        ],
        $this->getOutputFormat(),
        ['views_style_plugin' => $this],
      );
    }

    // Archive returns a grouped structure — total is the sum of all IDs
    // across content types, not a single-bundle entity count.
    if ($displayId === 'archive_rest_export') {
      $total = array_sum(array_map('count', $rows));
    }
    else {
      // Use the view's own total_rows (set by the pager during query
      // execution). This respects every filter the view applies — language,
      // child_age, status, distinct — so it matches the actual data count.
      // Falls back to counting transformed rows if total_rows is unavailable.
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
      'weekly_overview_export'   => $this->transformPregnancyWeekly($rows),
      'guide_rest_export'        => $this->transformGuide($rows),
      'vaccination_rest_export'  => $this->transformVaccinations($rows),
      'country_listing_export'   => $this->transformCountryGroups($rows),
      'archive_rest_export'      => $this->transformArchive($rows),
      'activities_rest_export'   => $this->transformActivities($rows),
      'articles_rest_export'     => $this->transformArticles($rows),
      'faq_rest_export'          => $this->transformFaq($rows),
      'basic_page_rest_export'   => $this->transformBasicPages($rows),
      'home_screen_rest_export'  => $this->transformDailyHomeScreenMessages($rows),
      'standard_deviation_rest_export' => $this->transformStandardDeviation($rows),
      'milestones_rest_export' => $this->transformMilestones($rows),
      'child_dev_boy_rest_export'  => $this->transformChildDevelopment($rows),
      'child_dev_girl_rest_export' => $this->transformChildDevelopment($rows),
      'health_checkup_rest_export' => $this->transformHealthCheckUps($rows),
      'survey_rest_export'         => $this->transformSurveys($rows),
      'vocabulary_rest_export'     => $this->transformVocabularies($rows),
      'terms_rest_export'          => $this->transformTaxonomies($rows),
      'video_article_rest_export'  => $this->transformVideoArticles($rows),
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

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id', 'prental_age']);
      $this->castToFloat($row, ['average_height', 'average_weight']);
      $this->toIntArray($row, ['related_articles']);

      // Parse view → featured_image_1 and view_1 → featured_image_2
      // from the embedded media_details view JSON output {url, name, alt}.
      $row['featured_image_1'] = $this->parseViewCoverImage($row['featured_image_1'] ?? NULL);
      $row['featured_image_2'] = $this->parseViewCoverImage($row['featured_image_2'] ?? NULL);
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
   * Transforms rows for the activities REST export display.
   *
   * Casts numeric fields, converts multi-value references to int arrays,
   * resolves cover_image to {url, name, alt} with WebP, and decodes title.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformActivities(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, [
        'id', 'activity_category', 'equipment',
        'type_of_support', 'mandatory',
      ]);
      $this->toIntArray($row, ['child_age', 'related_milestone']);
      $this->toStringArray($row, ['embedded_images']);
      $this->decodeHtmlEntities($row, ['title']);

      // Parse view → cover_image ({url, name, alt}) from the embedded
      // media_details view (media_image_rest_export display) JSON output.
      $row['cover_image'] = $this->parseViewCoverImage($row['cover_image'] ?? NULL);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the articles REST export display.
   *
   * Batch-loads cover_image media to {url, name, alt} with the
   * content_1200xh_ image style and WebP conversion. Casts numeric fields,
   * converts multi-value references to int arrays, normalizes
   * field_embedded_images (pre-computed by the view) to a string array, and
   * cleans body/summary HTML (absolutises src paths, strips presentation
   * markup, decodes entities) without DOMDocument parsing.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformArticles(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

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

      // Parse view → cover_image ({url, name, alt}) from the embedded
      // media_details view (media_image_rest_export display) JSON output.
      $row['cover_image'] = $this->parseViewCoverImage($row['cover_image'] ?? NULL);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the video article display.
   *
   * Resolves cover_video to a video object {url, name, site} and
   * cover_video_image to a thumbnail {url, name, alt} (renamed to
   * cover_image to match v1 output). Casts numeric fields and converts
   * multi-value references to int arrays.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformVideoArticles(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, [
        'id', 'category', 'child_gender', 'parent_gender',
        'licensed', 'premature', 'mandatory',
      ]);
      $this->toIntArray($row, [
        'child_age', 'keywords', 'related_articles', 'related_video_articles',
      ]);
      $this->toStringArray($row, ['embedded_images']);
      $this->decodeHtmlEntities($row, ['title']);

      $row['cover_video'] = $this->parseViewVideoMedia($row['cover_video'] ?? NULL);
      $row['cover_image'] = $this->parseViewCoverImage($row['cover_image'] ?? NULL);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the FAQ display.
   *
   * Casts numeric fields to int and decodes HTML entities in the question
   * field. FAQs have no media, multi-value, or embedded image fields.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformFaq(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id', 'chatbot_subcategory', 'related_article', 'mandatory']);
      $this->decodeHtmlEntities($row, ['question']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the basic pages display.
   *
   * Casts numeric fields to int, converts embedded_images to a string array,
   * and decodes HTML entities in the title. No media fields or multi-value
   * int fields — embedded images are pre-computed in field_embedded_images.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformBasicPages(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id', 'mandatory']);
      $this->toStringArray($row, ['embedded_images']);
      $this->decodeHtmlEntities($row, ['title']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the daily home screen messages display.
   *
   * Casts id to int and decodes HTML entities in title. This is one of the
   * simplest endpoints — no media fields, no multi-value arrays, no embedded
   * images.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformDailyHomeScreenMessages(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id']);
      $this->decodeHtmlEntities($row, ['title']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the milestones display.
   *
   * Casts numeric fields to int, converts multi-value reference fields to
   * int arrays, and decodes HTML entities in the title.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformMilestones(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id', 'mandatory']);
      $this->toIntArray($row, [
        'child_age',
        'related_activities',
        'related_articles',
        'related_video_articles',
      ]);
      $this->decodeHtmlEntities($row, ['title']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the child development pinned-content displays.
   *
   * Resolves media fields (cover_video handles image/remote_video/video types,
   * cover_video_image handles image only), casts numeric and boolean fields,
   * converts multi-value references to int arrays, and applies type-specific
   * field handling: Articles drop video fields, Video Articles rename
   * cover_video_image to cover_image.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformChildDevelopment(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    // Deduplicate rows by the related article's nid. Multiple
    // child_development nodes can pin the same article, and multi-value
    // field JOINs can further multiply rows. Keep only the first
    // occurrence of each article ID.
    $seen = [];
    $rows = array_values(array_filter($rows, function ($row) use (&$seen) {
      $id = $row['id'] ?? NULL;
      if ($id === NULL || isset($seen[$id])) {
        return FALSE;
      }
      $seen[$id] = TRUE;
      return TRUE;
    }));

    foreach ($rows as &$row) {
      $this->castToInt($row, [
        'id', 'category', 'child_gender', 'parent_gender',
        'licensed', 'premature', 'mandatory',
      ]);
      $this->toIntArray($row, ['child_age', 'keywords', 'related_articles']);
      $this->decodeHtmlEntities($row, ['title']);

      $coverVideo = $this->parseViewVideoMedia($row['cover_video'] ?? NULL);
      $coverImage = $this->parseViewCoverImage($row['cover_image'] ?? NULL);

      // Pinned-content type-specific field handling (matches v1 logic).
      $type = $row['type'] ?? '';
      if ($type === 'Article') {
        // Articles have no video fields.
      }
      elseif ($type === 'Video Article') {
        $row['cover_video'] = $coverVideo;
        $row['cover_image'] = $coverImage;
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the health check-ups pinned-content display.
   *
   * Similar to child development but includes a direct cover_image field
   * (image media) alongside cover_video/cover_video_image, and adds
   * related_video_articles as an additional int array field.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformHealthCheckUps(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    // Deduplicate rows by the related article's nid — multiple
    // health_check_ups nodes can pin the same article via the relationship.
    $seen = [];
    $rows = array_values(array_filter($rows, function ($row) use (&$seen) {
      $id = $row['id'] ?? NULL;
      if ($id === NULL || isset($seen[$id])) {
        return FALSE;
      }
      $seen[$id] = TRUE;
      return TRUE;
    }));

    foreach ($rows as &$row) {
      $this->castToInt($row, [
        'id', 'category', 'child_gender', 'parent_gender',
        'licensed', 'premature', 'mandatory',
      ]);
      $this->toIntArray($row, [
        'child_age', 'related_articles', 'related_video_articles',
      ]);
      $this->decodeHtmlEntities($row, ['title']);

      // Pinned-content type-specific field handling (matches v1 logic).
      $type = $row['type'] ?? '';
      if ($type === 'Article') {
        unset($row['cover_video'], $row['cover_video_image']);
      }
      elseif ($type === 'Video Article') {
        $row['cover_image'] = $row['cover_video_image'];
        unset($row['cover_video_image']);
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms rows for the surveys display.
   *
   * Surveys are simple — no relationships, no pinned-content deduplication.
   * Casts numeric fields, resolves featured images, and decodes HTML entities.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformSurveys(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    foreach ($rows as &$row) {
      $this->castToInt($row, ['id']);
      $this->decodeHtmlEntities($row, ['title']);
    }
    unset($row);

    return $rows;
  }

  /**
   * Transforms vocabulary list rows into a keyed object.
   *
   * The view returns rows like [{name: "child_age,Child's Age"}, ...].
   * Splits each into machine_name and label, returning {machine_name:
   * {name: Label}} to match the V1 response shape.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Keyed object: {machine_name: {name: "Label"}, ...}.
   */
  private function transformVocabularies(array $rows): array {
    $result = [];
    foreach ($rows as $row) {
      $parts = explode(',', $row['name'] ?? '', 2);
      $machineName = trim($parts[0]);
      if ($machineName === '') {
        continue;
      }
      $label = htmlspecialchars_decode(
        trim($parts[1] ?? $machineName),
        ENT_QUOTES | ENT_HTML5,
      );
      $result[$machineName] = ['name' => $label];
    }
    return $result;
  }

  /**
   * Transforms taxonomy term rows for one or all vocabularies.
   *
   * When called with the "all" wildcard (multiple rows from the view), each
   * vocabulary is classified into one of three groups and dispatched in the
   * most query-efficient way:
   *
   *  - Specialty vocabs (own JOIN shape, one query each):
   *      growth_period, child_age, growth_introductory,
   *      chatbot_subcategory, category
   *
   *  - Unique-name vocabs (batched into ONE query):
   *      growth_type, activity_category, child_gender, parent_gender,
   *      relationship_to_parent, chatbot_category, subcategory,
   *      target_audience, course_category
   *
   *  - Basic vocabs (batched into ONE query):
   *      everything else (chatbot_child_age, type_of_article, etc.)
   *
   * The "keywords" vocabulary is always skipped (matches V1 behaviour).
   *
   * For a single-vocab call (/v2/api/taxonomies/en/{vocab}) the behaviour
   * is identical to the old single-dispatch path — no regressions.
   *
   * @param array $rows
   *   Raw rows from the view (one row per vocabulary).
   *
   * @return array
   *   Keyed by vocabulary machine name: {vocab: [terms]}.
   */
  private function transformTaxonomies(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    $langcode = $this->resolveLangcode();

    // Vocabulary classification lists.
    $specialtyVocabs  = [
      'growth_period',
      'child_age',
      'growth_introductory',
      'chatbot_subcategory',
      'category',
    ];
    $uniqueNameVocabs = [
      'growth_type',
      'activity_category',
      'child_gender',
      'parent_gender',
      'relationship_to_parent',
      'chatbot_category',
      'subcategory',
      'target_audience',
      'course_category',
    ];

    $toSpecialty  = [];
    $toUniqueName = [];
    $toBasic      = [];

    foreach ($rows as $row) {
      $vocabInfo    = explode(',', $row['vid'] ?? '', 2);
      $vocabMachine = trim($vocabInfo[0]);
      if ($vocabMachine === '' || $vocabMachine === 'keywords') {
        // Skip empty entries and the keywords vocab (matches V1 behaviour).
        continue;
      }

      if (in_array($vocabMachine, $specialtyVocabs, TRUE)) {
        $toSpecialty[] = $vocabMachine;
      }
      elseif (in_array($vocabMachine, $uniqueNameVocabs, TRUE)) {
        $toUniqueName[] = $vocabMachine;
      }
      else {
        $toBasic[] = $vocabMachine;
      }
    }

    $result = [];

    // Specialty vocabs: one query each (unavoidable — different JOIN shapes).
    foreach ($toSpecialty as $vocabMachine) {
      $result[$vocabMachine] = match ($vocabMachine) {
        'growth_period'       => $this->queryGrowthPeriodTerms($langcode),
        'child_age'           => $this->queryChildAgeTerms($langcode),
        'growth_introductory' => $this->queryGrowthIntroductoryTerms($langcode),
        'chatbot_subcategory' => $this->queryChatbotSubcategoryTerms($langcode),
        'category'            => $this->queryCategoryTerms($langcode),
      };
    }

    // Unique-name vocabs: one batch query for all of them combined.
    if (!empty($toUniqueName)) {
      foreach ($this->queryUniqueNameTermsBatch($toUniqueName, $langcode) as $vid => $terms) {
        $result[$vid] = $terms;
      }
    }

    // Basic vocabs: one batch query for all of them combined.
    if (!empty($toBasic)) {
      foreach ($this->queryBasicTermsBatch($toBasic, $langcode) as $vid => $terms) {
        $result[$vid] = $terms;
      }
    }

    return $result;
  }

  /**
   * Builds the base taxonomy term query shared by all vocabularies.
   *
   * Selects published terms for a specific vocabulary and language.
   *
   * @param string $vocabMachine
   *   The vocabulary machine name.
   * @param string $langcode
   *   The language code.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The base select query.
   */
  private function buildTermBaseQuery(string $vocabMachine, string $langcode): SelectInterface {
    $query = $this->database->select('taxonomy_term_field_data', 'td');
    $query->condition('td.vid', $vocabMachine);
    $query->condition('td.langcode', $langcode);
    $query->condition('td.status', 1);
    $query->fields('td', ['tid', 'name']);
    return $query;
  }

  /**
   * Queries growth_period vocabulary terms.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Array of [{id, name, vaccination_opens}].
   */
  private function queryGrowthPeriodTerms(string $langcode): array {
    $query = $this->buildTermBaseQuery('growth_period', $langcode);
    $query->leftJoin('taxonomy_term__field_vaccination_opens', 'vo', 'vo.entity_id = td.tid');
    $query->addField('vo', 'field_vaccination_opens_value', 'vaccination_opens');

    $results = $query->execute()->fetchAll();
    $terms = [];
    foreach ($results as $row) {
      $terms[] = [
        'id' => (int) $row->tid,
        'name' => $row->name,
        'vaccination_opens' => (int) ($row->vaccination_opens ?? 0),
      ];
    }
    return $terms;
  }

  /**
   * Queries child_age vocabulary terms.
   *
   * Includes days_from, days_to, buffers_days, and multi-value age_bracket.
   * Sorted by weight (matching V1). Supports ?pregnancy=true filtering.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Array of [{id, name, days_from, days_to, buffers_days, age_bracket}].
   */
  private function queryChildAgeTerms(string $langcode): array {
    $query = $this->buildTermBaseQuery('child_age', $langcode);
    $query->leftJoin('taxonomy_term__field_days_from', 'df', 'df.entity_id = td.tid');
    $query->leftJoin('taxonomy_term__field_days_to', 'dt', 'dt.entity_id = td.tid');
    $query->leftJoin('taxonomy_term__field_buffers_days', 'bd', 'bd.entity_id = td.tid');
    $query->addField('df', 'field_days_from_value', 'days_from');
    $query->addField('dt', 'field_days_to_value', 'days_to');
    $query->addField('bd', 'field_buffers_days_value', 'buffers_days');
    $query->addField('td', 'weight', 'weight');
    $query->orderBy('td.weight', 'ASC');

    // Pregnancy filtering: exclude Pregnancy term unless ?pregnancy=true.
    $request = $this->requestStack->getCurrentRequest();
    $pregnancyParam = $request ? $request->query->get('pregnancy') : NULL;
    if ($pregnancyParam !== 'true') {
      $query->condition('td.name', 'Pregnancy', '<>');
    }

    $results = $query->execute()->fetchAll();
    $tids = array_column($results, 'tid');

    // Batch-load multi-value age_bracket field.
    $ageBracketMap = [];
    if (!empty($tids)) {
      $abQuery = $this->database->select('taxonomy_term__field_age_bracket', 'ab');
      $abQuery->fields('ab', ['entity_id', 'field_age_bracket_target_id']);
      $abQuery->condition('ab.entity_id', $tids, 'IN');
      $abQuery->orderBy('ab.delta', 'ASC');
      $abResults = $abQuery->execute()->fetchAll();
      foreach ($abResults as $abRow) {
        $ageBracketMap[(int) $abRow->entity_id][] = (int) $abRow->field_age_bracket_target_id;
      }
    }

    $terms = [];
    foreach ($results as $row) {
      $tid = (int) $row->tid;
      $terms[] = [
        'id' => $tid,
        'name' => $row->name,
        'days_from' => (int) ($row->days_from ?? 0),
        'days_to' => (int) ($row->days_to ?? 0),
        'buffers_days' => (int) ($row->buffers_days ?? 0),
        'age_bracket' => $ageBracketMap[$tid] ?? [],
      ];
    }
    return $terms;
  }

  /**
   * Queries growth_introductory vocabulary terms.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Array of [{id, name, body, days_from, days_to}].
   */
  private function queryGrowthIntroductoryTerms(string $langcode): array {
    $query = $this->buildTermBaseQuery('growth_introductory', $langcode);
    $query->addField('td', 'description__value', 'body');
    $query->leftJoin('taxonomy_term__field_days_from', 'df', 'df.entity_id = td.tid');
    $query->leftJoin('taxonomy_term__field_days_to', 'dt', 'dt.entity_id = td.tid');
    $query->addField('df', 'field_days_from_value', 'days_from');
    $query->addField('dt', 'field_days_to_value', 'days_to');

    $results = $query->execute()->fetchAll();
    $terms = [];
    foreach ($results as $row) {
      $terms[] = [
        'id' => (int) $row->tid,
        'name' => $row->name,
        'body' => $row->body ?? '',
        'days_from' => (int) ($row->days_from ?? 0),
        'days_to' => (int) ($row->days_to ?? 0),
      ];
    }
    return $terms;
  }

  /**
   * Queries chatbot_subcategory vocabulary terms.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Array of [{id, name, parent_category_id, unique_name}].
   */
  private function queryChatbotSubcategoryTerms(string $langcode): array {
    $query = $this->buildTermBaseQuery('chatbot_subcategory', $langcode);
    $query->leftJoin('taxonomy_term__field_chatbot_category', 'cc', 'cc.entity_id = td.tid');
    $query->leftJoin('taxonomy_term__field_unique_name', 'un', 'un.entity_id = td.tid');
    $query->addField('cc', 'field_chatbot_category_target_id', 'parent_category_id');
    $query->addField('un', 'field_unique_name_value', 'unique_name');

    $results = $query->execute()->fetchAll();
    $terms = [];
    $needsMachineName = [];
    foreach ($results as $idx => $row) {
      $terms[] = [
        'id' => (int) $row->tid,
        'name' => $row->name,
        'parent_category_id' => (int) ($row->parent_category_id ?? 0),
        'unique_name' => $row->unique_name ?? '',
      ];
      if (empty($row->unique_name)) {
        $needsMachineName[$idx] = (int) $row->tid;
      }
    }

    // Batch-resolve machine names for terms missing field_unique_name.
    $this->batchResolveMachineNames($terms, $needsMachineName);

    return $terms;
  }

  /**
   * Queries category vocabulary terms.
   *
   * Resolves field_type_of_article entity reference label via a single JOIN
   * instead of N+1 entity loads.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Array of [{id, name, unique_name, field_type_of_article}].
   */
  private function queryCategoryTerms(string $langcode): array {
    $query = $this->buildTermBaseQuery('category', $langcode);
    $query->leftJoin('taxonomy_term__field_unique_name', 'un', 'un.entity_id = td.tid');
    $query->leftJoin('taxonomy_term__field_type_of_article', 'toa', 'toa.entity_id = td.tid');
    // Resolve the entity reference to a label via JOIN to term field data.
    $query->leftJoin('taxonomy_term_field_data', 'toa_td', "toa_td.tid = toa.field_type_of_article_target_id AND toa_td.langcode = td.langcode");
    $query->addField('un', 'field_unique_name_value', 'unique_name');
    $query->addField('toa_td', 'name', 'type_of_article');

    $results = $query->execute()->fetchAll();
    $terms = [];
    $needsMachineName = [];
    foreach ($results as $idx => $row) {
      $terms[] = [
        'id' => (int) $row->tid,
        'name' => $row->name,
        'unique_name' => $row->unique_name ?? '',
        'field_type_of_article' => $row->type_of_article ?? '',
      ];
      if (empty($row->unique_name)) {
        $needsMachineName[$idx] = (int) $row->tid;
      }
    }

    $this->batchResolveMachineNames($terms, $needsMachineName);

    return $terms;
  }

  /**
   * Queries multiple unique-name vocabularies in a single DB round-trip.
   *
   * Fetches all terms for every vocabulary in $vocabs with a single SELECT
   * that includes a LEFT JOIN on field_unique_name, then groups the flat
   * result set by vocabulary machine name in PHP. Missing machine names are
   * resolved via batchResolveMachineNames() — once per affected vocabulary.
   *
   * Used by transformTaxonomies() when more than one unique-name vocab is
   * requested in the same call (e.g. the "all" wildcard endpoint).
   *
   * @param array $vocabs
   *   List of vocabulary machine names to fetch.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Keyed by vocabulary machine name; each value is
   *   [{id, name, unique_name}].
   */
  private function queryUniqueNameTermsBatch(array $vocabs, string $langcode): array {
    if (empty($vocabs)) {
      return [];
    }

    $query = $this->database->select('taxonomy_term_field_data', 'td');
    $query->condition('td.vid', $vocabs, 'IN');
    $query->condition('td.langcode', $langcode);
    $query->condition('td.status', 1);
    $query->fields('td', ['tid', 'name', 'vid']);
    $query->leftJoin('taxonomy_term__field_unique_name', 'un', 'un.entity_id = td.tid');
    $query->addField('un', 'field_unique_name_value', 'unique_name');

    $results = $query->execute()->fetchAll();

    // Group rows by vocabulary and track indices that need a generated name.
    $grouped                 = [];
    $needsMachineNameByVocab = [];
    foreach ($results as $row) {
      $vid = $row->vid;
      $idx = count($grouped[$vid] ?? []);
      $grouped[$vid][] = [
        'id'          => (int) $row->tid,
        'name'        => $row->name,
        'unique_name' => $row->unique_name ?? '',
      ];
      if (empty($row->unique_name)) {
        $needsMachineNameByVocab[$vid][$idx] = (int) $row->tid;
      }
    }

    // Resolve missing machine names per vocabulary.
    foreach ($needsMachineNameByVocab as $vid => $needsMap) {
      $this->batchResolveMachineNames($grouped[$vid], $needsMap);
    }

    // Guarantee every requested vocab key is present, even when empty.
    foreach ($vocabs as $vocab) {
      if (!isset($grouped[$vocab])) {
        $grouped[$vocab] = [];
      }
    }

    return $grouped;
  }

  /**
   * Queries multiple basic vocabularies in a single DB round-trip.
   *
   * Fetches tid + name for every vocabulary in $vocabs with a single SELECT
   * on taxonomy_term_field_data (no extra JOINs), then groups the result by
   * vocabulary machine name in PHP.
   *
   * Used by transformTaxonomies() when more than one basic vocab is requested
   * in the same call (e.g. the "all" wildcard endpoint).
   *
   * @param array $vocabs
   *   List of vocabulary machine names to fetch.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Keyed by vocabulary machine name; each value is [{id, name}].
   */
  private function queryBasicTermsBatch(array $vocabs, string $langcode): array {
    if (empty($vocabs)) {
      return [];
    }

    $query = $this->database->select('taxonomy_term_field_data', 'td');
    $query->condition('td.vid', $vocabs, 'IN');
    $query->condition('td.langcode', $langcode);
    $query->condition('td.status', 1);
    $query->fields('td', ['tid', 'name', 'vid']);

    $results = $query->execute()->fetchAll();

    $grouped = [];
    foreach ($results as $row) {
      $grouped[$row->vid][] = [
        'id'   => (int) $row->tid,
        'name' => $row->name,
      ];
    }

    // Guarantee every requested vocab key is present, even when empty.
    foreach ($vocabs as $vocab) {
      if (!isset($grouped[$vocab])) {
        $grouped[$vocab] = [];
      }
    }

    return $grouped;
  }

  /**
   * Batch-resolves machine names for terms missing field_unique_name.
   *
   * Fetches the English name for each term in a single query, then generates
   * a machine name (lowercased, non-alphanumeric replaced with underscores).
   * Modifies the $terms array in place.
   *
   * @param array &$terms
   *   The terms array to modify (each item has a 'unique_name' key).
   * @param array $needsMachineName
   *   Map of term array index => tid for terms needing a generated name.
   */
  private function batchResolveMachineNames(array &$terms, array $needsMachineName): void {
    if (empty($needsMachineName)) {
      return;
    }

    $tids = array_values($needsMachineName);
    // Fetch English names in a single query for stable machine name generation.
    $enNames = $this->database->select('taxonomy_term_field_data', 'td')
      ->fields('td', ['tid', 'name'])
      ->condition('td.tid', $tids, 'IN')
      ->condition('td.langcode', 'en')
      ->execute()
      ->fetchAllKeyed();

    foreach ($needsMachineName as $idx => $tid) {
      $source = $enNames[$tid] ?? $terms[$idx]['name'];
      $machine = strtolower($source);
      $machine = preg_replace('/[^a-z0-9]/', '_', $machine);
      $machine = preg_replace('/_+/', '_', $machine);
      $terms[$idx]['unique_name'] = trim($machine, '_');
    }
  }

  /**
   * Transforms rows for the standard deviation display.
   *
   * Restructures flat view rows into a deeply nested response grouped by
   * growth type and child-age bucket. Each bucket contains SD-keyed objects
   * with article data.
   *
   * Performance: resolves all taxonomy term IDs to field_unique_name values
   * in a single DB query (v1 made ~700+ individual Term::load calls).
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Nested structure: {weight_for_height: [...], height_for_age: [...]}.
   */
  private function transformStandardDeviation(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    // 1. Collect all unique term IDs for batch resolution.
    $termIds = [];
    foreach ($rows as $row) {
      if (!empty($row['growth_type']) && is_numeric($row['growth_type'])) {
        $termIds[] = (int) $row['growth_type'];
      }
      foreach ($this->splitNumericIds($row['child_age'] ?? '') as $id) {
        $termIds[] = $id;
      }
    }
    $termNameMap = $this->resolveTermUniqueNames(array_unique($termIds));

    // 2. Static maps — SD label to output key per growth type.
    $sdLabelMaps = [
      'height_for_age' => [
        'between -2SD to +3SD' => 'goodText',
        'below -2SD'           => 'warrningSmallLengthText',
        'below -3SD'           => 'emergencySmallLengthText',
        'above +3SD'           => 'warrningBigLengthText',
      ],
      'height_for_weight' => [
        'between -2SD to +2SD' => 'goodText',
        'between -2 and -3SD'  => 'warrningSmallHeightText',
        'below -3SD'           => 'emergencySmallHeightText',
        'between +2 and +3SD'  => 'warrningBigHeightText',
        'above +3SD'           => 'emergencyBigHeightText',
      ],
    ];

    // 3. Bucket definitions — child_age unique names grouped into ranges.
    $buckets = [
      '1st_month,2nd_month,3_4_months,5_6_months',
      '7_9_months',
      '10_12_months',
      '13_18_months,19_24_months',
      '25_36_months,37_48_months,49_60_months,61_72_months',
    ];

    // 4. Normalize rows and group by growth type.
    $itemsByGrowth = [
      'height_for_age'    => [],
      'height_for_weight' => [],
    ];

    foreach ($rows as $row) {
      $growthTid = (int) ($row['growth_type'] ?? 0);
      $growth    = $termNameMap[$growthTid] ?? NULL;
      if (!$growth || !isset($sdLabelMaps[$growth])) {
        continue;
      }

      // Resolve child_age TIDs to unique names for bucketing.
      $ageIds = $this->splitNumericIds($row['child_age'] ?? '');
      sort($ageIds, SORT_NUMERIC);
      $ageNames = array_filter(array_map(
        fn($id) => $termNameMap[$id] ?? NULL,
        $ageIds
      ));
      $bucketKey = implode(',', $ageNames);

      $itemsByGrowth[$growth][] = [
        'child_age'      => $row['child_age'] ?? '',
        'pinned_article' => $row['pinned_article'] ?? '',
        'title'          => htmlspecialchars_decode((string) ($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5),
        'body'           => str_replace(["\r", "\n"], '', (string) ($row['body'] ?? '')),
        'sd_label'       => trim((string) ($row['standard_deviation'] ?? '')),
        'bucket_key'     => $bucketKey,
      ];
    }

    // 5. Build nested output per growth type.
    $final = [];
    foreach (['height_for_weight', 'height_for_age'] as $growth) {
      $items = $itemsByGrowth[$growth] ?? [];
      if (empty($items)) {
        continue;
      }

      // Group items by bucket key.
      $grouped = array_fill_keys($buckets, []);
      foreach ($items as $item) {
        if (!empty($item['bucket_key']) && isset($grouped[$item['bucket_key']])) {
          $grouped[$item['bucket_key']][] = $item;
        }
      }

      $sdArr = [];
      foreach ($grouped as $groupItems) {
        if (empty($groupItems)) {
          continue;
        }

        // child_age: array of int IDs from the first item (shared by bucket).
        $childAgeIds = $this->splitNumericIds($groupItems[0]['child_age']);
        $sdData = [
          'child_age' => array_values(array_filter(array_map('intval', $childAgeIds))),
        ];

        // Map SD labels to output keys in explicit order.
        $tmpFields = [];
        foreach ($groupItems as $gi) {
          $fieldKey = $sdLabelMaps[$growth][$gi['sd_label']] ?? NULL;
          if (!$fieldKey) {
            continue;
          }
          $pinned = $this->splitNumericIds($gi['pinned_article']);
          $tmpFields[$fieldKey] = [
            'articleID' => array_values(array_filter(array_map('intval', $pinned))),
            'name'      => $gi['title'],
            'text'      => $gi['body'],
          ];
        }

        // Insert in explicit order.
        $order = $growth === 'height_for_age'
          ? ['goodText', 'warrningSmallLengthText', 'emergencySmallLengthText', 'warrningBigLengthText']
          : [
            'goodText', 'warrningSmallHeightText', 'emergencySmallHeightText',
            'warrningBigHeightText', 'emergencyBigHeightText',
          ];

        foreach ($order as $key) {
          if (isset($tmpFields[$key])) {
            $sdData[$key] = $tmpFields[$key];
          }
        }
        $sdArr[] = $sdData;
      }

      if (!empty($sdArr)) {
        // Remap: height_for_weight → weight_for_height (matches v1).
        $outputKey = $growth === 'height_for_weight' ? 'weight_for_height' : 'height_for_age';
        $final[$outputKey] = $sdArr;
      }
    }

    return $final;
  }

  /**
   * Splits a comma-separated string of numeric IDs into an int array.
   *
   * Handles both comma-separated strings and single values.
   *
   * @param string $value
   *   Comma-separated IDs (e.g. "43, 44, 45") or a single ID.
   *
   * @return int[]
   *   Array of integer IDs (empty values filtered out).
   */
  private function splitNumericIds(string $value): array {
    if ($value === '') {
      return [];
    }
    return array_map('intval', array_filter(
      array_map('trim', explode(',', $value)),
      'is_numeric'
    ));
  }

  /**
   * Resolves taxonomy term IDs to their field_unique_name values.
   *
   * Uses a direct DB query for minimal overhead — no entity hydration.
   * Suitable for small reference vocabularies (growth_type, child_age).
   *
   * @param int[] $termIds
   *   Term IDs to resolve.
   *
   * @return array<int, string>
   *   Map of term ID to field_unique_name value.
   */
  private function resolveTermUniqueNames(array $termIds): array {
    if (empty($termIds)) {
      return [];
    }
    return $this->database->select('taxonomy_term__field_unique_name', 't')
      ->fields('t', ['entity_id', 'field_unique_name_value'])
      ->condition('entity_id', $termIds, 'IN')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Transforms rows for the country-groups listing display.
   *
   * Resolves media IDs to {url, name, alt} objects, builds a rich languages
   * array from Group entities and custom_language_data, applies language
   * visibility filtering, and moves CountryID 126 to the end.
   *
   * @param array $rows
   *   Raw rows from the view.
   *
   * @return array
   *   Transformed rows.
   */
  private function transformCountryGroups(array $rows): array {
    if (empty($rows)) {
      return $rows;
    }

    // 1. Filter out CountryID 131.
    $rows = array_values(array_filter($rows, fn($row) => ($row['CountryID'] ?? '') !== '131'));

    // 2. Resolve media fields from raw IDs to {url, name, alt} objects.
    $mediaKeys = ['country_national_partner', 'country_sponsor_logo', 'unicef_logo'];
    $allMediaIds = [];
    foreach ($rows as $row) {
      foreach ($mediaKeys as $key) {
        $id = $row[$key] ?? '';
        if (!empty($id) && $id !== '0') {
          $allMediaIds[] = (int) $id;
        }
      }
    }
    $resolvedMedia = $this->resolveMediaIds($allMediaIds);
    $emptyMedia = ['url' => '', 'name' => '', 'alt' => ''];
    foreach ($rows as &$row) {
      foreach ($mediaKeys as $key) {
        $id = (int) ($row[$key] ?? 0);
        $row[$key] = ($id > 0 && isset($resolvedMedia[$id])) ? $resolvedMedia[$id] : $emptyMedia;
      }
    }
    unset($row);

    // 3. Batch-load all Group entities needed for language building.
    $groupIds = array_filter(array_column($rows, 'CountryID'));
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $groups = $groupStorage->loadMultiple($groupIds);

    // 4. Build languages for each row and clean up fields.
    $entry126Index = NULL;
    foreach ($rows as $index => &$row) {
      $countryId = $row['CountryID'] ?? '';
      $group = $groups[$countryId] ?? NULL;

      if ($countryId === '126') {
        // Special "Rest of the World" entry with hardcoded en + ru.
        $row['name'] = 'Rest of the world';
        $row['displayName'] = 'Rest of the world';
        $row['languages'] = $this->buildLanguagesForRestOfWorld();
        $entry126Index = $index;
      }
      elseif ($group instanceof Group) {
        $row['languages'] = $this->buildLanguagesForGroup($row['name'] ?? '', $group);
      }
      else {
        $row['languages'] = [];
      }

      // Remove raw langcode array — replaced by languages.
      unset($row['langcode']);
    }
    unset($row);

    // 5. Move CountryID 126 to end of array.
    if ($entry126Index !== NULL) {
      $entry = array_splice($rows, $entry126Index, 1);
      $rows[] = $entry[0];
    }

    return $rows;
  }

  /**
   * Transforms rows for the archive (deleted content) display.
   *
   * Groups node IDs by content type, producing a keyed array like:
   * @code
   * { "Article": [123, 456], "FAQ": [789], "Activities": [101] }
   * @endcode
   *
   * @param array $rows
   *   Raw rows from the view, each with at least 'nid' and 'type'.
   *
   * @return array
   *   Node IDs grouped by content type.
   */
  private function transformArchive(array $rows): array {
    $grouped = [];
    foreach ($rows as $row) {
      $type = (string) ($row['type'] ?? '');
      $nid  = (int) ($row['nid'] ?? 0);
      if ($type !== '' && $nid > 0) {
        $grouped[$type][] = $nid;
      }
    }
    return $grouped;
  }

  /**
   * Builds languages array for a regular country group.
   *
   * Loads language data from custom_language_data table and
   * ConfigurableLanguage entities, applies visibility filtering,
   * and sorts by language weight.
   *
   * @param string $countryName
   *   The country display name.
   * @param \Drupal\group\Entity\Group $group
   *   The group entity.
   *
   * @return array
   *   Array of language objects.
   */
  private function buildLanguagesForGroup(string $countryName, Group $group): array {
    $fieldLanguages = $group->get('field_language')->getValue();
    $langcodes = array_filter(array_column($fieldLanguages, 'value'));

    if (empty($langcodes)) {
      return [];
    }

    // Batch load custom_language_data and ConfigurableLanguage entities.
    $langData = $this->database->select('custom_language_data', 'cld')
      ->fields('cld')
      ->condition('langcode', $langcodes, 'IN')
      ->execute()
      ->fetchAllAssoc('langcode', \PDO::FETCH_ASSOC);

    $configLanguages = $this->entityTypeManager
      ->getStorage('configurable_language')
      ->loadMultiple($langcodes);

    $languages = [];
    foreach ($langcodes as $langcode) {
      // Skip disabled languages.
      if (!$this->languageManager->getLanguage($langcode)) {
        continue;
      }
      $configLang = $configLanguages[$langcode] ?? NULL;
      if (!$configLang) {
        continue;
      }

      $data = $langData[$langcode] ?? [];

      // Get per-language content_toggle. Only read from an explicit
      // translation; if no translation exists, default to empty string.
      // The top-level content_toggle field provides backward-compat fallback.
      if ($group->hasTranslation($langcode)) {
        $toggleValues = $group->getTranslation($langcode)->get('field_content_toggle')->getValue();
        $contentToggle = implode(', ', array_column($toggleValues, 'value'));
      }
      else {
        $contentToggle = '';
      }

      $languages[] = [
        'name' => $countryName,
        'displayName' => $data['custom_language_name_local'] ?? '',
        'languageCode' => $langcode,
        'locale' => $data['custom_locale'] ?? '',
        'luxonLocale' => $data['custom_luxon'] ?? '',
        'pluralShow' => $data['custom_plural'] ?? '',
        'content_toggle' => $contentToggle,
        'view_weight' => $configLang->get('weight') ?? 0,
      ];
    }

    // Apply language visibility filtering.
    $languages = $this->languageVisibilityService->filterLanguageDataForApi($languages, $group);

    // Sort by weight, then remove the weight key.
    usort($languages, fn($a, $b) => ($a['view_weight'] ?? 0) <=> ($b['view_weight'] ?? 0));
    foreach ($languages as &$lang) {
      unset($lang['view_weight']);
    }
    unset($lang);

    return $languages;
  }

  /**
   * Builds languages array for CountryID 126 (Rest of the World).
   *
   * Hardcoded to English and Russian.
   *
   * @return array
   *   Array of language objects for en and ru.
   */
  private function buildLanguagesForRestOfWorld(): array {
    $langcodes = ['en', 'ru'];
    $langData = $this->database->select('custom_language_data', 'cld')
      ->fields('cld')
      ->condition('langcode', $langcodes, 'IN')
      ->execute()
      ->fetchAllAssoc('langcode', \PDO::FETCH_ASSOC);

    $configLanguages = $this->entityTypeManager
      ->getStorage('configurable_language')
      ->loadMultiple($langcodes);

    $languages = [];
    foreach ($langcodes as $langcode) {
      $configLang = $configLanguages[$langcode] ?? NULL;
      $displayName = $configLang ? $configLang->label() : '';
      $data = $langData[$langcode] ?? [];

      $name = ($langcode === 'en') ? 'English' : 'Russian';
      $languages[] = [
        'name' => $name,
        'displayName' => $displayName,
        'languageCode' => $langcode,
        'locale' => $data['custom_locale'] ?? '',
        'luxonLocale' => $data['custom_luxon'] ?? '',
        'pluralShow' => $data['custom_plural'] ?? '',
      ];
    }

    return $languages;
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
      if (array_key_exists($field, $row)) {
        $row[$field] = (int) ($row[$field] ?? 0);
      }
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
      if (array_key_exists($field, $row)) {
        $row[$field] = round((float) ($row[$field] ?? 0), $decimals);
      }
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
      if (!array_key_exists($field, $row)) {
        continue;
      }
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
   * Converts comma-separated string fields to string arrays.
   *
   * Splits on comma, trims whitespace, removes empty values.
   *
   * @param array $row
   *   A single row (passed by reference).
   * @param array $fields
   *   Field names to convert.
   */
  private function toStringArray(array &$row, array $fields): void {
    foreach ($fields as $field) {
      if (!array_key_exists($field, $row)) {
        continue;
      }
      $raw = $row[$field] ?? [];
      if (is_array($raw)) {
        $values = $raw;
      }
      else {
        $values = array_map('trim', explode(',', (string) $raw));
      }
      $row[$field] = array_values(array_filter($values, fn($v) => $v !== ''));
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
   * Parses a cover_video view field JSON string to {url, name, site}.
   *
   * The view renders the field as a JSON array with one element:
   * @code
   * [{"url":"https://www.youtube.com/...","name":"...","site":"youtube"}]
   * @endcode
   *
   * @param mixed $raw
   *   The raw field value from the row (expected JSON string).
   *
   * @return array
   *   Resolved {url, name, site} object, or empty strings if parsing fails.
   */
  private function parseViewVideoMedia(mixed $raw): array {
    $empty = ['url' => '', 'name' => '', 'site' => ''];

    if (empty($raw) || !is_string($raw)) {
      return $empty;
    }

    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || empty($decoded[0])) {
      return $empty;
    }

    $item = $decoded[0];
    return [
      'url' => (string) ($item['url'] ?? ''),
      'name' => (string) ($item['name'] ?? ''),
      'site' => (string) ($item['site'] ?? ''),
    ];
  }

  /**
   * Parses a cover_image view field JSON string to {url, name, alt}.
   *
   * The view renders the field as a JSON array with one element:
   * @code
   * [{"name":"temir.png","url":"\/sites\/...\/temir.png?itok=...","alt":"..."}]
   * @endcode
   *
   * The "url" value is a relative path and is made absolute using the current
   * request's scheme and host.
   *
   * @param mixed $raw
   *   The raw field value from the row (expected JSON string).
   *
   * @return array
   *   Resolved {url, name, alt} object, or empty strings if parsing fails.
   */
  private function parseViewCoverImage(mixed $raw): array {
    $empty = ['url' => '', 'name' => '', 'alt' => ''];

    if (empty($raw) || !is_string($raw)) {
      return $empty;
    }

    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || empty($decoded[0])) {
      return $empty;
    }

    $item = $decoded[0];
    $url = (string) ($item['url'] ?? '');
    $name = (string) ($item['name'] ?? '');
    $alt = (string) ($item['alt'] ?? '');

    // Make the URL absolute if it is a root-relative path.
    if ($url !== '' && !str_starts_with($url, 'http')) {
      $request = $this->requestStack->getCurrentRequest();
      $url = ($request !== NULL ? $request->getSchemeAndHttpHost() : '') . $url;
    }

    return ['url' => $url, 'name' => $name, 'alt' => $alt];
  }

  /**
   * Batch-resolves media IDs to {url, name, alt} arrays.
   *
   * Loads media entities, extracts the image file, generates a styled URL
   * using the content_1200xh_ image style, and returns the media name and
   * alt text.
   *
   * @param array $mediaIds
   *   Array of media entity IDs.
   *
   * @return array
   *   Keyed by media ID, each value has url, name, and alt keys.
   */
  private function resolveMediaIds(array $mediaIds): array {
    $empty = ['url' => '', 'name' => '', 'alt' => ''];
    $mediaIds = array_filter(array_unique($mediaIds));
    if (empty($mediaIds)) {
      return [];
    }

    $mediaEntities = $this->entityTypeManager
      ->getStorage('media')
      ->loadMultiple($mediaIds);
    $imageStyle = $this->entityTypeManager
      ->getStorage('image_style')
      ->load('content_1200xh_');

    $resolved = [];
    foreach ($mediaIds as $id) {
      if (!isset($mediaEntities[$id]) || !$mediaEntities[$id]->hasField('field_media_image')) {
        $resolved[$id] = $empty;
        continue;
      }

      $media = $mediaEntities[$id];
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $media->get('field_media_image')->entity;
      if (!$file instanceof FileInterface) {
        $resolved[$id] = $empty;
        continue;
      }

      $name = (string) ($media->get('name')->value ?? '');
      $imageField = $media->get('field_media_image')->getValue();
      $alt = (string) ($imageField[0]['alt'] ?? '');

      $url = '';
      if ($imageStyle) {
        $url = $imageStyle->buildUrl($file->getFileUri());
      }

      if ($url !== '' && !str_starts_with($url, 'http')) {
        $request = $this->requestStack->getCurrentRequest();
        $url = ($request !== NULL ? $request->getSchemeAndHttpHost() : '') . $url;
      }

      $resolved[$id] = ['url' => $url, 'name' => $name, 'alt' => $alt];
    }

    return $resolved;
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
