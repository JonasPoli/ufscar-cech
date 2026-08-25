#!/usr/bin/env node

/**
 * Lattes Stealth Browser Crawler
 * --------------------------------
 * Emula um navegador Chrome real com plugin stealth para:
 * 1. Acessar cada currículo Lattes.
 * 2. Injetar o clique natural na caixinha do reCAPTCHA ('Não sou um robô').
 * 3. Submeter o formulário automaticamente.
 * 4. Extrair a foto oficial e salvar em public/uploads/photos/{idLattes}.jpg.
 * 5. Atualizar o banco de dados do CECH.
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

puppeteer.use(StealthPlugin());

const ROOT_DIR = path.resolve(__dirname, '..');
const OUTPUT_DIR = path.join(ROOT_DIR, 'public', 'uploads', 'photos');
const XML_DIR = path.join(ROOT_DIR, 'docs', 'banco', 'CECH');

if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

function getLattesIds() {
    let ids = [];
    if (fs.existsSync(XML_DIR)) {
        const files = fs.readdirSync(XML_DIR);
        for (const file of files) {
            if (file.endsWith('.xml')) {
                const name = file.replace('.xml', '').trim();
                if (name.length === 16 && /^\d+$/.test(name)) {
                    ids.push(name);
                }
            }
        }
    }

    if (ids.length === 0) {
        try {
            const out = execSync('php bin/console dbal:run-sql "SELECT id_lattes FROM researchers ORDER BY id ASC"', { cwd: ROOT_DIR }).toString();
            const matches = out.match(/\b\d{16}\b/g);
            if (matches) {
                ids = matches;
            }
        } catch (e) {}
    }

    return [...new Set(ids)].sort();
}

async function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function crawl() {
    const args = process.argv.slice(2);
    // Modo visível por padrão se não especificado --headless, para permitir resolução visual caso o Google exiba o desafio de imagens
    const isHeadless = args.includes('--headless');
    const specificIdArg = args.find(a => a.startsWith('--id='));
    const specificId = specificIdArg ? specificIdArg.split('=')[1] : null;

    let targetIds = specificId ? [specificId] : getLattesIds();

    console.log('='.repeat(70));
    console.log('🤖 CRAWLER DO LATTES (BROWSER EMULATION COM INJEÇÃO DE CLIQUE)');
    console.log('='.repeat(70));
    console.log(`[*] Modo: ${isHeadless ? 'Headless (Segundo plano)' : 'Visível (Janela do Chrome)'}`);
    console.log(`[*] Total de pesquisadores: ${targetIds.length}`);
    console.log(`[*] Diretório de fotos: ${OUTPUT_DIR}\n`);

    const browser = await puppeteer.launch({
        headless: isHeadless ? 'new' : false,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-web-security',
            '--disable-features=IsolateOrigins,site-per-process',
            '--window-size=1280,920',
        ],
        defaultViewport: { width: 1280, height: 920 }
    });

    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');

    let successCount = 0;
    let noPhotoCount = 0;
    let errorCount = 0;

    for (let i = 0; i < targetIds.length; i++) {
        const idLattes = targetIds[i];
        const destPath = path.join(OUTPUT_DIR, `${idLattes}.jpg`);
        const progress = `[${i + 1}/${targetIds.length}]`;

        if (fs.existsSync(destPath) && fs.statSync(destPath).size > 1000) {
            console.log(`${progress} ⏩ ${idLattes}: Foto já existe localmente.`);
            successCount++;
            continue;
        }

        console.log(`${progress} 🌐 Acessando http://lattes.cnpq.br/${idLattes}...`);

        try {
            await page.goto(`http://lattes.cnpq.br/${idLattes}`, { waitUntil: 'domcontentloaded', timeout: 25000 });
            await sleep(1200);

            // Verificar se está na tela de captcha
            let isCaptchaPage = await page.$('#formulario, #divCaptcha, iframe[src*="recaptcha"]');

            if (isCaptchaPage) {
                console.log(`   👉 Detectada tela de captcha. Injetando clique na caixinha 'Não sou um robô'...`);

                for (const frame of page.frames()) {
                    try {
                        const anchor = await frame.$('#recaptcha-anchor, .recaptcha-checkbox-border, .recaptcha-checkbox');
                        if (anchor) {
                            const box = await anchor.boundingBox();
                            if (box) {
                                await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 8 });
                                await sleep(150);
                                await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
                                console.log(`   🖱️  Clique executado com sucesso no checkbox do reCAPTCHA!`);
                                break;
                            }
                        }
                    } catch (e) {}
                }

                // Aguardar até que o botão Submeter seja habilitado (quando o captcha for aprovado)
                console.log(`   ⏳ Aguardando validação do captcha e liberação do botão...`);
                let approved = false;
                for (let attempt = 0; attempt < 15; attempt++) {
                    const isEnabled = await page.evaluate(() => {
                        const token = document.querySelector('#tokenCaptchar');
                        const btn = document.querySelector('#submitBtn');
                        return (token && token.value && token.value.length > 10) || (btn && !btn.disabled);
                    });

                    if (isEnabled) {
                        approved = true;
                        break;
                    }
                    await sleep(1000);
                }

                // Submeter formulário
                await page.evaluate(() => {
                    const btn = document.querySelector('#submitBtn') || document.querySelector('input[type="button"][value="Submeter"]');
                    if (btn) {
                        btn.removeAttribute('disabled');
                        btn.click();
                    }
                });

                console.log(`   🚀 Formulário submetido. Carregando currículo...`);
                try {
                    await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 12000 });
                } catch (e) {
                    await sleep(3000);
                }
            }

            // Na página do currículo: extrair a foto
            const photoData = await page.evaluate(async () => {
                const img = document.querySelector('.foto img') 
                         || document.querySelector('img[src*="servletrecuperafoto"]')
                         || document.querySelector('img.foto');
                
                if (!img || !img.src) {
                    return null;
                }

                try {
                    const res = await fetch(img.src);
                    const blob = await res.blob();
                    return new Promise((resolve) => {
                        const reader = new FileReader();
                        reader.onloadend = () => {
                            resolve({
                                src: img.src,
                                base64: reader.result.split(',')[1]
                            });
                        };
                        reader.readAsDataURL(blob);
                    });
                } catch (e) {
                    return { src: img.src, error: e.message };
                }
            });

            if (photoData && photoData.base64) {
                const buffer = Buffer.from(photoData.base64, 'base64');
                if (buffer.length > 500) {
                    fs.writeFileSync(destPath, buffer);
                    console.log(`   ✅ ${idLattes}: Foto baixada e salva com sucesso (${buffer.length} bytes)!`);
                    successCount++;
                } else {
                    console.log(`   ⚠️  ${idLattes}: Imagem retornada vazia.`);
                    errorCount++;
                }
            } else if (photoData && photoData.src) {
                console.log(`   📸 Foto detectada em ${photoData.src}.`);
                successCount++;
            } else {
                console.log(`   ℹ️  ${idLattes}: Currículo aberto, mas docente não possui foto no Lattes.`);
                noPhotoCount++;
            }

            await sleep(1000);

        } catch (err) {
            console.log(`   ❌ ${idLattes}: Erro: ${err.message}`);
            errorCount++;
        }
    }

    await browser.close();

    console.log('\n' + '='.repeat(70));
    console.log('🏁 RESULTADO FINAL:');
    console.log(`- Fotos obtidas/existentes: ${successCount}`);
    console.log(`- Currículos sem foto cadastrada: ${noPhotoCount}`);
    console.log(`- Erros: ${errorCount}`);
    console.log('='.repeat(70));

    console.log('\n[+] Atualizando vínculos no banco de dados do CECH...');
    try {
        execSync(`php bin/console app:import:photos --dir=${OUTPUT_DIR}`, { cwd: ROOT_DIR, stdio: 'inherit' });
    } catch (e) {}

    console.log('[+] Concluído!');
}

crawl().catch(err => {
    console.error('Erro fatal no crawler:', err);
    process.exit(1);
});
