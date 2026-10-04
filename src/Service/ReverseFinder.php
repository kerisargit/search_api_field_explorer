<?php

namespace Drupal\search_api_field_explorer\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\Utility\FieldsHelperInterface;

class ReverseFinder {

  use StringTranslationTrait;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FieldsHelperInterface $fieldsHelper;
  protected PropertyTreeWalkerInterface $walker;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FieldsHelperInterface $fields_helper,
    PropertyTreeWalkerInterface $walker,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldsHelper      = $fields_helper;
    $this->walker            = $walker;
  }

  public function getTargetEntityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($this->fieldsHelper->isContentEntityType($id)) {
        $options[$id] = (string) $definition->getLabel();
      }
    }
    \asort($options);
    return $options;
  }

  public function getIndexOptions(?string $serverId = NULL): array {
    $options = [];
    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
    foreach ($indexes as $index) {
      if ($serverId !== NULL && $index->getServerId() !== $serverId) {
        continue;
      }
      $options[$index->id()] = (string) $index->label();
    }
    \asort($options);
    return $options;
  }

  public function getServerOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('search_api_server')->loadMultiple() as $server) {
      $options[$server->id()] = (string) $server->label();
    }
    \asort($options);
    return $options;
  }

  public function findChainsTo(string $targetEntityTypeId, ?string $targetBundle, int $maxDepth, AccountInterface $account, ?array $indexIds = NULL): array {
    $results = [];
    $truncatedIndexLabels = [];

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple($indexIds ?: NULL);

    foreach ($indexes as $index) {
      if (!$index->access('fields', $account)) {
        continue;
      }

      $datasources = ['' => NULL] + $index->getDatasources();
      $indexMatches = [];
      $indexTruncated = FALSE;

      foreach ($datasources as $datasourceKey => $datasource) {
        $datasourceId = $datasourceKey === '' ? NULL : $datasourceKey;
        $tree = $this->walker->getTree($index, $datasourceId, $maxDepth);

        if ($tree['truncated']) {
          $indexTruncated = TRUE;
        }

        $matches = \array_values(\array_filter($tree['nodes'], function (array $node) use ($targetEntityTypeId, $targetBundle): bool {
          if (($node['target_entity_type'] ?? NULL) !== $targetEntityTypeId) {
            return FALSE;
          }
          if ($targetBundle === NULL) {
            return TRUE;
          }
          $bundles = $node['target_bundles'] ?? NULL;
          return !empty($bundles) && \in_array($targetBundle, $bundles, TRUE);
        }));

        if ($matches) {
          $indexMatches[$datasourceKey] = [
            'datasource_label' => $datasource ? $datasource->label() : (string) $this->t('General'),
            'matches'          => $matches,
          ];
        }
      }

      if ($indexMatches) {
        $results[$index->id()] = [
          'index_label' => $index->label(),
          'datasources' => $indexMatches,
        ];
      }
      if ($indexTruncated) {
        $truncatedIndexLabels[] = $index->label();
      }
    }

    return ['results' => $results, 'truncated_index_labels' => $truncatedIndexLabels];
  }

}
