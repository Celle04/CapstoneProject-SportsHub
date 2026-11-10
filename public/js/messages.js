(function () {
    'use strict';

    var recipientType = document.getElementById('recipient-type');
    var messageCategory = document.getElementById('message-category');
    var athleteSearch = document.getElementById('message-athlete-search');
    var athleteSelect = document.getElementById('message-athlete');
    var recipientSearch = document.getElementById('message-recipient-search');

    function panelMatches(panel, value) {
        return panel.dataset.recipientPanel.split(/\s+/).indexOf(value) !== -1;
    }

    function updatePanels() {
        if (recipientType) {
            document.querySelectorAll('[data-recipient-panel]').forEach(function (panel) {
                panel.hidden = !panelMatches(panel, recipientType.value);
            });
        }

        // The medical fields only belong to a medical follow-up.
        var medicalFields = document.querySelectorAll('[data-medical-fields]');
        if (medicalFields.length && messageCategory) {
            medicalFields.forEach(function (group) {
                group.hidden = messageCategory.value !== 'medical_follow_up';
            });
        }
    }

    if (recipientType) {
        recipientType.addEventListener('change', updatePanels);
    }
    if (messageCategory) {
        messageCategory.addEventListener('change', updatePanels);
    }
    if (athleteSearch && athleteSelect) {
        athleteSearch.addEventListener('input', function () {
            var query = athleteSearch.value.trim().toLocaleLowerCase();
            Array.prototype.forEach.call(athleteSelect.options, function (option) {
                option.hidden = Boolean(option.value) && query !== '' && option.textContent.toLocaleLowerCase().indexOf(query) === -1;
            });
        });
    }
    if (recipientSearch) {
        recipientSearch.addEventListener('input', function () {
            var query = recipientSearch.value.trim().toLocaleLowerCase();
            document.querySelectorAll('[data-recipient-option]').forEach(function (option) {
                option.hidden = query !== '' && option.textContent.toLocaleLowerCase().indexOf(query) === -1;
            });
        });
    }

    updatePanels();
}());
