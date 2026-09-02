#!/usr/bin/env bash
# ==============================================================================
# Script de Sincronização Automática: Produção (Online) -> Local (Dev)
# Exporta a base de dados completa do servidor online, baixa via SCP e restaura localmente
# ==============================================================================

set -e

# Cores para o terminal
COLOR_RESET="\033[0m"
COLOR_BOLD="\033[1m"
COLOR_GREEN="\033[32m"
COLOR_BLUE="\033[34m"
COLOR_CYAN="\033[36m"
COLOR_YELLOW="\033[33m"
COLOR_RED="\033[31m"

# ── Configurações de Conexão com o Servidor de Produção ─────────────────────
PROD_USER="${PROD_USER:-runcloud}"
PROD_HOST="${PROD_HOST:-104.236.71.49}"
PROD_APP_PATH="${PROD_APP_PATH:-/home/runcloud/webapps/ufscar-cech}"
PROD_PHP_BIN="${PROD_PHP_BIN:-/RunCloud/Packages/php84rc/bin/php}"

# Diretórios locais
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_BACKUP_DIR="${SCRIPT_DIR}/var/backups"
TIMESTAMP="$(date +'%Y-%m-%d_%H-%M-%S')"
CUSTOM_NAME="sync_prod_${TIMESTAMP}"

mkdir -p "${LOCAL_BACKUP_DIR}"

echo -e "${COLOR_BOLD}${COLOR_CYAN}====================================================================${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_CYAN}  🔄 SINCRONIZAÇÃO DE BANCO DE DADOS: PRODUÇÃO ➔ AMBIENTE LOCAL     ${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_CYAN}====================================================================${COLOR_RESET}"
echo -e "${COLOR_BLUE}• Servidor Remoto:${COLOR_RESET}  ${PROD_USER}@${PROD_HOST}"
echo -e "${COLOR_BLUE}• Caminho Remoto:${COLOR_RESET}   ${PROD_APP_PATH}"
echo -e "${COLOR_BLUE}• Destino Local:${COLOR_RESET}    ${LOCAL_BACKUP_DIR}"
echo ""

# ── 1. Gerar Dump no Servidor de Produção ──────────────────────────────────
echo -e "${COLOR_BOLD}${COLOR_YELLOW}[1/3] 📦 Gerando superdump compactado no servidor online...${COLOR_RESET}"
START_TIME=$(date +%s)

ssh "${PROD_USER}@${PROD_HOST}" "${PROD_PHP_BIN} ${PROD_APP_PATH}/bin/console app:database:dump --filename=${CUSTOM_NAME} --env=prod"

REMOTE_ZIP_PATH="${PROD_APP_PATH}/var/backups/${CUSTOM_NAME}.sql.zip"

# ── 2. Baixar o Arquivo para o Ambiente Local ─────────────────────────────
echo ""
echo -e "${COLOR_BOLD}${COLOR_YELLOW}[2/3] ⬇️  Baixando o arquivo ${CUSTOM_NAME}.sql.zip via SCP...${COLOR_RESET}"
scp "${PROD_USER}@${PROD_HOST}:${REMOTE_ZIP_PATH}" "${LOCAL_BACKUP_DIR}/"

LOCAL_FILE="${LOCAL_BACKUP_DIR}/${CUSTOM_NAME}.sql.zip"

if [ ! -f "${LOCAL_FILE}" ]; then
    echo -e "${COLOR_RED}❌ Erro: Arquivo de backup não encontrado em ${LOCAL_FILE}${COLOR_RESET}"
    exit 1
fi

FILE_SIZE_MB=$(du -m "${LOCAL_FILE}" | cut -f1)
echo -e "${COLOR_GREEN}✓ Download concluído! Arquivo: ${CUSTOM_NAME}.sql.zip (~${FILE_SIZE_MB} MB)${COLOR_RESET}"

# ── 3. Restaurar Localmente ───────────────────────────────────────────────
echo ""
echo -e "${COLOR_BOLD}${COLOR_YELLOW}[3/3] ⚡ Restaurando dados no banco local...${COLOR_RESET}"

php "${SCRIPT_DIR}/bin/console" app:database:restore "${LOCAL_FILE}" --force

END_TIME=$(date +%s)
ELAPSED=$((END_TIME - START_TIME))

echo ""
echo -e "${COLOR_BOLD}${COLOR_GREEN}====================================================================${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_GREEN}  ✅ BANCO DE DADOS LOCAL SINCRONIZADO COM SUCESSO! (${ELAPSED}s)      ${COLOR_RESET}"
echo -e "${COLOR_BOLD}${COLOR_GREEN}====================================================================${COLOR_RESET}"
