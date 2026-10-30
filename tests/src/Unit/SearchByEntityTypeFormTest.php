<?php

namespace Drupal\Tests\search_api_field_explorer\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api_field_explorer\Form\SearchByEntityTypeForm;
use Drupal\search_api_field_explorer\Service\FieldAdder;
use Drupal\search_api_field_explorer\Service\GroupingService;
use Drupal\search_api_field_explorer\Service\PropertyTreeWalkerInterface;
use Drupal\search_api_field_explorer\Service\ReverseFinder;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_field_explorer
 */
class SearchByEntityTypeFormTest extends UnitTestCase {

  private function makeForm(): SearchByEntityTypeForm {
    $finder = $this->createMock(ReverseFinder::class);
    $finder->method('getTargetEntityTypeOptions')->willReturn(['taxonomy_term' => 'Taxonomy term']);
    $finder->method('getServerOptions')->willReturn([]);
    $finder->method('getIndexOptions')->willReturn([]);
    $finder->method('findChainsTo')->willReturn(['results' => [], 'truncated_index_labels' => []]);

    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->willReturnMap([
      ['taxonomy_term', ['grammar_mark' => ['label' => 'Grammar mark']]],
    ]);

    $container = new ContainerBuilder();
    $container->set('search_api_field_explorer.reverse_finder', $finder);
    $container->set('search_api_field_explorer.grouping', $this->createMock(GroupingService::class));
    $container->set('search_api_field_explorer.field_adder', $this->createMock(FieldAdder::class));
    $container->set('entity_type.bundle.info', $bundleInfo);
    $container->set('search_api_field_explorer.tree_walker', $this->createMock(PropertyTreeWalkerInterface::class));
    $container->set('entity_type.manager', $this->createMock(EntityTypeManagerInterface::class));
    $container->set('config.factory', $this->getConfigFactoryStub(['search_api_field_explorer.settings' => ['max_depth' => 5]]));
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('current_user', $this->createMock(AccountInterface::class));
    \Drupal::setContainer($container);

    $form = SearchByEntityTypeForm::create($container);
    $form->setConfigFactory($container->get('config.factory'));
    return $form;
  }

  /**
   * The bundle select is filled in by AJAX, so its wrapper must exist before.
   */
  public function testBundleWrapperIsRenderedBeforeAnEntityTypeIsChosen(): void {
    $formObject = $this->makeForm();
    $form = $formObject->buildForm([], new FormState());

    $wrapper = $form['controls']['bundle_wrapper'];
    $this->assertSame('search-api-field-explorer-bundle-wrapper', $wrapper['#attributes']['id']);
    $this->assertFalse($wrapper['bundle']['#access']);
    $this->assertSame($wrapper, $formObject->updateBundleOptions($form, new FormState()));
  }

  public function testBundleOptionsAppearOnceAnEntityTypeIsChosen(): void {
    $form = $this->makeForm()->buildForm([], (new FormState())->setValue('entity_type_id', 'taxonomy_term'));

    $bundle = $form['controls']['bundle_wrapper']['bundle'];
    $this->assertTrue($bundle['#access']);
    $this->assertArrayHasKey('grammar_mark', $bundle['#options']);
  }

}
