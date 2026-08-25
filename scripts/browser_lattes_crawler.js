/**
 * CECH - In-Browser Full Lattes Data & Photo Importer (Versão 2.1)
 * ----------------------------------------------------------------
 * Sincroniza qualquer currículo Lattes aberto no navegador diretamente com o CECH.
 * Suporta fallback automático entre HTTPS/HTTP em 127.0.0.1 e localhost.
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
    toast.style.boxShadow = '0 12px 30px rgba(0,0,0,0.35)';
    toast.style.fontFamily = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    toast.style.fontSize = '13px';
    toast.style.maxWidth = '400px';
    toast.style.lineHeight = '1.5';
    toast.style.cursor = 'pointer';
    toast.style.border = '1px solid rgba(255,255,255,0.2)';
    toast.textContent = '⏳ Sincronizando com CECH... Tentando conectar ao servidor local...';
    document.body.appendChild(toast);

    const endpoints = [
        'https://127.0.0.1:8000/api/curriculum/import-html',
        'https://localhost:8000/api/curriculum/import-html',
        'http://127.0.0.1:8000/api/curriculum/import-html',
        'http://localhost:8000/api/curriculum/import-html'
    ];

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
                console.log('✅ [CECH] Currículo sincronizado:', data.researcher);
                toast.style.background = '#15803d';
                toast.innerHTML = '<b>🎉 Sincronizado com Sucesso!</b><br><br>'
                    + '<b>Docente:</b> ' + (data.researcher.fullName || '') + '<br>'
                    + '<b>ID Lattes:</b> ' + (data.researcher.idLattes || '') + '<br>'
                    + '<b>Produções Importadas:</b> ' + (data.researcher.productionsCount ?? 0) + '<br>'
                    + '<b>Orientações Importadas:</b> ' + (data.researcher.orientationsCount ?? 0) + '<br><br>'
                    + '<small style="opacity:0.85">Clique para fechar esta notificação</small>';
                toast.onclick = () => toast.remove();
                setTimeout(() => { if (toast.parentNode) toast.remove(); }, 8000);
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

