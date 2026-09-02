# Guia de Deploy e Ambiente de Produção — CECH UFSCar

Este documento centraliza todos os parâmetros de conexão, caminhos absolutos, binários e procedimentos operacionais para execução de comandos no **servidor de produção online**.

---

## ⚙️ 1. Parâmetros do Servidor de Produção (Editável)

> [!NOTE]
> Se o servidor, usuário ou versão do PHP forem alterados no futuro, atualize esta tabela e o arquivo `AGENTS.md` / `GEMINI.md`.

| Parâmetro | Variável de Referência | Valor Atual em Produção | Descrição |
| :--- | :--- | :--- | :--- |
| **Painel / Gestão** | `SERVER_TYPE` | `RunCloud` | Painel de gerenciamento do servidor |
| **Usuário SSH** | `PROD_USER` | `runcloud` | Usuário do sistema para acesso SSH e cron jobs |
| **Host / IP do Servidor** | `PROD_HOST` | `104.236.71.49` | Endereço IP / Host da máquina remota |
| **Comando de Conexão SSH** | `PROD_SSH` | `ssh runcloud@104.236.71.49` | Comando para abrir sessão remota |
| **Diretório Raiz do Projeto** | `PROD_APP_PATH` | `/home/runcloud/webapps/ufscar-cech` | Caminho absoluto da aplicação |
| **Binário do PHP (CLI)** | `PROD_PHP_BIN` | `/RunCloud/Packages/php84rc/bin/php` | Caminho completo do executável PHP (v8.4) |
| **Ponto de Entrada do Console** | `PROD_CONSOLE` | `/RunCloud/Packages/php84rc/bin/php bin/console` | Comando padrão para rodar Symfony Console |

---

## 🚀 2. Procedimento Padrão de Deploy e Atualização

### Passo 1: Conectar ao Servidor e Atualizar Código
```bash
# Conectar via SSH
ssh runcloud@104.236.71.49

# Navegar até o diretório do projeto
cd /home/runcloud/webapps/ufscar-cech

# Atualizar o código do repositório
git pull origin main
```

### Passo 2: Instalar Dependências e Executar Migrations
```bash
# Atualizar dependências do Composer (se houver novas)
/RunCloud/Packages/php84rc/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader

# Aplicar migrations no banco de dados de produção
/RunCloud/Packages/php84rc/bin/php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

### Passo 3: Compilar Assets e Limpar Cache
```bash
# Recompilar CSS Tailwind minificado e AssetMapper
./build.sh

# Limpar e aquecer o cache do Symfony em produção
/RunCloud/Packages/php84rc/bin/php bin/console cache:clear --env=prod
```

---

## 📦 3. Envio de Arquivos Pesados do Banco (`docs/banco/`)

Como o diretório `docs/banco/` está no `.gitignore` para não inflar o repositório Git com arquivos grandes (ex: `TeD-UFSCar.csv` de ~98 MB), transfira os arquivos diretamente da sua máquina local para o servidor:

```bash
# Enviar o CSV do Repositório Institucional (TeD-UFSCar):
scp docs/banco/TeD-UFSCar.csv runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/

# Enviar arquivos de Tesauro (.the) ou planilhas (.xlsx):
scp docs/banco/*.the runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/
scp docs/banco/*.xlsx runcloud@104.236.71.49:/home/runcloud/webapps/ufscar-cech/docs/banco/
```

---

## 🛠️ 4. Execução de Rotinas e Commands no Servidor

Todos os comandos devem utilizar o binário oficial `/RunCloud/Packages/php84rc/bin/php`:

### 4.1. Importação do Repositório Institucional (TeD-UFSCar)
```bash
cd /home/runcloud/webapps/ufscar-cech

# Modo Simulação (Dry-Run):
/RunCloud/Packages/php84rc/bin/php bin/console app:import:repository --dry-run --env=prod

# Execução Real:
/RunCloud/Packages/php84rc/bin/php bin/console app:import:repository --env=prod
```

### 4.2. Indexação de Periódicos & Bases Internacionais
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:index:journals --env=prod
```

### 4.3. Normalização Geral de Currículos e Autores
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:curriculums:normalize --all --env=prod
/RunCloud/Packages/php84rc/bin/php bin/console app:thesaurus:sync-authors --env=prod
```

### 4.4. Sincronização Automática do Banco Online para o Local (Script Único)
Para exportar a base de dados online, baixar via SCP e restaurar automaticamente no seu ambiente de desenvolvimento com um único comando no seu Mac:

```bash
./sync-db.sh
```

Ou manualmente pelo terminal do servidor:
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:database:dump --env=prod
```

### 4.5. Gerenciamento de Administradores
```bash
/RunCloud/Packages/php84rc/bin/php bin/console app:admin-user <usuario> <senha> --env=prod
```

---

## 🔄 5. Como Alterar as Configurações de Produção no Futuro

Caso o servidor seja migrado ou a versão do PHP seja atualizada:

1. **Troca de versão do PHP**: Se mudar para PHP 8.5 no RunCloud, substitua `/RunCloud/Packages/php84rc/bin/php` por `/RunCloud/Packages/php85rc/bin/php`.
2. **Troca de Servidor/Pasta**: Edite os valores na Seção 1 deste documento (`docs/DEPLOY_E_PRODUCAO.md`) e nos arquivos [`AGENTS.md`](file:///Users/jonaspoli/work/html/ufscar-cech/AGENTS.md) e [`GEMINI.md`](file:///Users/jonaspoli/work/html/ufscar-cech/GEMINI.md).
3. Toda a assistência da IA utilizará automaticamente os novos caminhos informados nesses arquivos.
