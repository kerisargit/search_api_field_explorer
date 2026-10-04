<?php

namespace Drupal\search_api_field_explorer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_field_explorer\Service\OrphanedTableFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

class OrphanedTablesForm extends FormBase {

  protected OrphanedTableFinder $finder;
  protected PrivateTempStoreFactory $tempStoreFactory;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->finder = $container->get('search_api_field_explorer.orphaned_table_finder');
    $instance->tempStoreFactory = $container->get('tempstore.private');
    return $instance;
  }

  public function getFormId() {
    return 'search_api_field_explorer_orphaned_tables';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?IndexInterface $search_api_index = NULL) {
    if (!$search_api_index) {
      return $form;
    }
    $form_state->set('index_id', $search_api_index->id());

    try {
      $orphans = $this->finder->findOrphanedTables($search_api_index);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not list the tables of this index: @message', ['@message' => $e->getMessage()]));
      return $form;
    }

    $form['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Physical database tables that still exist but are no longer referenced by any field currently tracked on this index — leftovers from fields removed outside the normal cleanup path (config import, direct edits, or heavy field-renaming). Safe to remove; nothing currently reads them.'),
    ];

    if (!$orphans) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No orphaned tables found for this index.'),
      ];
      return $form;
    }

    $options = [];
    foreach ($orphans as $orphan) {
      $options[$orphan['table']] = [
        'table' => $orphan['table'],
        'rows' => $orphan['rows'] ?? $this->t('unknown'),
      ];
    }

    $form['tables'] = [
      '#type' => 'tableselect',
      '#header' => [
        'table' => $this->t('Table name'),
        'rows' => $this->t('Row count'),
      ],
      '#options' => $options,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete selected'),
      '#button_type' => 'danger',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $selected = \array_filter($form_state->getValue('tables') ?? []);
    if (!$selected) {
      $form_state->setErrorByName('tables', $this->t('Select at least one table to delete.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $indexId = $form_state->get('index_id');
    $selected = \array_keys(\array_filter($form_state->getValue('tables') ?? []));

    $this->tempStoreFactory->get('search_api_field_explorer')
      ->set('cleanup_selection:' . $indexId, $selected);

    $form_state->setRedirect('search_api_field_explorer.cleanup_confirm', [
      'search_api_index' => $indexId,
    ]);
  }

}
