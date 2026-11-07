/*
 * Rede de coautoria do Portal CECH.
 *
 * Marcação esperada (ver templates/pub/research/_graph.html.twig):
 *   <section data-cech-graph data-src="/api/v1/grafo?..." data-pair-src="/api/v1/grafo/par?...">
 *     [data-graph-canvas] [data-graph-focus] [data-graph-zoom-in] [data-graph-zoom-out] [data-graph-reset]
 *     [data-graph-fullscreen] [data-graph-names] [data-graph-legend] [data-graph-status] [data-graph-info]
 *     <dialog data-graph-dialog> … </dialog>
 *   </section>
 *
 * O ECharts (5.5.1) só é baixado quando há um grafo na página. Cada departamento tem cor e forma próprias, para a
 * legenda não depender só de cor.
 */
(function () {
    'use strict';

    var ECHARTS_URL = 'https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js';
    var COLORS = ['#0369a1', '#b45309', '#047857', '#7c3aed', '#be123c', '#0f766e', '#a16207', '#4338ca', '#c2410c', '#15803d', '#9d174d', '#475569'];
    var SHAPES = ['circle', 'rect', 'triangle', 'diamond', 'roundRect', 'pin'];
    var SHAPE_NAMES = { circle: 'círculo', rect: 'quadrado', triangle: 'triângulo', diamond: 'losango', roundRect: 'quadrado arredondado', pin: 'gota' };
    var loading = null;

    function loadECharts() {
        if (window.echarts) return Promise.resolve(window.echarts);
        if (loading) return loading;
        loading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = ECHARTS_URL;
            s.async = true;
            s.onload = function () { resolve(window.echarts); };
            s.onerror = function () { loading = null; reject(new Error('Não foi possível carregar a biblioteca de gráficos.')); };
            document.head.appendChild(s);
        });
        return loading;
    }

    function isDark() { return document.documentElement.classList.contains('dark'); }
    function reducedMotion() {
        return document.documentElement.classList.contains('a11y-reduce-motion') ||
            (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    function esc(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function fmt(n) { return Number(n).toLocaleString('pt-BR'); }

    function styleFor(departments) {
        var map = {};
        Object.keys(departments).forEach(function (code, i) {
            map[code] = { color: COLORS[i % COLORS.length], shape: SHAPES[i % SHAPES.length], index: i };
        });
        return map;
    }

    function workHtml(w) {
        var authors = (w.authors || []).map(function (a) {
            return a.slug ? '<a class="underline decoration-sky-500/50 underline-offset-2 hover:text-sky-700" href="/professor/' + encodeURIComponent(a.slug) + '">' + esc(a.name) + '</a>' : esc(a.name);
        }).join('; ') + (w.moreAuthors > 0 ? '; et al.' : '');
        var meta = [esc(w.typeLabel)];
        if (w.venue) meta.push('<span class="italic">' + esc(w.venue) + '</span>');
        if (w.issn) meta.push('ISSN ' + esc(w.issn));
        if (w.doi) meta.push('<a class="font-semibold text-sky-700 dark:text-sky-400 hover:underline" target="_blank" rel="noopener noreferrer" href="https://doi.org/' + esc(w.doi) + '">DOI<span class="sr-only"> (abre em nova aba)</span></a>');
        return '<li class="py-3 flex gap-3"><span class="w-12 shrink-0 font-mono text-xs font-semibold text-slate-500">' + esc(w.year || '—') + '</span>' +
            '<div class="min-w-0 space-y-1"><p class="text-sm font-semibold text-slate-900 dark:text-white">' + esc(w.title) + '</p>' +
            '<p class="text-xs text-slate-500 dark:text-slate-400">' + meta.join(' · ') + '</p>' +
            (authors ? '<p class="text-xs text-slate-600 dark:text-slate-300">' + authors + '</p>' : '') + '</div></li>';
    }

    function avatarHtml(n) {
        var ring = '<span class="inline-flex shrink-0 rounded-full bg-gradient-to-br from-cech-blue via-cech-teal to-cech-red p-[2px] shadow-sm">';
        if (n && n.photoUrl) {
            var webp = String(n.photoUrl).replace(/\.(jpe?g|png)$/i, '-256.webp');
            return ring + '<img src="' + esc(webp) + '" alt="" width="44" height="44" loading="lazy" decoding="async" class="h-11 w-11 rounded-full bg-slate-100 object-cover ring-2 ring-white dark:ring-slate-900" onerror="this.onerror=null;this.src=\'' + esc(n.photoUrl) + '\'"></span>';
        }
        return ring + '<span class="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-cech-blue-light to-cech-teal-light font-heading text-sm font-bold text-cech-blue-dark ring-2 ring-white dark:from-slate-800 dark:to-slate-700 dark:text-cech-teal dark:ring-slate-900" aria-hidden="true">' + esc(n ? n.initials : '?') + '</span></span>';
    }

    function pairCardHtml(a, b, weight, rank) {
        return '<li><button type="button" data-graph-pair data-a="' + esc(a.id) + '" data-b="' + esc(b.id) + '" class="group flex w-full items-center gap-4 rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-cech-teal/60 hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-cech-teal dark:border-white/10 dark:bg-slate-900/80">' +
            '<span class="hidden w-6 shrink-0 text-center font-heading text-sm font-extrabold text-slate-300 sm:block dark:text-slate-600">' + rank + '</span>' +
            '<span class="flex shrink-0 -space-x-3">' + avatarHtml(a) + avatarHtml(b) + '</span>' +
            '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-900 dark:text-white">' + esc(a.name) + '</span>' +
            '<span class="flex items-center gap-1.5 truncate text-xs text-slate-500 dark:text-slate-400"><sl-icon name="arrow-left-right" class="shrink-0 text-cech-teal" aria-hidden="true"></sl-icon><span class="truncate">' + esc(b.name) + '</span></span></span>' +
            '<span class="shrink-0 text-right"><span class="block bg-gradient-to-br from-cech-blue to-cech-teal bg-clip-text font-heading text-2xl font-extrabold leading-none text-transparent">' + weight + '</span>' +
            '<span class="block text-[11px] text-slate-500 dark:text-slate-400">' + (weight === 1 ? 'publicação' : 'publicações') + '</span></span></button></li>';
    }

    function Graph(root) {
        this.root = root;
        this.canvas = root.querySelector('[data-graph-canvas]');
        this.status = root.querySelector('[data-graph-status]');
        this.legend = root.querySelector('[data-graph-legend]');
        this.focusSelect = root.querySelector('[data-graph-focus]');
        this.info = root.querySelector('[data-graph-info]');
        this.dialog = root.querySelector('[data-graph-dialog]');
        this.pairsBox = root.querySelector('[data-graph-pairs]');
        this.pairsTitleHtml = this.pairsBox ? this.pairsBox.querySelector('[data-graph-pairs-title]').innerHTML : '';
        this.showNames = root.dataset.names !== 'off';
        this.selected = null;
        this.data = null;
        this.chart = null;
        this.bind();
        this.load();
    }

    Graph.prototype.setStatus = function (html) { if (this.status) this.status.innerHTML = html; };

    Graph.prototype.bind = function () {
        var self = this;
        function on(sel, fn) { var el = self.root.querySelector(sel); if (el) el.addEventListener('click', fn); }
        on('[data-graph-zoom-in]', function () { self.zoom(1.25); });
        on('[data-graph-zoom-out]', function () { self.zoom(0.8); });
        on('[data-graph-reset]', function () { self.clearSelection(); self.render(); });
        on('[data-graph-names]', function (e) {
            self.showNames = !self.showNames;
            e.currentTarget.setAttribute('aria-pressed', self.showNames ? 'true' : 'false');
            self.render();
        });
        on('[data-graph-fullscreen]', function () {
            var el = self.root.querySelector('[data-graph-frame]') || self.root;
            if (document.fullscreenElement) { document.exitFullscreen(); }
            else if (el.requestFullscreen) { el.requestFullscreen(); }
        });
        document.addEventListener('fullscreenchange', function () { if (self.chart) setTimeout(function () { self.chart.resize(); }, 50); });
        if (this.focusSelect) {
            this.focusSelect.addEventListener('change', function () {
                var id = self.focusSelect.value;
                if (id) { self.select(id); } else { self.clearSelection(); }
            });
        }
        // Um só ouvinte para os cartões de par, inclusive os que a seleção de um docente desenha depois
        this.root.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-graph-pair]') : null;
            if (btn && self.root.contains(btn)) self.openPair(btn.dataset.a, btn.dataset.b);
        });
        on('[data-graph-pairs-reset]', function () { self.clearSelection(); });
        if (this.dialog) {
            var close = this.dialog.querySelector('[data-graph-dialog-close]');
            if (close) close.addEventListener('click', function () { self.dialog.close(); });
            this.dialog.addEventListener('click', function (e) { if (e.target === self.dialog) self.dialog.close(); });
        }
        window.addEventListener('resize', function () { if (self.chart) self.chart.resize(); });
        // Redesenha só quando o tema muda (outras classes do <html>, como as de acessibilidade, não mexem nas cores)
        this.dark = isDark();
        new MutationObserver(function () {
            if (self.chart && isDark() !== self.dark) { self.dark = isDark(); self.render(); }
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    };

    Graph.prototype.load = function () {
        var self = this;
        this.setStatus('Carregando a rede…');
        Promise.all([loadECharts(), fetch(this.root.dataset.src, { headers: { Accept: 'application/json' } }).then(function (r) {
            if (!r.ok) throw new Error('Não foi possível consultar a rede.');
            return r.json();
        })]).then(function (res) {
            self.data = res[1];
            self.styles = styleFor(self.data.departments || {});
            self.fillFocus();
            self.fillLegend();
            if (!self.data.nodes.length) {
                self.canvas.innerHTML = '<p class="h-full flex items-center justify-center text-sm text-slate-500 dark:text-slate-400 p-6 text-center">Nenhuma coautoria entre docentes do CECH para estes filtros.</p>';
                self.updateStatus();
                return;
            }
            self.chart = window.echarts.init(self.canvas, null, { renderer: 'canvas' });
            self.chart.on('click', function (p) {
                if (p.dataType === 'node') { self.select(p.data.id); }
                else if (p.dataType === 'edge') { self.openPair(p.data.source, p.data.target); }
            });
            self.chart.on('dblclick', function (p) {
                if (p.dataType === 'node' && p.data.slug) window.location.href = '/professor/' + encodeURIComponent(p.data.slug);
            });
            self.render();
            if (self.data.focus) self.select(String(self.data.focus.id), true);
        }).catch(function (err) {
            self.setStatus('<span class="text-rose-700 dark:text-rose-400">' + esc(err.message) + '</span>');
        });
    };

    Graph.prototype.fillFocus = function () {
        if (!this.focusSelect) return;
        var opts = ['<option value="">Toda a rede visível</option>'];
        this.data.nodes.slice().sort(function (a, b) { return a.name.localeCompare(b.name, 'pt-BR'); }).forEach(function (n) {
            opts.push('<option value="' + esc(n.id) + '">' + esc(n.name) + '</option>');
        });
        this.focusSelect.innerHTML = opts.join('');
    };

    Graph.prototype.fillLegend = function () {
        if (!this.legend) return;
        var self = this;
        var items = Object.keys(this.data.departments || {}).map(function (code) {
            var st = self.styles[code];
            return '<li class="inline-flex items-center gap-1.5" title="' + esc(self.data.departments[code]) + '">' +
                '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">' + shapeSvg(st.shape, st.color) + '</svg>' +
                '<span>' + esc(code) + '</span><span class="sr-only"> (' + esc(SHAPE_NAMES[st.shape]) + ')</span></li>';
        });
        this.legend.innerHTML = items.join('');
    };

    function shapeSvg(shape, color) {
        switch (shape) {
            case 'rect': return '<rect x="1" y="1" width="10" height="10" fill="' + color + '"/>';
            case 'roundRect': return '<rect x="1" y="1" width="10" height="10" rx="3" fill="' + color + '"/>';
            case 'triangle': return '<polygon points="6,1 11,11 1,11" fill="' + color + '"/>';
            case 'diamond': return '<polygon points="6,0 12,6 6,12 0,6" fill="' + color + '"/>';
            case 'pin': return '<path d="M6 11 C2 7 2 1 6 1 C10 1 10 7 6 11Z" fill="' + color + '"/>';
            default: return '<circle cx="6" cy="6" r="5" fill="' + color + '"/>';
        }
    }

    Graph.prototype.render = function () {
        if (!this.chart || !this.data) return;
        var self = this;
        var dark = isDark();
        var text = dark ? '#e2e8f0' : '#0f172a';
        var maxW = 1;
        this.data.edges.forEach(function (e) { if (e.weight > maxW) maxW = e.weight; });
        var maxS = 1;
        this.data.nodes.forEach(function (n) { if (n.strength > maxS) maxS = n.strength; });
        var codes = Object.keys(this.data.departments || {});

        var nodes = this.data.nodes.map(function (n) {
            var st = self.styles[n.department] || { color: '#64748b', shape: 'circle', index: 0 };
            var size = 10 + 22 * Math.sqrt(n.strength / maxS);
            return {
                id: n.id, name: n.name, slug: n.slug, value: n.strength,
                category: Math.max(0, codes.indexOf(n.department)),
                symbol: st.shape, symbolSize: n.focus ? size + 8 : size,
                itemStyle: { color: st.color, borderColor: n.focus ? (dark ? '#fff' : '#0f172a') : (dark ? '#0f172a' : '#fff'), borderWidth: n.focus ? 3 : 1 },
                label: { show: self.showNames && (n.focus || n.strength >= maxS * 0.08 || self.data.nodes.length <= 40) },
                tooltipText: '<strong>' + esc(n.name) + '</strong><br>' + esc(n.department) + ' · ' + plural(n.degree, 'coautor', 'coautores') + ' na rede<br><span style="opacity:.75">Clique para destacar; clique duplo abre o perfil</span>'
            };
        });
        var edges = this.data.edges.map(function (e) {
            return { source: e.source, target: e.target, value: e.weight, lineStyle: { width: 1 + 7 * Math.sqrt(e.weight / maxW) } };
        });

        this.chart.setOption({
            animation: !reducedMotion(),
            tooltip: {
                confine: true,
                formatter: function (p) {
                    if (p.dataType === 'node') return p.data.tooltipText;
                    var a = self.nodeById(p.data.source), b = self.nodeById(p.data.target);
                    return esc(a ? a.name : '') + ' × ' + esc(b ? b.name : '') + '<br>' + plural(p.data.value, 'publicação', 'publicações') + ' em comum<br><span style="opacity:.75">Clique para ver as publicações</span>';
                }
            },
            series: [{
                type: 'graph', layout: 'force', roam: true, draggable: true,
                data: nodes, links: edges,
                categories: codes.map(function (c) { return { name: c }; }),
                force: { repulsion: 85, edgeLength: [25, 90], gravity: 0.16, friction: 0.15, layoutAnimation: !reducedMotion() },
                label: { position: 'right', fontSize: 11, color: text, textBorderColor: dark ? '#020617' : '#fff', textBorderWidth: 3 },
                lineStyle: { color: dark ? '#64748b' : '#94a3b8', opacity: 0.75, curveness: 0.08 },
                emphasis: { focus: 'adjacency', label: { show: true }, lineStyle: { opacity: 1 } },
                blur: { itemStyle: { opacity: 0.15 }, lineStyle: { opacity: 0.05 }, label: { show: false } },
                scaleLimit: { min: 0.3, max: 6 }
            }]
        }, true);
        this.updateStatus();
        if (this.selected) this.highlight(this.selected);
    };

    Graph.prototype.nodeById = function (id) {
        for (var i = 0; i < this.data.nodes.length; i++) if (this.data.nodes[i].id === String(id)) return this.data.nodes[i];
        return null;
    };

    Graph.prototype.highlight = function (id) {
        var idx = -1;
        for (var i = 0; i < this.data.nodes.length; i++) if (this.data.nodes[i].id === String(id)) idx = i;
        if (idx < 0 || !this.chart) return;
        this.chart.dispatchAction({ type: 'downplay', seriesIndex: 0 });
        this.chart.dispatchAction({ type: 'highlight', seriesIndex: 0, dataIndex: idx });
    };

    Graph.prototype.select = function (id, silent) {
        var node = this.nodeById(id);
        if (!node) return;
        this.selected = String(id);
        if (this.focusSelect) this.focusSelect.value = this.selected;
        this.highlight(this.selected);
        var neighbors = this.data.edges.filter(function (e) { return e.source === node.id || e.target === node.id; });
        var shared = neighbors.reduce(function (s, e) { return s + e.weight; }, 0);
        if (this.info) {
            this.info.innerHTML = '<div class="flex flex-wrap items-center justify-between gap-2">' +
                '<p class="text-sm"><strong class="text-slate-900 dark:text-white">' + esc(node.name) + '</strong> · ' + plural(neighbors.length, 'coautor', 'coautores') + ' nesta rede · ' + plural(shared, 'publicação compartilhada', 'publicações compartilhadas') + '</p>' +
                '<span class="flex gap-3 text-sm"><a class="font-semibold text-sky-700 dark:text-sky-400 hover:underline" href="/professor/' + encodeURIComponent(node.slug) + '">Ver perfil</a>' +
                '<button type="button" class="font-semibold text-slate-600 dark:text-slate-300 hover:underline" data-graph-clear>Limpar seleção</button></span></div>';
            var self = this;
            this.info.querySelector('[data-graph-clear]').addEventListener('click', function () { self.clearSelection(); });
            this.info.hidden = false;
        }
        if (!silent) {
            this.updateStatus();
            this.showPairsFor(node);
        }
    };

    // Lista de pares do docente selecionado: pede a vizinhança completa (o grafo desenha só as ligações mais fortes)
    Graph.prototype.showPairsFor = function (node) {
        if (!this.pairsBox) return;
        var self = this;
        var box = this.pairsBox;
        var list = box.querySelector('[data-graph-pairs-list]');
        var focusList = box.querySelector('[data-graph-pairs-focus]');
        var more = box.querySelector('[data-graph-pairs-more]');
        var title = box.querySelector('[data-graph-pairs-title]');
        var reset = box.querySelector('[data-graph-pairs-reset]');
        var token = {};
        this.pairsToken = token;

        title.innerHTML = 'Pares de ' + esc(node.name) + ' <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(carregando…)</span>';
        reset.hidden = false;
        if (more) more.hidden = true;

        var url = this.root.dataset.src.replace(/([?&])(docente|ligacoes)=[^&]*/g, '$1').replace(/[?&]+$/, '');
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'ligacoes=0&docente=' + encodeURIComponent(node.slug);
        fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
            if (!r.ok) throw new Error();
            return r.json();
        }).then(function (data) {
            if (self.pairsToken !== token) return;
            var byId = {};
            data.nodes.forEach(function (n) { byId[n.id] = n; });
            var focusId = data.focus ? String(data.focus.id) : node.id;
            var pairs = data.edges.filter(function (e) { return e.source === focusId || e.target === focusId; })
                .sort(function (a, b) { return b.weight - a.weight; });
            title.innerHTML = 'Pares de ' + esc(node.name) + ' <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(' + plural(pairs.length, 'coautor', 'coautores') + ' no CECH)</span>';
            focusList.innerHTML = pairs.length ? pairs.map(function (e, i) {
                var other = byId[e.source === focusId ? e.target : e.source];
                return pairCardHtml(byId[focusId] || node, other || { id: '', name: '' }, e.weight, i + 1);
            }).join('') : '<li class="text-sm text-slate-500 dark:text-slate-400">Nenhuma coautoria neste recorte.</li>';
            list.hidden = true;
            focusList.hidden = false;
        }).catch(function () {
            if (self.pairsToken !== token) return;
            title.innerHTML = 'Pares de ' + esc(node.name);
            focusList.innerHTML = '<li class="text-sm text-rose-700">Não foi possível carregar os pares. Tente novamente.</li>';
            list.hidden = true;
            focusList.hidden = false;
        });
    };

    Graph.prototype.resetPairs = function () {
        if (!this.pairsBox) return;
        var box = this.pairsBox;
        this.pairsToken = null;
        box.querySelector('[data-graph-pairs-title]').innerHTML = this.pairsTitleHtml;
        box.querySelector('[data-graph-pairs-list]').hidden = false;
        box.querySelector('[data-graph-pairs-focus]').hidden = true;
        box.querySelector('[data-graph-pairs-reset]').hidden = true;
        var more = box.querySelector('[data-graph-pairs-more]');
        if (more) more.hidden = false;
    };

    Graph.prototype.clearSelection = function () {
        this.selected = null;
        if (this.focusSelect) this.focusSelect.value = '';
        if (this.chart) this.chart.dispatchAction({ type: 'downplay', seriesIndex: 0 });
        if (this.info) { this.info.hidden = true; this.info.innerHTML = ''; }
        this.resetPairs();
        this.updateStatus();
    };

    Graph.prototype.zoom = function (factor) {
        if (!this.chart) return;
        var opt = this.chart.getOption();
        var zoom = ((opt.series && opt.series[0] && opt.series[0].zoom) || 1) * factor;
        this.chart.setOption({ series: [{ zoom: Math.max(0.3, Math.min(6, zoom)) }] });
    };

    Graph.prototype.updateStatus = function () {
        if (!this.data) return;
        var d = this.data;
        var parts = [plural(d.shownPeople, 'pessoa', 'pessoas'), fmt(d.shownPairs) + ' de ' + fmt(d.totalPairs) + ' pares', 'maior espessura = mais publicações'];
        this.setStatus(parts.join(' · '));
    };

    Graph.prototype.openPair = function (a, b) {
        if (!this.dialog || !this.root.dataset.pairSrc) return;
        var url = this.root.dataset.pairSrc + (this.root.dataset.pairSrc.indexOf('?') >= 0 ? '&' : '?') + 'a=' + encodeURIComponent(a) + '&b=' + encodeURIComponent(b);
        var title = this.dialog.querySelector('[data-graph-dialog-title]');
        var body = this.dialog.querySelector('[data-graph-dialog-body]');
        var na = this.data ? this.nodeById(a) : null, nb = this.data ? this.nodeById(b) : null;
        title.textContent = (na && nb) ? na.name + ' × ' + nb.name : 'Pesquisa compartilhada';
        body.innerHTML = '<p class="py-6 text-sm text-slate-500">Carregando publicações…</p>';
        if (!this.dialog.open) this.dialog.showModal();
        fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
            if (!r.ok) throw new Error();
            return r.json();
        }).then(function (data) {
            if (data.a && data.b) title.textContent = data.a.name + ' × ' + data.b.name;
            body.innerHTML = '<p class="text-xs text-slate-500 dark:text-slate-400">' + plural(data.count, 'publicação', 'publicações') + ' em comum</p>' +
                '<ol class="divide-y divide-slate-200 dark:divide-white/10">' + data.results.map(workHtml).join('') + '</ol>';
        }).catch(function () {
            body.innerHTML = '<p class="py-6 text-sm text-rose-700">Não foi possível carregar as publicações. Tente novamente.</p>';
        });
    };

    window.CechGraph = {
        initAll: function (scope) {
            (scope || document).querySelectorAll('[data-cech-graph]').forEach(function (el) {
                if (!el.__cechGraph) el.__cechGraph = new Graph(el);
            });
        },
        workHtml: workHtml
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.CechGraph.initAll(); });
    } else {
        window.CechGraph.initAll();
    }
})();
