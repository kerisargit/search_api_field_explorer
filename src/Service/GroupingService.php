<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

class GroupingService {

  use StringTranslationTrait;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected EntityTypeBundleInfoInterface $bundleInfo;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityTypeBundleInfoInterface $bundle_info) {
    $this->entityTypeManager = $entity_type_manager;
    $this->bundleInfo        = $bundle_info;
  }

  public function computeGroups(array $nodes): array {
    $groups = [];
    foreach ($nodes as $node) {
      if (empty($node['target_entity_type'])) {
        continue;
      }
      $bundles   = $node['target_bundles'] ?? NULL;
      $bundleKey = $bundles ? $this->joinedSorted($bundles) : '*';
      $key       = $node['target_entity_type'] . '|' . $bundleKey;

      if (!isset($groups[$key])) {
        $groups[$key] = [
          'entity_type_id'    => $node['target_entity_type'],
          'entity_type_label' => $this->entityTypeLabel($node['target_entity_type']),
          'bundles'           => $bundles,
          'bundle_labels'     => $bundles ? $this->bundleLabels($node['target_entity_type'], $bundles) : NULL,
          'members'           => [],
        ];
      }
      $groups[$key]['members'][] = $node;
    }

    return array_values(array_filter($groups, static fn (array $g): bool => \count($g['members']) >= 2));
  }

  public function groupBadgeText(array $group): string {
    if ($group['bundle_labels']) {
      return $group['entity_type_label'] . ': ' . \implode(', ', $group['bundle_labels']);
    }
    return $group['entity_type_label'] . ' (' . $this->t('any bundle') . ')';
  }

  public function describeGroup(array $group): string {
    if ($group['bundle_labels']) {
      return (string) \sprintf(
        'Both/all resolve to entity type %s (%s), bundle: %s',
        $group['entity_type_label'],
        $group['entity_type_id'],
        \implode(', ', $group['bundle_labels'])
      );
    }
    return (string) \sprintf('Both/all resolve to entity type %s (%s)', $group['entity_type_label'], $group['entity_type_id']);
  }

  private function joinedSorted(array $values): string {
    \sort($values);
    return \implode(',', $values);
  }

  private function entityTypeLabel(string $entityTypeId): string {
    try {
      $definition = $this->entityTypeManager->getDefinition($entityTypeId);
      return (string) $definition->getLabel();
    }
    catch (\Exception $e) {
      return $entityTypeId;
    }
  }

  private function bundleLabels(string $entityTypeId, array $bundles): array {
    $info   = $this->bundleInfo->getBundleInfo($entityTypeId);
    $labels = [];
    foreach ($bundles as $bundle) {
      $labels[] = (string) ($info[$bundle]['label'] ?? $bundle);
    }
    return $labels;
  }

}
