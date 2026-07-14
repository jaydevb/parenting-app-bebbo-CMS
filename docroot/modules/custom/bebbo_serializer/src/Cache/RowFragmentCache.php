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
    $sets = [];
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
      $sets[$cid] = ['row' => $rendered, 'tags' => $tags, 'nid' => (int) $row->nid];
    }

    foreach ($sets as $cid => $item) {
      $this->cache->set($cid, ['row' => $item['row'], 'tags' => $item['tags']], Cache::PERMANENT, $item['tags']);
    }

    ksort($out);
    return array_values($out);
  }

}
