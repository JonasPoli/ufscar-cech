/*
 * Fórmulas em títulos: melhoria progressiva, sem custo quando a página não tem fórmula.
 *
 * Depois do DOMContentLoaded, procura no <main> os delimitadores \( … \) ou $$ … $$ e só então baixa o
 * KaTeX 0.16.11 (CSS, JS e auto-render, versões fixas no jsDelivr, com SRI). O cifrão simples nunca é
 * delimitador, porque aparece em valores como "R$ 4.996,96".
 *
 * Os dois delimitadores ficam em linha (display: false): em títulos, inclusive nos metadados de editoras,
 * $$ … $$ marca fórmulas no meio da frase. Para conteúdo carregado depois (abas por fetch), chame
 * window.CechMath.render(elemento).
 */
(function () {
    'use strict';

    var BASE = 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/';
    var CSS = { url: BASE + 'katex.min.css', integrity: 'sha384-nB0miv6/jRmo5UMMR1wu3Gz6NLsoTkbqJghGIsx//Rlm+ZU03BU6SQNC66uf4l5+' };
    var SCRIPTS = [
        { url: BASE + 'katex.min.js', integrity: 'sha384-7zkQWkzuo3B5mTepMUcHkMB5jZaolc2xDwL6VFqjFALcbeS9Ggm/Yr2r3Dy4lfFg' },
        { url: BASE + 'contrib/auto-render.min.js', integrity: 'sha384-43gviWU0YVjaDtb/GhzOouOXtZMP/7XUzwPTstBeZFe/+rCMvRwr4yROQP43s0Xk' }
    ];
    var DELIMITERS = [
        { left: '$$', right: '$$', display: false },
        { left: '\\(', right: '\\)', display: false }
    ];
    // As mesmas etiquetas que o auto-render ignora: código, scripts e campos de formulário não são lidos
    var IGNORED_TAGS = ['script', 'noscript', 'style', 'textarea', 'pre', 'code', 'option'];
    var IGNORED = {};
    IGNORED_TAGS.forEach(function (tag) { IGNORED[tag.toUpperCase()] = true; });

    var HINT = /\\\(|\$\$/;
    var FORMULA = /\\\([\s\S]*?\\\)|\$\$[\s\S]+?\$\$/;

    // Procura a fórmula nos nós de texto (pulando scripts e código), parando no primeiro achado
    function hasMath(root) {
        if (!root || !HINT.test(root.textContent || '')) {
            return false;
        }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (node.nodeType === 1) {
                    return IGNORED[node.nodeName] ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_SKIP;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        while (walker.nextNode()) {
            if (FORMULA.test(walker.currentNode.nodeValue)) {
                return true;
            }
        }
        return false;
    }

    function loadScript(file) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = file.url;
            script.integrity = file.integrity;
            script.crossOrigin = 'anonymous';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    var loading = null;
    function loadKatex() {
        if (loading) {
            return loading;
        }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = CSS.url;
        link.integrity = CSS.integrity;
        link.crossOrigin = 'anonymous';
        document.head.appendChild(link);
        // O auto-render depende do KaTeX: carregados em sequência
        loading = loadScript(SCRIPTS[0]).then(function () {
            return loadScript(SCRIPTS[1]);
        });
        return loading;
    }

    function render(element) {
        var root = element || document.querySelector('main');
        if (!hasMath(root)) {
            return Promise.resolve(false);
        }
        return loadKatex().then(function () {
            window.renderMathInElement(root, {
                delimiters: DELIMITERS,
                ignoredTags: IGNORED_TAGS,
                throwOnError: false
            });
            return true;
        }).catch(function () {
            // Sem rede ou CDN bloqueado: o título continua legível como texto
            loading = null;
            return false;
        });
    }

    window.CechMath = { render: render };

    var started = false;
    function start() {
        if (started) return;
        started = true;
        render();
    }
    if (document.readyState === 'complete') {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
        window.addEventListener('load', start);
    }
})();
