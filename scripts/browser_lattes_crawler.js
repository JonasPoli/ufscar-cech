/**
 * CECH - In-Browser Full Lattes Data & Photo Importer (Versão 2.0)
 * ----------------------------------------------------------------
 * Sincroniza qualquer currículo Lattes aberto no navegador diretamente com o CECH.
 * Extrai o ID Lattes (16 dígitos), o HTML completo renderizado e envia para a API
 * /api/curriculum/import-html do servidor local CECH.
 *
 * COMO USAR:
 * 1. Abra o currículo no Lattes (ex: http://lattes.cnpq.br/3389666977978800).
 * 2. Pressione F12 -> Console.
 * 3. Cole o código abaixo e pressione ENTER.
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
    toast.style.maxWidth = '380px';
    toast.style.lineHeight = '1.5';
    toast.style.cursor = 'pointer';
    toast.style.border = '1px solid rgba(255,255,255,0.2)';
    toast.textContent = '⏳ Sincronizando com CECH... Enviando dados para o servidor local...';
    document.body.appendChild(toast);

    try {
        const resp = await fetch('https://127.0.0.1:8000/api/curriculum/import-html', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                idLattes: idLattes,
                html: document.documentElement.outerHTML
            })
        });

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
        } else {
            console.error('❌ [CECH] Erro retornado pela API:', data.message);
            toast.style.background = '#b91c1c';
            toast.innerHTML = '<b>⚠️ Erro na Importação:</b><br>' + (data.message || 'Erro desconhecido') + '<br><br><small>Clique para fechar</small>';
            toast.onclick = () => toast.remove();
        }
    } catch (e) {
        console.error('❌ [CECH] Erro de conexão:', e);
        toast.style.background = '#b91c1c';
        toast.innerHTML = '<b>❌ Erro de Conexão:</b><br>'
            + 'Não foi possível conectar com https://127.0.0.1:8000.<br>'
            + 'Certifique-se de que o servidor Symfony local está rodando e a aba local está acessível.<br><br>'
            + '<small>Clique para fechar</small>';
        toast.onclick = () => toast.remove();
    }
})();
