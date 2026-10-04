<?php

namespace Drupal\search_api_field_explorer\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_field_explorer\RowKey;
use Drupal\search_api_field_explorer\Service\FieldAdder;
use Drupal\search_api_field_explorer\Service\GroupingService;
use Drupal\search_api_field_explorer\Service\PropertyTreeWalkerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BrowseIndexForm extends FormBase {

  protected PropertyTreeWalkerInterface $walker;
  protected GroupingService $grouping;
  protected FieldAdder $fieldAdder;
  protected EntityTypeManagerInterface $entityTypeManager;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->walker             = $container->get('search_api_field_explorer.tree_walker');
    $instance->grouping           = $container->get('search_api_field_explorer.grouping');
    $instance->fieldAdder         = $container->get('search_api_field_explorer.field_adder');
    $instance->entityTypeManager  = $container->get('entity_type.manager');
    return $instance;
  }

  public function getFormId() {
    return 'search_api_field_explorer_browse_index';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?IndexInterface $search_api_index = NULL) {
    if (!$search_api_index) {
      return $form;
    }
    $index = $search_api_index;
    $form_state->set('index_id', $index->id());

    $maxDepth = $form_state->get('max_depth') ?? RowKey::clampDepth(
      (int) ($this->config('search_api_field_explorer.settings')->get('max_depth') ?? 0)
    );
    $form_state->set('max_depth', $maxDepth);

    $viewMode = $form_state->get('view_mode') ?? 'list';
    $form_state->set('view_mode', $viewMode);

    $form['controls'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'display:flex; gap:1em; align-items:flex-end; margin-bottom:1em;'],
    ];
    $form['controls']['max_depth'] = [
      '#type' => 'select',
      '#title' => $this->t('Max depth'),
      '#options' => \array_combine(\range(1, 20), \range(1, 20)),
      '#default_value' => $maxDepth,
    ];
    $form['controls']['apply_depth'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#submit' => ['::applyDepth'],
      '#limit_validation_errors' => [['max_depth']],
    ];
    $form['controls']['rebuild'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild tree (clear cache)'),
      '#submit' => ['::rebuildTree'],
      '#limit_validation_errors' => [],
    ];
    $form['controls']['view_list'] = [
      '#type' => 'submit',
      '#value' => $this->t('List view'),
      '#submit' => ['::setViewMode'],
      '#limit_validation_errors' => [['max_depth']],
      '#view_mode' => 'list',
      '#attributes' => $viewMode === 'list' ? ['class' => ['button--primary']] : [],
    ];
    $form['controls']['view_tree'] = [
      '#type' => 'submit',
      '#value' => $this->t('Tree view'),
      '#submit' => ['::setViewMode'],
      '#limit_validation_errors' => [['max_depth']],
      '#view_mode' => 'tree',
      '#attributes' => $viewMode === 'tree' ? ['class' => ['button--primary']] : [],
    ];

    $allNodes = [];
    $datasources = ['' => NULL] + $index->getDatasources();
    foreach ($datasources as $datasourceKey => $datasource) {
      $datasourceId = $datasourceKey === '' ? NULL : $datasourceKey;
      $tree = $this->walker->getTree($index, $datasourceId, $maxDepth);
      foreach ($tree['nodes'] as $node) {
        $node['datasource_label'] = $datasource ? $datasource->label() : (string) $this->t('General');
        $allNodes[] = $node;
      }
      if ($tree['truncated']) {
        $this->messenger()->addWarning($this->t(
          'The property tree for %datasource was too large to walk fully at this depth and was truncated. Lower "Max depth" or raise the node budget in the settings.',
          ['%datasource' => $datasource ? $datasource->label() : (string) $this->t('General')]
        ));
      }
    }

    $groups = $this->grouping->computeGroups($allNodes);
    $groupByMember = RowKey::indexGroupsByMember($groups);

    $rowData = [];
    $multipleDatasources = \count($datasources) > 1;
    foreach ($allNodes as $node) {
      if (empty($node['can_be_indexed'])) {
        continue;
      }
      $key = RowKey::encode('', $node['datasource_id'] ?? '', $node['property_path']);
      $groupInfo = $groupByMember[RowKey::memberKey($node)] ?? NULL;

      $machineName = $this->fieldAdder->suggestFieldId($index, $node['datasource_id'] ?? NULL, $node['property_path']);

      if ($viewMode === 'tree') {
        $labelCell = '<span class="search-api-field-explorer-tree-label" style="--sasfe-depth: '
          . (int) $node['depth'] . '">' . \htmlspecialchars($node['label'], \ENT_QUOTES) . '</span>';
      }
      else {
        $labelCell = \htmlspecialchars($node['label_path'], \ENT_QUOTES);
      }
      if ($node['already_indexed_as']) {
        $labelCell .= ' ' . (string) $this->t('(already indexed as %field)', ['%field' => \implode(', ', $node['already_indexed_as'])]);
      }

      $rowData[$key] = [
        'label'         => $labelCell,
        'machine_name'  => '<code>' . \htmlspecialchars($machineName, \ENT_QUOTES) . '</code>',
        'property_path' => '<code>' . \htmlspecialchars($node['property_path'], \ENT_QUOTES) . '</code>',
        'datasource'    => $multipleDatasources ? \htmlspecialchars($node['datasource_label'], \ENT_QUOTES) : '',
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
        'datasource'    => $this->t('Datasource'),
        'type'          => $this->t('Type'),
        'group'         => $this->t('Same as'),
        'as_target'     => $this->t('Also as aggregation target'),
      ],
      '#empty' => $this->t('No indexable properties found at this depth.'),
    ];
    foreach ($rowData as $key => $row) {
      $form['rows'][$key]['select'] = [
        '#type' => 'checkbox',
        '#attributes' => ['class' => ['search-api-field-explorer-select-checkbox']],
      ];
      $form['rows'][$key]['label'] = ['#markup' => $row['label']];
      $form['rows'][$key]['machine_name'] = ['#markup' => $row['machine_name']];
      $form['rows'][$key]['property_path'] = ['#markup' => $row['property_path']];
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

  public function applyDepth(array &$form, FormStateInterface $form_state): void {
    $form_state->set('max_depth', RowKey::clampDepth((int) $form_state->getValue('max_depth')));
    $form_state->setRebuild(TRUE);
  }

  public function rebuildTree(array &$form, FormStateInterface $form_state): void {
    $indexId = $form_state->get('index_id');
    if ($indexId) {
      $this->walker->invalidate($indexId);
      $this->messenger()->addStatus($this->t('Tree cache cleared.'));
    }
    $form_state->setRebuild(TRUE);
  }

  public function setViewMode(array &$form, FormStateInterface $form_state): void {
    $form_state->set('max_depth', RowKey::clampDepth((int) $form_state->getValue('max_depth')));
    $triggeringElement = $form_state->getTriggeringElement();
    $form_state->set('view_mode', $triggeringElement['#view_mode'] ?? 'list');
    $form_state->setRebuild(TRUE);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$this->selectedRowKeys($form_state)) {
      $form_state->setErrorByName('rows', $this->t('Select at least one field to add.'));
    }
  }

  private function selectedRowKeys(FormStateInterface $form_state): array {
    $keys = [];
    foreach ((array) $form_state->getValue('rows') as $key => $row) {
      if (!empty($row['select'])) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $rowsValue = (array) $form_state->getValue('rows');
    $selectedKeys = $this->selectedRowKeys($form_state);
    $indexId  = $form_state->get('index_id');
    $maxDepth = $form_state->get('max_depth');

    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($indexId);
    if (!$index || !$index->access('fields', $this->currentUser(), TRUE)->isAllowed()) {
      $this->messenger()->addError($this->t('You no longer have permission to modify this index.'));
      return;
    }

    $nodesByKey = [];
    foreach (['' => NULL] + $index->getDatasources() as $datasourceKey => $datasource) {
      $datasourceId = $datasourceKey === '' ? NULL : $datasourceKey;
      foreach ($this->walker->getTree($index, $datasourceId, $maxDepth)['nodes'] as $node) {
        $nodesByKey[RowKey::encode('', $node['datasource_id'] ?? '', $node['property_path'])] = $node;
      }
    }

    $selections = [];
    foreach ($selectedKeys as $key) {
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

    try {
      $summary = $this->fieldAdder->addSelectedFields($index, $selections);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not add the fields to %index: @message', ['%index' => $index->label(), '@message' => $e->getMessage()]));
      return;
    }
    $this->walker->invalidate($index->id());

    $this->messenger()->addStatus($this->t('Added @n field(s) to %index.', ['@n' => $summary['added'], '%index' => $index->label()]));
    if ($summary['duplicated']) {
      $this->messenger()->addStatus($this->t('Also created @n aggregation-target duplicate field(s) (field ID suffixed "_agg").', ['@n' => $summary['duplicated']]));
    }
    if ($summary['skipped']) {
      $this->messenger()->addWarning($this->t('Skipped @n field(s) that could not be resolved or mapped to a type: @list', [
        '@n' => \count($summary['skipped']),
        '@list' => \implode(', ', $summary['skipped']),
      ]));
    }
    if ($summary['needs_config']) {
      $this->messenger()->addWarning($this->t('The following added field(s) need additional configuration before they will work correctly — edit them on the Fields tab: @list', [
        '@list' => \implode(', ', $summary['needs_config']),
      ]));
    }

    $form_state->setRedirectUrl($index->toUrl('fields'));
  }

}
