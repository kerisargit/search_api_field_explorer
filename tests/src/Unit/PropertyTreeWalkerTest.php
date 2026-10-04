<?php

namespace Drupal\Tests\search_api_field_explorer\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Utility\DataTypeHelperInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api_field_explorer\Service\EntityTargetResolver;
use Drupal\search_api_field_explorer\Service\PropertyTreeWalker;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_field_explorer
 */
class PropertyTreeWalkerTest extends UnitTestCase {

  private function makeConfigFactory(int $nodeBudget = 0): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('node_budget')->willReturn($nodeBudget);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('search_api_field_explorer.settings')->willReturn($config);
    return $configFactory;
  }

  private function makeCache(): CacheBackendInterface {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    return $cache;
  }

  private function makeEntityFieldManager(): EntityFieldManagerInterface {
    return $this->createMock(EntityFieldManagerInterface::class);
  }

  private function makeIndex(array $rootProperties): IndexInterface {
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('test_index');
    $index->method('getCacheTags')->willReturn([]);
    $index->method('getFields')->willReturn([]);
    $index->method('getPropertyDefinitions')->willReturn($rootProperties);
    return $index;
  }

  public function testCycleGuardStopsSelfReferencingChain(): void {
    $refFieldItem = $this->createMock(ComplexDataDefinitionInterface::class);
    $refFieldItem->method('getLabel')->willReturn('Ref');
    $refFieldItem->method('getMainPropertyName')->willReturn('target_id');
    $refFieldItem->method('getDataType')->willReturn('field_item:entity_reference');

    $targetIdProp = $this->createMock(DataDefinitionInterface::class);
    $targetIdProp->method('getDataType')->willReturn('integer');

    $entityRef = $this->createMock(DataReferenceDefinitionInterface::class);
    $entityRef->method('getLabel')->willReturn('Entity');

    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('test_entity');
    $entityDef->method('getBundles')->willReturn(NULL);
    $entityDef->method('getMainPropertyName')->willReturn(NULL);

    $entityRef->method('getTargetDefinition')->willReturn($entityDef);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('isContentEntityType')->with('test_entity')->willReturn(TRUE);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([]);
    $fieldsHelper->method('getInnerProperty')->willReturnCallback(
      function ($property) use ($refFieldItem, $entityRef, $entityDef) {
        if ($property === $entityRef) {
          return $entityDef;
        }
        return $property;
      }
    );
    $fieldsHelper->method('getNestedProperties')->willReturnCallback(
      function ($property) use ($refFieldItem, $entityRef, $targetIdProp, $entityDef) {
        if ($property === $refFieldItem) {
          return ['target_id' => $targetIdProp, 'entity' => $entityRef];
        }
        if ($property === $entityDef) {
          return ['ref' => $refFieldItem];
        }
        return [];
      }
    );

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn(['integer' => 'integer']);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(), $this->makeEntityFieldManager());

    $index = $this->makeIndex(['ref' => $refFieldItem]);
    $result = $walker->getTree($index, NULL, 8);

    $this->assertFalse($result['truncated']);

    $byPath = [];
    foreach ($result['nodes'] as $node) {
      $byPath[$node['property_path']] = $node;
    }

    $this->assertCount(4, $result['nodes']);
    $this->assertArrayHasKey('ref', $byPath);
    $this->assertArrayHasKey('ref:entity', $byPath);
    $this->assertArrayHasKey('ref:entity:ref', $byPath);
    $this->assertArrayHasKey('ref:entity:ref:entity', $byPath);
    $this->assertArrayNotHasKey('ref:entity:ref:entity:ref', $byPath);

    $this->assertSame('test_entity', $byPath['ref']['target_entity_type']);
    $this->assertFalse($byPath['ref']['cycle_stopped']);

    $this->assertFalse($byPath['ref:entity']['cycle_stopped']);
    $this->assertFalse($byPath['ref:entity:ref']['cycle_stopped']);

    $this->assertSame('test_entity', $byPath['ref:entity:ref:entity']['target_entity_type']);
    $this->assertTrue($byPath['ref:entity:ref:entity']['cycle_stopped']);
  }

  public function testInheritedBundleRestrictionNarrowsEntityHopChildren(): void {
    $rootItem = $this->createMock(ComplexDataDefinitionInterface::class);
    $rootItem->method('getLabel')->willReturn('Root');
    $rootItem->method('getMainPropertyName')->willReturn('target_id');
    $rootItem->method('getDataType')->willReturn('field_item:entity_reference');

    $targetIdProp = $this->createMock(DataDefinitionInterface::class);
    $targetIdProp->method('getDataType')->willReturn('integer');

    $entityRef = $this->createMock(DataReferenceDefinitionInterface::class);
    $entityRef->method('getLabel')->willReturn('Entity');

    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('test_entity');
    $entityDef->method('getBundles')->willReturn(NULL);
    $entityDef->method('getMainPropertyName')->willReturn(NULL);

    $entityRef->method('getTargetDefinition')->willReturn($entityDef);

    $fieldRoot = $this->createMock(FieldDefinitionInterface::class);
    $fieldRoot->method('getLabel')->willReturn('Root Field');
    $fieldRoot->method('getSetting')->with('handler_settings')->willReturn([
      'target_bundles' => ['bundle_a' => 'bundle_a'],
    ]);

    $onlyInA = $this->createMock(DataDefinitionInterface::class);
    $onlyInA->method('getLabel')->willReturn('Only in A');
    $onlyInA->method('getDataType')->willReturn('string');

    $onlyInB = $this->createMock(DataDefinitionInterface::class);
    $onlyInB->method('getLabel')->willReturn('Only in B');
    $onlyInB->method('getDataType')->willReturn('string');

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('isContentEntityType')->willReturn(TRUE);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([]);
    $fieldsHelper->method('getInnerProperty')->willReturnCallback(
      function ($property) use ($fieldRoot, $rootItem, $entityRef, $entityDef) {
        if ($property === $fieldRoot) {
          return $rootItem;
        }
        if ($property === $entityRef) {
          return $entityDef;
        }
        return $property;
      }
    );
    $fieldsHelper->method('getNestedProperties')->willReturnCallback(
      function ($property) use ($rootItem, $entityRef, $targetIdProp, $entityDef, $onlyInA, $onlyInB) {
        if ($property === $rootItem) {
          return ['target_id' => $targetIdProp, 'entity' => $entityRef];
        }
        if ($property === $entityDef) {
          return ['field_a' => $onlyInA, 'field_b' => $onlyInB];
        }
        return [];
      }
    );

    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $entityFieldManager->method('getFieldDefinitions')->willReturnCallback(
      function (string $entityTypeId, string $bundle) use ($onlyInA) {
        return ($entityTypeId === 'test_entity' && $bundle === 'bundle_a')
          ? ['field_a' => $onlyInA]
          : [];
      }
    );

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn(['string' => 'string']);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(), $entityFieldManager);

    $index = $this->makeIndex(['field_root' => $fieldRoot]);
    $result = $walker->getTree($index, NULL, 8);

    $byPath = [];
    foreach ($result['nodes'] as $node) {
      $byPath[$node['property_path']] = $node;
    }

    $this->assertArrayHasKey('field_root:entity:field_a', $byPath);
    $this->assertArrayNotHasKey('field_root:entity:field_b', $byPath);
    $this->assertSame(['bundle_a'], $byPath['field_root:entity']['target_bundles']);
  }

  public function testNodeBudgetTruncatesWidePropertySet(): void {
    $properties = [];
    for ($i = 0; $i < 10; $i++) {
      $prop = $this->createMock(DataDefinitionInterface::class);
      $prop->method('getLabel')->willReturn('Field ' . $i);
      $prop->method('getDataType')->willReturn('string');
      $properties['field_' . $i] = $prop;
    }

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->willReturnArgument(0);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([]);

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn(['string' => 'string']);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(3), $this->makeEntityFieldManager());

    $index = $this->makeIndex($properties);
    $result = $walker->getTree($index, NULL, 8);

    $this->assertTrue($result['truncated']);
    $this->assertCount(3, $result['nodes']);
  }

  public function testMaxDepthIsClampedRegardlessOfInput(): void {
    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->willReturnArgument(0);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([]);

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn([]);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(), $this->makeEntityFieldManager());

    $index = $this->makeIndex([]);
    $result = $walker->getTree($index, NULL, 999);
    $this->assertSame([], $result['nodes']);
    $this->assertFalse($result['truncated']);
  }

  public function testAlreadyIndexedAsListsEveryMatchingField(): void {
    $prop = $this->createMock(DataDefinitionInterface::class);
    $prop->method('getLabel')->willReturn('Field A');
    $prop->method('getDataType')->willReturn('string');

    $existingFieldA = $this->createMock(\Drupal\search_api\Item\FieldInterface::class);
    $existingFieldA->method('getFieldIdentifier')->willReturn('field_a_source');
    $existingFieldB = $this->createMock(\Drupal\search_api\Item\FieldInterface::class);
    $existingFieldB->method('getFieldIdentifier')->willReturn('field_a_aggregated');

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->willReturnArgument(0);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([$existingFieldA, $existingFieldB]);

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn(['string' => 'string']);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(), $this->makeEntityFieldManager());

    $index = $this->makeIndex(['field_a' => $prop]);
    $result = $walker->getTree($index, NULL, 8);

    $this->assertSame(['field_a_source', 'field_a_aggregated'], $result['nodes'][0]['already_indexed_as']);
  }

  public function testAlreadyIndexedAsIsEmptyArrayWhenNoMatch(): void {
    $prop = $this->createMock(DataDefinitionInterface::class);
    $prop->method('getLabel')->willReturn('Field A');
    $prop->method('getDataType')->willReturn('string');

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->willReturnArgument(0);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([]);

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn(['string' => 'string']);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(), $this->makeEntityFieldManager());

    $index = $this->makeIndex(['field_a' => $prop]);
    $result = $walker->getTree($index, NULL, 8);

    $this->assertSame([], $result['nodes'][0]['already_indexed_as']);
  }

  public function testLabelPathSkipsEntityHopLabel(): void {
    $fieldA = $this->createMock(ComplexDataDefinitionInterface::class);
    $fieldA->method('getLabel')->willReturn('Field A');
    $fieldA->method('getMainPropertyName')->willReturn('target_id');
    $fieldA->method('getDataType')->willReturn('field_item:entity_reference');

    $targetIdProp = $this->createMock(DataDefinitionInterface::class);
    $targetIdProp->method('getDataType')->willReturn('integer');

    $entityRef = $this->createMock(DataReferenceDefinitionInterface::class);
    $entityRef->method('getLabel')->willReturn('Some Entity Type Label');

    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('test_entity');
    $entityDef->method('getBundles')->willReturn(NULL);
    $entityDef->method('getMainPropertyName')->willReturn(NULL);
    $entityRef->method('getTargetDefinition')->willReturn($entityDef);

    $fieldB = $this->createMock(DataDefinitionInterface::class);
    $fieldB->method('getLabel')->willReturn('Field B');
    $fieldB->method('getDataType')->willReturn('string');

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('isContentEntityType')->with('test_entity')->willReturn(TRUE);
    $fieldsHelper->method('filterForPropertyPath')->willReturn([]);
    $fieldsHelper->method('getInnerProperty')->willReturnCallback(
      function ($property) use ($entityRef, $entityDef) {
        return $property === $entityRef ? $entityDef : $property;
      }
    );
    $fieldsHelper->method('getNestedProperties')->willReturnCallback(
      function ($property) use ($fieldA, $targetIdProp, $entityRef, $entityDef, $fieldB) {
        if ($property === $fieldA) {
          return ['target_id' => $targetIdProp, 'entity' => $entityRef];
        }
        if ($property === $entityDef) {
          return ['field_b' => $fieldB];
        }
        return [];
      }
    );

    $dataTypeHelper = $this->createMock(DataTypeHelperInterface::class);
    $dataTypeHelper->method('getFieldTypeMapping')->willReturn(['integer' => 'integer', 'string' => 'string']);

    $resolver = new EntityTargetResolver($fieldsHelper);
    $walker = new PropertyTreeWalker($fieldsHelper, $dataTypeHelper, $resolver, $this->makeCache(), $this->makeConfigFactory(), $this->makeEntityFieldManager());

    $index = $this->makeIndex(['field_a' => $fieldA]);
    $result = $walker->getTree($index, NULL, 8);

    $byPath = [];
    foreach ($result['nodes'] as $node) {
      $byPath[$node['property_path']] = $node;
    }

    $this->assertSame('Field A', $byPath['field_a']['label_path']);
    $this->assertSame('Field A » Some Entity Type Label', $byPath['field_a:entity']['label_path']);
    $this->assertSame('Field A » Field B', $byPath['field_a:entity:field_b']['label_path']);

    $this->assertSame('Field B', $byPath['field_a:entity:field_b']['label']);
    $this->assertSame('Field A', $byPath['field_a']['label']);
  }

}
