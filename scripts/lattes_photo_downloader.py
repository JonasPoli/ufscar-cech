#!/usr/bin/env python3
"""
Lattes Photo Downloader & Captcha Resolver
------------------------------------------
Este script auxilia no download das fotos oficiais dos pesquisadores da Plataforma Lattes.

Modos de Uso:
1. Modo Sessão de Navegador:
   - Abra qualquer currículo Lattes no navegador (ex: http://lattes.cnpq.br/6958372164719600)
   - Resolva o captcha "Não sou um robô" e clique em Submeter para abrir o currículo.
   - Abra o DevTools (F12) -> Application -> Cookies -> 'buscatextual.cnpq.br'
   - Copie o valor do cookie 'JSESSIONID' e execute:
     python3 scripts/lattes_photo_downloader.py --jsessionid=SEU_JSESSIONID
"""

import argparse
import os
import sys
import time
import requests
import json
import re
from urllib.parse import urljoin

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_DIR = os.path.join(BASE_DIR, "public", "uploads", "photos")

def get_lattes_ids():
    """Lê os IDs Lattes de todos os 414 pesquisadores direto do banco ou do diretório XML."""
    import subprocess
    ids = []

    # 1. Tentar via dbal:run-sql
    try:
        cmd = ["php", os.path.join(BASE_DIR, "bin", "console"), "dbal:run-sql", "SELECT id_lattes FROM researchers ORDER BY id ASC"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        found = re.findall(r'\b(\d{16})\b', res.stdout)
        if found:
            ids.extend(found)
    except Exception:
        pass

    # 2. Se vazio, ler do diretório de XMLs (docs/banco/CECH)
    if not ids:
        xml_dir = os.path.join(BASE_DIR, "docs", "banco", "CECH")
        if os.path.isdir(xml_dir):
            for f in os.listdir(xml_dir):
                if f.endswith(".xml"):
                    name = f[:-4]
                    if len(name) == 16 and name.isdigit():
                        ids.append(name)

    unique_ids = sorted(list(set(ids)))
    print(f"[*] Total de pesquisadores identificados: {len(unique_ids)}")
    return unique_ids

def download_with_session(jsessionid, lattes_ids):
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    session = requests.Session()
    session.headers.update({
        "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
        "Referer": "http://buscatextual.cnpq.br/buscatextual/",
    })
    session.cookies.set("JSESSIONID", jsessionid, domain="buscatextual.cnpq.br")

    print(f"[*] Iniciando download para {len(lattes_ids)} pesquisadores com a sessão informada...\n")
    success = 0

    for idx, lattes_id in enumerate(lattes_ids, 1):
        dest_file = os.path.join(OUTPUT_DIR, f"{lattes_id}.jpg")
        if os.path.exists(dest_file) and os.path.getsize(dest_file) > 1000:
            print(f"[{idx}/{len(lattes_ids)}] {lattes_id}: Foto já existe localmente, pulando.")
            success += 1
            continue

        try:
            # 1. Obter página Lattes para resolver K-Number
            url_lattes = f"http://lattes.cnpq.br/{lattes_id}"
            r_page = session.get(url_lattes, timeout=10, allow_redirects=True)
            
            # Buscar K-Number no formulário ou URL
            k_match = re.search(r'id=([A-Z0-9]{10})', r_page.url) or re.search(r'name="id"\s+value="([A-Z0-9]{10})"', r_page.text)
            k_id = k_match.group(1) if k_match else lattes_id

            # 2. Procurar imagem de foto no HTML renderizado
            photo_src_match = re.search(r'src=["\x27]([^"\x27]*servletrecuperafoto[^"\x27]*)["\x27]', r_page.text, re.I)
            
            candidate_urls = []
            if photo_src_match:
                candidate_urls.append(urljoin(r_page.url, photo_src_match.group(1)))

            candidate_urls.extend([
                f"http://buscatextual.cnpq.br/buscatextual/servletrecuperafoto?id={k_id}",
                f"https://buscatextual.cnpq.br/buscatextual/servletrecuperafoto?id={k_id}",
                f"http://buscatextual.cnpq.br/buscatextual/servletrecuperafoto?id={lattes_id}",
            ])

            downloaded = False
            for p_url in candidate_urls:
                try:
                    r_img = session.get(p_url, timeout=8)
                    # Validar se o retorno é binário de imagem real (JPEG/PNG) e maior que 600 bytes
                    if r_img.status_code == 200 and len(r_img.content) > 600:
                        content_start = r_img.content[:4]
                        if content_start in [b'\xff\xd8\xff\xe0', b'\xff\xd8\xff\xe1', b'\xff\xd8\xff\xdb', b'\x89PNG']:
                            with open(dest_file, "wb") as f:
                                f.write(r_img.content)
                            print(f"[{idx}/{len(lattes_ids)}] ✅ {lattes_id}: Foto baixada ({len(r_img.content)} bytes).")
                            success += 1
                            downloaded = True
                            break
                except Exception:
                    continue

            if not downloaded:
                print(f"[{idx}/{len(lattes_ids)}] ⚠️  {lattes_id}: Foto não disponível ou sessão expirada no Lattes.")

            time.sleep(0.3)
        except Exception as e:
            print(f"[{idx}/{len(lattes_ids)}] ❌ {lattes_id}: Erro: {e}")

    print(f"\n[+] Finalizado! {success} de {len(lattes_ids)} fotos obtidas com sucesso em '{OUTPUT_DIR}'.")
    print("[+] Executando atualização no banco de dados...")
    import subprocess
    subprocess.run(["php", os.path.join(BASE_DIR, "bin", "console"), "app:import:photos", f"--dir={OUTPUT_DIR}"])

def main():
    parser = argparse.ArgumentParser(description="Lattes Photo Downloader & Captcha Resolver")
    parser.add_argument("--jsessionid", help="Valor do cookie JSESSIONID após resolver o captcha no navegador", default=None)
    parser.add_argument("--id", help="Baixar para um ID Lattes específico (opcional)", default=None)
    args = parser.parse_args()

    if args.id:
        lattes_ids = [args.id]
    else:
        lattes_ids = get_lattes_ids()

    if not args.jsessionid:
        print("\n" + "="*70)
        print("💡 COMO RESOLVER O CAPTCHA E BAIXAR TODAS AS FOTOS EM 1 MINUTO:")
        print("="*70)
        print("1. Abra no seu navegador qualquer currículo:")
        print("   👉 http://lattes.cnpq.br/6958372164719600")
        print("2. Marque a caixinha 'Não sou um robô' do captcha e clique em 'Submeter'.")
        print("3. Pressione F12 -> Aba 'Application' (ou Armazenamento) -> Cookies -> 'buscatextual.cnpq.br'")
        print("4. Copie o valor do cookie 'JSESSIONID' (ex: 8B9D6C0F123...).")
        print("5. Execute o comando com seu cookie:")
        print("   👉 python3 scripts/lattes_photo_downloader.py --jsessionid=SEU_VALOR_AQUI")
        print("="*70 + "\n")
        return

    download_with_session(args.jsessionid, lattes_ids)

if __name__ == "__main__":
    main()
