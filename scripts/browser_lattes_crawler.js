/**
 * CECH - In-Browser Full Lattes Data & Photo Importer (Versão 2.2)
 * ----------------------------------------------------------------
 * Sincroniza qualquer currículo Lattes aberto no navegador diretamente com o CECH.
 * Exibe relatório detalhado no console F12 (console.table) e no toast flutuante.
 */
(async function importFullLattesToCech() {
    console.log('%c🚀 [CECH] INICIANDO SINCRONIZAÇÃO DO CURRÍCULO LATTES...', 'color: #0284c7; font-size: 14px; font-weight: bold;');

    // 1. Identificar ID Lattes (16 dígitos no texto, query string ou campo oculto)
    const textMatch = document.documentElement.innerHTML.match(/lattes\.cnpq\.br\/(\d{16})/i);
    const urlParams = new URLSearchParams(window.location.search);
    const idInput = document.querySelector('input[name="id"]');
    const idVal = idInput ? idInput.value : '';
    const idLattes = (textMatch ? textMatch[1] : null) || urlParams.get('id') || idVal || window.location.pathname.split('/').pop() || '';

    // 2. Feedback visual flutuante na página do Lattes
    const toastId = 'cech-sync-toast';
    const oldToast = document.getElementById(toastId);
    if (oldToast) oldToast.remove();

    const toast = document.createElement('div');
    toast.id = toastId;
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '9999999';
    toast.style.background = '#0284c7';
    toast.style.color = '#ffffff';
    toast.style.padding = '16px 22px';
    toast.style.borderRadius = '14px';
    toast.style.boxShadow = '0 12px 30px rgba(0,0,0,0.4)';
    toast.style.fontFamily = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    toast.style.fontSize = '13px';
    toast.style.maxWidth = '480px';
    toast.style.maxHeight = '80vh';
    toast.style.overflowY = 'auto';
    toast.style.lineHeight = '1.5';
    toast.style.cursor = 'pointer';
    toast.style.border = '1px solid rgba(255,255,255,0.2)';
    toast.textContent = '⏳ Sincronizando com CECH... Tentando conectar ao servidor local...';
    document.body.appendChild(toast);

    const endpoints = Array.from(new Set([
        window.location.origin + '/api/curriculum/import-html',
        'https://127.0.0.1:8002/api/curriculum/import-html',
        'https://localhost:8002/api/curriculum/import-html',
        'http://127.0.0.1:8002/api/curriculum/import-html',
        'http://localhost:8002/api/curriculum/import-html',
        'https://127.0.0.1:8000/api/curriculum/import-html',
        'https://localhost:8000/api/curriculum/import-html',
        'http://127.0.0.1:8000/api/curriculum/import-html',
        'http://localhost:8000/api/curriculum/import-html'
    ]));

    let success = false;
    let lastError = null;

    for (const endpoint of endpoints) {
        try {
            console.log(`📡 [CECH] Tentando envio para: ${endpoint}...`);
            const resp = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    idLattes: idLattes,
                    html: document.documentElement.outerHTML
                })
            });

            if (!resp.ok && resp.status >= 500) {
                const errData = await resp.json().catch(() => ({ message: `HTTP ${resp.status}` }));
                throw new Error(errData.message || `HTTP ${resp.status}`);
            }

            const data = await resp.json();

            if (data.success) {
                console.group('%c📊 [CECH] RELATÓRIO DETALHADO DE ALTERAÇÕES', 'color: #0284c7; font-size: 14px; font-weight: bold;');
                console.log('👤 Docente:', data.researcher.fullName, `(Lattes: ${data.researcher.idLattes})`);
                console.log('📝 Resumo:', data.report ? data.report.summaryMessage : data.message);

                let detailsHtml = '';
                if (data.report && data.report.addedItems) {
                    const items = data.report.addedItems;
                    let list = [];

                    if (items.articles && items.articles.length > 0) {
                        console.log('%c📰 Novos Artigos Adicionados (' + items.articles.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.articles.map(a => ({ Título: a.title, Ano: a.year || '-', Periódico: a.journal || '-', Qualis: a.qualis || '-' })));
                        items.articles.forEach(a => list.push(`<li><b>📰 Artigo:</b> "${a.title}" (${a.year || 's/d'})</li>`));
                    }
                    if (items.books && items.books.length > 0) {
                        console.log('%c📚 Novos Livros Adicionados (' + items.books.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.books.map(b => ({ Título: b.title, Ano: b.year || '-', Editora: b.publisher || '-' })));
                        items.books.forEach(b => list.push(`<li><b>📚 Livro:</b> "${b.title}" (${b.year || 's/d'})</li>`));
                    }
                    if (items.chapters && items.chapters.length > 0) {
                        console.log('%c📖 Novos Capítulos Adicionados (' + items.chapters.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.chapters.map(c => ({ Título: c.title, Ano: c.year || '-' })));
                        items.chapters.forEach(c => list.push(`<li><b>📖 Capítulo:</b> "${c.title}" (${c.year || 's/d'})</li>`));
                    }
                    if (items.events && items.events.length > 0) {
                        console.log('%c🎤 Novos Trabalhos em Eventos (' + items.events.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.events.map(e => ({ Título: e.title, Ano: e.year || '-' })));
                        items.events.forEach(e => list.push(`<li><b>🎤 Evento:</b> "${e.title}" (${e.year || 's/d'})</li>`));
                    }
                    if (items.orientations && items.orientations.length > 0) {
                        console.log('%c🎓 Nova(s) Orientação(ões) Adicionada(s) (' + items.orientations.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.orientations.map(o => ({ Tipo: o.type, Aluno: o.student, Título: o.title || '-', Ano: o.year || '-' })));
                        items.orientations.forEach(o => list.push(`<li><b>🎓 Orientação (${o.type}):</b> ${o.student} - <i>${o.title || 'Sem título'}</i> (${o.year || 's/d'})</li>`));
                    }
                    if (items.projects && items.projects.length > 0) {
                        console.log('%c🔬 Novos Projetos de Pesquisa (' + items.projects.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.projects.map(p => ({ Projeto: p.name, Ano: p.startYear || '-' })));
                        items.projects.forEach(p => list.push(`<li><b>🔬 Projeto:</b> ${p.name} (${p.startYear || 's/d'})</li>`));
                    }
                    if (items.awards && items.awards.length > 0) {
                        console.log('%c🏆 Novos Prêmios e Títulos (' + items.awards.length + '):', 'color: #16a34a; font-weight: bold;');
                        console.table(items.awards.map(aw => ({ Prêmio: aw.name, Ano: aw.year || '-' })));
                        items.awards.forEach(aw => list.push(`<li><b>🏆 Prêmio:</b> ${aw.name} (${aw.year || 's/d'})</li>`));
                    }

                    if (list.length > 0) {
                        detailsHtml = `<div style="margin-top:10px; background:rgba(255,255,255,0.08); padding:10px 12px; border-radius:8px; font-size:12px;">`
                            + `<b style="color:#38bdf8;">Novos itens adicionados (${list.length}):</b>`
                            + `<ul style="margin:6px 0 0 16px; padding:0; line-height:1.4;">${list.slice(0, 8).join('')}${list.length > 8 ? `<li><i>... e mais ${list.length - 8} itens</i></li>` : ''}</ul>`
                            + `</div>`;
                    } else {
                        detailsHtml = `<div style="margin-top:8px; opacity:0.85; font-size:12px;">ℹ️ Nenhuma nova produção ou orientação identificada (dados já atualizados).</div>`;
                    }
                }
                console.groupEnd();

                toast.style.background = '#0f172a';
                toast.innerHTML = '<b>🎉 Sincronizado com Sucesso!</b><br><br>'
                    + '<b>Docente:</b> ' + (data.researcher.fullName || '') + '<br>'
                    + '<b>ID Lattes:</b> ' + (data.researcher.idLattes || '') + '<br>'
                    + '<b>Total Produções:</b> ' + (data.researcher.productionsCount ?? 0) + '<br>'
                    + '<b>Total Orientações:</b> ' + (data.researcher.orientationsCount ?? 0) + '<br>'
                    + detailsHtml + '<br>'
                    + '<small style="opacity:0.7">Clique para fechar | F12 para ver a tabela completa no console</small>';
                toast.onclick = () => toast.remove();
                setTimeout(() => { if (toast.parentNode) toast.remove(); }, 12000);
                success = true;
                break;
            } else {
                throw new Error(data.message || 'Erro desconhecido retornado pela API');
            }
        } catch (e) {
            console.warn(`⚠️ [CECH] Falha no endpoint ${endpoint}:`, e.message);
            lastError = e;
        }
    }

    if (!success) {
        console.error('❌ [CECH] Todas as tentativas falharam:', lastError);
        toast.style.background = '#b91c1c';
        toast.innerHTML = '<b>❌ Não foi possível conectar ao servidor local:</b><br><br>'
            + '1. Verifique se o servidor está rodando: <code>symfony serve</code><br>'
            + '2. Se a página do Lattes estiver em <b>HTTPS</b>, acerte o certificado local com <code>symfony server:ca:install</code> ou abra o Lattes via <b>HTTP</b> (<code>http://buscatextual.cnpq.br/...</code>).<br>'
            + '3. Alternativamente, cole o código-fonte em <b>/admin/curriculum/new</b>.<br><br>'
            + '<small>Clique para fechar</small>';
        toast.onclick = () => toast.remove();
    }
})();
