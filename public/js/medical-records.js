(function () {
    'use strict';

    var search = document.getElementById('medical-athlete-search');
    var athleteSelect = document.getElementById('medical-athlete');
    var studentId = document.getElementById('medical-student-id');
    var sport = document.getElementById('medical-assigned-sport');

    if (!search || !athleteSelect) {
        return;
    }

    function updateAthleteDetails() {
        var option = athleteSelect.options[athleteSelect.selectedIndex];
        if (!option || !option.value) {
            if (studentId) { studentId.value = ''; }
            if (sport) { sport.value = ''; }
            return;
        }

        if (studentId) { studentId.value = option.dataset.studentId || 'Not recorded'; }
        if (sport) { sport.value = option.dataset.sport || 'Unassigned'; }
    }

    search.addEventListener('input', function () {
        var query = search.value.trim().toLocaleLowerCase();
        Array.prototype.forEach.call(athleteSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }

            option.hidden = query !== '' && option.text.toLocaleLowerCase().indexOf(query) === -1;
        });
    });

    athleteSelect.addEventListener('change', updateAthleteDetails);
    updateAthleteDetails();
}());
