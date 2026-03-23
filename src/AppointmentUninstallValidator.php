<?php

namespace Drupal\appointment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Supprime automatiquement le contenu appointment avant désinstallation.
 */
class AppointmentUninstallValidator implements ModuleUninstallValidatorInterface
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
     *
     * Retourne un tableau vide = pas d'erreur = désinstallation autorisée.
     * Supprime le contenu avant que Drupal core vérifie sa présence.
     */
    public function validate($module): array
    {
        if ($module !== 'appointment') {
            return [];
        }

        // Supprimer les RDV
        if ($this->entityTypeManager->hasDefinition('appointment')) {
            $appointments = $this->entityTypeManager
                ->getStorage('appointment')->loadMultiple();
            if (!empty($appointments)) {
                $this->entityTypeManager
                    ->getStorage('appointment')->delete($appointments);
            }
        }

        // Supprimer les agences
        if ($this->entityTypeManager->hasDefinition('agency')) {
            $agencies = $this->entityTypeManager
                ->getStorage('agency')->loadMultiple();
            if (!empty($agencies)) {
                $this->entityTypeManager
                    ->getStorage('agency')->delete($agencies);
            }
        }

        // Supprimer users adviser — par rôle ET par mail de secours
        $adviser_mails = [
            'ahmed.benali@agency.ma',
            'fatima.idrissi@agency.ma',
            'karim.mansouri@agency.ma',
            'sara.alaoui@agency.ma',
            'youssef.elamrani@agency.ma',
        ];

        // D'abord par rôle (si le rôle existe encore)
        $advisers = $this->entityTypeManager
            ->getStorage('user')
            ->loadByProperties(['roles' => 'adviser']);
        foreach ($advisers as $user) {
            $user->delete();
        }

        // Ensuite par mail (filet de sécurité)
        foreach ($adviser_mails as $mail) {
            $users = $this->entityTypeManager
                ->getStorage('user')
                ->loadByProperties(['mail' => $mail]);
            foreach ($users as $user) {
                $user->delete();
            }
        }

        // Retourner [] = aucune erreur = désinstallation autorisée
        return [];
    }
}
