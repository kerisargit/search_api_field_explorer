<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ConfigurablePropertyInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;

class FieldAdder {

  private const AGGREGATION_TARGET_SUFFIX = '_agg';

  protected FieldsHelperInterface $fieldsHelper;

  public function __construct(FieldsHelperInterface $fields_helper) {
    $this->fieldsHelper = $fields_helper;
  }

  public function addSelectedFields(IndexInterface $index, array $selections): array {
    $added = 0;
    $duplicated = 0;
    $skipped = [];
    $needsConfig = [];
    $propertiesByDatasource = [];

    foreach ($selections as $selection) {
      $datasourceId = $selection['datasource_id'];
      $propertyPath = $selection['property_path'];
      $type         = $selection['search_api_type'] ?? NULL;

      if (!$type) {
        $skipped[] = $propertyPath;
        continue;
      }

      $cacheKey = $datasourceId ?? '';
      if (!\array_key_exists($cacheKey, $propertiesByDatasource)) {
        $propertiesByDatasource[$cacheKey] = $index->getPropertyDefinitions($datasourceId);
      }

      $property = $this->fieldsHelper->retrieveNestedProperty($propertiesByDatasource[$cacheKey], $propertyPath);
      if (!$property) {
        $skipped[] = $propertyPath;
        continue;
      }

      if ($property instanceof ComplexDataDefinitionInterface) {
        $mainPropertyName = $property->getMainPropertyName();
        if ($mainPropertyName) {
          $nested = $this->fieldsHelper->getNestedProperties($property);
          if (isset($nested[$mainPropertyName])) {
            $property = $nested[$mainPropertyName];
          }
        }
      }

      $fieldId = $this->suggestFieldId($index, $datasourceId, $propertyPath);
      $field = $this->fieldsHelper->createFieldFromProperty($index, $property, $datasourceId, $propertyPath, $fieldId, $type);
      $field->setLabel($selection['label_path'] ?? $fieldId);
      $index->addField($field);
      $added++;

      if ($property instanceof ConfigurablePropertyInterface) {
        $needsConfig[] = $field->getFieldIdentifier();
      }

      if (!empty($selection['also_as_aggregation_target'])) {
        $dupFieldId = $this->dedupeFieldId($index, $field->getFieldIdentifier() . self::AGGREGATION_TARGET_SUFFIX);
        $dupField = $this->fieldsHelper->createFieldFromProperty($index, $property, $datasourceId, $propertyPath, $dupFieldId, $type);
        $dupField->setLabel($field->getLabel() . ' (aggregated)');
        $index->addField($dupField);
        $duplicated++;

        if ($property instanceof ConfigurablePropertyInterface) {
          $needsConfig[] = $dupField->getFieldIdentifier();
        }
      }
    }

    if ($added > 0 || $duplicated > 0) {
      $index->save();
    }

    return ['added' => $added, 'duplicated' => $duplicated, 'skipped' => $skipped, 'needs_config' => $needsConfig];
  }

  private const MAX_LEAF_LENGTH = 24;

  public function suggestFieldId(IndexInterface $index, ?string $datasourceId, string $propertyPath): string {
    $segments = \array_values(\array_filter(
      \explode(':', $propertyPath),
      static fn (string $segment): bool => $segment !== 'entity' && $segment !== ''
    ));
    $segments = \array_map(
      static fn (string $segment): string => \str_starts_with($segment, 'field_') ? \substr($segment, 6) : $segment,
      $segments
    );

    $leaf = $segments ? \end($segments) : \str_replace(':', '_', $propertyPath);
    if (\strlen($leaf) > self::MAX_LEAF_LENGTH) {
      $leaf = \substr($leaf, 0, self::MAX_LEAF_LENGTH);
    }
    $hash = $this->computeHash($datasourceId, $propertyPath);
    $suggestedId = $leaf . '_' . $hash;
    $suggestedId = \str_replace('search_api_', '', $suggestedId);

    return $this->dedupeFieldId($index, $suggestedId);
  }

  private const HASH_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

  private const HASH_LENGTH = 8;

  private function computeHash(?string $datasourceId, string $propertyPath): string {
    $digest = \substr(\md5(($datasourceId ?? '') . '|' . $propertyPath), 0, 10);
    $value = \hexdec($digest);

    $encoded = '';
    do {
      $encoded = self::HASH_ALPHABET[$value % 36] . $encoded;
      $value = \intdiv((int) $value, 36);
    } while ($value > 0);

    return \str_pad($encoded, self::HASH_LENGTH, '0', \STR_PAD_LEFT);
  }

  private function dedupeFieldId(IndexInterface $index, string $suggestedId): string {
    $fieldId = $suggestedId;
    $i = 0;
    while ($index->getField($fieldId)) {
      $fieldId = $suggestedId . '_' . ++$i;
    }
    return $fieldId;
  }

}
