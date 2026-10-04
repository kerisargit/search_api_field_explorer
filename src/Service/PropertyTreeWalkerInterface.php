<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\search_api\IndexInterface;

interface PropertyTreeWalkerInterface {

  public function getTree(IndexInterface $index, ?string $datasource_id, int $maxDepth): array;

  public function invalidate(?string $indexId = NULL): void;

}
