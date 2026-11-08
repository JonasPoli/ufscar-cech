## Why

O code review de 07/11/2026 (código novo de projetos, busca de especialistas, coautorias, explorar, panorama e novidades, mais o que os agentes viram no resto do código ao escrever as specs) achou 21 defeitos. Cinco eram graves: importação por HTML em laço infinito, restauração de backup que anunciava sucesso sem restaurar, importação do Repositório alterando dados do Lattes, entidades HTML na tela e erro de JavaScript ao limpar a busca em `/temas`.

## What Changes

- Importação por HTML: os laços de integrantes e financiadores não reaproveitam mais o contador dos projetos; projetos sem título deixam de ser agrupados num só.
- Restauração de backup: `.zip` e `.gz` enviados são abertos pelo formato do nome original; comando SQL que falha faz a restauração ser informada como incompleta, não como sucesso.
- Importação do Repositório: `nature` e `course_name` do Lattes ficam intactos; o programa vai para `repository_program_name` (migration nova) e a orientação com Handle/UUID conta como concluída na exibição e nos indicadores. A correspondência por nome contido no outro exige palavras inteiras, nunca usa orientação com outro Handle e é processada depois das correspondências exatas.
- Busca, Explorar, coautorias e API: textos do Lattes com entidades HTML são decodificados na exibição.
- `/temas`: apagar a busca volta à nuvem de temas em destaque.
- `/coautorias?ligacoes=<inválido>` volta a 120 ligações.
- Cache: texto livre não vai mais para o cache da pesquisa; parâmetros desconhecidos e filtros com valor inexistente (área, departamento) não geram arquivo no cache de páginas.
- Admin: índice de instituições refeito depois de cada alteração; uploads de imagem (SEO, logotipo de base) aceitam só imagens, pelo conteúdo, e os formulários exigem token CSRF; salvar SEO limpa o cache de páginas; excluir um periódico tira o selo Qualis e as bases dos artigos; nome de usuário repetido mostra mensagem em vez de erro 500.
- Busca no modo Teses e orientações aplica tema, departamento, grande área, área, vínculo e período.
- Menores: total real de publicações em comum de um par; grandes áreas contadas uma vez com as duas grafias; exportação da aba Redes baixa uma vez; "Ver todas" da capa leva à lista de publicações recentes; egressos e séries anuais usam o ano corrente; `app:cache:clear --all` limpa o `cache.app`; `/departamento/CS` inclui os docentes gravados como `DCSo`.

## Capabilities

### New Capabilities

(nenhuma)

### Modified Capabilities

- `repository-import`: dados do Lattes intactos e correspondência mais segura.
- `expert-search`: filtros no modo Teses e orientações.
- `page-cache`: elegibilidade, `--all` e limpezas automáticas.
- `database-backup`: formato do upload e restauração incompleta.
- `coauthorship-network`: total de publicações de um par.
- `explore-catalog`: contagem das grandes áreas.
- `indicators`: egressos pelo ano corrente.
- `department-page`: grafias equivalentes do departamento.
- `portal-home`: link "Ver todas".
- `seo`: CSRF, tipo da imagem e limpeza do cache.
- `academic-databases`: CSRF e tipo do logotipo; exclusão de periódico.
- `admin-access`: nome de usuário repetido.
- `research-thesauri`: índice de instituições após a curadoria.
- `thematic-search`: limpar a busca.
- `projects-catalog`: projetos sem título.

## Impact

- Código: `src/Service/Import`, `src/Service/Backup`, `src/Service/Research`, `src/Service/PageCacheService.php`, controllers do admin e públicos, templates de busca, `/temas`, Indicadores e capa.
- Banco: migration `Version20261108030454` (coluna `orientations.repository_program_name`).
- Produção: depois do deploy, rodar `app:index:projects` (recalcula as chaves dos projetos sem título) e `app:cache:clear --all`.
