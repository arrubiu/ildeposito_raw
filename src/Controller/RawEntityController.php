<?php

namespace Drupal\ildeposito_raw\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller per la gestione delle operazioni sulle configurazioni raw.
 */
class RawEntityController extends ControllerBase {

  /**
   * Il servizio config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Costruttore.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Il servizio config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Elimina una configurazione raw.
   *
   * @param int $delta
   *   L'indice della configurazione da eliminare.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Reindirizza alla pagina di configurazione.
   */
  public function deleteConfig($delta) {
    $config = $this->configFactory->getEditable('ildeposito_raw.settings');
    $raw_entities = $config->get('raw_entities') ?? [];

    if (isset($raw_entities[$delta])) {
      unset($raw_entities[$delta]);
      $config->set('raw_entities', array_values($raw_entities))->save();
      $this->messenger()->addStatus($this->t('Configurazione eliminata con successo.'));
    }

    return new RedirectResponse(Url::fromRoute('ildeposito_raw.settings')->toString());
  }

}