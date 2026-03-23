/**
 * @file
 * Gestion du calendrier FullCalendar pour la sélection de créneaux.
 */
(function (Drupal, drupalSettings, once) {

    'use strict';

    Drupal.behaviors.appointmentCalendar = {
        attach: function (context, settings) {

            // once() évite que le behavior se réattache plusieurs fois
            once('appointment-calendar', '#appointment-calendar', context).forEach(function (el) {

                const slots = settings.appointment?.slots ?? [];
                const adviserId = settings.appointment?.adviser_id ?? null;

                // Convertir les slots disponibles en événements FullCalendar
                const availableEvents = slots.map(function (slot) {
                    const isAvailable = slot.available !== false;
                    return {
                        start: slot.start,
                        end: slot.end,
                        title: isAvailable ? Drupal.t('Disponible') : Drupal.t('Indisponible'),
                        color: isAvailable ? '#2e7d32' : '#c62828',
                        extendedProps: {
                            available: isAvailable,
                        },
                    };
                });

                const calendar = new FullCalendar.Calendar(el, {
                    initialView: 'timeGridWeek',
                    locale: 'fr',
                    slotMinTime: '08:00:00',
                    slotMaxTime: '18:00:00',
                    slotDuration: '00:30:00',
                    allDaySlot: false,
                    nowIndicator: true,
                    headerToolbar: {
                        left: 'prev,next',
                        center: 'title',
                        right: 'timeGridWeek,timeGridDay',
                    },
                    events: availableEvents,

                    eventClick: function (info) {
                        // Créneau réservé → message d'erreur, pas de sélection
                        if (!info.event.extendedProps.available) {
                            Drupal.appointmentShowSlotError(
                                Drupal.t('Ce créneau est déjà réservé. Veuillez en choisir un autre.')
                            );
                            return;
                        }

                        // Réinitialiser tous les créneaux disponibles
                        calendar.getEvents().forEach(function (e) {
                            if (e.extendedProps.available) {
                                e.setProp('color', '#2e7d32');
                                e.setExtendedProp('selected', false);
                            }
                        });

                        // Sélectionner en bleu
                        info.event.setProp('color', '#1565c0');
                        info.event.setExtendedProp('selected', true);

                        // Effacer le message d'erreur si présent
                        Drupal.appointmentClearSlotError();

                        // Remplir le champ caché
                        const hiddenField = document.getElementById('appointment-selected-date');
                        if (hiddenField) {
                            // Construire manuellement le format Y-m-d\TH:i:s sans timezone
                            const d = info.event.start;
                            const pad = n => String(n).padStart(2, '0');
                            const isoLocal = d.getFullYear() + '-' +
                                pad(d.getMonth() + 1) + '-' +
                                pad(d.getDate()) + 'T' +
                                pad(d.getHours()) + ':' +
                                pad(d.getMinutes()) + ':' +
                                pad(d.getSeconds());

                            hiddenField.value = isoLocal;
                            hiddenField.dispatchEvent(new Event('change'));
                        }

                        Drupal.appointmentShowSelectedSlot(info.event.start, info.event.end);
                    },

                    selectable: false,
                });

                calendar.render();

                // Si une date était déjà sélectionnée (retour à l'étape 4)
                const existing = document.getElementById('appointment-selected-date')?.value;
                if (existing) {
                    Drupal.appointmentHighlightSlot(calendar, existing);
                }
            });
        }
    };

    /**
     * Gestion des boutons de modification et de suppression.
     */
    Drupal.behaviors.appointmentModify = {
        attach: function (context, settings) {
            once('appointment-modify', '.btn-modify, .btn-delete', context).forEach(function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const id = this.dataset.id;
                    const action = this.classList.contains('btn-modify') ? 'modify' : 'delete';
                    const btn = document.getElementById('btn-' + action + '-' + id);
                    if (btn) btn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                    if (btn) btn.click();
                });
            });
        }
    };

    /**
     * Affiche un résumé du créneau sélectionné sous le calendrier.
     *
     * @param {Date} start
     * @param {Date} end
     */
    Drupal.appointmentShowSelectedSlot = function (start, end) {
        const fmt = new Intl.DateTimeFormat('fr-FR', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });

        const fmtTime = new Intl.DateTimeFormat('fr-FR', {
            hour: '2-digit',
            minute: '2-digit',
        });

        const label = fmt.format(start) + ' de ' +
            fmtTime.format(start) + ' à ' +
            fmtTime.format(end);

        // Créer ou mettre à jour le bloc de confirmation sous le calendrier
        let info = document.getElementById('appointment-slot-info');
        if (!info) {
            info = document.createElement('div');
            info.id = 'appointment-slot-info';
            info.className = 'appointment-slot-selected';
            document.getElementById('appointment-calendar')
                .insertAdjacentElement('afterend', info);
        }
        info.innerHTML = '<strong>' + Drupal.t('Créneau sélectionné :') +
            '</strong> ' + label;
    };

    /**
     * Met en surbrillance un créneau déjà sélectionné (retour arrière).
     *
     * @param {FullCalendar.Calendar} calendar
     * @param {string} dateStr  Format ISO Y-m-d\TH:i:s
     */
    Drupal.appointmentHighlightSlot = function (calendar, dateStr) {
        calendar.getEvents().forEach(function (e) {
            if (e.startStr === dateStr) {
                e.setProp('color', '#1565c0');
                e.setExtendedProp('selected', true);
                Drupal.appointmentShowSelectedSlot(e.start, e.end);
            }
        });
    };

    /**
 * Affiche un message d'erreur sous le calendrier.
 */
    Drupal.appointmentShowSlotError = function (message) {
        let el = document.getElementById('appointment-slot-error');
        if (!el) {
            el = document.createElement('div');
            el.id = 'appointment-slot-error';
            el.className = 'appointment-slot-error';
            document.getElementById('appointment-calendar')
                .insertAdjacentElement('afterend', el);
        }
        el.innerHTML = '<strong>' + message + '</strong>';
    };

    /**
     * Supprime le message d'erreur.
     */
    Drupal.appointmentClearSlotError = function () {
        const el = document.getElementById('appointment-slot-error');
        if (el) el.remove();
    };

}(Drupal, drupalSettings, once));