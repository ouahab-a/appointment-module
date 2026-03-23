<?php

declare(strict_types=1);

namespace Drupal\appointment\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\appointment\AppointmentAccessControlHandler;
use Drupal\appointment\AppointmentInterface;
use Drupal\appointment\AppointmentListBuilder;
use Drupal\appointment\Form\AppointmentForm;
use Drupal\views\EntityViewsData;

/**
 * Defines the appointment entity class.
 */
#[ContentEntityType(
  id: 'appointment',
  label: new TranslatableMarkup('Appointment'),
  label_collection: new TranslatableMarkup('Appointments'),
  label_singular: new TranslatableMarkup('appointment'),
  label_plural: new TranslatableMarkup('appointments'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'published' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => AppointmentListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => AppointmentAccessControlHandler::class,
    'form' => [
      'add' => AppointmentForm::class,
      'edit' => AppointmentForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/appointment',
    'add-form' => '/appointment/add',
    'canonical' => '/appointment/{appointment}',
    'edit-form' => '/appointment/{appointment}/edit',
    'delete-form' => '/appointment/{appointment}/delete',
    'delete-multiple-form' => '/admin/content/appointment/delete-multiple',
  ],
  admin_permission: 'administer appointment',
  base_table: 'appointment',
  label_count: [
    'singular' => '@count appointments',
    'plural' => '@count appointments',
  ],
  field_ui_base_route: 'entity.appointment.settings',
)]
class Appointment extends ContentEntityBase implements AppointmentInterface
{

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array
  {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Status'))
      ->setDefaultValue(TRUE)
      ->setSetting('on_label', 'Enabled')
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'settings' => [
          'display_label' => FALSE,
        ],
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'label' => 'above',
        'weight' => 0,
        'settings' => [
          'format' => 'enabled-disabled',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'type' => 'text_default',
        'label' => 'above',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Authored on'))
      ->setDescription(t('The time that the appointment was created.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the appointment was last edited.'));

    // Date et heure du rendez-vous
    $fields['appointment_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Date et heure'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'datetime')
      ->setDisplayOptions('view', [      
        'type'   => 'datetime_default',
        'weight' => 10,
        'label'  => 'above',
        'settings' => ['format_type' => 'medium'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Référence vers l'agence
    $fields['agency'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Agence'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'agency')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 11,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Référence vers le conseiller (User)
    $fields['adviser'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Conseiller'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 12,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Type de rendez-vous (taxonomie)
    $fields['appointment_type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Type de rendez-vous'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['appointment_type' => 'appointment_type']])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 13])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Nom du client
    $fields['customer_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Nom complet'))
      ->setRequired(TRUE)
      ->setSettings(['max_length' => 255])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 14])
      ->setDisplayConfigurable('form', TRUE);

    // Email du client
    $fields['customer_email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Adresse e-mail'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'email_default', 'weight' => 15])
      ->setDisplayConfigurable('form', TRUE);

    // Téléphone du client
    $fields['customer_phone'] = BaseFieldDefinition::create('telephone')
      ->setLabel(t('Numéro de téléphone'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'telephone_default', 'weight' => 16])
      ->setDisplayConfigurable('form', TRUE);

    // Statut du RDV
    $fields['appointment_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Statut'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'pending'   => t('En attente'),
          'confirmed' => t('Confirmé'),
          'cancelled' => t('Annulé'),
        ],
      ])
      ->setDefaultValue('pending')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 17])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Notes
    $fields['notes'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Notes'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 18])
      ->setDisplayConfigurable('form', TRUE);

    // Référence unique (ex: RDV-2024-XXXX)
    $fields['reference'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Référence'))
      ->setSettings(['max_length' => 64])
      ->setReadOnly(TRUE);

    return $fields;
  }
}
