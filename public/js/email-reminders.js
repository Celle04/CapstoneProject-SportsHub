(function () {
    'use strict';

    var form = document.getElementById('email-reminder-form');
    var recipientType = document.getElementById('recipient-type');
    var recipientSearch = document.getElementById('reminder-recipient-search');
    var previewButton = document.getElementById('email-reminder-preview');
    var sportLabel = document.getElementById('reminder-sport-label');
    var sportHint = document.getElementById('reminder-sport-hint');

    function panelMatches(panel, value) {
        return panel.dataset.recipientPanel.split(/\s+/).indexOf(value) !== -1;
    }

    function updatePanels() {
        if (!recipientType) {
            return;
        }

        var value = recipientType.value;
        document.querySelectorAll('[data-recipient-panel]').forEach(function (panel) {
            var active = panelMatches(panel, value);
            panel.hidden = !active;
            panel.querySelectorAll('input, select, textarea, button').forEach(function (field) {
                if (field.type === 'button' || field.type === 'submit') {
                    return;
                }
                field.disabled = !active && panel.dataset.recipientPanel.indexOf('selected') === -1 ? !active : !active;
            });
        });

        if (sportLabel && sportHint) {
            if (value === 'team') {
                sportLabel.textContent = 'Team';
                sportHint.textContent = 'Teams are selected from existing sports programs. Athletes assigned to that team/sport are included.';
            } else {
                sportLabel.textContent = 'Sport';
                sportHint.textContent = 'Select an existing sports program. Athletes assigned to that sport or approved for it are included.';
            }
        }
    }

    if (recipientType) {
        recipientType.addEventListener('change', updatePanels);
    }

    if (recipientSearch) {
        recipientSearch.addEventListener('input', function () {
            var query = recipientSearch.value.trim().toLocaleLowerCase();
            document.querySelectorAll('[data-recipient-option]').forEach(function (option) {
                option.hidden = query !== '' && option.textContent.toLocaleLowerCase().indexOf(query) === -1;
            });
        });
    }

    if (previewButton && form) {
        previewButton.addEventListener('click', async function () {
            var previewUrl = form.dataset.previewUrl;
            if (!previewUrl) {
                return;
            }

            previewButton.disabled = true;
            var original = previewButton.textContent;
            previewButton.textContent = 'Preparing Preview...';

            try {
                var data = new FormData(form);
                var token = form.querySelector('input[name="_token"]');
                var response = await fetch(previewUrl, {
                    method: 'POST',
                    headers: token ? { 'X-CSRF-TOKEN': token.value, 'Accept': 'text/html' } : { 'Accept': 'text/html' },
                    body: data,
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Preview could not be generated.');
                }

                var html = await response.text();
                var previewWindow = window.open('', '_blank');
                if (previewWindow) {
                    previewWindow.document.open();
                    previewWindow.document.write(html);
                    previewWindow.document.close();
                }
            } catch (error) {
                window.alert('Unable to generate the email preview. Please check the required fields and try again.');
            } finally {
                previewButton.disabled = false;
                previewButton.textContent = original;
            }
        });
    }

    updatePanels();
}());
