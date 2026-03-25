<?php

declare(strict_types=1);

namespace Drupal\appointment\Service;

use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 *
 */
class UninstallService {
  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $logger;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   *
   */
  public function preUninstall(string $module, bool $is_syncing): void {
    if ($module !== 'appointment') {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('user');

    // Supprimer les advisers par rôle.
    $advisers = $storage->loadByProperties(['roles' => 'adviser']);
    foreach ($advisers as $user) {
      $user->delete();
    }

    // Filet de sécurité par mail (configurable)
    $config = $this->configFactory->get('appointment.settings');
    $adviser_mails = $config->get('adviser_mails') ?? [];
    foreach ($adviser_mails as $mail) {
      $users = $storage->loadByProperties(['mail' => $mail]);
      foreach ($users as $user) {
        $user->delete();
      }
    }

    // Supprimer RDV et agences.
    foreach (['appointment', 'agency'] as $type) {
      if ($this->entityTypeManager->hasDefinition($type)) {
        $entities = $this->entityTypeManager->getStorage($type)->loadMultiple();
        if (!empty($entities)) {
          $this->entityTypeManager->getStorage($type)->delete($entities);
        }
      }
    }
  }

  /**
   *
   */
  public function uninstall(): void {
    $entity_type_manager = $this->entityTypeManager;
    $db                  = $this->database;
    $schema              = $db->schema();

    $field_tables = [
      'field_adviser_agency'          => 'user__field_adviser_agency',
      'field_adviser_specializations' => 'user__field_adviser_specializations',
      'field_adviser_hours'           => 'user__field_adviser_hours',
    ];

    foreach ($field_tables as $field_name => $table_name) {
      if (!$schema->tableExists($table_name)) {
        $db->query("CREATE TABLE `{$table_name}` (entity_id INT UNSIGNED NOT NULL)");
      }

      $field_instance = FieldConfig::loadByName('user', 'user', $field_name);
      if ($field_instance) {
        $field_instance->delete();
      }

      $field_storage = FieldStorageConfig::loadByName('user', $field_name);
      if ($field_storage) {
        $field_storage->delete();
      }

      if ($schema->tableExists($table_name)) {
        $schema->dropTable($table_name);
      }
    }

    // Supprimer les utilisateurs avec le rôle adviser.
    $user_storage = $entity_type_manager->getStorage('user');
    $advisers     = $user_storage->loadByProperties(['roles' => 'adviser']);
    foreach ($advisers as $user) {
      $user->delete();
    }

    // Supprimer le rôle adviser.
    $role = Role::load('adviser');
    if ($role) {
      $role->delete();
    }

    // Supprimer termes de taxonomie.
    $term_storage = $entity_type_manager->getStorage('taxonomy_term');
    $terms        = $term_storage->loadByProperties(['vid' => 'appointment_type']);
    foreach ($terms as $term) {
      $term->delete();
    }

    // Supprimer le vocabulaire.
    $vocab = Vocabulary::load('appointment_type');
    if ($vocab) {
      $vocab->delete();
    }

    // Supprimer la configuration du module.
    $this->configFactory->getEditable('appointment.settings')->delete();

    // Nettoyer les tables de migration.
    $migrations = ['appointment_agencies', 'appointment_advisers'];
    foreach ($migrations as $id) {
      foreach (['migrate_map_', 'migrate_message_'] as $prefix) {
        $table = $prefix . $id;
        if ($schema->tableExists($table)) {
          $schema->dropTable($table);
        }
      }
    }

    // Supprimer les configs de migration orphelines.
    $migration_configs = [
      'migrate_plus.migration.appointment_advisers',
      'migrate_plus.migration.appointment_agencies',
      'migrate_plus.migration_group.appointment',
    ];
    foreach ($migration_configs as $config_name) {
      $config = $this->configFactory->getEditable($config_name);
      if (!$config->isNew()) {
        $config->delete();
      }
    }

    $this->logger->get('appointment')->info('Module appointment désinstallé proprement.');
  }

}
