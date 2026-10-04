<?php

namespace Drupal\search_api_field_explorer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_field_explorer\Service\PropertyTreeWalkerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SettingsForm extends ConfigFormBase {

  private const MIN_DEPTH = 1;
  private const MAX_DEPTH = 20;
  private const DEFAULT_DEPTH = 5;
  private const MIN_NODE_BUDGET = 100;
  private const MAX_NODE_BUDGET = 50000;
  private const DEFAULT_NODE_BUDGET = 5000;

  protected PropertyTreeWalkerInterface $walker;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->walker = $container->get('search_api_field_explorer.tree_walker');
    return $instance;
  }

  public function getFormId() {
    return 'search_api_field_explorer_settings';
  }

  protected function getEditableConfigNames() {
    return ['search_api_field_explorer.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('search_api_field_explorer.settings');

    $form['max_depth'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum property-tree depth'),
      '#description' => $this->t('How many levels of nested entity references to follow when walking a property tree. Clamped to @min-@max regardless of what is entered here.', ['@min' => self::MIN_DEPTH, '@max' => self::MAX_DEPTH]),
      '#default_value' => (int) ($config->get('max_depth') ?: self::DEFAULT_DEPTH),
      '#min' => self::MIN_DEPTH,
      '#max' => self::MAX_DEPTH,
      '#required' => TRUE,
    ];
    $form['node_budget'] = [
      '#type' => 'number',
      '#title' => $this->t('Node budget per tree walk'),
      '#description' => $this->t('Maximum number of properties to discover in a single walk before truncating (a safety net against very wide entity/bundle graphs). Clamped to @min-@max.', ['@min' => self::MIN_NODE_BUDGET, '@max' => self::MAX_NODE_BUDGET]),
      '#default_value' => (int) ($config->get('node_budget') ?: self::DEFAULT_NODE_BUDGET),
      '#min' => self::MIN_NODE_BUDGET,
      '#max' => self::MAX_NODE_BUDGET,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $maxDepth = \max(self::MIN_DEPTH, \min(self::MAX_DEPTH, (int) $form_state->getValue('max_depth')));
    $nodeBudget = \max(self::MIN_NODE_BUDGET, \min(self::MAX_NODE_BUDGET, (int) $form_state->getValue('node_budget')));

    $this->config('search_api_field_explorer.settings')
      ->set('max_depth', $maxDepth)
      ->set('node_budget', $nodeBudget)
      ->save();

    $this->walker->invalidate();

    parent::submitForm($form, $form_state);
  }

}
