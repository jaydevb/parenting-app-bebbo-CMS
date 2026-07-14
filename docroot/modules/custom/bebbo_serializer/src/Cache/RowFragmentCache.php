<?php

declare(strict_types=1);

namespace Drupal\bebbo_serializer\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;

/**
 * Caches per-node rendered row fragments for the Bebbo API serializers.
 *
 * Each result row's expensive rowPlugin render output is cached verbatim,
 * keyed by display + node id + langcode + host, and tagged with the row's
 * own bubbled cache tags so a single node edit invalidates only its
 * fragment. Because the cached value is the pipeline's own output, the
 * assembled JSON is byte-identical to the uncached path.
 */
class RowFragmentCache {

  public function __construct(
    protected CacheBackendInterface $cache,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Builds the cache id for one row.
   */
  public function cid(string $displayId, int $nid, string $langcode, string $host): string {
    return 'bebbo_api:' . $displayId . ':' . $nid . ':' . $langcode . ':' . $host;
  }

  /**
   * Returns rendered raw rows, rendering (and caching) only cache misses.
   *
   * @param string $displayId
   *   The view display machine name.
   * @param string $langcode
   *   The requested language.
   * @param string $host
   *   Scheme + host, so absolute URLs in the fragment stay correct.
   * @param object[] $resultRows
   *   The view result rows; each MUST expose a numeric `nid` property.
   * @param callable $renderRow
   *   Fn(int $index, object $row): mixed — renders one row on a miss.
   * @param \Drupal\Core\Render\BubbleableMetadata $bubble
   *   Collects cache tags from every row (hit and miss) so the outer page
   *   cache invalidates exactly as the uncached view would.
   *
   * @return array
   *   Rendered raw rows in $resultRows order.
   */
  public function render(string $displayId, string $langcode, string $host, array $resultRows, callable $renderRow, BubbleableMetadata $bubble): array {
    $cids = [];
    foreach ($resultRows as $i => $row) {
      $cids[$i] = $this->cid($displayId, (int) $row->nid, $langcode, $host);
    }

    $cached = $this->cache->getMultiple($cids);
    $out = [];
    foreach ($resultRows as $i => $row) {
      $cid = $this->cid($displayId, (int) $row->nid, $langcode, $host);
      if (isset($cached[$cid])) {
        $out[$i] = $cached[$cid]->data['row'];
        $bubble->addCacheTags($cached[$cid]->data['tags']);
        continue;
      }

      // Miss: render in an isolated context to capture this row's tags.
      $context = new RenderContext();
      $rendered = $this->renderer->executeInRenderContext($context, function () use ($renderRow, $i, $row) {
        return $renderRow($i, $row);
      });
      $tags = ['node:' . (int) $row->nid];
      if (!$context->isEmpty()) {
        $tags = Cache::mergeTags($tags, $context->pop()->getCacheTags());
      }
      $out[$i] = $rendered;
      $bubble->addCacheTags($tags);

      // Persist each fragment as soon as it is rendered. Writing per-row (not
      // in a single batch after the loop) means a request aborted mid-render
      // — e.g. a cold /api/articles/{lang} killed by the gateway timeout —
      // still saves the rows it managed to build, so successive requests
      // render only what remains and the endpoint converges to fully warm.
      $this->cache->set($cid, ['row' => $rendered, 'tags' => $tags], Cache::PERMANENT, $tags);
    }

    ksort($out);
    return array_values($out);
  }

  /**
   * Builds the cache id for a per-language warm marker.
   */
  public function warmFlagCid(string $key, string $langcode, string $host): string {
    return 'bebbo_api:warm:' . $key . ':' . $langcode . ':' . $host;
  }

  /**
   * Whether the given endpoint/language/host has already been warmed.
   */
  public function isWarm(string $key, string $langcode, string $host): bool {
    return (bool) $this->cache->get($this->warmFlagCid($key, $langcode, $host));
  }

  /**
   * Records that the given endpoint/language/host has been warmed.
   *
   * The marker carries the same list tags as the article response, so any
   * node or media change (and any full cache flush) expires it and the cron
   * warmer re-renders that endpoint on its next pass.
   */
  public function markWarm(string $key, string $langcode, string $host): void {
    $this->cache->set(
      $this->warmFlagCid($key, $langcode, $host),
      TRUE,
      Cache::PERMANENT,
      ['node_list', 'media_list'],
    );
  }

}
