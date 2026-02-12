<?php

namespace Drupal\ildeposito_raw\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   * Costruttore.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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
        'callback' => '::updateBundleOptions',
        'wrapper' => 'bundle-wrapper',
      ],
    ];

    $form['add']['bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#options' => $this->getBundleOptions($form_state->getValue('entity_type')),
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#prefix' => '<div id="bundle-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['add']['view_modes'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => [
        'default' => $this->t('Default'),
        'full' => $this->t('Full'),
        'teaser' => $this->t('Teaser'),
      ],
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
   * Ajax callback per aggiornare le opzioni dei bundle.
   */
  public function updateBundleOptions(array &$form, FormStateInterface $form_state) {
    return $form['add']['bundles'];
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if ($entity_type = $form_state->getValue('entity_type')) {
      $raw_entities[] = [
        'entity_type' => $entity_type,
        'bundles' => $form_state->getValue('bundles'),
        'view_modes' => $form_state->getValue('view_modes'),
      ];

      $config->set('raw_entities', $raw_entities)->save();

      $this->messenger()->addStatus($this->t('Configurazione aggiunta con successo.'));
    }

    parent::submitForm($form, $form_state);
  }
}