<?php

namespace Drupal\Tests\search_api_field_explorer\Unit;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api_field_explorer\Service\GroupingService;
use Drupal\Tests\UnitTestCase;

/**
 * @group search_api_field_explorer
 */
class GroupingServiceTest extends UnitTestCase {

  private function makeService(): GroupingService {
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityType->method('getLabel')->willReturn('Taxonomy term');

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->willReturn($entityType);

    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->willReturn([
      'tags' => ['label' => 'Tags'],
      'categories' => ['label' => 'Categories'],
    ]);

    $service = new GroupingService($entityTypeManager, $bundleInfo);
    $service->setStringTranslation($this->getStringTranslationStub());
    return $service;
  }

  private function node(string $entityType, ?array $bundles, string $path): array {
    return [
      'property_path' => $path,
      'label_path' => $path,
      'target_entity_type' => $entityType,
      'target_bundles' => $bundles,
    ];
  }

  public function testNodesWithoutTargetAreIgnored(): void {
    $service = $this->makeService();
    $nodes = [
      ['property_path' => 'a', 'target_entity_type' => NULL, 'target_bundles' => NULL],
      ['property_path' => 'b', 'target_entity_type' => '', 'target_bundles' => NULL],
    ];
    $this->assertSame([], $service->computeGroups($nodes));
  }

  public function testLoneMatchIsNotAGroup(): void {
    $service = $this->makeService();
    $nodes = [$this->node('taxonomy_term', NULL, 'a')];
    $this->assertSame([], $service->computeGroups($nodes));
  }

  public function testTwoNodesWithSameEntityTypeAndNoBundleAreGrouped(): void {
    $service = $this->makeService();
    $nodes = [
      $this->node('taxonomy_term', NULL, 'field_tags'),
      $this->node('taxonomy_term', NULL, 'field_related_terms'),
    ];
    $groups = $service->computeGroups($nodes);

    $this->assertCount(1, $groups);
    $this->assertSame('taxonomy_term', $groups[0]['entity_type_id']);
    $this->assertCount(2, $groups[0]['members']);
  }

  public function testDifferentEntityTypesAreNotGrouped(): void {
    $service = $this->makeService();
    $nodes = [
      $this->node('taxonomy_term', NULL, 'field_tags'),
      $this->node('user', NULL, 'uid'),
    ];
    $this->assertSame([], $service->computeGroups($nodes));
  }

  public function testSameEntityTypeDifferentBundlesAreSeparateGroups(): void {
    $service = $this->makeService();
    $nodes = [
      $this->node('taxonomy_term', ['tags'], 'field_a'),
      $this->node('taxonomy_term', ['tags'], 'field_b'),
      $this->node('taxonomy_term', ['categories'], 'field_c'),
    ];
    $groups = $service->computeGroups($nodes);

    $this->assertCount(1, $groups);
    $this->assertSame(['tags'], $groups[0]['bundles']);
  }

  public function testBundleOrderDoesNotAffectGrouping(): void {
    $service = $this->makeService();
    $nodes = [
      $this->node('taxonomy_term', ['tags', 'categories'], 'field_a'),
      $this->node('taxonomy_term', ['categories', 'tags'], 'field_b'),
    ];
    $groups = $service->computeGroups($nodes);
    $this->assertCount(1, $groups);
    $this->assertCount(2, $groups[0]['members']);
  }

  public function testDescribeGroupIncludesBundleLabelsWhenPresent(): void {
    $service = $this->makeService();
    $group = [
      'entity_type_id' => 'taxonomy_term',
      'entity_type_label' => 'Taxonomy term',
      'bundles' => ['tags'],
      'bundle_labels' => ['Tags'],
    ];
    $this->assertStringContainsString('Tags', $service->describeGroup($group));
    $this->assertStringContainsString('taxonomy_term', $service->describeGroup($group));
  }

  public function testDescribeGroupOmitsBundleTextWhenAnyBundle(): void {
    $service = $this->makeService();
    $group = [
      'entity_type_id' => 'taxonomy_term',
      'entity_type_label' => 'Taxonomy term',
      'bundles' => NULL,
      'bundle_labels' => NULL,
    ];
    $this->assertStringNotContainsString('bundle:', $service->describeGroup($group));
  }

  public function testGroupBadgeTextIncludesBundleWhenPresent(): void {
    $service = $this->makeService();
    $group = [
      'entity_type_id' => 'taxonomy_term',
      'entity_type_label' => 'Taxonomy term',
      'bundles' => ['tags'],
      'bundle_labels' => ['Tags'],
    ];
    $this->assertSame('Taxonomy term: Tags', $service->groupBadgeText($group));
  }

  public function testGroupBadgeTextMarksAnyBundleWhenUnconstrained(): void {
    $service = $this->makeService();
    $group = [
      'entity_type_id' => 'taxonomy_term',
      'entity_type_label' => 'Taxonomy term',
      'bundles' => NULL,
      'bundle_labels' => NULL,
    ];
    $text = $service->groupBadgeText($group);
    $this->assertStringContainsString('Taxonomy term', $text);
    $this->assertStringNotContainsString(': Tags', $text);
  }

  public function testGroupBadgeTextDistinguishesDifferentBundlesOfSameEntityType(): void {
    $service = $this->makeService();
    $tagsGroup = [
      'entity_type_id' => 'taxonomy_term',
      'entity_type_label' => 'Taxonomy term',
      'bundles' => ['tags'],
      'bundle_labels' => ['Tags'],
    ];
    $categoriesGroup = [
      'entity_type_id' => 'taxonomy_term',
      'entity_type_label' => 'Taxonomy term',
      'bundles' => ['categories'],
      'bundle_labels' => ['Categories'],
    ];
    $this->assertNotSame($service->groupBadgeText($tagsGroup), $service->groupBadgeText($categoriesGroup));
  }

}
