#!/usr/bin/env bash
# ==============================================================================
# Publica a página /periodicos e atualiza as áreas CAPES no servidor de produção.
# Roda sem IA: envia os artefatos, faz backup e executa app:journals:pipeline.
#
#   bin/publish-journals.sh               código + artefatos + pipeline
#   bin/publish-journals.sh --data-only   só artefatos + pipeline (novas listas)
#   bin/publish-journals.sh --check       só confere as pré-condições
#
# Os artefatos são gerados nesta máquina (docs/banco/ é ignorado pelo Git).
# Guia: docs/DEPLOY_E_PRODUCAO.md, seção 4.6.
# ==============================================================================

set -euo pipefail

PROD_USER="${PROD_USER:-runcloud}"
PROD_HOST="${PROD_HOST:-104.236.71.49}"
PROD_APP_PATH="${PROD_APP_PATH:-/home/runcloud/webapps/ufscar-cech}"
PROD_PHP_BIN="${PROD_PHP_BIN:-/RunCloud/Packages/php84rc/bin/php}"
PROD_URL="${PROD_URL:-https://cech.wab.com.br}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DERIVED="${SCRIPT_DIR}/docs/banco/derived"
SSH_TARGET="${PROD_USER}@${PROD_HOST}"
SSH_OPTS=(-o ServerAliveInterval=30 -o ServerAliveCountMax=20)
STAMP="$(date +%Y%m%d-%H%M%S)"

MODE="${1:-full}"
case "${MODE}" in
    full|--data-only|--check) ;;
    *) echo "Uso: bin/publish-journals.sh [--data-only|--check]" >&2; exit 2 ;;
esac

remote() { ssh "${SSH_OPTS[@]}" "${SSH_TARGET}" "cd ${PROD_APP_PATH} && $1"; }

echo "== 1. Pré-condições =="
cd "${SCRIPT_DIR}"
for f in "${DERIVED}/journal_evidence.jsonl.gz" "${DERIVED}/scielo_articlemeta.json"; do
    [ -f "${f}" ] || { echo "Falta ${f}: rode app:journals:evidence:build." >&2; exit 1; }
done
gzip -t "${DERIVED}/journal_evidence.jsonl.gz"

if [ "${MODE}" != "--data-only" ]; then
    if [ -n "$(git status --porcelain)" ]; then
        echo "Há alterações sem commit. Faça o commit e o push antes de publicar." >&2
        git status --short >&2
        [ "${MODE}" = "--check" ] || exit 1
    fi
    git fetch --quiet origin main
    if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
        echo "O HEAD local difere de origin/main. Faça o push antes de publicar." >&2
        [ "${MODE}" = "--check" ] || exit 1
    fi
fi

DUPLICATES="$(remote "${PROD_PHP_BIN} bin/console dbal:run-sql --env=prod 'SELECT COUNT(*) FROM (SELECT normalized_name FROM journal_name_variants GROUP BY normalized_name HAVING COUNT(*) > 1) d'" | awk '/^ +[0-9]+ *$/ { gsub(/ /, ""); print; exit }')"
if [ "${DUPLICATES:-x}" != "0" ]; then
    echo "journal_name_variants em produção tem ${DUPLICATES:-?} nomes duplicados: a migration abortaria. Mescle-os no admin." >&2
    exit 1
fi
echo "Pré-condições ok."
[ "${MODE}" = "--check" ] && exit 0

echo "== 2. Envio dos artefatos =="
remote "mkdir -p docs/banco/derived var/audit"
rsync -az --human-readable --prune-empty-dirs \
    --include='journal_evidence.jsonl.gz' --include='scielo_articlemeta.json' \
    --include='ai/' --include='ai/*.out.jsonl' \
    --include='ai_titles/' --include='ai_titles/*.out.jsonl' \
    --include='overrides/' --include='overrides/*.jsonl' \
    --exclude='*' \
    "${DERIVED}/" "${SSH_TARGET}:${PROD_APP_PATH}/docs/banco/derived/"

echo "== 3. Backup do banco de produção =="
BACKUP_NAME="antes_periodicos_${STAMP}"
remote "${PROD_PHP_BIN} -d memory_limit=1G bin/console app:database:dump --filename=${BACKUP_NAME} --env=prod --no-interaction" | tail -3
remote "test -s var/backups/${BACKUP_NAME}.sql.zip" || { echo "Backup não encontrado: nada foi alterado." >&2; exit 1; }
echo "Backup: var/backups/${BACKUP_NAME}.sql.zip"

if [ "${MODE}" != "--data-only" ]; then
    echo "== 4. Código, migrations e assets =="
    LOCK_BEFORE="$(remote "git rev-parse HEAD:composer.lock")"
    remote "git pull --ff-only origin main"
    if [ "${LOCK_BEFORE}" != "$(remote "git rev-parse HEAD:composer.lock")" ]; then
        remote "${PROD_PHP_BIN} \$(command -v composer) install --no-dev --optimize-autoloader"
    fi
    remote "${PROD_PHP_BIN} bin/console doctrine:migrations:migrate --no-interaction --env=prod"
    remote "./build.sh"
    remote "${PROD_PHP_BIN} bin/console cache:clear --env=prod"
fi

echo "== 5. Pipeline de dados =="
remote "${PROD_PHP_BIN} -d memory_limit=1536M bin/console app:journals:pipeline --derived=docs/banco/derived --env=prod --no-ansi 2>&1 | tee var/audit/pipeline-${STAMP}.log; exit \${PIPESTATUS[0]}"

echo "== 6. Conferência =="
for path in /periodicos /periodicos/scopus "/periodicos?area=educacao" /indicadores /; do
    printf '%s  %s\n' "$(curl -s -o /dev/null -w '%{http_code}' --max-time 90 "${PROD_URL}${path}")" "${path}"
done
echo "Publicado. Backup: ${PROD_APP_PATH}/var/backups/${BACKUP_NAME}.sql.zip | Log: var/audit/pipeline-${STAMP}.log"
