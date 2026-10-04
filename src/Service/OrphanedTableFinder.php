<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database as CoreDatabase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;

class OrphanedTableFinder {

  private const KEY_VALUE_COLLECTION = 'search_api_db.indexes';
  private const BACKEND_PLUGIN_ID = 'search_api_db';

  protected KeyValueFactoryInterface $keyValueFactory;

  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->keyValueFactory = $key_value_factory;
  }

  public function findOrphanedTables(IndexInterface $index): array {
    $connection = $this->resolveConnection($index);
    if (!$connection) {
      return [];
    }

    [$referenced, $tablePrefix, $otherPrefixes] = $this->getReferencedTables($index);
    if ($referenced === NULL) {
      return [];
    }

    $orphans = [];
    foreach ($connection->schema()->findTables($tablePrefix . '_%') as $table) {
      // findTables() is a LIKE pattern, so '_' matches any character.
      if (!str_starts_with($table, $tablePrefix . '_') || isset($referenced[$table])) {
        continue;
      }
      // Index "content" must not claim tables of index "content_archive".
      foreach ($otherPrefixes as $other) {
        if ($table === $other || str_starts_with($table, $other . '_')) {
          continue 2;
        }
      }
      $orphans[$table] = $this->countRows($connection, $table);
    }
    \ksort($orphans);

    $result = [];
    foreach ($orphans as $table => $rows) {
      $result[] = ['table' => $table, 'rows' => $rows];
    }
    return $result;
  }

  public function dropTables(IndexInterface $index, array $tableNames): array {
    $connection = $this->resolveConnection($index);
    if (!$connection) {
      return ['dropped' => [], 'skipped' => $tableNames, 'failed' => []];
    }

    // If the re-check itself fails, nothing is verified, so nothing is dropped.
    try {
      $stillOrphaned = \array_flip(\array_column($this->findOrphanedTables($index), 'table'));
    }
    catch (\Throwable) {
      return ['dropped' => [], 'skipped' => $tableNames, 'failed' => []];
    }

    $dropped = [];
    $skipped = [];
    $failed = [];
    foreach ($tableNames as $table) {
      if (!isset($stillOrphaned[$table])) {
        $skipped[] = $table;
        continue;
      }
      try {
        $connection->schema()->dropTable($table);
        $dropped[] = $table;
      }
      catch (\Throwable $e) {
        $failed[$table] = $e->getMessage();
      }
    }

    return ['dropped' => $dropped, 'skipped' => $skipped, 'failed' => $failed];
  }

  /**
   * @return array{0: array<string, true>|null, 1: string, 2: string[]}
   *   Tables referenced by any search_api_db index (NULL if this index has
   *   no stored info), this index's table prefix, and the table prefixes of
   *   other indexes whose id starts with this one's.
   */
  private function getReferencedTables(IndexInterface $index): array {
    $prefix = self::BACKEND_PLUGIN_ID . '_' . $index->id();

    if (!$this->usesSearchApiDbBackend($index)) {
      return [NULL, $prefix, []];
    }

    $store = $this->keyValueFactory->get(self::KEY_VALUE_COLLECTION);
    if (!$store->get($index->id())) {
      return [NULL, $prefix, []];
    }

    $referenced = [];
    $allDbInfo = $store->getAll();
    foreach ($allDbInfo as $dbInfo) {
      if (!\is_array($dbInfo)) {
        continue;
      }
      if (!empty($dbInfo['index_table'])) {
        $referenced[$dbInfo['index_table']] = TRUE;
      }
      foreach ($dbInfo['field_tables'] ?? [] as $info) {
        if (!empty($info['table'])) {
          $referenced[$info['table']] = TRUE;
        }
      }
    }

    // Index ids from both sources: a disabled read-only index keeps its
    // tables but has no key-value entry any more, only the entity.
    $indexIds = \array_keys($allDbInfo);
    foreach ($this->entityTypeManager->getStorage('search_api_index')->getQuery()->accessCheck(FALSE)->execute() as $id) {
      $indexIds[] = (string) $id;
    }
    $otherPrefixes = [];
    foreach (\array_unique($indexIds) as $indexId) {
      $otherPrefix = self::BACKEND_PLUGIN_ID . '_' . $indexId;
      if ($indexId !== $index->id() && str_starts_with($otherPrefix, $prefix . '_')) {
        $otherPrefixes[] = $otherPrefix;
      }
    }

    return [$referenced, $prefix, $otherPrefixes];
  }

  private function usesSearchApiDbBackend(IndexInterface $index): bool {
    try {
      $server = $index->getServerInstance();
    }
    catch (SearchApiException) {
      return FALSE;
    }
    return $server && $server->getBackendId() === self::BACKEND_PLUGIN_ID;
  }

  protected function resolveConnection(IndexInterface $index): ?Connection {
    if (!$this->usesSearchApiDbBackend($index)) {
      return NULL;
    }

    try {
      $server = $index->getServerInstance();
    }
    catch (SearchApiException) {
      return NULL;
    }
    if (!$server) {
      return NULL;
    }

    $databaseSetting = $server->getBackendConfig()['database'] ?? NULL;
    if (!$databaseSetting || !\str_contains($databaseSetting, ':')) {
      return NULL;
    }
    [$key, $target] = \explode(':', $databaseSetting, 2);

    try {
      return CoreDatabase::getConnection($target, $key);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  private function countRows(Connection $connection, string $table): ?int {
    try {
      return (int) $connection->select($table)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception) {
      return NULL;
    }
  }

}
