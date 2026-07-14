<?php

declare(strict_types=1);

namespace Drupal\bebbo_serializer\Warmer;

use Drupal\bebbo_serializer\Cache\RowFragmentCache;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Pre-renders the large Bebbo article list endpoints into the fragment cache.
 *
 * A cold /api/articles/{lang} renders every result row inline and, for
 * content-heavy languages, exceeds the front-end gateway timeout before it can
 * finish — so a real visitor never warms it and the endpoint stays inaccessible
 * after every deploy or cache flush. This warmer runs from cron (out of band,
 * with no execution-time limit) and issues an internal request per language, so
 * the fragment cache is populated before any visitor arrives.
 *
 * It only renders languages whose warm marker is absent, so once an endpoint is
 * warm the pass is cheap; a node/media edit or full flush expires the marker
 * (it carries the article list tags) and the next run re-warms just that scope.
 */
class ArticleFragmentWarmer {

  /**
   * Endpoint path prefixes to warm; the language code is appended to each.
   */
  private const PATHS = ['api/articles', 'v2/api/articles'];

  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected LanguageManagerInterface $languageManager,
    protected RequestStack $requestStack,
    protected RowFragmentCache $fragmentCache,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Warms every cold article endpoint/language for the current site.
   */
  public function warm(): void {
    $current = $this->requestStack->getCurrentRequest();
    $base = $current !== NULL ? $current->getSchemeAndHttpHost() : '';
    if ($base === '') {
      // Without a host the rendered cache id would not match live traffic
      // (the cid is keyed by host), so warming would be wasted. This happens
      // when cron is invoked without a site URI.
      $this->logger->warning('Article fragment warmer skipped: no request host available (run cron with a site URI).');
      return;
    }

    $langcodes = array_keys($this->languageManager->getLanguages());
    $warmed = 0;
    foreach (self::PATHS as $path) {
      foreach ($langcodes as $langcode) {
        if ($this->fragmentCache->isWarm($path, $langcode, $base)) {
          continue;
        }

        $request = Request::create($base . '/' . $path . '/' . $langcode);
        try {
          $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST);
        }
        catch (\Throwable $e) {
          $this->logger->error('Article fragment warmer failed for @path/@lang: @msg', [
            '@path' => $path,
            '@lang' => $langcode,
            '@msg' => $e->getMessage(),
          ]);
          continue;
        }

        if ($response->getStatusCode() === 200) {
          $this->fragmentCache->markWarm($path, $langcode, $base);
          $warmed++;
        }
      }
    }

    if ($warmed > 0) {
      $this->logger->info('Article fragment warmer rendered @count cold endpoint(s) for @host.', [
        '@count' => $warmed,
        '@host' => $base,
      ]);
    }
  }

}
