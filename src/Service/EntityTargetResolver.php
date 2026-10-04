<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;

class EntityTargetResolver {

  protected FieldsHelperInterface $fieldsHelper;

  public function __construct(FieldsHelperInterface $fields_helper) {
    $this->fieldsHelper = $fields_helper;
  }

  public function resolveTarget(DataDefinitionInterface $property): ?array {
    $inner = $this->fieldsHelper->getInnerProperty($property);

    if ($inner instanceof EntityDataDefinitionInterface) {
      return $this->fromEntityDefinition($inner, $property);
    }

    if ($inner instanceof ComplexDataDefinitionInterface) {
      $nested = $this->fieldsHelper->getNestedProperties($inner);
      $entityProperty = $nested['entity'] ?? NULL;
      if ($entityProperty instanceof DataReferenceDefinitionInterface) {
        $target = $entityProperty->getTargetDefinition();
        if ($target instanceof EntityDataDefinitionInterface) {
          return $this->fromEntityDefinition($target, $property);
        }
      }
    }

    return NULL;
  }

  private function fromEntityDefinition(EntityDataDefinitionInterface $definition, DataDefinitionInterface $originalProperty): ?array {
    $entityTypeId = $definition->getEntityTypeId();
    if (!$entityTypeId || !$this->fieldsHelper->isContentEntityType($entityTypeId)) {
      return NULL;
    }

    $bundles = $definition->getBundles();
    if (!$bundles) {
      $bundles = $this->bundlesFromHandlerSettings($originalProperty);
    }

    return [
      'entity_type_id' => $entityTypeId,
      'bundles'        => $bundles,
    ];
  }

  private function bundlesFromHandlerSettings(DataDefinitionInterface $property): ?array {
    if (!$property instanceof FieldDefinitionInterface) {
      return NULL;
    }
    $handlerSettings = $property->getSetting('handler_settings');
    $targetBundles = \is_array($handlerSettings) ? ($handlerSettings['target_bundles'] ?? NULL) : NULL;
    return (\is_array($targetBundles) && $targetBundles) ? \array_values($targetBundles) : NULL;
  }

}
