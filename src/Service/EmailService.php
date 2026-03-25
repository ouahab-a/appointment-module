<?php

namespace Drupal\appointment\Service;

use Drupal\appointment\Entity\Appointment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service d'envoi d'emails pour les rendez-vous.
 */
class EmailService {

  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->mailManager       = $mail_manager;
    $this->languageManager   = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  // ---------------------------------------------------------------------------
  // API PUBLIQUE
  // ---------------------------------------------------------------------------

  /**
   * Envoie l'email de confirmation après création du RDV.
   *
   * @param \Drupal\appointment\Entity\Appointment $appointment
   */
  public function sendConfirmation(Appointment $appointment): void {
    $params = $this->buildParams($appointment, 'confirmation');
    $this->send($appointment->get('customer_email')->value, $params);
  }

  /**
   * Envoie l'email de modification du RDV.
   *
   * @param \Drupal\appointment\Entity\Appointment $appointment
   */
  public function sendModification(Appointment $appointment): void {
    $params = $this->buildParams($appointment, 'modification');
    $this->send($appointment->get('customer_email')->value, $params);
  }

  /**
   * Envoie l'email d'annulation du RDV.
   *
   * @param \Drupal\appointment\Entity\Appointment $appointment
   */
  public function sendCancellation(Appointment $appointment): void {
    $params = $this->buildParams($appointment, 'cancellation');
    $this->send($appointment->get('customer_email')->value, $params);
  }

  // ---------------------------------------------------------------------------
  // HELPERS PRIVÉS
  // ---------------------------------------------------------------------------

  /**
   * Construit le tableau de paramètres pour hook_mail().
   *
   * @param \Drupal\appointment\Entity\Appointment $appointment
   * @param string $type  'confirmation' | 'modification' | 'cancellation'
   *
   * @return array
   */
  protected function buildParams(Appointment $appointment, string $type): array {
    // Charger les entités liées pour avoir leurs labels
    $agency = $this->entityTypeManager
      ->getStorage('agency')
      ->load($appointment->get('agency')->target_id);

    $adviser = $this->entityTypeManager
      ->getStorage('user')
      ->load($appointment->get('adviser')->target_id);

    $term = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->load($appointment->get('appointment_type')->target_id);

    // Formater la date lisible
    $raw_date    = $appointment->get('appointment_date')->value;
    $date_obj    = new \DateTime($raw_date);
    $date_end    = clone $date_obj;
    $date_end->modify('+30 minutes');

    $date_label  = $date_obj->format('d/m/Y');
    $time_label  = $date_obj->format('H\hi') . ' - ' . $date_end->format('H\hi');

    return [
      'type'           => $type,
      'reference'      => $appointment->get('reference')->value,
      'customer_name'  => $appointment->get('customer_name')->value,
      'customer_email' => $appointment->get('customer_email')->value,
      'customer_phone' => $appointment->get('customer_phone')->value,
      'agency_name'    => $agency ? $agency->label() : '',
      'adviser_name'   => $adviser ? $adviser->getDisplayName() : '',
      'rdv_type'       => $term ? $term->label() : '',
      'date_label'     => $date_label,
      'time_label'     => $time_label,
    ];
  }

  /**
   * Envoie l'email via le MailManager Drupal.
   *
   * @param string $to     Adresse destinataire.
   * @param array  $params Paramètres du mail.
   */
  protected function send(string $to, array $params): void {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    $result = $this->mailManager->mail(
      module:   'appointment',  // → déclenche hook_mail() dans appointment.module
      key:      $params['type'],
      to:       $to,
      langcode: $langcode,
      params:   $params,
    );

    if (!$result['result']) {
      \Drupal::logger('appointment')->error(
        'Échec envoi email @type à @to pour RDV @ref',
        [
          '@type' => $params['type'],
          '@to'   => $to,
          '@ref'  => $params['reference'],
        ]
      );
    }
  }

}