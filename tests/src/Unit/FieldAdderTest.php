<?php

namespace Drupal\Tests\search_api_field_explorer\Unit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api_field_explorer\Service\FieldAdder;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_field_explorer
 */
class FieldAdderTest extends UnitTestCase {

  private function addOneField(string $propertyPath, array $existingFieldIds, ?string &$capturedFieldId): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $field = $this->createMock(FieldInterface::class);
    $field->method('getFieldIdentifier')->willReturn('irrelevant');

    $index = $this->createMock(IndexInterface::class);
    $index->method('getPropertyDefinitions')->willReturn(['root' => $property]);
    $index->method('getField')->willReturnCallback(
      static fn (string $fieldId) => \in_array($fieldId, $existingFieldIds, TRUE) ? TRUE : NULL
    );

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('retrieveNestedProperty')->willReturn($property);
    $fieldsHelper->method('createFieldFromProperty')->willReturnCallback(
      function ($index, $property, $datasourceId, $propertyPath, $fieldId, $type) use ($field, &$capturedFieldId) {
        $capturedFieldId = $fieldId;
        return $field;
      }
    );

    $adder = new FieldAdder($fieldsHelper);
    $adder->addSelectedFields($index, [[
      'datasource_id'   => NULL,
      'property_path'   => $propertyPath,
      'search_api_type' => 'integer',
      'label_path'      => 'Some » Label',
    ]]);
  }

  public function testFieldIdUsesLeafAndHash(): void {
    $captured = NULL;
    $this->addOneField('field_senses:entity:field_theme_mark', [], $captured);

    $this->assertMatchesRegularExpression('/^theme_mark_[0-9a-z]{8}$/', $captured);
  }

  public function testFieldIdStripsEntityAndFieldPrefixFromLeaf(): void {
    $captured = NULL;
    $this->addOneField('field_headword:entity:field_elements:entity:field_author_mark', [], $captured);

    $this->assertMatchesRegularExpression('/^author_mark_[0-9a-z]{8}$/', $captured);
  }

  public function testFieldIdHandlesNonFieldPrefixedSegments(): void {
    $captured = NULL;
    $this->addOneField('uid:entity:field_roles', [], $captured);

    $this->assertMatchesRegularExpression('/^roles_[0-9a-z]{8}$/', $captured);
  }

  public function testFieldIdDiffersForDifferentChainsWithSameLeaf(): void {
    $capturedA = NULL;
    $this->addOneField('field_headword:entity:field_elements:entity:field_author_mark', [], $capturedA);
    $capturedB = NULL;
    $this->addOneField('field_senses:entity:field_items:entity:field_author_mark', [], $capturedB);

    $this->assertNotSame($capturedA, $capturedB);
    $this->assertStringStartsWith('author_mark_', $capturedA);
    $this->assertStringStartsWith('author_mark_', $capturedB);
  }

  public function testFieldIdIsDeterministicForSamePath(): void {
    $capturedA = NULL;
    $this->addOneField('field_senses:entity:field_theme_mark', [], $capturedA);
    $capturedB = NULL;
    $this->addOneField('field_senses:entity:field_theme_mark', [], $capturedB);

    $this->assertSame($capturedA, $capturedB);
  }

  public function testFieldIdDedupesAgainstExistingFields(): void {
    $probe = NULL;
    $this->addOneField('field_senses:entity:field_theme_mark', [], $probe);

    $captured = NULL;
    $this->addOneField('field_senses:entity:field_theme_mark', [$probe, $probe . '_1'], $captured);

    $this->assertSame($probe . '_2', $captured);
  }

  public function testFieldIdIsShortRegardlessOfChainDepth(): void {
    $captured = NULL;
    $longPath = \implode(':entity:', [
      'field_' . \str_repeat('a', 20),
      'field_' . \str_repeat('b', 20),
      'field_' . \str_repeat('c', 20),
      'field_' . \str_repeat('d', 20),
      'field_leaf',
    ]);
    $this->addOneField($longPath, [], $captured);

    $this->assertLessThanOrEqual(30, \strlen($captured));
    $this->assertStringStartsWith('leaf_', $captured);
  }

  public function testFieldIdTruncatesAnOverlongLeafSegment(): void {
    $captured = NULL;
    $this->addOneField('field_' . \str_repeat('x', 40), [], $captured);

    $this->assertMatchesRegularExpression('/^x{24}_[0-9a-z]{8}$/', $captured);
  }

  private function addWithDuplicateOption(bool $alsoAsTarget, ?string $existingAggregatedId = NULL): array
  {
    $property = $this->createMock(DataDefinitionInterface::class);

    $primaryField = $this->createMock(FieldInterface::class);
    $primaryField->method('getFieldIdentifier')->willReturn('theme_mark_a1b2c3d4');
    $primaryField->method('getLabel')->willReturn('Original Label');
    $primaryLabels = [];
    $primaryField->method('setLabel')->willReturnCallback(static function (string $label) use (&$primaryLabels): void {
      $primaryLabels[] = $label;
    });

    $dupField = $this->createMock(FieldInterface::class);
    $dupLabels = [];
    $dupField->method('setLabel')->willReturnCallback(static function (string $label) use (&$dupLabels): void {
      $dupLabels[] = $label;
    });

    $index = $this->createMock(IndexInterface::class);
    $index->method('getPropertyDefinitions')->willReturn(['root' => $property]);
    $index->method('getField')->willReturnCallback(
      static fn (string $fieldId) => $existingAggregatedId !== NULL && $fieldId === $existingAggregatedId ? TRUE : NULL
    );
    $addedFields = [];
    $index->method('addField')->willReturnCallback(static function ($field) use (&$addedFields): void {
      $addedFields[] = $field;
    });

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('retrieveNestedProperty')->willReturn($property);

    $fieldIds = [];
    $callCount = 0;
    $fieldsHelper->method('createFieldFromProperty')->willReturnCallback(
      function ($index, $property, $datasourceId, $propertyPath, $fieldId, $type) use (&$callCount, &$fieldIds, $primaryField, $dupField) {
        $callCount++;
        $fieldIds[] = $fieldId;
        return $callCount === 1 ? $primaryField : $dupField;
      }
    );

    $adder = new FieldAdder($fieldsHelper);
    $summary = $adder->addSelectedFields($index, [[
      'datasource_id'              => NULL,
      'property_path'              => 'field_senses:entity:field_theme_mark',
      'search_api_type'            => 'integer',
      'label_path'                 => 'Original Label',
      'also_as_aggregation_target' => $alsoAsTarget,
    ]]);

    return [
      'fieldIds' => $fieldIds,
      'primaryLabels' => $primaryLabels,
      'dupLabels' => $dupLabels,
      'summary' => $summary,
      'addedCount' => \count($addedFields),
    ];
  }

  public function testAlsoAsAggregationTargetCreatesASecondField(): void {
    $result = $this->addWithDuplicateOption(TRUE);

    $this->assertCount(2, $result['fieldIds']);
    $this->assertStringEndsWith('_agg', $result['fieldIds'][1]);
    $this->assertSame(1, $result['summary']['added']);
    $this->assertSame(1, $result['summary']['duplicated']);
    $this->assertSame(2, $result['addedCount']);
  }

  public function testAlsoAsAggregationTargetIsOffByDefault(): void {
    $result = $this->addWithDuplicateOption(FALSE);

    $this->assertCount(1, $result['fieldIds']);
    $this->assertSame(1, $result['summary']['added']);
    $this->assertSame(0, $result['summary']['duplicated']);
    $this->assertSame(1, $result['addedCount']);
    $this->assertSame([], $result['dupLabels']);
  }

  public function testAggregationTargetDuplicateDedupesItsOwnId(): void {
    $result = $this->addWithDuplicateOption(TRUE, 'theme_mark_a1b2c3d4_agg');

    $this->assertSame('theme_mark_a1b2c3d4_agg_1', $result['fieldIds'][1]);
  }

  public function testFieldLabelUsesLabelPathIndependentlyOfMachineName(): void {
    $result = $this->addWithDuplicateOption(TRUE);

    $this->assertSame(['Original Label'], $result['primaryLabels']);
    $this->assertSame(['Original Label (aggregated)'], $result['dupLabels']);
  }

  public function testFieldLabelFallsBackToMachineNameWhenNoLabelPathGiven(): void {
    $property = $this->createMock(DataDefinitionInterface::class);
    $field = $this->createMock(FieldInterface::class);
    $field->method('getFieldIdentifier')->willReturn('irrelevant');
    $capturedLabels = [];
    $field->method('setLabel')->willReturnCallback(static function (string $label) use (&$capturedLabels): void {
      $capturedLabels[] = $label;
    });

    $index = $this->createMock(IndexInterface::class);
    $index->method('getPropertyDefinitions')->willReturn(['root' => $property]);
    $index->method('getField')->willReturn(NULL);

    $fieldsHelper = $this->createMock(FieldsHelperInterface::class);
    $fieldsHelper->method('retrieveNestedProperty')->willReturn($property);
    $fieldsHelper->method('createFieldFromProperty')->willReturn($field);

    $adder = new FieldAdder($fieldsHelper);
    $adder->addSelectedFields($index, [[
      'datasource_id'   => NULL,
      'property_path'   => 'field_senses:entity:field_theme_mark',
      'search_api_type' => 'integer',
    ]]);

    $this->assertCount(1, $capturedLabels);
    $this->assertMatchesRegularExpression('/^theme_mark_[0-9a-z]{8}$/', $capturedLabels[0]);
  }

  public function testSuggestFieldIdIsPubliclyCallableForFormPreviews(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturn(NULL);

    $adder = new FieldAdder($this->createMock(FieldsHelperInterface::class));

    $this->assertMatchesRegularExpression(
      '/^theme_mark_[0-9a-z]{8}$/',
      $adder->suggestFieldId($index, NULL, 'field_senses:entity:field_theme_mark')
    );
  }

  public function testFieldIdDiffersForDifferentDatasourcesWithSamePath(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturn(NULL);

    $adder = new FieldAdder($this->createMock(FieldsHelperInterface::class));

    $idA = $adder->suggestFieldId($index, 'entity:node', 'field_theme_mark');
    $idB = $adder->suggestFieldId($index, 'entity:paragraph', 'field_theme_mark');

    $this->assertNotSame($idA, $idB);
    $this->assertStringStartsWith('theme_mark_', $idA);
    $this->assertStringStartsWith('theme_mark_', $idB);
  }

  public function testFieldIdHashIsAlwaysExactlyEightCharacters(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('getField')->willReturn(NULL);

    $adder = new FieldAdder($this->createMock(FieldsHelperInterface::class));

    foreach (['a', 'field_b', 'field_x:entity:field_y', 'zzz', 'q1w2e3'] as $path) {
      $id = $adder->suggestFieldId($index, NULL, $path);
      [, $hash] = \explode('_', $id, 2);
      $this->assertSame(8, \strlen($hash), "Hash for '$path' should be exactly 8 characters, got '$hash'.");
      $this->assertMatchesRegularExpression('/^[0-9a-z]{8}$/', $hash);
    }
  }

}
