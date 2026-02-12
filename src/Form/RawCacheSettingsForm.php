<?php

namespace Drupal\ildeposito_raw\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form di configurazione per le impostazioni della cache raw.
 */
class RawCacheSettingsForm extends ConfigFormBase {

  /**
   * Il servizio date.formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Il servizio state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Il servizio config.factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   Il servizio date.formatter.
   * @param \Drupal\Core\State\StateInterface $state
   *   Il servizio state.
   */
  public function __construct(ConfigFactoryInterface $config_factory, DateFormatterInterface $date_formatter, StateInterface $state) {
    parent::__construct($config_factory);
    $this->dateFormatter = $date_formatter;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('date.formatter'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ildeposito_raw_cache_settings_form';
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
    
    $form['cache_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Impostazioni generali della cache'),
      '#open' => TRUE,
    ];
    
    $form['cache_settings']['cache_max_age'] = [
      '#type' => 'select',
      '#title' => $this->t('Durata massima della cache'),
      '#description' => $this->t('Quanto tempo conservare i dati in cache prima che scadano. Impostare su "Permanente" per mantenere la cache fino a quando non viene invalidata esplicitamente.'),
      '#options' => [
        -1 => $this->t('Permanente'),
        60 => $this->t('1 minuto'),
        300 => $this->t('5 minuti'),
        900 => $this->t('15 minuti'),
        1800 => $this->t('30 minuti'),
        3600 => $this->t('1 ora'),
        7200 => $this->t('2 ore'),
        14400 => $this->t('4 ore'),
        28800 => $this->t('8 ore'),
        43200 => $this->t('12 ore'),
        86400 => $this->t('1 giorno'),
        604800 => $this->t('1 settimana'),
      ],
      '#default_value' => $config->get('cache_max_age') ?? -1,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ildeposito_raw.settings')
      ->set('cache_max_age', $form_state->getValue('cache_max_age'))
      ->save();

    parent::submitForm($form, $form_state);
  }



}
