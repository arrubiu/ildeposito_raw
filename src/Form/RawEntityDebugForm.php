<?php

namespace Drupal\ildeposito_raw\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ildeposito_raw\RawEntityManager;

/**
 * Form per il debug dei dati raw delle entità.
 */
class RawEntityDebugForm extends FormBase {

  /**
   * Costruttore.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RawEntityManager $rawEntityManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ildeposito_raw.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ildeposito_raw_debug';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $entity_types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
        $entity_types[$entity_type_id] = $entity_type->getLabel();
      }
    }

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo entità'),
      '#options' => $entity_types,
      '#default_value' => 'node',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateEntityAutocomplete',
        'wrapper' => 'entity-autocomplete-wrapper',
      ],
    ];

    $form['entity_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Entità'),
      '#target_type' => $form_state->getValue('entity_type', 'node'),
      '#required' => TRUE,
      '#prefix' => '<div id="entity-autocomplete-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Visualizza dati raw'),
    ];

    // Visualizza i dati raw se disponibili
    if ($data = $form_state->get('data')) {
      $form['data'] = [
        '#type' => 'details',
        '#title' => $this->t('Dati raw'),
        '#open' => TRUE,
        'data' => [
          '#markup' => '<pre>' . print_r($data, TRUE) . '</pre>',
        ],
      ];
    }

    return $form;
  }

  /**
   * Ajax callback per aggiornare l'autocomplete dell'entità.
   */
  public function updateEntityAutocomplete(array &$form, FormStateInterface $form_state) {
    return $form['entity_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_type = $form_state->getValue('entity_type');
    $entity_id = $form_state->getValue('entity_id');

    if ($entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id)) {
      $data = $this->rawEntityManager->getRawData($entity);
      $form_state->set('data', $data);
      $form_state->setRebuild();
    }
  }
}