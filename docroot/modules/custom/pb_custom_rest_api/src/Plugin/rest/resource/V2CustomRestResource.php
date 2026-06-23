<?php

namespace Drupal\pb_custom_rest_api\Plugin\rest\resource;

/**
 * V2 endpoint for the force-update check API.
 *
 * @RestResource(
 *   id = "v2_custom_rest_resource",
 *   label = @Translation("V2 Custom Rest Resource"),
 *   uri_paths = {
 *     "canonical" = "/v2/api/check-update/{country}"
 *   }
 * )
 */
class V2CustomRestResource extends CustomRestResource {

}
