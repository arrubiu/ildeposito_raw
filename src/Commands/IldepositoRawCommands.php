<?php

namespace Drupal\ildeposito_raw\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ildeposito_raw\RawEntityManager;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;

/**
 * Comandi Drush per il modulo ildeposito_raw.
 */
class IldepositoRawCommands extends DrushCommands {

  use StringTranslationTrait;

  /**
   * Il servizio entity_type.manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Il servizio config.factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Il servizio cache.ildeposito_raw.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Il servizio ildeposito_raw.manager.
   *
   * @var \Drupal\ildeposito_raw\RawEntityManager
   */
  protected $rawManager;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Il servizio entity_type.manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Il servizio config.factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Il servizio cache.ildeposito_raw.
   * @param \Drupal\ildeposito_raw\RawEntityManager $raw_manager
   *   Il servizio ildeposito_raw.manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
    RawEntityManager $raw_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->rawManager = $raw_manager;
  }

  /**
   * Esegue il warming della cache per gli array $data del modulo ildeposito_raw.
   *
   * @param array $options
   *   Opzioni del comando.
   *
   * @option entity-type
   *   Tipo di entità specifico da elaborare (opzionale).
   * @option bundle
   *   Bundle specifico da elaborare (opzionale).
   * @option view-mode
   *   View mode specifico da elaborare (opzionale).
   * @option limit
   *   Numero massimo di entità da elaborare per tipo (default: 0 = nessun limite).
   * @option post-alter
   *   Se impostato, genera anche la cache post-alterazione (default: true).
   * @option stats-only
   *   Se impostato, mostra solo le statistiche senza eseguire il warming (default: false).
   * @option clear-cache
   *   Se impostato, cancella la cache del modulo prima del warming (default: true).
   *
   * @command ildeposito:raw-cache-warm
   * @aliases ircw
   * @usage ildeposito:raw-cache-warm
   *   Esegue il warming della cache per tutte le entità configurate.
   * @usage ildeposito:raw-cache-warm --entity-type=node --bundle=article
   *   Esegue il warming della cache solo per i nodi di tipo article.
   * @usage ildeposito:raw-cache-warm --stats-only
   *   Mostra solo le statistiche senza eseguire il warming.
   */
  public function warmCache(array $options = [
    'entity-type' => NULL,
    'bundle' => NULL,
    'view-mode' => NULL,
    'limit' => 0,
    'post-alter' => TRUE,
    'stats-only' => FALSE,
    'clear-cache' => TRUE,
  ]) {
    $config = $this->configFactory->get('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if (empty($raw_entities)) {
      $this->logger()->warning('Nessuna entità configurata per l\'elaborazione raw. Configura il modulo prima di eseguire questo comando.');
      return;
    }

    // Se è richiesta solo la visualizzazione delle statistiche, non eseguire il warming
    if ($options['stats-only']) {
      return $this->cacheStats($raw_entities);
    }
    
    // Cancella la cache del modulo prima di eseguire il warming
    if (!empty($options['clear-cache'])) {
      $this->logger()->notice('Cancellazione della cache per il modulo ildeposito_raw...');
      \Drupal::cache()->invalidate('ildeposito_raw');
      $this->logger()->notice('Cache del modulo ildeposito_raw cancellata.');
    }
    
    $this->logger()->notice('Avvio del warming della cache per gli array $data del modulo ildeposito_raw...');
    $total_processed = 0;

    foreach ($raw_entities as $raw_entity) {
      // Verifica che l'array raw_entity contenga tutti gli indici necessari
      if (!isset($raw_entity['entity_type']) || !isset($raw_entity['bundles']) || !isset($raw_entity['view_modes'])) {
        $this->logger()->warning('Configurazione incompleta trovata e saltata.');
        continue;
      }
      
      $entity_type = $raw_entity['entity_type'];
      
      // Filtra per tipo di entità se specificato
      if (!empty($options['entity-type']) && $options['entity-type'] !== $entity_type) {
        continue;
      }

      $bundles = $raw_entity['bundles'];
      if (!is_array($bundles)) {
        $bundles = [];
      }
      
      // Filtra per bundle se specificato
      if (!empty($options['bundle'])) {
        if (!in_array($options['bundle'], $bundles)) {
          continue;
        }
        $bundles = [$options['bundle']];
      }

      $view_modes = $raw_entity['view_modes'];
      if (!is_array($view_modes)) {
        $view_modes = [];
      }
      
      // Filtra per view mode se specificato
      if (!empty($options['view-mode'])) {
        if (!in_array($options['view-mode'], $view_modes)) {
          continue;
        }
        $view_modes = [$options['view-mode']];
      }
      
      // Se non ci sono bundle o view_modes, salta questa entità
      if (empty($bundles) || empty($view_modes)) {
        continue;
      }

      foreach ($bundles as $bundle) {
        $this->logger()->notice(sprintf('Elaborazione %s di tipo %s...', $entity_type, $bundle));
        
        // Carica le entità del tipo e bundle specificati
        $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()
          ->accessCheck(FALSE);
        
        // Aggiungi condizione per il bundle se necessario
        $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
        $bundle_key = $entity_type_definition->getKey('bundle');
        if ($bundle_key) {
          $query->condition($bundle_key, $bundle);
        }
        
        // Applica il limite se specificato
        if (!empty($options['limit']) && $options['limit'] > 0) {
          $query->range(0, $options['limit']);
        }
        
        $entity_ids = $query->execute();
        
        if (empty($entity_ids)) {
          $this->logger()->notice(sprintf('Nessuna entità trovata per %s di tipo %s.', $entity_type, $bundle));
          continue;
        }
        
        $entities = $this->entityTypeManager->getStorage($entity_type)->loadMultiple($entity_ids);
        $count = count($entities);
        
        $this->logger()->notice(sprintf('Trovate %d entità di tipo %s:%s da elaborare.', $count, $entity_type, $bundle));
        
        // Crea una progress bar
        $progress = new ProgressBar($this->output(), $count);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progress->start();
        
        foreach ($entities as $entity) {
          foreach ($view_modes as $view_mode) {
            // Verifica se l'entità deve essere elaborata in modalità raw
            if ($this->rawManager->shouldProcessRaw($entity, $view_mode)) {
              // Genera i dati raw (questo li metterà in cache)
              $this->rawManager->getRawData($entity);
              
              // Se richiesto, genera anche la cache post-alterazione
              if ($options['post-alter']) {
                $data = $this->rawManager->getRawData($entity);
                
                // Applica le alterazioni
                \Drupal::moduleHandler()->alter('ildeposito_raw_data', $data, $entity);
                if (\Drupal::hasService('theme.manager')) {
                  $theme_name = \Drupal::theme()->getActiveTheme()->getName();
                  $function = $theme_name . '_ildeposito_raw_data_alter';
                  if (function_exists($function)) {
                    $function($data, $entity);
                  }
                }
                
                // Salva i dati post-alterazione in cache
                $post_alter_cid = 'ildeposito_raw:' . $entity->getEntityTypeId() . ':' . $entity->id() . ':post_alter';
                $cache_tags = $this->rawManager->collectCacheTags($entity);
                $this->cache->set(
                  $post_alter_cid,
                  $data,
                  \Drupal\Core\Cache\Cache::PERMANENT,
                  \Drupal\Core\Cache\Cache::mergeTags($cache_tags, ['ildeposito_raw:entity:' . $entity->getEntityTypeId()])
                );
              }
              
              $total_processed++;
            }
          }
          $progress->advance();
        }
        
        $progress->finish();
        $this->output()->writeln('');
      }
    }
    
    if ($total_processed > 0) {
      $this->logger()->success(sprintf('Warming della cache completato con successo per %d entità.', $total_processed));
    }
    else {
      $this->logger()->warning('Nessuna entità elaborata. Verifica la configurazione del modulo ildeposito_raw.');
    }
  }

  /**
   * Visualizza le statistiche sulla cache del modulo ildeposito_raw.
   *
   * @param array $options
   *   Opzioni del comando.
   *
   * @option entity-type
   *   Tipo di entità specifico da visualizzare (opzionale).
   * @option bundle
   *   Bundle specifico da visualizzare (opzionale).
   * @option view-mode
   *   View mode specifico da visualizzare (opzionale).
   * @option include-post-alter
   *   Se impostato, include anche le statistiche sulla cache post-alterazione (default: true).
   * @option format
   *   Formato di output: table o json (default: table).
   *
   * @command ildeposito:raw-cache-stats
   * @aliases ircs
   * @usage ildeposito:raw-cache-stats
   *   Visualizza le statistiche sulla cache per tutte le entità configurate.
   * @usage ildeposito:raw-cache-stats --entity-type=node --bundle=article
   *   Visualizza le statistiche sulla cache solo per i nodi di tipo article.
   */
  public function cacheStats(array $options = [
    'entity-type' => NULL,
    'bundle' => NULL,
    'view-mode' => NULL,
    'include-post-alter' => TRUE,
    'format' => 'table',
  ]) {
    if (is_array($options) && !isset($options['entity-type']) && !isset($options['format'])) {
      // Se $options è il parametro $raw_entities passato da warmCache
      $raw_entities = $options;
      $include_post_alter = TRUE;
      $format = 'table';
    } else {
      $config = $this->configFactory->get('ildeposito_raw.settings');
      $raw_entities = $config->get('raw_entities') ?? [];
      $include_post_alter = $options['include-post-alter'] ?? TRUE;
      $format = $options['format'] ?? 'table';
    }

    if (empty($raw_entities)) {
      $this->logger()->warning('Nessuna entità configurata per l\'elaborazione raw.');
      return;
    }

    $stats = [];
    $total_raw = 0;
    $total_post_alter = 0;
    $total_configured = 0;

    foreach ($raw_entities as $raw_entity) {
      // Verifica che l'array raw_entity contenga tutti gli indici necessari
      if (!isset($raw_entity['entity_type']) || !isset($raw_entity['bundles']) || !isset($raw_entity['view_modes'])) {
        $this->logger()->warning('Configurazione incompleta trovata e saltata.');
        continue;
      }
      
      $entity_type = $raw_entity['entity_type'];
      
      // Filtra per tipo di entità se specificato
      if (!empty($options['entity-type']) && $options['entity-type'] !== $entity_type) {
        continue;
      }

      $bundles = $raw_entity['bundles'];
      if (!is_array($bundles)) {
        $bundles = [];
      }
      
      // Filtra per bundle se specificato
      if (!empty($options['bundle'])) {
        if (!in_array($options['bundle'], $bundles)) {
          continue;
        }
        $bundles = [$options['bundle']];
      }

      $view_modes = $raw_entity['view_modes'];
      if (!is_array($view_modes)) {
        $view_modes = [];
      }
      
      // Filtra per view mode se specificato
      if (!empty($options['view-mode'])) {
        if (!in_array($options['view-mode'], $view_modes)) {
          continue;
        }
        $view_modes = [$options['view-mode']];
      }
      
      // Se non ci sono bundle o view_modes, salta questa entità
      if (empty($bundles) || empty($view_modes)) {
        continue;
      }

      foreach ($bundles as $bundle) {
        foreach ($view_modes as $view_mode) {
          try {
            // Conta le entità configurate
            $query = $this->entityTypeManager->getStorage($entity_type)->getQuery()
              ->accessCheck(FALSE);
            
            // Aggiungi condizione per il bundle se necessario
            if ($entity_type === 'node' || $entity_type === 'taxonomy_term' || $entity_type === 'media') {
              $query->condition('type', $bundle);
            }
            elseif ($entity_type !== 'user') {
              $query->condition('bundle', $bundle);
            }
            
            $entity_ids = $query->execute();
            $count_configured = count($entity_ids);
            $total_configured += $count_configured;
            
            // Conta gli elementi in cache
            $count_raw = 0;
            $count_post_alter = 0;
            
            foreach ($entity_ids as $entity_id) {
              $cid = 'ildeposito_raw:' . $entity_type . ':' . $entity_id;
              if ($this->cache->get($cid)) {
                $count_raw++;
                $total_raw++;
              }
              
              if ($include_post_alter) {
                $post_alter_cid = 'ildeposito_raw:' . $entity_type . ':' . $entity_id . ':post_alter';
                if ($this->cache->get($post_alter_cid)) {
                  $count_post_alter++;
                  $total_post_alter++;
                }
              }
            }
            
            $stats[] = [
              'entity_type' => $entity_type,
              'bundle' => $bundle,
              'view_mode' => $view_mode,
              'configured' => $count_configured,
              'raw_cached' => $count_raw,
              'post_alter_cached' => $include_post_alter ? $count_post_alter : 'N/A',
              'raw_percentage' => $count_configured > 0 ? round(($count_raw / $count_configured) * 100, 2) . '%' : 'N/A',
              'post_alter_percentage' => $include_post_alter ? ($count_configured > 0 ? round(($count_post_alter / $count_configured) * 100, 2) . '%' : 'N/A') : 'N/A',
            ];
          }
          catch (\Exception $e) {
            $this->logger()->error(sprintf('Errore durante l\'elaborazione di %s di tipo %s: %s', $entity_type, $bundle, $e->getMessage()));
            continue;
          }
        }
      }
    }
    
    if (empty($stats)) {
      $this->logger()->warning('Nessuna entità trovata con i filtri specificati.');
      return;
    }

    // Nuovo output: totale, per bundle, in cache per bundle
    $total_entities = 0;
    $entities_per_bundle = [];
    $raw_per_bundle = [];
    foreach ($stats as $row) {
      $bundle_key = $row['entity_type'] . '--' . $row['bundle'];
      if (!isset($entities_per_bundle[$bundle_key])) {
        $entities_per_bundle[$bundle_key] = 0;
      }
      if (!isset($raw_per_bundle[$bundle_key])) {
        $raw_per_bundle[$bundle_key] = 0;
      }
      $entities_per_bundle[$bundle_key] += $row['configured'];
      $raw_per_bundle[$bundle_key] += $row['raw_cached'];
      $total_entities += $row['configured'];
    }
    $output = [];
    $output[] = 'Entità totali: ' . $total_entities;
    $output[] = 'Entità per bundle:';
    foreach ($entities_per_bundle as $bundle => $count) {
      $output[] = '- ' . $bundle . ': ' . $count;
    }
    $output[] = 'Entità in cache (raw) per bundle:';
    foreach ($raw_per_bundle as $bundle => $count) {
      $output[] = '- ' . $bundle . ': ' . $count;
    }
    $this->output()->writeln(implode("\n", $output));
  }

}
