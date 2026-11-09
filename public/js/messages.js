(function () {
    'use strict';

    var recipientType = document.getElementById('recipient-type');
    var messageType = document.getElementById('message-type');
    var athleteSearch = document.getElementById('message-athlete-search');
    var athleteSelect = document.getElementById('message-athlete');
    var recipientSearch = document.getElementById('message-recipient-search');

    function updatePanels() {
        if (recipientType) {
            document.querySelectorAll('[data-recipient-panel]').forEach(function (panel) {
                panel.hidden = panel.dataset.recipientPanel !== recipientType.value;
            });
        }

        var medicalFields = document.querySelector('[data-medical-fields]');
        if (medicalFields && messageType) {
            medicalFields.hidden = messageType.value !== 'medical_reminder';
        }
    }

    if (recipientType) {
        recipientType.addEventListener('change', updatePanels);
    }
    if (messageType) {
        messageType.addEventListener('change', updatePanels);
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
