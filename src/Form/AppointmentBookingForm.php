<?php


namespace Drupal\appointment\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\appointment\Service\AppointmentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulaire multi-étapes de prise de rendez-vous.
 */
class AppointmentBookingForm extends FormBase
{

  /**
   * Nombre total d'étapes.
   */
  const TOTAL_STEPS = 6;

  /**
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * @var \Drupal\appointment\Service\AppointmentManager
   */
  protected $appointmentManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    AppointmentManager $appointment_manager
  ) {
    // On crée un "bucket" privé pour ce formulaire
    $this->tempStore = $temp_store_factory->get('appointment_booking');
    $this->appointmentManager = $appointment_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('tempstore.private'),
      $container->get('appointment.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'appointment_booking_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    // Ajout de la bibliotheque.
    $form['#attached']['library'][] = 'appointment/appointment.booking';
    // Étape courante — 1 par défaut
    $step = $form_state->get('step') ?? 1;
    $form_state->set('step', $step);

    // Wrapper AJAX autour du formulaire entier
    $form['#prefix'] = '<div id="appointment-booking-wrapper">';
    $form['#suffix'] = '</div>';

    // Indicateur de progression
    $form['progress'] = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['appointment-progress']],
    ];


    // Construction de l'étape courante
    $form = match ($step) {
      1 => $this->buildStep1($form, $form_state),
      2 => $this->buildStep2($form, $form_state),
      3 => $this->buildStep3($form, $form_state),
      4 => $this->buildStep4($form, $form_state),
      5 => $this->buildStep5($form, $form_state),
      6 => $this->buildStep6($form, $form_state),
      default => $this->buildStep1($form, $form_state),
    };

    // Boutons navigation
    $form['actions'] = ['#type' => 'actions'];

    if ($step > 1 && $step < 6) {
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Retour'),
        '#submit' => ['::previousStep'],
        '#limit_validation_errors' => [],
        '#ajax' => $this->ajaxConfig(),
      ];
    }

    if ($step < 5) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Suivant'),
        '#submit' => ['::nextStep'],
        '#ajax' => $this->ajaxConfig(),
      ];
    }

    if ($step === 5) {
      $form['actions']['confirm'] = [
        '#type' => 'submit',
        '#value' => $this->t('Confirmer'),
        '#ajax' => $this->ajaxConfig(),
      ];
    }

    return $form;
  }

  // ---------------------------------------------------------------------------
  // ÉTAPES
  // ---------------------------------------------------------------------------

  /**
   * Étape 1 : Choix de l'agence.
   */
  protected function buildStep1(array $form, FormStateInterface $form_state): array
  {
    // Nettoyage d'une session précédente si on revient au début
    if ($form_state->get('step') === 1 && !$this->tempStore->get('_fresh')) {
      foreach (
        [
          'agency_id',
          'adviser_id',
          'appointment_type',
          'appointment_date',
          'customer_name',
          'customer_firstname',
          'customer_phone',
          'customer_email',
          'reference'
        ] as $key
      ) {
        $this->tempStore->delete($key);
      }
      $this->tempStore->set('_fresh', TRUE);
    }
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Choisissez une agence') . '</h2>',
    ];

    $agencies = $this->appointmentManager->getAgencies();

    $form['agency_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Agence'),
      '#options' => $agencies,
      '#required' => TRUE,
      '#default_value' => $this->tempStore->get('agency_id'),
    ];

    return $form;
  }

  /**
   * Étape 2 : Choix du type de rendez-vous.
   */
  protected function buildStep2(array $form, FormStateInterface $form_state): array
  {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Type de rendez-vous') . '</h2>',
    ];

    $types = $this->appointmentManager->getAppointmentTypes();

    $form['appointment_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Commencez dès maintenant, prenez votre rendez-vous pour'),
      '#options' => $types,
      '#required' => TRUE,
      '#default_value' => $this->tempStore->get('appointment_type'),
    ];

    return $form;
  }

  /**
   * Étape 3 : Choix du conseiller.
   */
  protected function buildStep3(array $form, FormStateInterface $form_state): array
  {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Choisissez votre conseiller') . '</h2>',
    ];

    $agency_id = $this->tempStore->get('agency_id');
    $type_id   = $this->tempStore->get('appointment_type');
    $advisers  = $this->appointmentManager->getAdvisersByAgencyAndType($agency_id, $type_id);

    $form['adviser_id'] = [
      '#type' => 'radios',
      '#title' => $this->t('Conseiller'),
      '#options' => $advisers,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Étape 4 : Choix de la date et heure (FullCalendar).
   */
  protected function buildStep4(array $form, FormStateInterface $form_state): array
  {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Choisissez le jour et l\'heure') . '</h2>',
    ];

    $adviser_id = $this->tempStore->get('adviser_id');

    // Créneaux disponibles calculés par le service
    $slots = $this->appointmentManager->getAvailableSlots($adviser_id);

    $form['#attached']['library'][] = 'appointment/fullcalendar';
    $form['#attached']['drupalSettings']['appointment']['slots'] = array_values($slots);
    // $form['#attached']['drupalSettings']['appointment']['slots'] = $slots;
    $form['#attached']['drupalSettings']['appointment']['adviser_id'] = $adviser_id;
    // Champ caché rempli par le JS quand l'user clique un créneau
    $form['appointment_date'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'appointment-selected-date'],
      '#default_value' => $this->tempStore->get('appointment_date'),
    ];

    // Conteneur pour FullCalendar
    $form['calendar'] = [
      '#markup' => '<div id="appointment-calendar"></div>',
    ];

    return $form;
  }

  /**
   * Étape 5 : Informations personnelles.
   */
  protected function buildStep5(array $form, FormStateInterface $form_state): array
  {
    $form['step_title'] = [
      '#markup' => '<h2>' . $this->t('Renseignez vos informations') . '</h2>',
    ];

    // Résumé du RDV choisi
    $form['summary'] = [
      '#theme' => 'appointment_summary',
      '#agency_id' => $this->tempStore->get('agency_id'),
      '#adviser_id' => $this->tempStore->get('adviser_id'),
      '#date' => $this->tempStore->get('appointment_date'),
    ];

    $form['customer_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nom'),
      '#required' => TRUE,
      '#default_value' => $this->tempStore->get('customer_name'),
      '#attributes' => ['placeholder' => $this->t('Nom')],
    ];

    $form['customer_firstname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prénom'),
      '#required' => TRUE,
      '#default_value' => $this->tempStore->get('customer_firstname'),
      '#attributes' => ['placeholder' => $this->t('Prénom')],
    ];

    $form['customer_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile'),
      '#required' => TRUE,
      '#default_value' => $this->tempStore->get('customer_phone'),
      '#attributes' => ['placeholder' => '06XXXXXXXX'],
    ];

    $form['customer_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Adresse e-mail'),
      '#required' => TRUE,
      '#default_value' => $this->tempStore->get('customer_email'),
    ];

    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('En cochant cette case, j\'accepte et je reconnais avoir pris connaissance des conditions générales d\'utilisation.'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Étape 6 : Confirmation finale (lecture seule).
   */
  protected function buildStep6(array $form, FormStateInterface $form_state): array
  {
    $reference  = $this->tempStore->get('reference');
    $date_raw   = $this->tempStore->get('appointment_date');
    $date_obj   = new \DateTime($date_raw);
    $date_end   = clone $date_obj;
    $date_end->modify('+' . (\Drupal::config('appointment.settings')->get('slot_duration') ?? 30) . ' minutes');

    $agency_id  = $this->tempStore->get('agency_id');
    $type_id    = $this->tempStore->get('appointment_type');
    $adviser_id = $this->tempStore->get('adviser_id');

    $agencies = $this->appointmentManager->getAgencies();
    $advisers = $this->appointmentManager->getAdvisersByAgencyAndType($agency_id, $type_id);

    $form['confirmation'] = [
      '#theme'         => 'appointment_confirmation',
      '#reference'     => $reference,
      '#date'          => $date_obj->format('d/m/Y'),
      '#time'          => $date_obj->format('H\hi') . ' – ' . $date_end->format('H\hi'),
      '#agency'        => $agencies[$agency_id] ?? '—',
      '#adviser'       => $advisers[$adviser_id] ?? '—',
      '#customer_name' => \Drupal\Component\Utility\Html::escape($this->tempStore->get('customer_name')) . ' ' . \Drupal\Component\Utility\Html::escape($this->tempStore->get('customer_firstname')),
      '#customer_email' => \Drupal\Component\Utility\Html::escape($this->tempStore->get('customer_email')),
      '#customer_phone' => \Drupal\Component\Utility\Html::escape($this->tempStore->get('customer_phone')),
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // NAVIGATION
  // ---------------------------------------------------------------------------

  /**
   * Passe à l'étape suivante.
   */
  public function nextStep(array &$form, FormStateInterface $form_state): void
  {
    $step = $form_state->get('step');

    // Sauvegarder les valeurs de l'étape courante dans TempStore
    $this->saveStepValues($step, $form_state);

    $form_state->set('step', $step + 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Revient à l'étape précédente.
   */
  public function previousStep(array &$form, FormStateInterface $form_state): void
  {
    $step = $form_state->get('step');

    // Effacer les valeurs des étapes suivantes pour éviter les conflits
    match ($step) {
      3 => $this->tempStore->delete('adviser_id'),
      4 => $this->tempStore->delete('appointment_date'),
      default => NULL,
    };

    $form_state->set('step', $step - 1);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Sauvegarde les valeurs d'une étape dans TempStore.
   */
  protected function saveStepValues(int $step, FormStateInterface $form_state): void
  {
    $map = [
      1 => ['agency_id'],
      2 => ['appointment_type'],
      3 => ['adviser_id'],
      4 => ['appointment_date'],
      5 => ['customer_name', 'customer_firstname', 'customer_phone', 'customer_email'],
    ];

    foreach ($map[$step] ?? [] as $field) {
      $value = $form_state->getValue($field);
      if ($value !== NULL) {
        if ($field === 'appointment_date') {
          // Supprimer tout suffix timezone : Z, +01:00, +00:00, etc.
          // Drupal datetime attend strictement Y-m-d\TH:i:s (20 chars max)
          $value = preg_replace('/([+-]\d{2}:\d{2}|Z)$/', '', (string) $value);
        }
        $this->tempStore->set($field, $value);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // VALIDATION
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $step = $form_state->get('step');
    $trigger = $form_state->getTriggeringElement()['#submit'][0] ?? '';

    // Pas de validation sur "Retour"
    if ($trigger === '::previousStep') {
      return;
    }

    match ($step) {
      4 => $this->validateStep4($form, $form_state),
      5 => $this->validateStep5($form, $form_state),
      default => NULL,
    };
  }

  /**
   * Validation étape 4 : créneau sélectionné + anti double-booking.
   */
  protected function validateStep4(array &$form, FormStateInterface $form_state): void
  {
    $date = $form_state->getValue('appointment_date');

    if (empty($date)) {
      $form_state->setErrorByName(
        'appointment_date',
        $this->t('Veuillez sélectionner un créneau dans le calendrier.')
      );
      return;
    }

    // Nettoyer tout suffix timezone
    $date = preg_replace('/([+-]\d{2}:\d{2}|Z)$/', '', (string) $date);
    $form_state->setValue('appointment_date', $date);

    $adviser_id = $this->tempStore->get('adviser_id');
    if ($this->appointmentManager->isSlotTaken($adviser_id, $date)) {
      $form_state->setErrorByName(
        'appointment_date',
        $this->t('Ce créneau vient d\'être réservé. Veuillez en choisir un autre.')
      );
    }
  }

  /**
   * Validation étape 5 : format téléphone marocain.
   */
  protected function validateStep5(array &$form, FormStateInterface $form_state): void
  {
    $phone = $form_state->getValue('customer_phone');

    if (!preg_match('/^(0|\+212)[567]\d{8}$/', $phone)) {
      $form_state->setErrorByName(
        'customer_phone',
        $this->t('Le numéro de téléphone n\'est pas valide (ex: 06XXXXXXXX ou +2126XXXXXXXX).')
      );
    }
  }

  // ---------------------------------------------------------------------------
  // SUBMIT FINAL
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    // Sauvegarder les dernières valeurs (étape 5)
    $this->saveStepValues(5, $form_state);

    // Créer l'entité Appointment via le service
    $appointment = $this->appointmentManager->createAppointment([
      'agency_id'        => $this->tempStore->get('agency_id'),
      'adviser_id'       => $this->tempStore->get('adviser_id'),
      'appointment_type' => $this->tempStore->get('appointment_type'),
      'appointment_date' => $this->tempStore->get('appointment_date'),
      'customer_name'    => $this->tempStore->get('customer_name') . ' ' . $this->tempStore->get('customer_firstname'),
      'customer_phone'   => $this->tempStore->get('customer_phone'),
      'customer_email'   => $this->tempStore->get('customer_email'),
    ]);

    // Stocker la référence pour buildStep6()
    $this->tempStore->set('reference', $appointment->get('reference')->value);

    // Envoyer l'email de confirmation
    \Drupal::service('appointment.email')->sendConfirmation($appointment);

    // NE PAS vider le TempStore ici — buildStep6() en a besoin
    // Le nettoyage se fait quand l'utilisateur repart vers /prendre-un-rendez-vous

    // Passer à l'étape 6 (confirmation)
    $form_state->set('step', 6);
    $form_state->setRebuild(TRUE);
  }

  // ---------------------------------------------------------------------------
  // HELPERS
  // ---------------------------------------------------------------------------

  /**
   * Config AJAX commune à tous les boutons.
   */
  protected function ajaxConfig(): array
  {
    return [
      'wrapper' => 'appointment-booking-wrapper',
      'callback' => '::ajaxCallback',
      'effect' => 'fade',
    ];
  }

  /**
   * Callback AJAX — retourne le formulaire entier.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state): array
  {
    return $form;
  }

  /**
   * Construit les items de la barre de progression.
   */
  protected function buildProgressItems(int $current_step): array
  {
    $labels = [
      1 => $this->t('Agence'),
      2 => $this->t('Type'),
      3 => $this->t('Conseiller'),
      4 => $this->t('Date'),
      5 => $this->t('Infos'),
      6 => $this->t('Confirmation'),
    ];

    $html = '<nav class="appointment-progress"><ul>';
    foreach ($labels as $step => $label) {
      $class = match (true) {
        $step < $current_step  => 'step-done',
        $step === $current_step => 'step-active',
        default                => 'step-pending',
      };
      $html .= '<li class="' . $class . '">' . $label . '</li>';
    }
    $html .= '</ul></nav>';

    return [['#markup' => $html]];
  }
}
