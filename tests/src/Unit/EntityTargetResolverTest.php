<?php

namespace Drupal\Tests\search_api_field_explorer\Unit;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api_field_explorer\Service\EntityTargetResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_field_explorer
 */
class EntityTargetResolverTest extends UnitTestCase {

  private function makeResolver(FieldsHelperInterface $fieldsHelper): EntityTargetResolver {
    return new EntityTargetResolver($fieldsHelper);
  }

  public function testPlainScalarPropertyResolvesToNull(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($property);

    $resolver = $this->makeResolver($fieldsHelper);
    $this->assertNull($resolver->resolveTarget($property));
  }

  public function testDirectEntityReferencePropertyResolvesTarget(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('node');
    $entityDef->method('getBundles')->willReturn(['article']);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($entityDef);
    $fieldsHelper->method('isContentEntityType')->with('node')->willReturn(TRUE);

    $resolver = $this->makeResolver($fieldsHelper);
    $result = $resolver->resolveTarget($property);

    $this->assertSame(['entity_type_id' => 'node', 'bundles' => ['article']], $result);
  }

  public function testComplexPropertyWithNestedEntityReferenceResolvesTarget(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $complexInner = $this->createMock(ComplexDataDefinitionInterface::class);
    $entityRef = $this->createMock(DataReferenceDefinitionInterface::class);
    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);

    $entityDef->method('getEntityTypeId')->willReturn('taxonomy_term');
    $entityDef->method('getBundles')->willReturn(NULL);
    $entityRef->method('getTargetDefinition')->willReturn($entityDef);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($complexInner);
    $fieldsHelper->method('getNestedProperties')->with($complexInner)->willReturn(['entity' => $entityRef, 'target_id' => $this->createMock(DataDefinitionInterface::class)]);
    $fieldsHelper->method('isContentEntityType')->with('taxonomy_term')->willReturn(TRUE);

    $resolver = $this->makeResolver($fieldsHelper);
    $result = $resolver->resolveTarget($property);

    $this->assertSame(['entity_type_id' => 'taxonomy_term', 'bundles' => NULL], $result);
  }

  public function testComplexPropertyWithoutEntityNestedKeyResolvesToNull(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $complexInner = $this->createMock(ComplexDataDefinitionInterface::class);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($complexInner);
    $fieldsHelper->method('getNestedProperties')->with($complexInner)->willReturn(['value' => $this->createMock(DataDefinitionInterface::class)]);

    $resolver = $this->makeResolver($fieldsHelper);
    $this->assertNull($resolver->resolveTarget($property));
  }

  public function testEntityReferenceToNonContentEntityTypeResolvesToNull(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('some_config_entity');

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($entityDef);
    $fieldsHelper->method('isContentEntityType')->with('some_config_entity')->willReturn(FALSE);

    $resolver = $this->makeResolver($fieldsHelper);
    $this->assertNull($resolver->resolveTarget($property));
  }

  public function testNullEntityTypeIdResolvesToNull(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn(NULL);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($entityDef);

    $resolver = $this->makeResolver($fieldsHelper);
    $this->assertNull($resolver->resolveTarget($property));
  }

  public function testFallsBackToHandlerSettingsWhenEntityDefinitionHasNoBundles(): void {
    $property = $this->createMock(FieldDefinitionInterface::class);
    $property->method('getSetting')->with('handler_settings')->willReturn([
      'target_bundles' => ['thematic_mark' => 'thematic_mark'],
    ]);

    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('taxonomy_term');
    $entityDef->method('getBundles')->willReturn(NULL);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($entityDef);
    $fieldsHelper->method('isContentEntityType')->with('taxonomy_term')->willReturn(TRUE);

    $resolver = $this->makeResolver($fieldsHelper);
    $result = $resolver->resolveTarget($property);

    $this->assertSame(['entity_type_id' => 'taxonomy_term', 'bundles' => ['thematic_mark']], $result);
  }

  public function testDoesNotOverrideNonEmptyGetBundlesWithHandlerSettings(): void {
    $property = $this->createMock(FieldDefinitionInterface::class);
    $property->method('getSetting')->with('handler_settings')->willReturn([
      'target_bundles' => ['wrong_bundle' => 'wrong_bundle'],
    ]);

    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('taxonomy_term');
    $entityDef->method('getBundles')->willReturn(['correct_bundle']);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($entityDef);
    $fieldsHelper->method('isContentEntityType')->with('taxonomy_term')->willReturn(TRUE);

    $resolver = $this->makeResolver($fieldsHelper);
    $result = $resolver->resolveTarget($property);

    $this->assertSame(['correct_bundle'], $result['bundles']);
  }

  public function testHandlerSettingsFallbackIsNullForNonFieldProperties(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $entityDef = $this->createMock(EntityDataDefinitionInterface::class);
    $entityDef->method('getEntityTypeId')->willReturn('taxonomy_term');
    $entityDef->method('getBundles')->willReturn(NULL);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('getInnerProperty')->with($property)->willReturn($entityDef);
    $fieldsHelper->method('isContentEntityType')->with('taxonomy_term')->willReturn(TRUE);

    $resolver = $this->makeResolver($fieldsHelper);
    $result = $resolver->resolveTarget($property);

    $this->assertNull($result['bundles']);
  }

}
