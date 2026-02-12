<?php

namespace Drupal\ildeposito_raw\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form di configurazione per il modulo Il Deposito Raw.
 */
class RawEntitySettingsForm extends ConfigFormBase {

  /**
   * Il gestore dei tipi di entità.
   */
  protected $entityTypeManager;

  /**
   * Il repository degli entity display.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * Costruttore.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityDisplayRepositoryInterface $entity_display_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ildeposito_raw_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ildeposito_raw.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    $form['raw_entities'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Tipo entità'),
        $this->t('Bundle'),
        $this->t('View mode'),
        $this->t('Operazioni'),
      ],
      '#empty' => $this->t('Nessuna configurazione presente.'),
    ];

    // Aggiungi righe esistenti
    foreach ($raw_entities as $delta => $raw_entity) {
      $form['raw_entities'][$delta] = [
        'entity_type' => [
          '#markup' => $raw_entity['entity_type'],
        ],
        'bundles' => [
          '#markup' => implode(', ', $raw_entity['bundles']),
        ],
        'view_modes' => [
          '#markup' => implode(', ', $raw_entity['view_modes']),
        ],
        'operations' => [
          '#type' => 'operations',
          '#links' => [
            'delete' => [
              'title' => $this->t('Elimina'),
              'url' => \Drupal\Core\Url::fromRoute('ildeposito_raw.delete_config', [
                'delta' => $delta,
              ]),
            ],
          ],
        ],
      ];
    }

    // Form per aggiungere nuova configurazione
    $entity_types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        $entity_types[$entity_type_id] = $entity_type->getLabel();
      }
    }

    $form['add'] = [
      '#type' => 'details',
      '#title' => $this->t('Aggiungi configurazione'),
      '#open' => TRUE,
    ];

    $form['add']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo entità'),
      '#options' => $entity_types,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateEntityTypeFields',
        'wrapper' => 'entity-type-dependent-wrapper',
      ],
    ];

    $form['add']['entity_type_dependent'] = [
      '#type' => 'container',
      '#prefix' => '<div id="entity-type-dependent-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['add']['entity_type_dependent']['bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => $this->getBundleOptions($form_state->getValue('entity_type')),
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    $form['add']['entity_type_dependent']['view_modes'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => $this->getViewModeOptions($form_state->getValue('entity_type')),
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    $form['add']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Aggiungi configurazione'),
      '#submit' => ['::submitForm'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Ajax callback per aggiornare i campi dipendenti dal tipo di entità.
   */
  public function updateEntityTypeFields(array &$form, FormStateInterface $form_state) {
    return $form['add']['entity_type_dependent'];
  }

  /**
   * Ottiene le opzioni dei bundle per un tipo di entità.
   */
  protected function getBundleOptions($entity_type_id) {
    $options = [];
    
    if ($entity_type_id) {
      $bundle_info = \Drupal::service('entity_type.bundle.info')
        ->getBundleInfo($entity_type_id);
      
      foreach ($bundle_info as $bundle => $info) {
        $options[$bundle] = $info['label'];
      }
    }
    
    return $options;
  }

  /**
   * Ottiene le opzioni delle view mode per un tipo di entità.
   */
  protected function getViewModeOptions($entity_type_id) {
    $options = [];
    
    if ($entity_type_id) {
      $view_modes = $this->entityDisplayRepository->getViewModes($entity_type_id);
      
      foreach ($view_modes as $view_mode_id => $view_mode_info) {
        $options[$view_mode_id] = $view_mode_info['label'];
      }
    }
    
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if ($entity_type = $form_state->getValue('entity_type')) {
      $raw_entities[] = [
        'entity_type' => $entity_type,
        'bundles' => $form_state->getValue(['entity_type_dependent', 'bundles']),
        'view_modes' => $form_state->getValue(['entity_type_dependent', 'view_modes']),
      ];

      $config->set('raw_entities', $raw_entities)->save();

      $this->messenger()->addStatus($this->t('Configurazione aggiunta con successo.'));
    }

    parent::submitForm($form, $form_state);
  }
}