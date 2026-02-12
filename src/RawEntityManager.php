<?php

namespace Drupal\ildeposito_raw;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Service per la gestione dei dati raw delle entità.
 */
class RawEntityManager {

  /**
   * L'entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Il factory di configurazione.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Il servizio di cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Il servizio time.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * L'utente corrente.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Il gestore delle lingue.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   L'entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Il factory di configurazione.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Il servizio di cache.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Il servizio time.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   L'utente corrente.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   Il gestore delle lingue.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
    TimeInterface $time,
    AccountInterface $current_user,
    LanguageManagerInterface $language_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->time = $time;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * Verifica se l'entità deve essere elaborata in modalità raw.
   */
  public function shouldProcessRaw(EntityInterface $entity, string $view_mode = 'default'): bool {
    $config = $this->configFactory->get('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    foreach ($raw_entities as $raw_entity) {
      if ($raw_entity['entity_type'] === $entity->getEntityTypeId() &&
          in_array($entity->bundle(), $raw_entity['bundles']) &&
          in_array($view_mode, $raw_entity['view_modes'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Genera i dati raw per un'entità.
   *
   * Non usa cache interna: il caching è delegato al render cache di Drupal
   * tramite tags/contexts/max-age nel build array.
   */
  public function getRawData(EntityInterface $entity): array {
    return $this->buildRawData($entity);
  }

  /**
   * Raccoglie tutti i cache tags necessari, inclusi quelli delle entità referenziate.
   */
  public function collectCacheTags(FieldableEntityInterface $entity): array {
    $cache_tags = $entity->getCacheTags();

    foreach ($entity->getFields() as $field) {
      $field_type = $field->getFieldDefinition()->getType();
      
      // Gestione delle entità referenziate
      if ($field_type === 'entity_reference') {
        foreach ($field as $field_item) {
          if ($referenced_entity = $field_item->entity) {
            $cache_tags = Cache::mergeTags($cache_tags, $referenced_entity->getCacheTags());
          }
        }
      }
      // Gestione specifica per file e immagini
      elseif (in_array($field_type, ['file', 'image'])) {
        foreach ($field as $field_item) {
          if ($file_entity = $field_item->entity) {
            $cache_tags = Cache::mergeTags($cache_tags, $file_entity->getCacheTags());
          }
        }
      }
    }

    return $cache_tags;
  }

  /**
   * Determina i cache contexts necessari per l'entità.
   */
  public function getCacheContexts(ContentEntityInterface $entity): array {
    $contexts = ['user.permissions'];

    // Context lingua — usa il context generico, non il valore specifico.
    if ($entity->isTranslatable()) {
      $contexts[] = 'languages:language_content';
    }

    return $contexts;
  }

  /**
   * Costruisce l'array di dati raw per un'entità.
   */
  protected function buildRawData(ContentEntityInterface $entity): array {
    $created = ($entity instanceof \Drupal\node\NodeInterface)
      ? $this->formatDate($entity->getCreatedTime())
      : NULL;
    $changed = ($entity instanceof EntityChangedInterface)
      ? $this->formatDate($entity->getChangedTime())
      : NULL;

    $data = [
      'id' => $entity->id(),
      'uuid' => $entity->uuid(),
      'bundle' => $entity->bundle(),
      'entity_type' => $entity->getEntityTypeId(),
      'created' => $created,
      'changed' => $changed,
    ];

    // Aggiungi i campi dell'entità.
    foreach ($entity->getFields() as $field_name => $field) {
      if (strpos($field_name, 'field_') === 0) {
        $clean_name = substr($field_name, 6);
      } else {
        $clean_name = $field_name;
      }

      $data[$clean_name] = $this->processField($field);
    }

    return $data;
  }

  /**
   * Processa un campo e restituisce i suoi valori in formato raw.
   */
  protected function processField($field): mixed {
    $values = [];
    
    foreach ($field->getValue() as $delta => $value) {
      switch ($field->getFieldDefinition()->getType()) {
        case 'link':
          $values[$delta] = [
            'url' => $value['uri'],
            'title' => $value['title'],
          ];
          break;

        case 'entity_reference':
          $target_entity = $field->get($delta)->entity;
          if ($target_entity) {
            $values[$delta] = [
              'id' => $target_entity->id(),
              'uuid' => $target_entity->uuid(),
              'label' => $target_entity->label(),
              'url' => $target_entity->toUrl()->toString(),
              'entity_type' => $target_entity->getEntityTypeId(),
              'bundle' => $target_entity->bundle(),
            ];

            // Gestione specifica per i termini di tassonomia
            if ($target_entity->getEntityTypeId() === 'taxonomy_term') {
              $values[$delta]['description'] = $target_entity->getDescription();
              if ($target_entity->hasField('field_image')) {
                $values[$delta]['image'] = $this->processField($target_entity->get('field_image'));
              }
            }
          }
          break;

        case 'image':
          $file_entity = $field->get($delta)->entity;
          if ($file_entity) {
            $file_uri = $file_entity->getFileUri();
            $values[$delta] = [
              'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file_uri),
              'alt' => $value['alt'] ?? '',
              'title' => $value['title'] ?? '',
              'width' => $value['width'] ?? null,
              'height' => $value['height'] ?? null,
              'fid' => $file_entity->id(),
              'filename' => $file_entity->getFilename(),
              'filemime' => $file_entity->getMimeType(),
            ];
          }
          break;

        case 'file':
          $file_entity = $field->get($delta)->entity;
          if ($file_entity) {
            $file_uri = $file_entity->getFileUri();
            $values[$delta] = [
              'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file_uri),
              'fid' => $file_entity->id(),
              'filename' => $file_entity->getFilename(),
              'filemime' => $file_entity->getMimeType(),
              'filesize' => $file_entity->getSize(),
            ];
          }
          break;

        case 'geofield':
          $values[$delta] = [
            'lat' => $value['lat'],
            'lon' => $value['lon'],
          ];
          break;

        case 'datetime':
        case 'date':
          if (!empty($value['value'])) {
            $values[$delta] = $this->formatDate(strtotime($value['value']));
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
        case 'string':
        case 'string_long':
          $values[$delta] = $value['value'] ?? null;
          break;

        case 'boolean':
          $values[$delta] = (bool) ($value['value'] ?? false);
          break;

        case 'integer':
        case 'number_integer':
          $values[$delta] = (int) ($value['value'] ?? 0);
          break;

        case 'decimal':
        case 'float':
        case 'number_decimal':
        case 'number_float':
          $values[$delta] = (float) ($value['value'] ?? 0);
          break;

        default:
          if (isset($value['value'])) {
            $values[$delta] = $value['value'];
          } else {
            $values[$delta] = $value;
          }
      }
    }

    // Se c'è solo un valore, restituisci direttamente il valore invece dell'array
    return count($values) === 1 ? reset($values) : $values;
  }

  /**
   * Formatta una data nel formato YYYY-MM-DD.
   */
  protected function formatDate(?int $timestamp): ?string {
    return $timestamp ? date('Y-m-d', $timestamp) : null;
  }

  /**
   * Invalida la cache per un'entità.
   *
   * Invalida i cache tags dell'entità stessa e il tag generico per tipo,
   * così il render cache di Drupal si aggiorna anche quando cambia
   * un'entità referenziata (i suoi cache tags sono nel build array).
   */
  public function invalidateCache(EntityInterface $entity): void {
    Cache::invalidateTags(Cache::mergeTags(
      $entity->getCacheTags(),
      ['ildeposito_raw:entity:' . $entity->getEntityTypeId()]
    ));
  }
}