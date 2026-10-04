<?php

namespace Drupal\search_api_field_explorer;

final class RowKey {

  private const MIN_DEPTH = 1;
  private const MAX_DEPTH = 20;
  private const DEFAULT_DEPTH = 5;

  private function __construct() {
  }

  public static function clampDepth(int $depth): int {
    if ($depth < self::MIN_DEPTH || $depth > self::MAX_DEPTH) {
      return $depth <= 0 ? self::DEFAULT_DEPTH : \max(self::MIN_DEPTH, \min(self::MAX_DEPTH, $depth));
    }
    return $depth;
  }

  public static function encode(string $indexId, string $datasourceKey, string $propertyPath): string {
    return $indexId . '||' . $datasourceKey . '||' . $propertyPath;
  }

  public static function decode(string $key): array {
    $parts = \explode('||', $key, 3);
    return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
  }

  public static function memberKey(array $node): string {
    return ($node['index_id'] ?? '') . '::' . ($node['datasource_id'] ?? '') . '::' . $node['property_path'];
  }

  public static function indexGroupsByMember(array $groups): array {
    $byMember = [];
    foreach ($groups as $group) {
      foreach ($group['members'] as $member) {
        $byMember[self::memberKey($member)] = $group;
      }
    }
    return $byMember;
  }

}
