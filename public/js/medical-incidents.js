(function () {
    'use strict';

    var athleteSelect = document.getElementById('incident-athlete');
    var sportSelect = document.getElementById('incident-sport');

    if (!athleteSelect || !sportSelect) {
        return;
    }

    /**
     * Picks the sport of the athlete that was chosen.
     *
     * An athlete without an assigned sport falls back to the empty option,
     * which means "use the athlete's sport", so an incident is never filed
     * under the sport of the athlete that was picked before.
     */
    function selectAthleteSport() {
        var option = athleteSelect.options[athleteSelect.selectedIndex];
        var sportId = option ? (option.dataset.sportId || '') : '';

        sportSelect.value = sportId;
    }

    athleteSelect.addEventListener('change', selectAthleteSport);

    // A form that was sent back with errors, or an incident that is being
    // edited, keeps the sport that was already chosen.
    if (!sportSelect.value) {
        selectAthleteSport();
    }
}());
