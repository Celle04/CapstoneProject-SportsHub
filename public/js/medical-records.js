(function () {
    'use strict';

    var search = document.getElementById('medical-athlete-search');
    var athleteSelect = document.getElementById('medical-athlete');
    var results = document.getElementById('medical-athlete-results');
    var studentId = document.getElementById('medical-student-id');
    var sport = document.getElementById('medical-assigned-sport');
    var activeIndex = -1;

    if (!search || !athleteSelect || !results) {
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

    function closeResults() {
        results.hidden = true;
        search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    }

    function renderResults() {
        var query = search.value.trim().toLocaleLowerCase();
        results.replaceChildren();
        activeIndex = -1;

        if (!query) {
            closeResults();
            return;
        }

        var matches = Array.prototype.filter.call(athleteSelect.options, function (option) {
            return option.value && option.textContent.toLocaleLowerCase().indexOf(query) !== -1;
        });

        if (!matches.length) {
            var empty = document.createElement('div');
            empty.className = 'medical-athlete-empty';
            empty.setAttribute('role', 'option');
            empty.setAttribute('aria-disabled', 'true');
            empty.textContent = 'No athletes match "' + search.value.trim() + '".';
            results.appendChild(empty);
        } else {
            matches.slice(0, 10).forEach(function (option, index) {
                var result = document.createElement('button');
                result.type = 'button';
                result.className = 'medical-athlete-result';
                result.id = 'medical-athlete-result-' + option.value;
                result.setAttribute('role', 'option');
                result.setAttribute('aria-selected', 'false');
                result.dataset.athleteValue = option.value;
                result.textContent = option.textContent.trim();
                result.addEventListener('click', function () {
                    athleteSelect.value = option.value;
                    search.value = option.textContent.trim();
                    athleteSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    closeResults();
                });
                results.appendChild(result);
            });

            if (matches.length > 10) {
                var more = document.createElement('div');
                more.className = 'medical-athlete-more';
                more.textContent = 'Showing the first 10 matches. Refine your search to see others.';
                results.appendChild(more);
            }
        }

        results.hidden = false;
        search.setAttribute('aria-expanded', 'true');
    }

    function setActiveResult(index) {
        var options = results.querySelectorAll('[role="option"]:not([aria-disabled="true"])');
        if (!options.length) {
            return;
        }

        activeIndex = (index + options.length) % options.length;
        Array.prototype.forEach.call(options, function (option, optionIndex) {
            var isActive = optionIndex === activeIndex;
            option.setAttribute('aria-selected', isActive ? 'true' : 'false');
            if (isActive) {
                search.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    search.addEventListener('input', renderResults);
    search.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' && !results.hidden) {
            event.preventDefault();
            setActiveResult(activeIndex + 1);
        } else if (event.key === 'ArrowUp' && !results.hidden) {
            event.preventDefault();
            setActiveResult(activeIndex <= 0 ? 0 : activeIndex - 1);
        } else if (event.key === 'Enter' && !results.hidden && activeIndex >= 0) {
            event.preventDefault();
            results.querySelectorAll('[role="option"]:not([aria-disabled="true"])')[activeIndex].click();
        } else if (event.key === 'Escape' && !results.hidden) {
            closeResults();
        }
    });

    search.addEventListener('blur', function () {
        window.setTimeout(closeResults, 150);
    });

    athleteSelect.addEventListener('change', function () {
        updateAthleteDetails();
        var option = athleteSelect.options[athleteSelect.selectedIndex];
        search.value = option && option.value ? option.textContent.trim() : '';
    });

    var selectedOption = athleteSelect.options[athleteSelect.selectedIndex];
    if (selectedOption && selectedOption.value) {
        search.value = selectedOption.textContent.trim();
    }
    updateAthleteDetails();
}());
