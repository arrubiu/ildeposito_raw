<?php

namespace Drupal\ildeposito_raw\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Response;

class RawStatusReportController extends ControllerBase {
  protected $configFactory;
  protected $entityTypeManager;

  public function __construct(ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager) {
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  public function report() {
    $config = $this->configFactory->get('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];
    $total_entities = 0;
    $entities_per_bundle = [];
    $raw_per_bundle = [];
    foreach ($raw_entities as $raw_entity) {
      if (empty($raw_entity['entity_type']) || empty($raw_entity['bundles']) || empty($raw_entity['view_modes'])) {
        continue;
      }
      $entity_type = $raw_entity['entity_type'];
      foreach ($raw_entity['bundles'] as $bundle) {
        $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()->accessCheck(FALSE);
        if (in_array($entity_type, ['node', 'taxonomy_term', 'media'])) {
          $query->condition('type', $bundle);
        } elseif ($entity_type !== 'user') {
          $query->condition('bundle', $bundle);
        }
        $entity_ids = $query->execute();
        $bundle_key = $entity_type . '--' . $bundle;
        if (!isset($entities_per_bundle[$bundle_key])) {
          $entities_per_bundle[$bundle_key] = 0;
        }
        if (!isset($raw_per_bundle[$bundle_key])) {
          $raw_per_bundle[$bundle_key] = 0;
        }
        foreach ($entity_ids as $eid) {
          $total_entities++;
          $entities_per_bundle[$bundle_key]++;
          $cid = "ildeposito_raw:{$entity_type}:{$eid}";
          $cache = \Drupal::cache()->get($cid);
          if ($cache && $cache->data) {
            $raw_per_bundle[$bundle_key]++;
          }
        }
      }
    }
    $header = ['Bundle', 'Entità totali', 'Entità in cache (raw)'];
    $rows = [];
    foreach ($entities_per_bundle as $bundle => $count) {
      $rows[] = [
        $bundle,
        $count,
        $raw_per_bundle[$bundle] ?? 0,
      ];
    }
    $build = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#caption' => $this->t('Report stato cache ilDeposito Raw'),
    ];
    $summary = [
      '#markup' => '<p><strong>Entità totali:</strong> ' . $total_entities . '</p>',
    ];
    return [
      $summary,
      $build,
    ];
  }
}
