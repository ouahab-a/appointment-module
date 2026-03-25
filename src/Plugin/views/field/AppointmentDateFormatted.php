<?php

namespace Drupal\appointment\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Affiche la date du RDV formatée.
 *
 * @ViewsField("appointment_date_formatted")
 */
class AppointmentDateFormatted extends FieldPluginBase
{

    /**
     * {@inheritdoc}
     */
    public function query(): void {
  $this->ensureMyTable();
  $this->field_alias = $this->query->addField(
    $this->tableAlias,
    'appointment_date',
    'appointment_appointment_date'
  );
}

    /**
     * {@inheritdoc}
     */
    public function render(ResultRow $values): string {
  // La propriété réelle dans ResultRow
  $raw = $values->appointment_appointment_date ?? NULL;

  if (!$raw) {
    return '';
  }

  try {
    $date = new \DateTime($raw);
    return $date->format('d/m/Y - H:i');
  }
  catch (\Exception $e) {
    return $raw;
  }
}
}
