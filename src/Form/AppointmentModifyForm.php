<?php

namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\appointment\Service\AppointmentManager;
use Drupal\appointment\Service\EmailService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulaire de modification et suppression de rendez-vous.
 *
 * Étapes :
 *   1 → Saisie numéro de téléphone
 *   2 → Liste des RDV trouvés
 *   3 → Choix nouvelle date (FullCalendar)
 *   4 → Confirmation modification
 */
class AppointmentModifyForm extends FormBase {

  /**
   * @var \Drupal\appointment\Service\AppointmentManager
   */
  protected $appointmentManager;

  /**
   * @var \Drupal\appointment\Service\EmailService
   */
  protected $emailService;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    AppointmentManager $appointment_manager,
    EmailService $email_service,
  ) {
    $this->appointmentManager = $appointment_manager;
    $this->emailService       = $email_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('appointment.manager'),
      $container->get('appointment.email'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_modify_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'appointment/appointment.booking';

    $form['#prefix'] = '<div id="appointment-modify-wrapper">';
    $form['#suffix'] = '</div>';

    $step = $form_state->get('step') ?? 1;
    $form_state->set('step', $step);

    $form = match($step) {
      1 => $this->buildStep1($form, $form_state),
      2 => $this->buildStep2($form, $form_state),
      3 => $this->buildStep3($form, $form_state),
      4 => $this->buildStep4($form, $form_state),
      default => $this->buildStep1($form, $form_state),
    };

    return $form;
  }

  // ---------------------------------------------------------------------------
  // ÉTAPES
  // ---------------------------------------------------------------------------

  /**
   * Étape 1 : Saisie du numéro de téléphone.
   */
  protected function buildStep1(array $form, FormStateInterface $form_state): array {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Renseignez votre numéro de téléphone pour modifier votre rendez-vous') . '</h2>',
    ];

    $form['phone'] = [
      '#type'        => 'tel',
      '#title'       => $this->t('Numéro de téléphone'),
      '#required'    => TRUE,
      '#attributes'  => ['placeholder' => '06XXXXXXXX'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Valider'),
      '#ajax'  => $this->ajaxConfig(),
    ];

    return $form;
  }

  /**
   * Étape 2 : Liste des RDV trouvés pour ce téléphone.
   */
  protected function buildStep2(array $form, FormStateInterface $form_state): array {
    $appointments = $form_state->get('appointments');

    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Votre rendez-vous a été confirmé, suivez les étapes si vous souhaitez le modifier') . '</h2>',
    ];

    foreach ($appointments as $appointment) {
      $id      = $appointment->id();
      $ref     = $appointment->get('reference')->value;
      $date    = new \DateTime($appointment->get('appointment_date')->value);
      $date_end = clone $date;
      $date_end->modify('+30 minutes');

      $agency  = $this->appointmentManager->getAgencies()[$appointment->get('agency')->target_id] ?? '—';
      $adviser_id = $appointment->get('adviser')->target_id;
      $adviser_user = \Drupal::entityTypeManager()->getStorage('user')->load($adviser_id);
      $adviser = $adviser_user ? $adviser_user->getDisplayName() : '—';
      $type_term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load(
        $appointment->get('appointment_type')->target_id
      );
      $type = $type_term ? $type_term->label() : '—';

      $form['appointment_' . $id] = [
        '#markup' => '
          <div class="appointment-item">
            <div class="appointment-item-info">
              <strong>' . $this->t('Rendez-vous le @date à @time', [
                '@date' => $date->format('d/m/Y'),
                '@time' => $date->format('H\hi'),
              ]) . '</strong><br>
              ' . $this->t('Avec') . ' <em>' . \Drupal\Component\Utility\Html::escape($adviser) . '</em><br>
              ' . $this->t('Agence de rendez-vous :') . ' ' . \Drupal\Component\Utility\Html::escape($agency) . '<br>
              ' . $this->t('Type de rendez-vous :') . ' ' . \Drupal\Component\Utility\Html::escape($type) . '
            </div>
            <div class="appointment-item-actions">
              <a href="#" class="btn-modify" data-id="' . $id . '">' . $this->t('Modifier') . '</a>
              <a href="#" class="btn-delete" data-id="' . $id . '">' . $this->t('Supprimer') . '</a>
            </div>
          </div>
        ',
      ];

      // Boutons cachés déclenchés par les liens JS
      $form['actions']['modify_' . $id] = [
        '#type'                    => 'submit',
        '#value'                   => 'modify_' . $id,
        '#attributes'              => ['class' => ['visually-hidden'], 'id' => 'btn-modify-' . $id],
        '#submit'                  => ['::goToModify'],
        '#limit_validation_errors' => [],
        '#ajax'                    => $this->ajaxConfig(),
        '#name'                    => 'modify_' . $id,
      ];

      $form['actions']['delete_' . $id] = [
        '#type'                    => 'submit',
        '#value'                   => 'delete_' . $id,
        '#attributes'              => ['class' => ['visually-hidden'], 'id' => 'btn-delete-' . $id],
        '#submit'                  => ['::goToDelete'],
        '#limit_validation_errors' => [],
        '#ajax'                    => $this->ajaxConfig(),
        '#name'                    => 'delete_' . $id,
      ];
    }

    return $form;
  }

  /**
   * Étape 3 : Choix de la nouvelle date (FullCalendar).
   */
  protected function buildStep3(array $form, FormStateInterface $form_state): array {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Choisissez une nouvelle date') . '</h2>',
    ];

    $appointment_id = $form_state->get('selected_appointment_id');
    $appointment    = \Drupal::entityTypeManager()
      ->getStorage('appointment')->load($appointment_id);
    $adviser_id     = $appointment->get('adviser')->target_id;

    $slots = $this->appointmentManager->getAvailableSlots($adviser_id);

    $form['#attached']['library'][]                           = 'appointment/fullcalendar';
    $form['#attached']['drupalSettings']['appointment']['slots']      = $slots;
    $form['#attached']['drupalSettings']['appointment']['adviser_id'] = $adviser_id;

    $form['appointment_date'] = [
      '#type'       => 'hidden',
      '#attributes' => ['id' => 'appointment-selected-date'],
    ];

    $form['calendar'] = [
      '#markup' => '<div id="appointment-calendar"></div>',
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['back'] = [
      '#type'                    => 'submit',
      '#value'                   => $this->t('Retour'),
      '#submit'                  => ['::backToList'],
      '#limit_validation_errors' => [],
      '#ajax'                    => $this->ajaxConfig(),
    ];

    $form['actions']['next'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Suivant'),
      '#submit' => ['::confirmNewDate'],
      '#ajax'   => $this->ajaxConfig(),
    ];

    return $form;
  }

  /**
   * Étape 4 : Confirmation de la modification.
   */
  protected function buildStep4(array $form, FormStateInterface $form_state): array {
    $new_date = $form_state->get('new_date');
    $date_obj = new \DateTime($new_date);
    $date_end = clone $date_obj;
    $date_end->modify('+30 minutes');

    $form['confirmation'] = [
      '#markup' => '
        <div class="appointment-confirmation">
          <div class="confirmation-icon">✓</div>
          <h2>' . $this->t('Votre rendez-vous a bien été modifié') . '</h2>
          <p>' . $this->t('Nouvelle date : <strong>@date</strong> de <strong>@start</strong> à <strong>@end</strong>', [
            '@date'  => $date_obj->format('d/m/Y'),
            '@start' => $date_obj->format('H\hi'),
            '@end'   => $date_end->format('H\hi'),
          ]) . '</p>
          <a href="/prendre-un-rendez-vous">' . $this->t('Prendre un nouveau rendez-vous') . '</a>
        </div>
      ',
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // SUBMIT HANDLERS
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $step    = $form_state->get('step');
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';

    // Validation étape 1 : téléphone + RDV existant
    if ($step === 1) {
      $phone        = $form_state->getValue('phone');
      $appointments = $this->appointmentManager->findByPhone($phone);

      if (empty($appointments)) {
        $form_state->setErrorByName('phone',
          $this->t('Aucun rendez-vous trouvé pour ce numéro de téléphone.')
        );
        return;
      }
      // Stocker pour buildStep2
      $form_state->set('appointments', $appointments);
    }

    // Validation étape 3 : créneau sélectionné
    if ($step === 3 && str_starts_with($trigger, 'next') === false) {
      return;
    }
    if ($step === 3) {
      $date = $form_state->getValue('appointment_date');
      if (empty($date)) {
        $form_state->setErrorByName('appointment_date',
          $this->t('Veuillez sélectionner un créneau.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  $appointments = $form_state->get('appointments');
  if ($appointments) {
    $storage = $form_state->getStorage();
    $storage['phone'] = $form_state->getValue('phone');
    $form_state->setStorage($storage);
    $form_state->set('step', 2);
    $form_state->setRebuild(TRUE);
  }
}

  /**
   * Aller vers la modification d'un RDV spécifique.
   */
  public function goToModify(array &$form, FormStateInterface $form_state): void {
  $trigger = $form_state->getTriggeringElement();
  $id      = str_replace('modify_', '', $trigger['#name']);

  $storage = $form_state->getStorage();
  $storage['selected_appointment_id'] = (int) $id;
  $form_state->setStorage($storage);

  $form_state->set('selected_appointment_id', (int) $id);
  $form_state->set('step', 3);
  $form_state->setRebuild(TRUE);
}

  /**
   * Annuler (soft delete) un RDV.
   */
  public function goToDelete(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $id      = str_replace('delete_', '', $trigger['#name']);

    $appointment = \Drupal::entityTypeManager()
      ->getStorage('appointment')->load((int) $id);

    if ($appointment) {
      try {
        $this->emailService->sendCancellation($appointment);
      }
      catch (\Exception $e) {
        \Drupal::logger('appointment')->warning('Email annulation non envoyé : @msg', ['@msg' => $e->getMessage()]);
      }
      $this->appointmentManager->cancelAppointment((int) $id);
      $this->messenger()->addStatus(
        $this->t('Votre rendez-vous @ref a été annulé.', [
          '@ref' => $appointment->get('reference')->value,
        ])
      );
    }

    // Revenir à l'étape 1
    $form_state->set('step', 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Retour à la liste des RDV (étape 2).
   */
  public function backToList(array &$form, FormStateInterface $form_state): void {
  $storage = $form_state->getStorage();
  $phone   = $storage['phone'] ?? NULL;

  if ($phone) {
    $appointments = $this->appointmentManager->findByPhone($phone);
    $form_state->set('appointments', $appointments);
  }

  $form_state->set('step', 2);
  $form_state->setRebuild(TRUE);
}

  /**
   * Valider le nouveau créneau et sauvegarder.
   */
  public function confirmNewDate(array &$form, FormStateInterface $form_state): void {
    $new_date = rtrim($form_state->getValue('appointment_date'), 'Z');
    $appointment_id = $form_state->get('selected_appointment_id');

    $appointment = \Drupal::entityTypeManager()
      ->getStorage('appointment')->load($appointment_id);

    if ($appointment && $new_date) {
      $appointment->set('appointment_date', $new_date);
      $appointment->save();

      try {
        $this->emailService->sendModification($appointment);
      }
      catch (\Exception $e) {
        \Drupal::logger('appointment')->warning('Email modification non envoyé : @msg', ['@msg' => $e->getMessage()]);
      }

      $form_state->set('new_date', $new_date);
      $form_state->set('step', 4);
      $form_state->setRebuild(TRUE);
    }
  }

  // ---------------------------------------------------------------------------
  // HELPERS
  // ---------------------------------------------------------------------------

  /**
   * Config AJAX commune.
   */
  protected function ajaxConfig(): array {
    return [
      'wrapper'  => 'appointment-modify-wrapper',
      'callback' => '::ajaxCallback',
      'effect'   => 'fade',
    ];
  }

  /**
   * Callback AJAX.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

}