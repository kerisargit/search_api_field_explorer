<?php

namespace Drupal\Tests\search_api_field_explorer\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_field_explorer\Service\OrphanedTableFinder;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_field_explorer
 */
class OrphanedTableFinderTest extends UnitTestCase {

  private function makeEntityTypeManager(array $indexIds = ['test_index']): EntityTypeManagerInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine($indexIds, $indexIds));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('search_api_index')->willReturn($storage);
    return $manager;
  }

  private function makeFinder(KeyValueFactoryInterface $keyValueFactory, ?Connection $connection, array $indexIds = ['test_index']): OrphanedTableFinder {
    return new class($keyValueFactory, $this->makeEntityTypeManager($indexIds), $connection) extends OrphanedTableFinder {
      private ?Connection $mockConnection;

      public function __construct(KeyValueFactoryInterface $key_value_factory, EntityTypeManagerInterface $entity_type_manager, ?Connection $connection) {
        parent::__construct($key_value_factory, $entity_type_manager);
        $this->mockConnection = $connection;
      }

      protected function resolveConnection(IndexInterface $index): ?Connection {
        return $this->mockConnection;
      }
    };
  }

  private function makeIndex(string $backendId = 'search_api_db'): IndexInterface {
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendId')->willReturn($backendId);
    $server->method('getBackendConfig')->willReturn(['database' => 'default:default']);

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('test_index');
    $index->method('getServerInstance')->willReturn($server);
    return $index;
  }

  private function makeKeyValueFactory(?array $dbInfo, array $otherIndexes = []): KeyValueFactoryInterface {
    $store = $this->createMock(KeyValueStoreInterface::class);
    $store->method('get')->with('test_index')->willReturn($dbInfo);
    $store->method('getAll')->willReturn(($dbInfo !== NULL ? ['test_index' => $dbInfo] : []) + $otherIndexes);

    $factory = $this->createMock(KeyValueFactoryInterface::class);
    $factory->method('get')->with('search_api_db.indexes')->willReturn($store);
    return $factory;
  }

  private function makeConnection(array $tables, array $rowCounts = []): Connection {
    $connection = $this->createMock(Connection::class);

    $schema = $this->createMock(Schema::class);
    $schema->method('findTables')->willReturn($tables);
    $connection->method('schema')->willReturn($schema);

    $connection->method('select')->willReturnCallback(
      function (string $table) use ($rowCounts) {
        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($rowCounts[$table] ?? 0);

        $countQuery = $this->createMock(SelectInterface::class);
        $countQuery->method('execute')->willReturn($statement);

        $select = $this->createMock(SelectInterface::class);
        $select->method('countQuery')->willReturn($countQuery);
        return $select;
      }
    );

    return $connection;
  }

  /**
   * Tables of another index whose id starts with this one's are not orphans.
   */
  public function testIgnoresTablesOfIndexesWithALongerId(): void {
    $dbInfo = [
      'index_table' => 'search_api_db_test_index',
      'field_tables' => ['field_a' => ['table' => 'search_api_db_test_index_field_a']],
    ];
    $archive = [
      'index_table' => 'search_api_db_test_index_archive',
      'field_tables' => ['field_a' => ['table' => 'search_api_db_test_index_archive_field_a']],
    ];
    // findTables() uses LIKE, where '_' matches any character.
    $physicalTables = [
      'search_api_db_test_index_field_a',
      'search_api_db_test_index_archive',
      'search_api_db_test_index_archive_field_a',
      'search_api_db_test_index_archive_stale',
      'search_api_db_test_indexXfoo',
      'search_api_db_test_index_real_orphan',
    ];
    $finder = $this->makeFinder(
      $this->makeKeyValueFactory($dbInfo, ['test_index_archive' => $archive]),
      $this->makeConnection($physicalTables)
    );

    $this->assertSame(
      ['search_api_db_test_index_real_orphan'],
      array_column($finder->findOrphanedTables($this->makeIndex()), 'table')
    );
  }

  /**
   * A disabled read-only index keeps its tables but loses its key-value
   * entry (search_api_db's removeIndex()); only the entity list knows it.
   */
  public function testIgnoresTablesOfDisabledReadOnlyIndexWithLongerId(): void {
    $dbInfo = [
      'index_table' => 'search_api_db_test_index',
      'field_tables' => ['field_a' => ['table' => 'search_api_db_test_index_field_a']],
    ];
    $physicalTables = [
      'search_api_db_test_index_field_a',
      'search_api_db_test_index_archive',
      'search_api_db_test_index_archive_field_a',
      'search_api_db_test_index_real_orphan',
    ];
    $finder = $this->makeFinder(
      $this->makeKeyValueFactory($dbInfo),
      $this->makeConnection($physicalTables),
      ['test_index', 'test_index_archive']
    );

    $this->assertSame(
      ['search_api_db_test_index_real_orphan'],
      array_column($finder->findOrphanedTables($this->makeIndex()), 'table')
    );
  }

  public function testReturnsEmptyForNonSearchApiDbBackend(): void {
    $finder = new OrphanedTableFinder($this->makeKeyValueFactory(NULL), $this->makeEntityTypeManager());
    $index = $this->makeIndex('some_other_backend');

    $this->assertSame([], $finder->findOrphanedTables($index));
    $this->assertSame(
      ['dropped' => [], 'skipped' => ['some_table'], 'failed' => []],
      $finder->dropTables($index, ['some_table'])
    );
  }

  public function testReturnsEmptyWhenServerCannotBeLoaded(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('test_index');
    $index->method('getServerInstance')->willThrowException(new SearchApiException());

    $finder = new OrphanedTableFinder($this->makeKeyValueFactory(NULL), $this->makeEntityTypeManager());

    $this->assertSame([], $finder->findOrphanedTables($index));
  }

  public function testReturnsEmptyWhenNoDbInfoInKeyValueStore(): void {
    $finder = $this->makeFinder($this->makeKeyValueFactory(NULL), $this->makeConnection([]));
    $index = $this->makeIndex();

    $this->assertSame([], $finder->findOrphanedTables($index));
  }

  public function testFindsTablesNotReferencedInFieldTables(): void {
    $dbInfo = [
      'index_table' => 'search_api_db_test_index',
      'field_tables' => [
        'field_a' => ['table' => 'search_api_db_test_index_field_a'],
      ],
    ];
    $physicalTables = [
      'search_api_db_test_index_field_a',
      'search_api_db_test_index_orphan_one',
      'search_api_db_test_index_orphan_two',
    ];
    $rowCounts = [
      'search_api_db_test_index_orphan_one' => 0,
      'search_api_db_test_index_orphan_two' => 42,
    ];

    $finder = $this->makeFinder(
      $this->makeKeyValueFactory($dbInfo),
      $this->makeConnection($physicalTables, $rowCounts)
    );

    $result = $finder->findOrphanedTables($this->makeIndex());

    $this->assertSame([
      ['table' => 'search_api_db_test_index_orphan_one', 'rows' => 0],
      ['table' => 'search_api_db_test_index_orphan_two', 'rows' => 42],
    ], $result);
  }

  public function testDropFailureOfOneTableDoesNotStopTheOthers(): void {
    $dbInfo = ['index_table' => 'search_api_db_test_index', 'field_tables' => []];
    $connection = $this->makeConnection(['search_api_db_test_index_a', 'search_api_db_test_index_b']);
    $connection->schema()->method('dropTable')->willReturnCallback(function (string $table) {
      if ($table === 'search_api_db_test_index_a') {
        throw new \RuntimeException('lock wait timeout');
      }
    });
    $finder = $this->makeFinder($this->makeKeyValueFactory($dbInfo), $connection);

    $result = $finder->dropTables($this->makeIndex(), ['search_api_db_test_index_a', 'search_api_db_test_index_b']);

    $this->assertSame(['search_api_db_test_index_b'], $result['dropped']);
    $this->assertSame(['search_api_db_test_index_a' => 'lock wait timeout'], $result['failed']);
  }

  public function testNothingIsDroppedWhenTheRecheckFails(): void {
    $dbInfo = ['index_table' => 'search_api_db_test_index', 'field_tables' => []];
    $connection = $this->createMock(Connection::class);
    $schema = $this->createMock(Schema::class);
    $schema->method('findTables')->willThrowException(new \RuntimeException('db down'));
    $schema->expects($this->never())->method('dropTable');
    $connection->method('schema')->willReturn($schema);
    $finder = $this->makeFinder($this->makeKeyValueFactory($dbInfo), $connection);

    $result = $finder->dropTables($this->makeIndex(), ['search_api_db_test_index_a']);
    $this->assertSame(['search_api_db_test_index_a'], $result['skipped']);
  }

  public function testDropTablesOnlyDropsStillOrphanedOnes(): void {
    $dbInfo = [
      'index_table' => 'search_api_db_test_index',
      'field_tables' => [
        'field_a' => ['table' => 'search_api_db_test_index_no_longer_orphan'],
      ],
    ];

    $connection = $this->createMock(Connection::class);
    $schema = $this->createMock(Schema::class);
    $schema->method('findTables')->willReturn([
      'search_api_db_test_index_no_longer_orphan',
      'search_api_db_test_index_still_orphan',
    ]);
    $dropped = [];
    $schema->method('dropTable')->willReturnCallback(function (string $table) use (&$dropped) {
      $dropped[] = $table;
    });
    $connection->method('schema')->willReturn($schema);
    $connection->method('select')->willReturnCallback(function (string $table) {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchField')->willReturn(0);
      $countQuery = $this->createMock(SelectInterface::class);
      $countQuery->method('execute')->willReturn($statement);
      $select = $this->createMock(SelectInterface::class);
      $select->method('countQuery')->willReturn($countQuery);
      return $select;
    });

    $finder = $this->makeFinder($this->makeKeyValueFactory($dbInfo), $connection);

    $result = $finder->dropTables($this->makeIndex(), [
      'search_api_db_test_index_no_longer_orphan',
      'search_api_db_test_index_still_orphan',
    ]);

    $this->assertSame(['search_api_db_test_index_still_orphan'], $result['dropped']);
    $this->assertSame(['search_api_db_test_index_no_longer_orphan'], $result['skipped']);
    $this->assertSame(['search_api_db_test_index_still_orphan'], $dropped);
  }

  public function testRowCountFailureIsReportedAsUnknownNotFatal(): void {
    $dbInfo = ['index_table' => 'search_api_db_test_index', 'field_tables' => []];

    $connection = $this->createMock(Connection::class);
    $schema = $this->createMock(Schema::class);
    $schema->method('findTables')->willReturn(['search_api_db_test_index_gone']);
    $connection->method('schema')->willReturn($schema);
    $connection->method('select')->willThrowException(new \RuntimeException('table vanished'));

    $finder = $this->makeFinder($this->makeKeyValueFactory($dbInfo), $connection);

    $result = $finder->findOrphanedTables($this->makeIndex());

    $this->assertSame([
      ['table' => 'search_api_db_test_index_gone', 'rows' => NULL],
    ], $result);
  }

}
