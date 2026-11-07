/*
 * Painel "Preferências de leitura" do portal público (templates/pub/base.html.twig).
 *
 * O núcleo fica no script inline do <head> (window.CechA11y): ele lê e grava as preferências em localStorage
 * ("cech-a11y" e "theme") e aplica as classes no <html> antes da pintura. Este arquivo só liga o painel:
 * botões que o abrem ([data-a11y-open]), controles, teclado (Esc fecha e devolve o foco), clique fora, ajuste
 * do cabeçalho ao texto ampliado e animações do Chart.js quando o usuário pede menos movimento.
 */
(function () {
    'use strict';

    var api = window.CechA11y;
    var panel = document.getElementById('a11y-panel');
    if (!api || !panel) {
        return;
    }

    var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-a11y-open]'));
    var fontValue = document.getElementById('a11y-font-value');
    var fontBar = panel.querySelector('[data-a11y-font-bar]');
    var fontButtons = Array.prototype.slice.call(panel.querySelectorAll('[data-a11y-font]'));
    var themeRadios = Array.prototype.slice.call(panel.querySelectorAll('input[name="a11y-theme"]'));
    var toggles = Array.prototype.slice.call(panel.querySelectorAll('[data-a11y-toggle]'));
    var status = document.getElementById('a11y-status');
    var lastTrigger = null;

    function isVisible(el) {
        return !!el && el.getClientRects().length > 0;
    }

    function nextStep(current, direction) {
        var steps = api.steps;
        var i;
        if (direction > 0) {
            for (i = 0; i < steps.length; i++) {
                if (steps[i] > current) return steps[i];
            }
            return steps[steps.length - 1];
        }
        for (i = steps.length - 1; i >= 0; i--) {
            if (steps[i] < current) return steps[i];
        }
        return steps[0];
    }

    // Deixa os controles iguais ao estado atual (também quando outra aba ou o sistema muda algo)
    function sync() {
        var state = api.state();
        var label = state.fontScale + '%';
        if (fontValue && fontValue.textContent !== label) {
            fontValue.textContent = label;
        }
        if (fontBar) {
            fontBar.style.width = (state.fontScale - 100) + '%';
        }
        fontButtons.forEach(function (button) {
            var direction = parseInt(button.getAttribute('data-a11y-font'), 10);
            var atLimit = direction < 0 ? state.fontScale <= 100 : state.fontScale >= 200;
            // aria-disabled em vez de disabled: o botão continua focável e o foco não se perde no limite
            button.setAttribute('aria-disabled', atLimit ? 'true' : 'false');
        });
        themeRadios.forEach(function (radio) {
            radio.checked = radio.value === state.theme;
        });
        toggles.forEach(function (toggle) {
            toggle.checked = !!state[toggle.getAttribute('data-a11y-toggle')];
        });
    }

    function announce(message) {
        if (!status) return;
        status.textContent = '';
        window.setTimeout(function () {
            status.textContent = message;
        }, 60);
    }

    function setExpanded(expanded) {
        triggers.forEach(function (trigger) {
            trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    function openPanel(trigger) {
        lastTrigger = trigger || null;
        // O menu do celular é um diálogo modal: fecha antes, e o foco volta depois para o botão do menu
        if (trigger && trigger.closest('#mobile-menu-drawer')) {
            if (typeof window.closeMobileMenu === 'function') {
                window.closeMobileMenu();
            }
            lastTrigger = document.getElementById('mobile-menu-btn');
        }
        sync();
        panel.hidden = false;
        setExpanded(true);
        panel.focus();
    }

    function closePanel(returnFocus) {
        if (panel.hidden) return;
        panel.hidden = true;
        setExpanded(false);
        if (returnFocus) {
            var candidates = [lastTrigger, document.getElementById('a11y-toggle'), document.getElementById('mobile-menu-btn')];
            for (var i = 0; i < candidates.length; i++) {
                if (isVisible(candidates[i])) {
                    candidates[i].focus();
                    break;
                }
            }
        }
    }

    // Enter e Espaço já acionam o <button>; o clique alterna o painel
    triggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            if (panel.hidden) {
                openPanel(trigger);
            } else {
                closePanel(true);
            }
        });
    });

    panel.addEventListener('click', function (event) {
        var target = event.target;
        if (target.closest('[data-a11y-close]')) {
            closePanel(true);
            return;
        }
        var fontButton = target.closest('[data-a11y-font]');
        if (fontButton) {
            if (fontButton.getAttribute('aria-disabled') === 'true') return;
            var direction = parseInt(fontButton.getAttribute('data-a11y-font'), 10);
            api.set('fontScale', nextStep(api.state().fontScale, direction));
            return;
        }
        if (target.closest('[data-a11y-reset]')) {
            api.reset();
            announce('Preferências de leitura restauradas ao padrão.');
        }
    });

    panel.addEventListener('change', function (event) {
        var target = event.target;
        if (target.name === 'a11y-theme') {
            api.setTheme(target.value);
            return;
        }
        var key = target.getAttribute('data-a11y-toggle');
        if (key) {
            // Em "reduzir animações", desmarcar grava false: a escolha passa a valer mesmo que o sistema peça menos movimento
            api.set(key, target.checked);
        }
    });

    document.addEventListener('keydown', function (event) {
        if ((event.key === 'Escape' || event.key === 'Esc') && !panel.hidden) {
            event.preventDefault();
            closePanel(true);
        }
    });

    function isInsideOrTrigger(node) {
        return panel.contains(node) || !!(node && node.closest && node.closest('[data-a11y-open]'));
    }

    // Clique fora ou foco fora fecha o painel (sem puxar o foco de volta)
    document.addEventListener('pointerdown', function (event) {
        if (!panel.hidden && !isInsideOrTrigger(event.target)) {
            closePanel(false);
        }
    });
    document.addEventListener('focusin', function (event) {
        if (!panel.hidden && !isInsideOrTrigger(event.target)) {
            closePanel(false);
        }
    });

    // Chart.js: sem animação quando o usuário (ou o sistema) pede menos movimento
    var chartAnimation = null;
    function applyChartMotion() {
        var Chart = window.Chart;
        if (!Chart || !Chart.defaults) return;
        if (chartAnimation === null) {
            chartAnimation = Chart.defaults.animation;
        }
        Chart.defaults.animation = api.state().reduceMotion ? false : chartAnimation;
    }

    document.addEventListener('cech:a11y-change', function () {
        sync();
        applyChartMotion();
    });

    // O cabeçalho é medido de novo quando a largura muda e quando as fontes terminam de carregar
    var frame = 0;
    window.addEventListener('resize', function () {
        if (frame) return;
        frame = window.requestAnimationFrame(function () {
            frame = 0;
            api.fitHeader();
        });
    });
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(function () { api.fitHeader(); });
    }
    document.addEventListener('DOMContentLoaded', applyChartMotion);
    window.addEventListener('load', function () {
        api.fitHeader();
        applyChartMotion();
    });

    sync();
    applyChartMotion();
})();
