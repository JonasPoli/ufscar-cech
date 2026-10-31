#!/usr/bin/env bash
# ==============================================================================
# Importa currículos Lattes (XML) em produção e atualiza tudo o que depende deles.
# Roda sem IA: backup, importação, teses do Repositório, periódicos, temas e cache.
#
#   bin/import-curriculos.sh lattes123.xml pasta-com-xmls/
#   bin/import-curriculos.sh --dept="DEd|Departamento de Educação" lattes123.xml
#   bin/import-curriculos.sh --check pasta-com-xmls/    só confere os arquivos
#   LOCAL=1 bin/import-curriculos.sh lattes123.xml      no banco local
#
# Currículo que já existe é substituído pelo novo XML (mesmo ID Lattes).
# Guia: docs/LATTES_IMPORT.md.
# ==============================================================================

set -euo pipefail

PROD_USER="${PROD_USER:-runcloud}"
PROD_HOST="${PROD_HOST:-104.236.71.49}"
PROD_APP_PATH="${PROD_APP_PATH:-/home/runcloud/webapps/ufscar-cech}"
PROD_PHP_BIN="${PROD_PHP_BIN:-/RunCloud/Packages/php84rc/bin/php}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SSH_TARGET="${PROD_USER}@${PROD_HOST}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BATCH="docs/banco/CECH/lote-${STAMP}"
REPOSITORY_FILE="${REPOSITORY_FILE:-docs/banco/TeD-UFSCar.csv}"
LOCAL="${LOCAL:-}"

CHECK=""
DEPT=""
PATHS=()
for arg in "$@"; do
    case "${arg}" in
        --check) CHECK=1 ;;
        --dept=*) DEPT="${arg#--dept=}" ;;
        -*) echo "Opção desconhecida: ${arg}" >&2; exit 2 ;;
        *) PATHS+=("${arg}") ;;
    esac
done
if [ "${#PATHS[@]}" -eq 0 ]; then
    echo "Uso: bin/import-curriculos.sh [--check] [--dept=\"CODIGO|Nome\"] arquivo.xml|pasta ..." >&2
    exit 2
fi

if [ -n "${LOCAL}" ]; then
    PHP="php"; ENV_ARGS="--no-ansi"
    remote() { (cd "${SCRIPT_DIR}" && bash -c "$1"); }
else
    PHP="${PROD_PHP_BIN}"; ENV_ARGS="--env=prod --no-debug --no-ansi"
    remote() { ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=20 "${SSH_TARGET}" "cd ${PROD_APP_PATH} && $1"; }
fi

echo "== 1. Arquivos =="
FILES=()
for p in "${PATHS[@]}"; do
    if [ -d "${p}" ]; then
        while IFS= read -r f; do FILES+=("${f}"); done < <(find "${p}" -maxdepth 1 -type f -iname '*.xml' -not -name '._*' | sort)
    elif [ -f "${p}" ]; then
        FILES+=("${p}")
    else
        echo "Não encontrado: ${p}" >&2; exit 1
    fi
done
[ "${#FILES[@]}" -gt 0 ] || { echo "Nenhum arquivo XML encontrado." >&2; exit 1; }

IDS=()
DATES=()
for f in "${FILES[@]}"; do
    command grep --color=never -aq '<CURRICULO-VITAE' "${f}" || { echo "Não é um XML do Lattes (sem CURRICULO-VITAE): ${f}" >&2; exit 1; }
    id="$(command grep --color=never -aoE 'NUMERO-IDENTIFICADOR="[0-9]{16}"' "${f}" | head -1 | command grep --color=never -oE '[0-9]{16}' || true)"
    # Parte dos XML vem sem o atributo: como o importador, usa os 16 dígitos do nome do arquivo.
    [ -n "${id}" ] || id="$(basename "${f}" | command grep --color=never -oE '[0-9]{16}' | head -1 || true)"
    [ -n "${id}" ] || { echo "Sem ID Lattes: nem NUMERO-IDENTIFICADOR no XML, nem 16 dígitos no nome do arquivo: ${f}" >&2; exit 1; }
    IDS+=("${id}")
    # DATA-ATUALIZACAO vem como DDMMAAAA; aqui fica AAAAMMDD para comparar.
    d="$(command grep --color=never -aoE 'DATA-ATUALIZACAO="[0-9]{8}"' "${f}" | head -1 | command grep --color=never -oE '[0-9]{8}' || true)"
    DATES+=("${d:4:4}${d:2:2}${d:0:2}")
done
IN_LIST="$(printf "'%s'," "${IDS[@]}")"; IN_LIST="${IN_LIST%,}"
EXISTING="$(remote "${PHP} bin/console dbal:run-sql ${ENV_ARGS} \"SELECT CONCAT(id_lattes, ':', COALESCE(DATE_FORMAT(last_lattes_update, '%Y%m%d'), '')) FROM researchers WHERE id_lattes IN (${IN_LIST})\" 2>/dev/null" | command grep --color=never -oE '[0-9]{16}:[0-9]*' || true)"
NEW=0
for i in "${!FILES[@]}"; do
    stored=""
    known=""
    for row in ${EXISTING}; do
        if [ "${row%%:*}" = "${IDS[$i]}" ]; then known=1; stored="${row##*:}"; fi
    done
    if [ -z "${known}" ]; then
        state="novo"; NEW=$((NEW + 1))
    elif [ -n "${stored}" ] && [ -n "${DATES[$i]}" ] && [ "${DATES[$i]}" -lt "${stored}" ]; then
        state="pulado (o XML é mais antigo que o currículo no banco)"
    else
        state="atualiza (substitui as produções atuais)"
    fi
    printf '  %s  %s  %s\n' "${IDS[$i]}" "${state}" "$(basename "${FILES[$i]}")"
done
echo "${#FILES[@]} arquivo(s), ${NEW} novo(s)."
if [ "${NEW}" -gt 0 ] && [ -z "${DEPT}" ]; then
    echo "Aviso: o XML não traz o departamento. Sem --dept, o docente novo fica sem departamento."
fi
[ -n "${CHECK}" ] && { echo "Conferência concluída; nada foi alterado."; exit 0; }

echo "== 2. Backup =="
remote "${PHP} -d memory_limit=1G bin/console app:database:dump --filename=antes_curriculos_${STAMP} ${ENV_ARGS} --no-interaction" | tail -2
remote "test -s var/backups/antes_curriculos_${STAMP}.sql.zip" || { echo "Backup não encontrado: nada foi alterado." >&2; exit 1; }

echo "== 3. Envio =="
remote "mkdir -p ${BATCH}"
if [ -n "${LOCAL}" ]; then
    cp "${FILES[@]}" "${SCRIPT_DIR}/${BATCH}/"
else
    rsync -az "${FILES[@]}" "${SSH_TARGET}:${PROD_APP_PATH}/${BATCH}/"
fi

echo "== 4. Importação =="
DEPT_ARG=""
[ -n "${DEPT}" ] && DEPT_ARG="--dept='${DEPT}'"
IMPORT_STATUS=0
remote "${PHP} -d memory_limit=1536M bin/console app:import:lattes --dir=${BATCH} ${DEPT_ARG} ${ENV_ARGS}" | tail -24 || IMPORT_STATUS=$?
# A importação refaz as orientações do Lattes, e com elas somem o link e o resumo vindos do Repositório UFSCar.
remote "if [ -f ${REPOSITORY_FILE} ]; then ${PHP} -d memory_limit=1G bin/console app:import:repository --file=${REPOSITORY_FILE} ${ENV_ARGS} | tail -3; else echo 'Sem ${REPOSITORY_FILE}: teses do Repositório não reaplicadas.'; fi"

echo "== 5. Periódicos, áreas e fila de nomes =="
remote "${PHP} -d memory_limit=1536M bin/console app:journals:pipeline --derived=docs/banco/derived ${ENV_ARGS} 2>&1 | tee var/audit/curriculos-${STAMP}.log | tail -32; exit \${PIPESTATUS[0]}"

echo "== 6. Temas e cache =="
remote "${PHP} -d memory_limit=1536M bin/console app:index:topics ${ENV_ARGS}" | tail -3
remote "${PHP} bin/console app:cache:clear ${ENV_ARGS}" | tail -1

echo "Concluído. Backup: var/backups/antes_curriculos_${STAMP}.sql.zip | Log: var/audit/curriculos-${STAMP}.log"
echo "Confira os nomes novos em /admin/journal-names."
if [ "${IMPORT_STATUS}" -ne 0 ]; then
    echo "Atenção: algum arquivo não foi importado. Veja a lista no passo 4; o resto seguiu normalmente." >&2
    exit 1
fi
