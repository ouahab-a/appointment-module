<?php

namespace Drupal\appointment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV des rendez-vous avec streaming.
 *
 * Utilise StreamedResponse pour envoyer les données au fur et à mesure
 * sans charger tous les RDV en mémoire — compatible avec des milliers de lignes.
 */
class AppointmentExportController extends ControllerBase {

  /**
   * Export CSV streamé.
   *
   * Traite les RDV par batch de 100 pour éviter les problèmes mémoire.
   * Envoie directement au navigateur sans fichier intermédiaire.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   */
  public function export(): StreamedResponse {
    $response = new StreamedResponse(function () {
      $handle = fopen('php://output', 'w');

      // BOM UTF-8 pour que Excel ouvre correctement le fichier
      fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

      // En-têtes CSV
      fputcsv($handle, [
        'Référence',
        'Date',
        'Agence',
        'Conseiller',
        'Type de rendez-vous',
        'Nom client',
        'Email',
        'Téléphone',
        'Statut',
      ], ';');

      $batch_size = 100;
      $offset     = 0;

      do {
        // Charger 100 RDV à la fois
        $ids = $this->entityTypeManager()
          ->getStorage('appointment')
          ->getQuery()
          ->accessCheck(FALSE)
          ->sort('created', 'DESC')
          ->range($offset, $batch_size)
          ->execute();

        if (empty($ids)) {
          break;
        }

        $appointments = $this->entityTypeManager()
          ->getStorage('appointment')
          ->loadMultiple($ids);

        foreach ($appointments as $appointment) {
          // Formater la date
          $date_raw = $appointment->get('appointment_date')->value;
          $date     = '';
          if ($date_raw) {
            try {
              $date = (new \DateTime($date_raw))->format('d/m/Y - H:i');
            }
            catch (\Exception $e) {
              $date = $date_raw;
            }
          }

          // Charger les labels des entités liées
          $agency_id  = $appointment->get('agency')->target_id;
          $adviser_id = $appointment->get('adviser')->target_id;
          $type_id    = $appointment->get('appointment_type')->target_id;

          $agency = '';
          if ($agency_id) {
            $agency_entity = $this->entityTypeManager()
              ->getStorage('agency')->load($agency_id);
            $agency = $agency_entity ? $agency_entity->label() : '';
          }

          $adviser = '';
          if ($adviser_id) {
            $adviser_entity = $this->entityTypeManager()
              ->getStorage('user')->load($adviser_id);
            $adviser = $adviser_entity ? $adviser_entity->getDisplayName() : '';
          }

          $type = '';
          if ($type_id) {
            $type_entity = $this->entityTypeManager()
              ->getStorage('taxonomy_term')->load($type_id);
            $type = $type_entity ? $type_entity->label() : '';
          }

          // Traduire le statut
          $statuses = [
            'pending'   => 'En attente',
            'confirmed' => 'Confirmé',
            'cancelled' => 'Annulé',
          ];
          $status = $statuses[$appointment->get('appointment_status')->value] ?? '';

          fputcsv($handle, [
            $appointment->get('reference')->value,
            $date,
            $agency,
            $adviser,
            $type,
            $appointment->get('customer_name')->value,
            $appointment->get('customer_email')->value,
            $appointment->get('customer_phone')->value,
            $status,
          ], ';');
        }

        // Libérer la mémoire après chaque batch
        unset($appointments);
        $this->entityTypeManager()->getStorage('appointment')->resetCache();
        $this->entityTypeManager()->getStorage('agency')->resetCache();
        $this->entityTypeManager()->getStorage('user')->resetCache();
        $this->entityTypeManager()->getStorage('taxonomy_term')->resetCache();

        // Envoyer les données au navigateur immédiatement
        flush();

        $offset += $batch_size;

      } while (count($ids) === $batch_size);

      fclose($handle);
    });

    // Headers HTTP pour le téléchargement
    $filename = 'rendez-vous-' . date('Y-m-d') . '.csv';
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="' . $filename . '"'
    );
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
  }

}