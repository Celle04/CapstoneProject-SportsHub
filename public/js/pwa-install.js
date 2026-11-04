(function () {
    var installButtons = Array.prototype.slice.call(document.querySelectorAll('[data-pwa-install]'));
    var dialog = document.querySelector('[data-pwa-install-dialog]');
    var steps = dialog && dialog.querySelector('[data-pwa-install-steps]');
    var deferredPrompt = null;

    if (!installButtons.length || !dialog || !steps) {
        return;
    }

    function isInstalled() {
        var standalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        try {
            return standalone || window.localStorage.getItem('sportshub-installed') === 'true';
        } catch (error) {
            console.error('SportsHub could not check its installation state:', error);
            return standalone;
        }
    }

    function hideInstallButtons() {
        installButtons.forEach(function (button) {
            button.hidden = true;
        });
    }

    function showInstructions() {
        var isAppleMobile = /iPhone|iPad|iPod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        var isAndroid = /Android/i.test(navigator.userAgent);
        var instructions;

        if (isAppleMobile) {
            instructions = [
                'Open this page in Safari.',
                'Tap the Share button.',
                'Choose “Add to Home Screen,” then tap “Add.”',
            ];
        } else if (isAndroid) {
            instructions = [
                'Open the browser menu (⋮).',
                'Choose “Install app” or “Add to Home screen.”',
                'Confirm to add SportsHub to your device.',
            ];
        } else {
            instructions = [
                'Look for the install icon in the address bar, or open your browser menu.',
                'Choose “Install SportsHub” or “Install this site as an app.”',
                'Confirm the prompt to add SportsHub to your device.',
            ];
        }

        steps.replaceChildren();
        instructions.forEach(function (instruction) {
            var item = document.createElement('li');
            item.textContent = instruction;
            steps.appendChild(item);
        });

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    installButtons.forEach(function (button) {
        button.addEventListener('click', async function () {
            if (!deferredPrompt) {
                showInstructions();
                return;
            }

            var prompt = deferredPrompt;
            deferredPrompt = null;

            try {
                await prompt.prompt();
                var choice = await prompt.userChoice;
                if (choice && choice.outcome === 'accepted') {
                    hideInstallButtons();
                } else {
                    showInstructions();
                }
            } catch (error) {
                console.error('SportsHub could not open the installation prompt:', error);
                showInstructions();
            }
        });
    });

    dialog.querySelector('[data-pwa-install-close]').addEventListener('click', function () {
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    });

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        installButtons.forEach(function (button) {
            button.hidden = false;
        });
        try {
            window.localStorage.removeItem('sportshub-installed');
        } catch (error) {
            console.error('SportsHub could not update its installation state:', error);
        }
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        hideInstallButtons();
        try {
            window.localStorage.setItem('sportshub-installed', 'true');
        } catch (error) {
            console.error('SportsHub could not save its installation state:', error);
        }
        if (dialog.open) {
            dialog.close();
        }
    });

    if (isInstalled()) {
        hideInstallButtons();
    }
})();
