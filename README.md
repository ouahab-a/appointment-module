# Appointment — Module Drupal 11

Module de prise de rendez-vous en ligne permettant aux utilisateurs de réserver des créneaux avec des conseillers dans différentes agences.

---

## Introduction

Ce module Drupal 11 implémente un système complet de gestion de rendez-vous avec :

- Formulaire multi-étapes de réservation avec calendrier interactif FullCalendar
- Gestion des rendez-vous (modification et annulation par numéro de téléphone)
- Interface d'administration avec filtres et export CSV
- Emails automatiques de confirmation, modification et annulation
- Import de données de démonstration via Migrate CSV

---

## Prérequis

- Drupal 11.x
- PHP 8.2+
- MySQL 8.0+
- Modules contrib activés :
  - `migrate`
  - `migrate_plus`
  - `migrate_source_csv`
  - `views`
  - `views_data_export`
  - `symfony_mailer`

---

## Installation

### 1. Copier le module
```bash
cp -r appointment/ web/modules/custom/appointment/
```

### 2. Activer le module
```bash
./vendor/bin/drush en appointment -y
./vendor/bin/drush cr
```

### 3. Importer les données de démonstration
```bash
# Importer les agences (obligatoire en premier)
./vendor/bin/drush migrate:import appointment_agencies -v

# Importer les conseillers
./vendor/bin/drush migrate:import appointment_advisers -v
```

### 4. Vérifier l'installation
```bash
./vendor/bin/drush php-eval '
$types = \Drupal::entityTypeManager()->getDefinitions();
echo "appointment: " . (isset($types["appointment"]) ? "OK ✓" : "KO ✗") . PHP_EOL;
echo "agency: " . (isset($types["agency"]) ? "OK ✓" : "KO ✗") . PHP_EOL;
$terms = \Drupal::entityTypeManager()
  ->getStorage("taxonomy_term")
  ->loadByProperties(["vid" => "appointment_type"]);
echo "Types RDV: " . count($terms) . " termes" . PHP_EOL;
$advisers = \Drupal::entityTypeManager()
  ->getStorage("user")
  ->loadByProperties(["roles" => "adviser"]);
echo "Conseillers: " . count($advisers) . PHP_EOL;
'
```

Résultat attendu :
```
appointment: OK ✓
agency: OK ✓
Types RDV: 8 termes
Conseillers: 5
```

---

## Désinstallation
```bash
./vendor/bin/drush pmu appointment -y
```

> Le module supprime automatiquement toutes les données (RDV, agences, conseillers, termes, champs user) lors de la désinstallation.

---

## Configuration

Accéder au formulaire de paramètres : `/admin/config/appointment/settings`

| Paramètre | Défaut | Description |
|---|---|---|
| Durée d'un créneau | 30 min | 15 / 30 / 45 / 60 minutes |
| Fenêtre de réservation | 14 jours | Nombre de jours affichés dans le calendrier |
| Délai minimum | 2 heures | Un RDV ne peut pas être pris moins de X heures avant |
| Email expéditeur | noreply@example.com | Adresse d'envoi des emails |
| Rappel 24h | Désactivé | Envoi d'un email de rappel la veille |

---

## Flux utilisateur

### Prise de rendez-vous — `/prendre-un-rendez-vous`
```
Étape 1 → Choisir une agence
Étape 2 → Choisir le type de rendez-vous
Étape 3 → Choisir le conseiller
Étape 4 → Choisir la date et l'heure (FullCalendar)
Étape 5 → Renseigner ses informations personnelles
Étape 6 → Confirmation + email automatique
```

### Modification / Annulation — `/modifier-rendez-vous`
```
Étape 1 → Saisir son numéro de téléphone
Étape 2 → Voir la liste de ses RDV actifs
Étape 3 → Modifier (nouveau créneau) ou Supprimer (soft delete)
```

---

## Interface d'administration

| URL | Description | Permission |
|---|---|---|
| `/admin/structure/appointment` | Liste de tous les RDV | `administer appointment` |
| `/admin/structure/appointment/export-csv` | Export CSV streamé | `administer appointment` |
| `/admin/config/appointment/settings` | Paramètres du module | `administer appointment configuration` |
| `/admin/structure/agency` | Gestion des agences | `administer agency` |

---

## Export CSV

Deux méthodes disponibles :

**Controller streamé (recommandé)** — `/admin/structure/appointment/export-csv`
- Traitement par batch de 100 RDV
- Streaming direct sans fichier intermédiaire
- BOM UTF-8 (compatible Excel)
- Pas de timeout possible

**Views Data Export** — `/admin/structure/appointment/export`
- Export standard via Views
- Limité par les performances PHP

---

## Emails

Le module envoie automatiquement des emails via Symfony Mailer pour :

- **Confirmation** — à la création d'un rendez-vous
- **Modification** — quand un créneau est modifié
- **Annulation** — quand un rendez-vous est supprimé

### Test en local avec Mailhog
```bash
# Démarrer Mailhog
docker run -d -p 1025:1025 -p 8025:8025 mailhog/mailhog

# Interface web
open http://localhost:8025
```

Configurer Symfony Mailer sur `/admin/config/system/mailer` :
- Host : `localhost`
- Port : `1025`
- Encryption : None

---

## Données de démonstration

### Agences (4)

| Nom | Ville |
|---|---|
| Casa Hay Mohammadi | Casablanca |
| Casa Sidi Maarouf | Casablanca |
| Casa Bernoussi | Casablanca |
| Agadir Hassan II | Agadir |

### Conseillers (5)

| Nom | Agence | Spécialisations |
|---|---|---|
| Ahmed Benali | Casa Hay Mohammadi | Conseil financier, Services emploi |
| Fatima Zahra Idrissi | Casa Hay Mohammadi | Conseil carrière, Orientation éducative |
| Karim Mansouri | Casa Sidi Maarouf | Consultation juridique, Services immigration |
| Sara Alaoui | Casa Bernoussi | Services de santé, Aide au logement |
| Youssef El Amrani | Agadir Hassan II | Conseil financier, Consultation juridique |

### Types de rendez-vous (8)

Conseil financier, Conseil carrière, Consultation juridique, Orientation éducative, Services de santé, Aide au logement, Services emploi, Services immigration.

---

## Permissions

| Permission | Rôle | Description |
|---|---|---|
| `access appointment booking` | anonymous, authenticated, adviser | Accéder aux formulaires de réservation |
| `view appointment` | adviser | Voir les rendez-vous |
| `administer appointment` | admin | Gérer tous les rendez-vous |
| `administer appointment configuration` | admin | Accéder aux paramètres |
| `administer agency` | admin | Gérer les agences |

---

## Architecture technique
```
src/
├── Controller/
│   ├── AppointmentController.php         # Controller principal
│   └── AppointmentExportController.php   # Export CSV streamé
├── Entity/
│   ├── Appointment.php                   # Entité RDV (custom entity)
│   └── Agency.php                        # Entité Agence (custom entity)
├── Form/
│   ├── AppointmentBookingForm.php        # Formulaire 6 étapes
│   ├── AppointmentModifyForm.php         # Modification/annulation
│   └── AppointmentSettingsForm.php       # Paramètres admin
├── Plugin/views/field/
│   └── AppointmentDateFormatted.php      # Plugin Views pour la date
├── Service/
│   ├── AppointmentManager.php            # Logique métier centrale
│   └── EmailService.php                  # Envoi des emails
└── AppointmentUninstallValidator.php     # Nettoyage à la désinstallation
```

---

## Performances

Testé avec 712 rendez-vous :

| Opération | Temps |
|---|---|
| COUNT query | 34ms |
| Export CSV 712 lignes | < 2s |
| Page admin (dev, cache off) | ~6s |
| Page admin (prod, cache on) | < 500ms |

---

## Limitations connues

- La première tentative de désinstallation peut échouer si du contenu existe. La deuxième tentative réussit toujours.
- Les warnings de schema liés à `better_exposed_filters` sont ignorables — ils viennent du module contrib et non du code custom.
- La configuration Symfony Mailer doit être ajustée pour un serveur SMTP de production.

---

## Auteur

**Achraf OUAHAB** — Stage PFE, VOID Agency, Mars 2026
