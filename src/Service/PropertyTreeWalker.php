<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPropertyInterface;
use Drupal\search_api\Utility\DataTypeHelperInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;

class PropertyTreeWalker implements PropertyTreeWalkerInterface {

  private const MIN_DEPTH = 1;
  private const MAX_DEPTH = 20;
  private const DEFAULT_NODE_BUDGET = 5000;

  private const MAX_KEY_REPEATS = 3;

  private const CACHE_TAG_ALL = 'search_api_field_explorer_tree';

  protected FieldsHelperInterface $fieldsHelper;
  protected DataTypeHelperInterface $dataTypeHelper;
  protected EntityTargetResolver $targetResolver;
  protected CacheBackendInterface $cache;
  protected ConfigFactoryInterface $configFactory;
  protected EntityFieldManagerInterface $entityFieldManager;

  public function __construct(
    FieldsHelperInterface $fields_helper,
    DataTypeHelperInterface $data_type_helper,
    EntityTargetResolver $target_resolver,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->fieldsHelper  = $fields_helper;
    $this->dataTypeHelper = $data_type_helper;
    $this->targetResolver = $target_resolver;
    $this->cache          = $cache;
    $this->configFactory  = $config_factory;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getTree(IndexInterface $index, ?string $datasource_id, int $maxDepth): array {
    $maxDepth = max(self::MIN_DEPTH, min(self::MAX_DEPTH, $maxDepth));
    $cid = $this->cacheId($index->id(), $datasource_id, $maxDepth);

    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $properties = $index->getPropertyDefinitions($datasource_id);
    $typeMapping = $this->dataTypeHelper->getFieldTypeMapping();
    $existingFields = $index->getFields();

    $nodes  = [];
    $budget = ['used' => 0, 'limit' => $this->getNodeBudget(), 'truncated' => FALSE];

    $this->walk($properties, $datasource_id, [], '', '', 0, $maxDepth, $typeMapping, $existingFields, $nodes, $budget, NULL);

    $result = [
      'nodes'     => $nodes,
      'truncated' => $budget['truncated'],
    ];

    $tags = Cache::mergeTags(
      $index->getCacheTags(),
      ['entity_field_info', 'entity_bundles', 'entity_types', self::CACHE_TAG_ALL, self::CACHE_TAG_ALL . ':' . $index->id()]
    );
    $this->cache->set($cid, $result, Cache::PERMANENT, $tags);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate(?string $indexId = NULL): void {
    Cache::invalidateTags([$indexId === NULL ? self::CACHE_TAG_ALL : self::CACHE_TAG_ALL . ':' . $indexId]);
  }

  private function cacheId(string $indexId, ?string $datasourceId, int $maxDepth): string {
    return 'search_api_field_explorer:tree:' . $indexId . ':' . ($datasourceId ?? '__root__') . ':' . $maxDepth;
  }

  private function getNodeBudget(): int {
    $configured = (int) ($this->configFactory->get('search_api_field_explorer.settings')->get('node_budget') ?? 0);
    return $configured > 0 ? $configured : self::DEFAULT_NODE_BUDGET;
  }

  private function walk(
    array $properties,
    ?string $datasourceId,
    array $pathStack,
    string $parentPath,
    string $labelPrefix,
    int $depth,
    int $maxDepth,
    array $typeMapping,
    array $existingFields,
    array &$nodes,
    array &$budget,
    ?array $inheritedTarget,
  ): void {
    foreach ($properties as $key => $property) {
      if ($budget['used'] >= $budget['limit']) {
        $budget['truncated'] = TRUE;
        return;
      }

      if ($property instanceof ProcessorPropertyInterface && $property->isHidden()) {
        continue;
      }

      $thisPath = $parentPath !== '' ? $parentPath . ':' . $key : (string) $key;
      $label    = (string) $property->getLabel();

      $inner = $this->fieldsHelper->getInnerProperty($property);

      $canBeIndexed   = FALSE;
      $searchApiType  = NULL;
      $nestedProperties = [];

      if ($inner instanceof ComplexDataDefinitionInterface) {
        if ($key === 'entity' && $inheritedTarget && $inner instanceof EntityDataDefinitionInterface
          && $inner->getEntityTypeId() === $inheritedTarget['entity_type_id']) {
          $nestedProperties = [];
          foreach ($inheritedTarget['bundles'] as $bundle) {
            $nestedProperties += $this->entityFieldManager->getFieldDefinitions($inheritedTarget['entity_type_id'], $bundle);
          }
        }
        else {
          $nestedProperties = $this->fieldsHelper->getNestedProperties($inner);
        }
        $mainPropertyName = $inner->getMainPropertyName();
        if ($mainPropertyName && isset($nestedProperties[$mainPropertyName])) {
          $mainDef = $nestedProperties[$mainPropertyName];
          unset($nestedProperties[$mainPropertyName]);
          $parentChildType = $inner->getDataType() . '.' . $mainDef->getDataType();
          if (!empty($typeMapping[$parentChildType])) {
            $searchApiType = $typeMapping[$parentChildType];
            $canBeIndexed  = TRUE;
          }
          elseif (!empty($typeMapping[$mainDef->getDataType()])) {
            $searchApiType = $typeMapping[$mainDef->getDataType()];
            $canBeIndexed  = TRUE;
          }
        }
      }
      else {
        $type = $inner->getDataType();
        if (!empty($typeMapping[$type])) {
          $searchApiType = $typeMapping[$type];
          $canBeIndexed  = TRUE;
        }
      }

      $target = $this->targetResolver->resolveTarget($property);
      if ($key === 'entity' && $inheritedTarget && $target
        && $target['entity_type_id'] === $inheritedTarget['entity_type_id']) {
        $target = $inheritedTarget;
      }

      if (!$canBeIndexed && empty($nestedProperties) && !$target) {
        continue;
      }

      $matches = $this->fieldsHelper->filterForPropertyPath($existingFields, $datasourceId, $thisPath);
      $alreadyIndexedAs = \array_map(
        static fn ($field) => $field->getFieldIdentifier(),
        \array_values($matches)
      );

      $cycleStopped = FALSE;
      $nextPathStack = $pathStack;
      if ($target) {
        $stackKey = $target['entity_type_id'] . ($target['bundles'] ? ':' . implode(',', $target['bundles']) : '');
        $seenCount = $pathStack[$stackKey] ?? 0;
        if ($seenCount >= self::MAX_KEY_REPEATS) {
          $cycleStopped = TRUE;
        }
        else {
          $nextPathStack[$stackKey] = $seenCount + 1;
        }
      }

      $nodes[] = [
        'property_path'      => $thisPath,
        'label'              => $label,
        'label_path'         => $labelPrefix . $label,
        'depth'               => $depth,
        'datasource_id'      => $datasourceId,
        'data_type'          => $inner->getDataType(),
        'search_api_type'    => $searchApiType,
        'can_be_indexed'     => $canBeIndexed,
        'target_entity_type' => $target['entity_type_id'] ?? NULL,
        'target_bundles'     => $target['bundles'] ?? NULL,
        'already_indexed_as' => $alreadyIndexedAs,
        'cycle_stopped'      => $cycleStopped,
      ];
      $budget['used']++;

      if ($nestedProperties && !$cycleStopped && $depth + 1 < $maxDepth) {
        $childLabelPrefix = ($key === 'entity') ? $labelPrefix : $labelPrefix . $label . ' » ';
        $childInheritedTarget = ($key !== 'entity' && $target && !empty($target['bundles']))
          ? $target
          : NULL;
        $this->walk(
          $nestedProperties, $datasourceId, $nextPathStack, $thisPath,
          $childLabelPrefix, $depth + 1, $maxDepth,
          $typeMapping, $existingFields, $nodes, $budget,
          $childInheritedTarget
        );
      }
    }
  }

}
