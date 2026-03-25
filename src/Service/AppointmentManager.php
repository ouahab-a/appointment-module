<?php

namespace Drupal\appointment\Service;

use Drupal\appointment\Entity\Appointment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service central de gestion des rendez-vous.
 */
class AppointmentManager
{

  use StringTranslationTrait;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager)
  {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  // ---------------------------------------------------------------------------
  // DONNÉES POUR LE FORMULAIRE
  // ---------------------------------------------------------------------------

  /**
   * Retourne toutes les agences actives sous forme [id => label].
   */
  public function getAgencies(): array
  {
    $agencies = $this->entityTypeManager
      ->getStorage('agency')
      ->loadByProperties(['status' => 1]);

    $options = [];
    foreach ($agencies as $agency) {
      $options[$agency->id()] = $agency->label();
    }
    return $options;
  }

  /**
   * Retourne les types de rendez-vous (taxonomy) sous forme [id => label].
   */
  public function getAppointmentTypes(): array
  {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'appointment_type', 'status' => 1]);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  /**
   * Retourne les conseillers d'une agence ayant une spécialisation donnée.
   *
   * @param int $agency_id ID de l'agence sélectionnée.
   * @param int $type_id   ID du terme de taxonomie (type de RDV).
   *
   * @return array [uid => nom complet]
   */
  public function getAdvisersByAgencyAndType(int $agency_id, int $type_id): array
  {
    // Charger les users avec le rôle adviser liés à cette agence
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties([
        'roles'                => 'adviser',
        'field_adviser_agency' => $agency_id,
        'status'               => 1,
      ]);

    $options = [];
    foreach ($users as $user) {
      // Vérifier que le conseiller a cette spécialisation
      $spec_ids = array_column(
        $user->get('field_adviser_specializations')->getValue(),
        'target_id'
      );

      if (in_array($type_id, $spec_ids)) {
        $options[$user->id()] = $user->getDisplayName();
      }
    }
    return $options;
  }

  // ---------------------------------------------------------------------------
  // CRÉNEAUX DISPONIBLES
  // ---------------------------------------------------------------------------

  /**
   * Calcule les créneaux disponibles pour un conseiller.
   *
   * Lit les horaires JSON du conseiller, génère des slots de 30 min,
   * retire les slots déjà réservés.
   *
   * @param int $adviser_id UID du conseiller.
   *
   * @return array Tableau de créneaux [['start' => '...', 'end' => '...', 'title' => '...']]
   */
  public function getAvailableSlots(int $adviser_id): array
  {
    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load($adviser_id);

    if (!$user) {
      return [];
    }

    // Lire la config au lieu de hardcoder
    $config         = \Drupal::config('appointment.settings');
    $slot_duration  = (int) ($config->get('slot_duration') ?? 30);
    $booking_window = (int) ($config->get('booking_window') ?? 14);
    $min_notice     = (int) ($config->get('min_notice') ?? 2);

    $hours_json = $user->get('field_adviser_hours')->value;
    $hours      = json_decode($hours_json, TRUE) ?? [];

    $slots      = [];
    $min_start  = new \DateTime('+' . $min_notice . ' hours');
    $end_window = new \DateTime('+' . $booking_window . ' days');
    $current    = new \DateTime('today');

    while ($current <= $end_window) {
      $day_name = strtolower($current->format('l'));

      if (isset($hours[$day_name])) {
        [$start_time, $end_time] = $hours[$day_name];

        $slot_start = new \DateTime($current->format('Y-m-d') . ' ' . $start_time);
        $slot_end   = new \DateTime($current->format('Y-m-d') . ' ' . $end_time);

        while ($slot_start < $slot_end) {
          // Respecter le délai minimum de réservation
          if ($slot_start > $min_start) {
            $slot_finish = clone $slot_start;
            $slot_finish->modify('+' . $slot_duration . ' minutes');

            $slots[] = [
              'start' => $slot_start->format('Y-m-d\TH:i:s'),
              'end'   => $slot_finish->format('Y-m-d\TH:i:s'),
              'title' => $this->t('Disponible')->render(),
            ];
          }
          $slot_start->modify('+' . $slot_duration . ' minutes');
        }
      }
      $current->modify('+1 day');
    }

    return $this->filterBookedSlots($slots, $adviser_id);
  }

  /**
   * Retire les créneaux déjà réservés pour ce conseiller.
   *
   * @param array $slots      Tous les créneaux générés.
   * @param int   $adviser_id UID du conseiller.
   *
   * @return array Créneaux disponibles uniquement.
   */
  protected function filterBookedSlots(array $slots, int $adviser_id): array
  {
    $booked = $this->entityTypeManager
      ->getStorage('appointment')
      ->loadByProperties([
        'adviser'            => $adviser_id,
        'appointment_status' => ['pending', 'confirmed'],
      ]);

    $booked_dates = [];
    foreach ($booked as $appointment) {
      $booked_dates[] = $appointment->get('appointment_date')->value;
    }

    // Marquer les créneaux réservés au lieu de les supprimer
    return array_map(function ($slot) use ($booked_dates) {
      $slot['available'] = !in_array($slot['start'], $booked_dates);
      return $slot;
    }, $slots);
  }

  // ---------------------------------------------------------------------------
  // VALIDATION
  // ---------------------------------------------------------------------------

  /**
   * Vérifie si un créneau est déjà pris (anti double-booking).
   *
   * @param int    $adviser_id UID du conseiller.
   * @param string $date       Date au format Y-m-d\TH:i:s.
   *
   * @return bool TRUE si le créneau est pris.
   */
  public function isSlotTaken(int $adviser_id, string $date): bool
  {
    $existing = $this->entityTypeManager
      ->getStorage('appointment')
      ->loadByProperties([
        'adviser'            => $adviser_id,
        'appointment_date'   => $date,
        'appointment_status' => ['pending', 'confirmed'],
      ]);

    return !empty($existing);
  }

  // ---------------------------------------------------------------------------
  // CRÉATION DU RENDEZ-VOUS
  // ---------------------------------------------------------------------------

  /**
   * Crée et sauvegarde une entité Appointment.
   *
   * @param array $data Données collectées par le formulaire.
   *
   * @return \Drupal\appointment\Entity\Appointment L'entité créée.
   */
  public function createAppointment(array $data): Appointment
  {
    // Générer une référence unique : RDV-2024-XXXXX
    $reference = $this->generateReference();

    $appointment = Appointment::create([
      'label'              => $this->t('RDV @ref', ['@ref' => $reference])->render(),
      'agency'             => ['target_id' => $data['agency_id']],
      'adviser'            => ['target_id' => $data['adviser_id']],
      'appointment_type'   => ['target_id' => $data['appointment_type']],
      'appointment_date'   => $data['appointment_date'],
      'customer_name'      => $data['customer_name'],
      'customer_phone'     => $data['customer_phone'],
      'customer_email'     => $data['customer_email'],
      'appointment_status' => 'pending',
      'reference'          => $reference,
      'status'             => 1,
    ]);

    $appointment->save();
    return $appointment;
  }

  /**
   * Génère une référence unique pour un rendez-vous.
   *
   * @return string Ex: RDV-2024-00042
   */
  protected function generateReference(): string
  {
    $year = date('Y');

    // Compter les RDV existants cette année pour incrémenter
    $count = $this->entityTypeManager
      ->getStorage('appointment')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    return sprintf('RDV-%s-%05d', $year, (int) $count + 1);
  }

  // ---------------------------------------------------------------------------
  // GESTION (MODIFIER / ANNULER)
  // ---------------------------------------------------------------------------

  /**
   * Trouve un rendez-vous par numéro de téléphone.
   *
   * @param string $phone Numéro de téléphone du client.
   *
   * @return array Tableau d'entités Appointment.
   */
  public function findByPhone(string $phone): array
  {
    return $this->entityTypeManager
      ->getStorage('appointment')
      ->loadByProperties([
        'customer_phone'     => $phone,
        'appointment_status' => ['pending', 'confirmed'],
      ]);
  }

  /**
   * Annule un rendez-vous (soft delete = statut cancelled).
   *
   * @param int $appointment_id ID de l'entité Appointment.
   */
  public function cancelAppointment(int $appointment_id): void
  {
    $appointment = $this->entityTypeManager
      ->getStorage('appointment')
      ->load($appointment_id);

    if ($appointment) {
      $appointment->set('appointment_status', 'cancelled');
      $appointment->save();
    }
  }
}
