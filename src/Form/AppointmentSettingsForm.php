<?php

namespace Drupal\appointment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulaire de configuration du module appointment.
 *
 * Utilise ConfigFormBase (et non FormBase) car on lit/écrit
 * dans le système de configuration Drupal (\Drupal::config()).
 * Les valeurs sont stockées dans appointment.settings et
 * persistent entre les déploiements si exportées avec drush cex.
 */
class AppointmentSettingsForm extends ConfigFormBase {

  /**
   * Nom de l'objet de configuration.
   */
  const CONFIG_NAME = 'appointment.settings';

  /**
   * {@inheritdoc}
   *
   * Déclare quels objets de config ce formulaire peut modifier.
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Charger la config existante (ou les défauts si première installation)
    $config = $this->config(self::CONFIG_NAME);

    // -------------------------------------------------------------------------
    // Créneaux
    // -------------------------------------------------------------------------
    $form['slots'] = [
      '#type'  => 'details',
      '#title' => $this->t('Paramètres des créneaux'),
      '#open'  => TRUE,
    ];

    $form['slots']['slot_duration'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Durée d\'un créneau'),
      '#options'       => [
        15 => $this->t('15 minutes'),
        30 => $this->t('30 minutes'),
        45 => $this->t('45 minutes'),
        60 => $this->t('1 heure'),
      ],
      '#default_value' => $config->get('slot_duration') ?? 30,
      '#description'   => $this->t('Durée de chaque créneau de rendez-vous.'),
    ];

    $form['slots']['booking_window'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Fenêtre de réservation (jours)'),
      '#default_value' => $config->get('booking_window') ?? 14,
      '#min'           => 1,
      '#max'           => 90,
      '#description'   => $this->t('Nombre de jours dans le futur affichés dans le calendrier.'),
    ];

    $form['slots']['min_notice'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Délai minimum de réservation (heures)'),
      '#default_value' => $config->get('min_notice') ?? 2,
      '#min'           => 0,
      '#max'           => 72,
      '#description'   => $this->t('Un RDV ne peut pas être pris moins de X heures avant le créneau.'),
    ];

    // -------------------------------------------------------------------------
    // Email
    // -------------------------------------------------------------------------
    $form['email'] = [
      '#type'  => 'details',
      '#title' => $this->t('Paramètres email'),
      '#open'  => TRUE,
    ];

    $form['email']['sender_name'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Nom de l\'expéditeur'),
      '#default_value' => $config->get('sender_name') ?? 'Service Rendez-vous',
      '#required'      => TRUE,
    ];

    $form['email']['sender_email'] = [
      '#type'          => 'email',
      '#title'         => $this->t('Email de l\'expéditeur'),
      '#default_value' => $config->get('sender_email') ?? 'noreply@example.com',
      '#required'      => TRUE,
    ];

    $form['email']['send_confirmation'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Envoyer un email de confirmation'),
      '#default_value' => $config->get('send_confirmation') ?? TRUE,
    ];

    $form['email']['send_reminder'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Envoyer un rappel 24h avant le RDV'),
      '#default_value' => $config->get('send_reminder') ?? FALSE,
    ];

    // -------------------------------------------------------------------------
    // Affichage
    // -------------------------------------------------------------------------
    $form['display'] = [
      '#type'  => 'details',
      '#title' => $this->t('Paramètres d\'affichage'),
      '#open'  => FALSE,
    ];

    $form['display']['calendar_first_day'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Premier jour de la semaine'),
      '#options'       => [
        1 => $this->t('Lundi'),
        0 => $this->t('Dimanche'),
        6 => $this->t('Samedi'),
      ],
      '#default_value' => $config->get('calendar_first_day') ?? 1,
    ];

    $form['display']['calendar_min_time'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Heure de début du calendrier'),
      '#default_value' => $config->get('calendar_min_time') ?? '08:00:00',
      '#description'   => $this->t('Format HH:MM:SS — ex: 08:00:00'),
      '#size'          => 10,
    ];

    $form['display']['calendar_max_time'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Heure de fin du calendrier'),
      '#default_value' => $config->get('calendar_max_time') ?? '18:00:00',
      '#description'   => $this->t('Format HH:MM:SS — ex: 18:00:00'),
      '#size'          => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Valider le format HH:MM:SS
    $time_regex = '/^\d{2}:\d{2}:\d{2}$/';
    foreach (['calendar_min_time', 'calendar_max_time'] as $field) {
      $value = $form_state->getValue($field);
      if (!preg_match($time_regex, $value)) {
        $form_state->setErrorByName($field,
          $this->t('Le format doit être HH:MM:SS (ex: 08:00:00).')
        );
      }
    }

    // Valider min < max
    $min = $form_state->getValue('calendar_min_time');
    $max = $form_state->getValue('calendar_max_time');
    if ($min >= $max) {
      $form_state->setErrorByName('calendar_max_time',
        $this->t('L\'heure de fin doit être après l\'heure de début.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Sauvegarder dans le système de config Drupal
    $this->config(self::CONFIG_NAME)
      ->set('slot_duration',      $form_state->getValue('slot_duration'))
      ->set('booking_window',     $form_state->getValue('booking_window'))
      ->set('min_notice',         $form_state->getValue('min_notice'))
      ->set('sender_name',        $form_state->getValue('sender_name'))
      ->set('sender_email',       $form_state->getValue('sender_email'))
      ->set('send_confirmation',  $form_state->getValue('send_confirmation'))
      ->set('send_reminder',      $form_state->getValue('send_reminder'))
      ->set('calendar_first_day', $form_state->getValue('calendar_first_day'))
      ->set('calendar_min_time',  $form_state->getValue('calendar_min_time'))
      ->set('calendar_max_time',  $form_state->getValue('calendar_max_time'))
      ->save();

    // Message de succès + log
    parent::submitForm($form, $form_state);
    \Drupal::logger('appointment')->info('Configuration du module appointment mise à jour.');
  }

}