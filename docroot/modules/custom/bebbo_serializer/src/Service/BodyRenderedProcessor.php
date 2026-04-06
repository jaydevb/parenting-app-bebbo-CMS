<?php

namespace Drupal\bebbo_serializer\Service;

use Drupal\Core\Render\RendererInterface;

/**
 * Pre-renders body HTML by applying text format filters and cleanup.
 *
 * Runs check_markup() (which applies the media_embed filter to convert
 * <drupal-media> tags to <img>) then cleans the output for API consumption.
 * Used at node save time to populate field_body_rendered, so the API view
 * can output pre-rendered HTML without runtime filter processing.
 */
class BodyRenderedProcessor {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * Constructs a BodyRenderedProcessor.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(RendererInterface $renderer) {
    $this->renderer = $renderer;
  }

  /**
   * Renders body HTML through the text format filter pipeline.
   *
   * @param string $bodyValue
   *   Raw body HTML (may contain <drupal-media> tags).
   * @param string $bodyFormat
   *   Text format ID (e.g. "full_html").
   * @param string $langcode
   *   Language code for rendering context.
   * @param string $baseUrl
   *   Absolute base URL (e.g. "https://bebbo.app").
   *
   * @return string
   *   Fully rendered and cleaned HTML.
   */
  public function render(string $bodyValue, string $bodyFormat, string $langcode, string $baseUrl): string {
    if (empty($bodyValue)) {
      return '';
    }

    // Run the full text format filter pipeline (including media_embed).
    // This is the same processing that Views' text_default formatter does.
    $build = [
      '#type' => 'processed_text',
      '#text' => $bodyValue,
      '#format' => $bodyFormat,
      '#langcode' => $langcode,
    ];
    $html = (string) $this->renderer->renderInIsolation($build);

    return $this->cleanBodyHtml($html, $baseUrl);
  }

  /**
   * Cleans rendered body HTML for API output.
   *
   * Performs deterministic string replacements to match v1 API output:
   * absolutises file paths, strips presentational markup, decodes entities.
   *
   * @param string $html
   *   Rendered HTML string.
   * @param string $baseUrl
   *   Absolute base URL of the current request.
   *
   * @return string
   *   Cleaned HTML string.
   */
  private function cleanBodyHtml(string $html, string $baseUrl): string {
    // 1. Absolutise file src paths.
    $html = str_replace('src="/sites/default/files/', 'src="' . $baseUrl . '/sites/default/files/', $html);
    // 2. Absolutise oEmbed src paths.
    $html = str_replace('src="/media/oembed', 'src="' . $baseUrl . '/media/oembed', $html);
    // 3. Remove newlines.
    $html = str_replace("\n", '', $html);
    // 4. Strip <span> tags (open and close).
    $html = preg_replace('/<span[^>]+>|<\/span>/i', '', $html) ?? $html;
    // 5. Remove empty <p> and <strong> tags.
    $html = str_replace('<p> </p>', '', $html);
    $html = str_replace('<strong> </strong>', '', $html);
    // 6. Strip inline style attributes.
    $html = preg_replace('/(<[^>]*) style=("[^"]*"|\'[^\']*\')([^>]*>)/i', '$1$3', $html) ?? $html;
    // 7. Remove remote-video dimension attributes.
    $html = str_replace('width="640"', '', $html);
    $html = str_replace('height="480"', '', $html);
    // 8. Remove CKEditor image label div.
    $html = str_replace('<div class="field__label visually-hidden">Image</div>', '', $html);
    // 9. Decode HTML entities.
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return $html;
  }

}
