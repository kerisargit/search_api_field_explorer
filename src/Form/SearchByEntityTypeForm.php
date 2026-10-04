<?php

namespace Drupal\search_api_field_explorer\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_field_explorer\RowKey;
use Drupal\search_api_field_explorer\Service\FieldAdder;
use Drupal\search_api_field_explorer\Service\GroupingService;
use Drupal\search_api_field_explorer\Service\PropertyTreeWalkerInterface;
use Drupal\search_api_field_explorer\Service\ReverseFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SearchByEntityTypeForm extends FormBase {

  protected ReverseFinder $reverseFinder;
  protected GroupingService $grouping;
  protected FieldAdder $fieldAdder;
  protected EntityTypeBundleInfoInterface $bundleInfo;
  protected PropertyTreeWalkerInterface $walker;
  protected EntityTypeManagerInterface $entityTypeManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->reverseFinder    = $container->get('search_api_field_explorer.reverse_finder');
    $instance->grouping         = $container->get('search_api_field_explorer.grouping');
    $instance->fieldAdder       = $container->get('search_api_field_explorer.field_adder');
    $instance->bundleInfo       = $container->get('entity_type.bundle.info');
    $instance->walker           = $container->get('search_api_field_explorer.tree_walker');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function getFormId() {
    return 'search_api_field_explorer_search_by_entity_type';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $entityTypeOptions = $this->reverseFinder->getTargetEntityTypeOptions();

    $selectedType = $form_state->getValue('entity_type_id') ?? $form_state->get('entity_type_id');
    $selectedBundle = $form_state->getValue('bundle') ?: $form_state->get('bundle');
    $selectedServerId = $form_state->getValue('server_id') ?? $form_state->get('server_id');
    $selectedIndexIds = (array) ($form_state->getValue('index_ids') ?? $form_state->get('index_ids') ?? []);
    $selectedIndexIds = \array_values(\array_filter($selectedIndexIds));

    $maxDepth = $form_state->get('max_depth') ?? RowKey::clampDepth(
      (int) ($this->config('search_api_field_explorer.settings')->get('max_depth') ?? 0)
    );
    $form_state->set('max_depth', $maxDepth);

    $viewMode = $form_state->get('view_mode') ?? 'list';
    $form_state->set('view_mode', $viewMode);

    $form['controls'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'display:flex; gap:1em; align-items:flex-end; flex-wrap:wrap; margin-bottom:1em;'],
    ];
    $form['controls']['entity_type_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Target entity type'),
      '#options' => ['' => $this->t('- Select -')] + $entityTypeOptions,
      '#default_value' => $selectedType ?: '',
      '#ajax' => [
        'callback' => '::updateBundleOptions',
        'wrapper' => 'search-api-field-explorer-bundle-wrapper',
      ],
    ];

    $bundleOptions = [];
    if ($selectedType) {
      foreach ($this->bundleInfo->getBundleInfo($selectedType) as $bundleId => $info) {
        $bundleOptions[$bundleId] = (string) ($info['label'] ?? $bundleId);
      }
    }
    $form['controls']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => ['' => $this->t('- Any bundle -')] + $bundleOptions,
      '#default_value' => $selectedBundle ?: '',
      '#prefix' => '<div id="search-api-field-explorer-bundle-wrapper">',
      '#suffix' => '</div>',
      '#access' => (bool) $bundleOptions,
    ];
    $form['controls']['server_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Server'),
      '#options' => ['' => $this->t('- Any server -')] + $this->reverseFinder->getServerOptions(),
      '#default_value' => $selectedServerId ?: '',
      '#ajax' => [
        'callback' => '::updateIndexOptions',
        'wrapper' => 'search-api-field-explorer-index-wrapper',
      ],
    ];
    $form['controls']['index_ids'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Index(es)'),
      '#options' => $this->reverseFinder->getIndexOptions($selectedServerId ?: NULL),
      '#default_value' => $selectedIndexIds,
      '#description' => $this->t('Leave empty to search every index.'),
      '#prefix' => '<div id="search-api-field-explorer-index-wrapper">',
      '#suffix' => '</div>',
    ];
    $form['controls']['max_depth'] = [
      '#type' => 'select',
      '#title' => $this->t('Max depth'),
      '#options' => \array_combine(\range(1, 20), \range(1, 20)),
      '#default_value' => $maxDepth,
    ];
    $form['controls']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#submit' => ['::runSearch'],
      '#limit_validation_errors' => [['entity_type_id'], ['bundle'], ['server_id'], ['index_ids'], ['max_depth']],
    ];
    $form['controls']['view_list'] = [
      '#type' => 'submit',
      '#value' => $this->t('List view'),
      '#submit' => ['::setViewMode'],
      '#limit_validation_errors' => [['entity_type_id'], ['bundle'], ['server_id'], ['index_ids'], ['max_depth']],
      '#view_mode' => 'list',
      '#attributes' => $viewMode === 'list' ? ['class' => ['button--primary']] : [],
    ];
    $form['controls']['view_tree'] = [
      '#type' => 'submit',
      '#value' => $this->t('Tree view'),
      '#submit' => ['::setViewMode'],
      '#limit_validation_errors' => [['entity_type_id'], ['bundle'], ['server_id'], ['index_ids'], ['max_depth']],
      '#view_mode' => 'tree',
      '#attributes' => $viewMode === 'tree' ? ['class' => ['button--primary']] : [],
    ];

    if (!$selectedType) {
      $form['rows'] = [
        '#type' => 'table',
        '#header' => [],
        '#empty' => $this->t('Pick a target entity type above and click Search.'),
      ];
      return $form;
    }

    $search = $this->reverseFinder->findChainsTo($selectedType, $selectedBundle ?: NULL, $maxDepth, $this->currentUser(), $selectedIndexIds ?: NULL);
    $results = $search['results'];
    $form_state->set('search_results', $results);

    if ($search['truncated_index_labels']) {
      $this->messenger()->addWarning($this->t(
        'The property tree was too large to walk fully at this depth for the following index(es), so some matches may be missing: @list. Lower "Max depth" won\'t help here — raise the node budget in the settings instead, or narrow the search.',
        ['@list' => \implode(', ', $search['truncated_index_labels'])]
      ));
    }

    $allNodes = [];
    foreach ($results as $indexId => $indexData) {
      foreach ($indexData['datasources'] as $dsData) {
        foreach ($dsData['matches'] as $node) {
          $node['index_id'] = $indexId;
          $node['index_label'] = $indexData['index_label'];
          $node['datasource_label'] = $dsData['datasource_label'];
          $allNodes[] = $node;
        }
      }
    }

    $groups = $this->grouping->computeGroups($allNodes);
    $groupByMember = RowKey::indexGroupsByMember($groups);

    $indexesById = $results
      ? $this->entityTypeManager->getStorage('search_api_index')->loadMultiple(\array_keys($results))
      : [];

    if ($viewMode === 'tree') {
      $form['#attached']['library'][] = 'search_api_field_explorer/explorer';
      $form['select_controls'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['search-api-field-explorer-select-all-controls'], 'style' => 'margin-bottom:0.5em;'],
        'select_all' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $this->t('Select all'),
          '#attributes' => ['href' => '#', 'data-action' => 'select-all', 'class' => ['button', 'button--small']],
        ],
        'select_none' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $this->t('Select none'),
          '#attributes' => ['href' => '#', 'data-action' => 'select-none', 'class' => ['button', 'button--small'], 'style' => 'margin-left:0.5em;'],
        ],
      ];
      $form['tree'] = $this->buildTreeSection($allNodes, $indexesById, $groupByMember);
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Add selected fields'),
      ];
      return $form;
    }

    $rowData = [];
    foreach ($allNodes as $node) {
      if (empty($node['can_be_indexed'])) {
        continue;
      }
      $key = RowKey::encode($node['index_id'], $node['datasource_id'] ?? '', $node['property_path']);
      $groupInfo = $groupByMember[RowKey::memberKey($node)] ?? NULL;

      $nodeIndex = $indexesById[$node['index_id']] ?? NULL;
      $machineName = $nodeIndex ? $this->fieldAdder->suggestFieldId($nodeIndex, $node['datasource_id'] ?? NULL, $node['property_path']) : $node['property_path'];

      $labelCell = \htmlspecialchars($node['label_path'], \ENT_QUOTES);
      if ($node['already_indexed_as']) {
        $labelCell .= ' ' . (string) $this->t('(already indexed as %field)', ['%field' => \implode(', ', $node['already_indexed_as'])]);
      }

      $rowData[$key] = [
        'label'         => $labelCell,
        'machine_name'  => '<code>' . \htmlspecialchars($machineName, \ENT_QUOTES) . '</code>',
        'property_path' => '<code>' . \htmlspecialchars($node['property_path'], \ENT_QUOTES) . '</code>',
        'index'         => \htmlspecialchars($node['index_label'], \ENT_QUOTES),
        'datasource'    => \htmlspecialchars($node['datasource_label'], \ENT_QUOTES),
        'type'          => \htmlspecialchars($node['search_api_type'] ?? $node['data_type'], \ENT_QUOTES),
        'group'         => $groupInfo
          ? '<span class="search-api-field-explorer-badge" title="' . \htmlspecialchars($this->grouping->describeGroup($groupInfo), \ENT_QUOTES) . '">' . \htmlspecialchars($this->grouping->groupBadgeText($groupInfo), \ENT_QUOTES) . '</span>'
          : '',
      ];
    }

    $form['#attached']['library'][] = 'search_api_field_explorer/explorer';

    $form['select_controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-api-field-explorer-select-all-controls'], 'style' => 'margin-bottom:0.5em;'],
      'select_all' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Select all'),
        '#attributes' => ['href' => '#', 'data-action' => 'select-all', 'class' => ['button', 'button--small']],
      ],
      'select_none' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Select none'),
        '#attributes' => ['href' => '#', 'data-action' => 'select-none', 'class' => ['button', 'button--small'], 'style' => 'margin-left:0.5em;'],
      ],
    ];

    $form['rows'] = [
      '#type' => 'table',
      '#header' => [
        'select'        => $this->t('Add'),
        'label'         => $this->t('Label'),
        'machine_name'  => $this->t('Machine name'),
        'property_path' => $this->t('Property path'),
        'index'         => $this->t('Index'),
        'datasource'    => $this->t('Datasource'),
        'type'          => $this->t('Type'),
        'group'         => $this->t('Same as'),
        'as_target'     => $this->t('Also as aggregation target'),
      ],
      '#empty' => $this->t('No chains to this entity type were found at this depth.'),
    ];
    foreach ($rowData as $key => $row) {
      $form['rows'][$key]['select'] = [
        '#type' => 'checkbox',
        '#attributes' => ['class' => ['search-api-field-explorer-select-checkbox']],
      ];
      $form['rows'][$key]['label'] = ['#markup' => $row['label']];
      $form['rows'][$key]['machine_name'] = ['#markup' => $row['machine_name']];
      $form['rows'][$key]['property_path'] = ['#markup' => $row['property_path']];
      $form['rows'][$key]['index'] = ['#markup' => $row['index']];
      $form['rows'][$key]['datasource'] = ['#markup' => $row['datasource']];
      $form['rows'][$key]['type'] = ['#markup' => $row['type']];
      $form['rows'][$key]['group'] = ['#markup' => $row['group']];
      $form['rows'][$key]['as_target'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Also as aggregation target'),
        '#title_display' => 'invisible',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add selected fields'),
    ];

    return $form;
  }

  private function buildTreeSection(array $allNodes, array $indexesById, array $groupByMember): array {
    $buckets = [];
    foreach ($allNodes as $node) {
      if (empty($node['can_be_indexed'])) {
        continue;
      }
      $bucketKey = $node['index_id'] . '|' . ($node['datasource_id'] ?? '');
      if (!isset($buckets[$bucketKey])) {
        $buckets[$bucketKey] = [
          'label' => $node['datasource_label']
            ? $node['index_label'] . ' — ' . $node['datasource_label']
            : $node['index_label'],
          'nodes' => [],
        ];
      }
      $buckets[$bucketKey]['nodes'][] = $node;
    }

    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-api-field-explorer-tree-wrapper']],
    ];

    if (!$buckets) {
      $section['empty'] = ['#markup' => '<p>' . $this->t('No chains to this entity type were found at this depth.') . '</p>'];
      return $section;
    }

    $multipleBuckets = \count($buckets) > 1;
    $bi = 0;
    foreach ($buckets as $bucket) {
      $items = [];
      foreach ($bucket['nodes'] as $node) {
        $segments = \explode(' » ', $node['label_path']);
        \array_pop($segments);
        $items[] = [
          'segments' => $segments,
          'node' => $node,
          'key' => RowKey::encode($node['index_id'], $node['datasource_id'] ?? '', $node['property_path']),
        ];
      }
      $trie = $this->groupBySegments($items);
      $rendered = $this->renderTrieLevel($trie, $indexesById, $groupByMember);

      if ($multipleBuckets) {
        $section['b' . $bi] = ['#type' => 'details', '#title' => $bucket['label'], '#open' => TRUE] + $rendered;
      }
      else {
        $section += $rendered;
      }
      $bi++;
    }

    return $section;
  }

  private function groupBySegments(array $items): array {
    $leaves = [];
    $bySegment = [];
    foreach ($items as $item) {
      if (!$item['segments']) {
        $leaves[] = $item;
        continue;
      }
      $segments = $item['segments'];
      $first = \array_shift($segments);
      $bySegment[$first][] = ['segments' => $segments, 'node' => $item['node'], 'key' => $item['key']];
    }
    $children = [];
    foreach ($bySegment as $segment => $subItems) {
      $children[$segment] = $this->groupBySegments($subItems);
    }
    return ['leaves' => $leaves, 'children' => $children];
  }

  private function renderTrieLevel(array $trieNode, array $indexesById, array $groupByMember): array {
    $render = [];
    $i = 0;
    foreach ($trieNode['children'] as $segmentLabel => $childTrie) {
      $render['g' . $i] = [
        '#type' => 'details',
        '#title' => $segmentLabel,
        '#open' => TRUE,
      ] + $this->renderTrieLevel($childTrie, $indexesById, $groupByMember);
      $i++;
    }
    foreach ($trieNode['leaves'] as $j => $leaf) {
      $render['l' . $j] = $this->buildTreeLeafRow($leaf['key'], $leaf['node'], $indexesById, $groupByMember);
    }
    return $render;
  }

  private function buildTreeLeafRow(string $key, array $node, array $indexesById, array $groupByMember): array {
    $groupInfo = $groupByMember[RowKey::memberKey($node)] ?? NULL;
    $nodeIndex = $indexesById[$node['index_id']] ?? NULL;
    $machineName = $nodeIndex ? $this->fieldAdder->suggestFieldId($nodeIndex, $node['datasource_id'] ?? NULL, $node['property_path']) : $node['property_path'];

    $labelText = $node['label'];
    if ($node['already_indexed_as']) {
      $labelText .= ' ' . (string) $this->t('(already indexed as %field)', ['%field' => \implode(', ', $node['already_indexed_as'])]);
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-api-field-explorer-tree-row']],
      'select' => [
        '#type' => 'checkbox',
        '#attributes' => ['class' => ['search-api-field-explorer-select-checkbox']],
        '#parents' => ['rows', $key, 'select'],
      ],
      'label' => ['#markup' => \htmlspecialchars($labelText, \ENT_QUOTES)],
      'machine_name' => ['#markup' => '<code>' . \htmlspecialchars($machineName, \ENT_QUOTES) . '</code>'],
      'property_path' => [
        '#markup' => '<code class="search-api-field-explorer-tree-row-path">'
          . \htmlspecialchars($node['property_path'], \ENT_QUOTES) . '</code>',
      ],
      'type' => [
        '#markup' => '<span class="search-api-field-explorer-tree-row-type">'
          . \htmlspecialchars($node['search_api_type'] ?? $node['data_type'], \ENT_QUOTES) . '</span>',
      ],
      'group' => [
        '#markup' => $groupInfo
          ? '<span class="search-api-field-explorer-badge" title="' . \htmlspecialchars($this->grouping->describeGroup($groupInfo), \ENT_QUOTES) . '">' . \htmlspecialchars($this->grouping->groupBadgeText($groupInfo), \ENT_QUOTES) . '</span>'
          : '',
      ],
      'as_target' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Also as aggregation target'),
        '#title_display' => 'after',
        '#parents' => ['rows', $key, 'as_target'],
        '#wrapper_attributes' => ['class' => ['search-api-field-explorer-tree-row-agg']],
      ],
    ];
  }

  public function updateBundleOptions(array &$form, FormStateInterface $form_state) {
    return $form['controls']['bundle'];
  }

  public function updateIndexOptions(array &$form, FormStateInterface $form_state) {
    return $form['controls']['index_ids'];
  }

  public function runSearch(array &$form, FormStateInterface $form_state): void {
    $this->persistSearchCriteria($form_state);
    $form_state->setRebuild(TRUE);
  }

  public function setViewMode(array &$form, FormStateInterface $form_state): void {
    $this->persistSearchCriteria($form_state);
    $triggeringElement = $form_state->getTriggeringElement();
    $form_state->set('view_mode', $triggeringElement['#view_mode'] ?? 'list');
    $form_state->setRebuild(TRUE);
  }

  private function persistSearchCriteria(FormStateInterface $form_state): void {
    $form_state->set('entity_type_id', $form_state->getValue('entity_type_id'));
    $form_state->set('bundle', $form_state->getValue('bundle'));
    $form_state->set('server_id', $form_state->getValue('server_id'));
    $form_state->set('index_ids', (array) $form_state->getValue('index_ids'));
    $form_state->set('max_depth', RowKey::clampDepth((int) $form_state->getValue('max_depth')));
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $triggeringSubmit = $form_state->getTriggeringElement()['#submit'][0] ?? NULL;
    if (\in_array($triggeringSubmit, ['::runSearch', '::setViewMode'], TRUE)) {
      return;
    }
    if (!$this->selectedRowKeys($form_state)) {
      $form_state->setErrorByName('rows', $this->t('Select at least one field to add.'));
    }
  }

  private function selectedRowKeys(FormStateInterface $form_state): array {
    $keys = [];
    foreach ((array) $form_state->getValue('rows') as $key => $row) {
      if (\is_array($row) && !empty($row['select'])) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $rowsValue = (array) $form_state->getValue('rows');
    $selectedKeys = $this->selectedRowKeys($form_state);
    if (!$selectedKeys) {
      return;
    }

    $indexStorage = $this->entityTypeManager->getStorage('search_api_index');

    $byIndex = [];
    foreach ($selectedKeys as $key) {
      [$indexId, , ] = RowKey::decode($key);
      $byIndex[$indexId][] = $key;
    }

    $totalAdded = 0;
    $totalDuplicated = 0;
    $allSkipped = [];
    $allNeedsConfig = [];
    $indexesTouched = 0;

    foreach ($byIndex as $indexId => $keys) {
      /** @var \Drupal\search_api\IndexInterface|null $index */
      $index = $indexStorage->load($indexId);
      if (!$index || !$index->access('fields', $this->currentUser(), TRUE)->isAllowed()) {
        $this->messenger()->addError($this->t('Skipped index %index — you no longer have permission to modify it.', ['%index' => $indexId]));
        continue;
      }

      $maxDepth = $form_state->get('max_depth');
      $nodesByKey = [];
      foreach (['' => NULL] + $index->getDatasources() as $datasourceKey => $datasource) {
        $datasourceId = $datasourceKey === '' ? NULL : $datasourceKey;
        foreach ($this->walker->getTree($index, $datasourceId, $maxDepth)['nodes'] as $node) {
          $nodesByKey[RowKey::encode($indexId, $node['datasource_id'] ?? '', $node['property_path'])] = $node;
        }
      }

      $selections = [];
      foreach ($keys as $key) {
        if (!isset($nodesByKey[$key])) {
          continue;
        }
        $node = $nodesByKey[$key];
        $selections[] = [
          'datasource_id'              => $node['datasource_id'],
          'property_path'              => $node['property_path'],
          'search_api_type'            => $node['search_api_type'],
          'label_path'                 => $node['label_path'],
          'also_as_aggregation_target' => !empty($rowsValue[$key]['as_target']),
        ];
      }

      // One failing index must not lose the report for the others.
      try {
        $summary = $this->fieldAdder->addSelectedFields($index, $selections);
      }
      catch (\Throwable $e) {
        $this->messenger()->addError($this->t('Could not add fields to %index: @message', ['%index' => $index->label(), '@message' => $e->getMessage()]));
        continue;
      }
      $totalAdded += $summary['added'];
      $totalDuplicated += $summary['duplicated'];
      $allSkipped = \array_merge($allSkipped, $summary['skipped']);
      $allNeedsConfig = \array_merge($allNeedsConfig, $summary['needs_config']);
      $indexesTouched++;
    }

    $this->messenger()->addStatus($this->t('Added @n field(s) across @m index(es).', ['@n' => $totalAdded, '@m' => $indexesTouched]));
    if ($totalDuplicated) {
      $this->messenger()->addStatus($this->t('Also created @n aggregation-target duplicate field(s) (field ID suffixed "_agg").', ['@n' => $totalDuplicated]));
    }
    if ($allSkipped) {
      $this->messenger()->addWarning($this->t('Skipped @n field(s) that could not be resolved or mapped to a type: @list', [
        '@n' => \count($allSkipped),
        '@list' => \implode(', ', $allSkipped),
      ]));
    }
    if ($allNeedsConfig) {
      $this->messenger()->addWarning($this->t('The following added field(s) need additional configuration before they will work correctly: @list', [
        '@list' => \implode(', ', $allNeedsConfig),
      ]));
    }

    $form_state->setRebuild(TRUE);
  }

}
