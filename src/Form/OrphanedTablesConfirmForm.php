<?php

namespace Drupal\search_api_field_explorer\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_field_explorer\Service\OrphanedTableFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

class OrphanedTablesConfirmForm extends ConfirmFormBase {

  protected OrphanedTableFinder $finder;
  protected PrivateTempStoreFactory $tempStoreFactory;
  protected ?IndexInterface $index = NULL;
  protected array $selection = [];

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->finder = $container->get('search_api_field_explorer.orphaned_table_finder');
    $instance->tempStoreFactory = $container->get('tempstore.private');
    return $instance;
  }

  public function getFormId() {
    return 'search_api_field_explorer_orphaned_tables_confirm';
  }

  public function getQuestion() {
    $count = \count($this->selection);
    return $this->formatPlural(
      $count,
      'Delete this 1 orphaned table?',
      'Delete these @count orphaned tables?'
    );
  }

  public function getConfirmText() {
    return $this->t('Delete');
  }

  public function getCancelUrl() {
    return Url::fromRoute('search_api_field_explorer.cleanup', [
      'search_api_index' => $this->index?->id(),
    ]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?IndexInterface $search_api_index = NULL) {
    $this->index = $search_api_index;
    if (!$this->index) {
      return $form;
    }

    $this->selection = $this->tempStoreFactory->get('search_api_field_explorer')
      ->get('cleanup_selection:' . $this->index->id()) ?? [];

    if (!$this->selection) {
      $this->messenger()->addWarning($this->t('No table selection found — select tables to delete first.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return $form;
    }

    $form = parent::buildForm($form, $form_state);

    $form['tables_list'] = [
      '#theme' => 'item_list',
      '#items' => $this->selection,
      '#weight' => -1,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->index || !$this->selection) {
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $result = $this->finder->dropTables($this->index, $this->selection);
    $this->tempStoreFactory->get('search_api_field_explorer')
      ->delete('cleanup_selection:' . $this->index->id());

    if ($result['dropped']) {
      $this->messenger()->addStatus($this->formatPlural(
        \count($result['dropped']),
        'Deleted 1 table.',
        'Deleted @count tables.'
      ));
    }
    if ($result['skipped']) {
      $this->messenger()->addWarning($this->t('Skipped @count table(s) that were no longer orphaned by the time of deletion (a field was likely re-added in the meantime): @tables', [
        '@count' => \count($result['skipped']),
        '@tables' => \implode(', ', $result['skipped']),
      ]));
    }

    foreach ($result['failed'] ?? [] as $table => $message) {
      $this->messenger()->addError($this->t('Could not delete @table: @message', ['@table' => $table, '@message' => $message]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
