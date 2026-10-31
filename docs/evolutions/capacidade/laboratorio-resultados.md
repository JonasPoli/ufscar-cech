# Resultados da medição de capacidade (portal CECH/UFSCar)

Gerado de results.csv (todas as linhas brutas estão lá). Valores ausentes aparecem como "não medido".

## Ambiente e desvios do plano

- Código medido: commit b108c09 (`git archive HEAD`). MainController, ProductionItemRepository e IndexJournalsCommand mudaram no working tree durante a medição e NÃO foram usados.
- MySQL do laboratório 8.0.40 (produção: 8.4.7), PHP 8.4.19, Mac Intel de 32 CPUs lógicas e 64 GB, macOS 14.6.1. Parâmetros do mysqld como no plano (buffer pool 128 MB, redo 100 MB, sync_binlog=1, flush_log_at_trx_commit=2, tmp/heap 16 MB, sort/join 256 KB); 1 GB e 16 GB só na bateria (e).
- Desvios: (1) socket em /private/tmp/claude-501/cechlab.sock, porque o caminho de $LAB passa de 103 caracteres (todos os clientes usam TCP 33077); (2) `innodb_buffer_pool_load_at_startup=OFF` e `dump_at_shutdown=OFF`, senão a reinicialização recarregaria o cache; (3) o cache de arquivos do macOS não pôde ser esvaziado (`purge` exige sudo): leituras que faltam no buffer pool vêm da RAM, então os tempos são otimistas frente a um VPS pequeno; (4) servidor web `php -S` com 1 processo e `-d variables_order=EGPCS` (sem isso o PHP embutido ignora APP_ENV=prod); o limite de memória efetivo da web é 512 MB, porque public/index.php chama ini_set('memory_limit','512M'); (5) o cache de páginas ficou ligado e var/cache/public_pages foi apagado antes de cada tentativa (`?cold=N` onde a rota aceita parâmetro extra; /periodicos redireciona parâmetros extras, então ali não se usou), e var/cache/prod/pools/app foi apagado antes de cada tentativa das rotas /temas; (6) página cuja 1ª tentativa passa de 120 s mede 1 vez (só a aba network de /indicadores); (7) as tabelas temáticas foram clonadas a partir do base e depois reconstruídas por `app:index:topics` antes de cada bateria de páginas; (8) a importação reimporta o currículo k=0, que fica diferente dos clones (ver tabela do perfil); (9) os clones repetem nomes (id_lattes e slug ganharam prefixo/sufixo); o extra de nomes distintos mede o efeito disso; (10) lista de professores: não existe rota própria, usei /busca?dept=all&type=researchers (e /departamento/PS); (11) a vigia de memória/disco dos comandos (28 GB de RSS, 12% de memória livre, 25 GB de disco) nunca disparou.
- Fator x1 = 415 currículos, 76.652 produções, 156.645 autores; x10 = 4.150 / 766.520 / 1.566.450; x100 = 41.500 / 7.665.200 / 15.664.500. Clonagem de x10 a x100: 520 s (INSERT..SELECT, bp 8 GB, sem binlog).


## (a) Tamanho por escala

| Medida | x1 | x10 | x100 |
|---|---|---|---|
| Currículos | 415 | 4,150 | 41,500 |
| Produções | 76,652 | 766,520 | 7,665,200 |
| Autores | 156,645 | 1,566,450 | 15,664,500 |
| Tabelas proporcionais (MB, dados+índices) | 181 | 1,846 | 18,085 |
| Banco cech inteiro (MB, dados+índices) | 410 | 2,096 | 18,335 |
| MB por currículo (proporcionais) | 0.436 | 0.445 | 0.436 |
| du datadir (MB, sem cech_base) | 784 | 2,677 | 19,576 |
| du datadir/cech (MB) | 589 | 2,348 | 18,783 |

Tamanho por tabela (MB, dados+índices):

| Tabela | x1 | x10 | x100 |
|---|---|---|---|
| thematic_term_researchers | 61 | 825 | 8,226 |
| production_items | 38 | 335 | 3,269 |
| production_authors | 27 | 240 | 2,357 |
| examination_boards | 14 | 117 | 1,143 |
| orientations | 13 | 98 | 930 |
| research_projects | 9 | 85 | 823 |
| event_participations | 10 | 75 | 728 |
| professional_experiences | 5 | 35 | 315 |
| educations | 2 | 15 | 126 |
| researchers | 2 | 10 | 89 |
| journal_area_evidence | 86 | 86 | 86 |
| thematic_terms | 30 | 51 | 51 |
| qualis_journals | 48 | 48 | 48 |
| knowledge_areas | 0 | 6 | 32 |
| awards | 0 | 3 | 32 |
| journal_name_variants | 20 | 20 | 20 |
| journal_areas | 20 | 20 | 20 |
| language_proficiencies | 0 | 2 | 16 |
| qualis_journal_academic_database | 10 | 10 | 10 |
| author_name_variants | 6 | 6 | 6 |

## (b) Páginas, cache frio de página, mediana de 3 (s)

| Página | x1 bp 128MB | x10 bp 128MB | x100 bp 128MB | x100 bp 1GB | x100 bp 16GB |
|---|---|---|---|---|---|
| home | 0.49 | 5.79 | 57.25 | 64.23 | 49.70 |
| indicadores | 0.37 | 3.64 | 36.84 | 38.16 | 31.09 |
| indicadores_aba_training | 0.05 | 0.44 | 5.08 | 6.01 | 3.69 |
| indicadores_aba_production | 0.28 | 4.10 | erro_http_500 | erro_http_500 | erro_http_500 |
| indicadores_aba_network | 2.82 | erro_http_500 | timeout | timeout | timeout |
| periodicos | 0.38 | 3.75 | 41.74 | 46.16 | 30.34 |
| periodicos_scopus | 0.20 | 1.00 | 12.04 | 15.06 | 9.16 |
| periodicos_area_educacao | 0.38 | 3.49 | 42.21 | 44.48 | 29.01 |
| lista_prof_pag1 | 0.05 | 0.06 | 0.12 | 0.12 | 0.12 |
| lista_prof_pag_alta | 0.10 | 0.29 | 0.41 | 0.41 | 0.41 |
| perfil_pesado | 0.35 | 2.22 | 2.24 | 2.26 | 2.58 |
| perfil_pesado_aba_analytics | 0.16 | 3.75 | 3.80 | 3.92 | 4.24 |
| perfil_mediano | 0.11 | 1.21 | 1.28 | 1.22 | 1.35 |
| busca_educacao | 0.10 | 1.03 | 10.12 | 9.81 | 8.82 |
| temas | 0.05 | 0.08 | 0.10 | 0.08 | 0.08 |
| temas_termo_educacao | 0.46 | 6.71 | 74.13 | 93.97 | 44.76 |
| temas_autocomplete | 0.04 | 0.07 | 0.07 | 0.07 | 0.07 |
| departamentos | 0.03 | 0.23 | 2.81 | 2.27 | 2.26 |
| departamento_PS | 0.35 | erro_http_500 | erro_http_500 | erro_http_500 | erro_http_500 |
| sitemap | 0.04 | 0.32 | erro_http_500 | erro_http_500 | erro_http_500 |

### Escalabilidade das páginas (bp 128 MB): razão de tempo entre escalas

| Página | x10/x1 | x100/x10 | x100/x1 | Classe (fator de dados 10 por salto) |
|---|---|---|---|---|
| home | 11.8 | 9.9 | 117 | linear (2x a 14x) |
| indicadores | 9.8 | 10.1 | 100 | linear (2x a 14x) |
| indicadores_aba_training | 8.8 | 11.5 | 102 | linear (2x a 14x) |
| indicadores_aba_production | 14.6 | n/d | n/d | QUEBROU (erro_http_500) |
| indicadores_aba_network | n/d | n/d | n/d | QUEBROU (timeout) |
| periodicos | 9.9 | 11.1 | 110 | linear (2x a 14x) |
| periodicos_scopus | 5.0 | 12.0 | 60 | linear (2x a 14x) |
| periodicos_area_educacao | 9.2 | 12.1 | 111 | linear (2x a 14x) |
| lista_prof_pag1 | 1.2 | 2.0 | 2 | plano (<2,5x por salto) |
| lista_prof_pag_alta | 2.9 | 1.4 | 4 | plano (<2,5x por salto) |
| perfil_pesado | 6.3 | 1.0 | 6 | plano (<2,5x por salto) |
| perfil_pesado_aba_analytics | 23.4 | 1.0 | 24 | plano (<2,5x por salto) |
| perfil_mediano | 11.0 | 1.1 | 12 | plano (<2,5x por salto) |
| busca_educacao | 10.3 | 9.8 | 101 | linear (2x a 14x) |
| temas | 1.6 | 1.2 | 2 | plano (<2,5x por salto) |
| temas_termo_educacao | 14.6 | 11.0 | 161 | linear (2x a 14x) |
| temas_autocomplete | 1.8 | 1.0 | 2 | plano (<2,5x por salto) |
| departamentos | 7.7 | 12.2 | 94 | linear (2x a 14x) |
| departamento_PS | n/d | n/d | n/d | QUEBROU (erro_http_500) |
| sitemap | 8.0 | n/d | n/d | QUEBROU (erro_http_500) |

Nota: em perfil_*, x1 mede dados intactos, enquanto x10 e x100 medem o currículo k=0 reimportado (ver a tabela do perfil mais abaixo: o clone intacto de x100 dá 0,34 s).

### Linhas lidas pelo InnoDB por requisição (mediana) e pico de memória do PHP

| Página | linhas x1 | linhas x10 | linhas x100 | pico PHP x1 (MB) | x10 | x100 |
|---|---|---|---|---|---|---|
| home | 1,342,112 | 14,125,209 | 141,342,481 | 4 | 4 | 4 |
| indicadores | 362,453 | 4,329,078 | 43,897,518 | 25 | 46 | 295 |
| indicadores_aba_training | 74,596 | 745,951 | 7,459,501 | 5 | 6 | 24 |
| indicadores_aba_production | 272,671 | 2,635,564 | n/d | 12 | 98 | n/d |
| indicadores_aba_network | 1,636,201 | n/d | n/d | 227 | n/d | n/d |
| periodicos | 531,371 | 3,044,836 | 27,029,476 | 5 | 19 | 4 |
| periodicos_scopus | 381,424 | 1,058,532 | 8,534,755 | 6 | 6 | 6 |
| periodicos_area_educacao | 551,055 | 3,070,152 | 27,054,794 | 4 | 4 | 4 |
| lista_prof_pag1 | 3,499 | 14,236 | 126,646 | 12 | 10 | 12 |
| lista_prof_pag_alta | 6,532 | 28,491 | 177,891 | 24 | 54 | 54 |
| perfil_pesado | 84,865 | 3,454,856 | 3,454,856 | 50 | 59 | 70 |
| perfil_pesado_aba_analytics | 6,063 | 6,523,697 | 6,523,697 | 30 | 24 | 24 |
| perfil_mediano | 75,890 | 2,046,943 | 2,046,943 | 28 | 32 | 32 |
| busca_educacao | 105,834 | 1,054,938 | 10,545,970 | 8 | 10 | 10 |
| temas | 261,468 | 451,756 | 451,756 | 3 | 3 | 3 |
| temas_termo_educacao | 564,810 | 3,485,060 | 30,803,470 | 6 | 14 | 104 |
| temas_autocomplete | 130,733 | 225,877 | 225,877 | 2 | 4 | 20 |
| departamentos | 69,919 | 699,181 | 6,991,801 | 2 | 2 | 2 |
| departamento_PS | 35,446 | n/d | n/d | 82 | n/d | n/d |
| sitemap | 70,333 | 703,330 | n/d | 22 | 238 | n/d |

## (c) Importação de currículo (app:import:lattes --file, substituição)

| Execução | x1 s | x1 RSS MB | x10 s | x10 RSS MB | x100 s | x100 RSS MB |
|---|---|---|---|---|---|---|
| XML pesado (2015 produções), 1ª | 2.94 | 163 | 3.41 | 164 | 5.86 | 237 |
| XML pesado, 2ª | 1.54 | 134 | 1.81 | 139 | 3.89 | 231 |
| XML mediano (140 produções) | 1.36 | 101 | 1.41 | 105 | 3.64 | 180 |

## (d) Comandos que varrem a base (limite 30 min; memory_limit passado 1536M)

| Comando | x1 s | x1 RSS MB | x10 s | x10 RSS MB | x100 s | x100 RSS MB | status x100 |
|---|---|---|---|---|---|---|---|
| topics | 8.4 | 393 | 76.9 | 1837 | 1656.1 | 16420 | ok |
| keywords | 0.2 | 62 | 0.2 | 62 | 0.4 | 62 | ok |
| index | 3.0 | 141 | 14.2 | 201 | 127.6 | 856 | ok |
| families | 9.2 | 438 | 9.8 | 451 | 18.1 | 579 | ok |
| report | 0.6 | 72 | 1.7 | 72 | 12.2 | 72 | ok |

### Perfil de professor: dados intactos x currículo reimportado (bp 128 MB, mediana de 3, s)

| Rota | x1 (dump intacto) | x100, clone intacto | x10, k=0 reimportado | x100, k=0 reimportado |
|---|---|---|---|---|
| perfil pesado (2015 produções) | 0.35 | 0.34 | 2.22 | 2.24 |
| aba analytics do pesado | 0.16 | 0.17 | 3.75 | 3.80 |
| perfil mediano (140 produções) | 0.11 | 0.09 | 1.21 | 1.28 |

Causa do currículo reimportado ser mais lento: depois de app:import:lattes os autores ficam sem author_identity_id/matched_researcher_id (0 de 2474; intacto: 2474 e 2029) e os 440 artigos sem qualis_journal_id (intacto: 251). A página então faz dezenas de buscas em author_name_variants por normalized_name, coluna sem índice (varredura de ~38 mil linhas por busca; 78 buscas e 3,4 milhões de linhas lidas por perfil).

### Efeito do buffer pool em x100 (mediana de 3, s)

| Página | 128 MB | 1 GB | 16 GB | 16 GB / 128 MB |
|---|---|---|---|---|
| home | 57.25 | 64.23 | 49.70 | 0.87 |
| indicadores | 36.84 | 38.16 | 31.09 | 0.84 |
| indicadores_aba_training | 5.08 | 6.01 | 3.69 | 0.73 |
| indicadores_aba_production | erro_http_500 | erro_http_500 | erro_http_500 | n/d |
| indicadores_aba_network | timeout | timeout | timeout | n/d |
| periodicos | 41.74 | 46.16 | 30.34 | 0.73 |
| periodicos_scopus | 12.04 | 15.06 | 9.16 | 0.76 |
| periodicos_area_educacao | 42.21 | 44.48 | 29.01 | 0.69 |
| lista_prof_pag1 | 0.12 | 0.12 | 0.12 | 1.00 |
| lista_prof_pag_alta | 0.41 | 0.41 | 0.41 | 1.00 |
| perfil_pesado | 2.24 | 2.26 | 2.58 | 1.15 |
| perfil_pesado_aba_analytics | 3.80 | 3.92 | 4.24 | 1.12 |
| perfil_mediano | 1.28 | 1.22 | 1.35 | 1.05 |
| busca_educacao | 10.12 | 9.81 | 8.82 | 0.87 |
| temas | 0.10 | 0.08 | 0.08 | 0.80 |
| temas_termo_educacao | 74.13 | 93.97 | 44.76 | 0.60 |
| temas_autocomplete | 0.07 | 0.07 | 0.07 | 1.00 |
| departamentos | 2.81 | 2.27 | 2.26 | 0.80 |
| departamento_PS | erro_http_500 | erro_http_500 | erro_http_500 | n/d |
| sitemap | erro_http_500 | erro_http_500 | erro_http_500 | n/d |

## Extra: pesquisadores com nomes distintos (o índice de autores do CECH é um cache chaveado por nome)

Os clones repetem os nomes, então esse índice não cresce com a escala. Para medir o efeito de nomes distintos, os nomes dos clones foram tornados únicos e o perfil de professor foi pedido duas vezes (1ª constrói o índice, 2ª lê do cache em disco).

| Pesquisadores com nome distinto | HTTP da 2ª requisição | Cache do índice (MB) | Pico de memória do PHP (MB) | Tempo 2ª requisição (s) |
|---|---|---|---|---|
| 4150 | 200 | 26.7 | 103 | 2.243747 |
| 10375 | 200 | 60.1 | 211 | 2.296042 |
| 20750 | 200 | 116.0 | 405 | 2.746451 |
| 31125 | 500 | 172.3 | 513 | 0.470876 |
| 41.500 (todos os clones distintos) | 500 já na 1ª requisição | não gravou (tentou alocar 234 MB) | > 512 | n/d |

## (e) As 5 consultas mais lentas em x100 (bp 128 MB) e EXPLAIN

Fonte: slow log (long_query_time=1) e performance_schema; EXPLAIN rodado no banco x100 (arquivos em results/explain). A consulta nº 1 foi interrompida aos 300 s e por isso não entra no slow log: o texto vem do slow log de x10 e o tempo do performance_schema. Arquivos: nº 1 em results/explain-network/explain-q1.txt; nºs 2 a 5 em results/explain/explain-q1 a q4.txt (q5 a q8 são as seguintes do slow log).

| # | Consulta (rota) | Tempo máx. | Tabela / acesso / linhas estimadas | Agregação sobre tudo? |
|---|---|---|---|---|
| 1 | Rede de coautoria: autojunção de production_authors por production_item_id + GROUP BY dos pares + COUNT(DISTINCT) (aba network de /indicadores) | não terminou em 300 s (42,9 mi de linhas examinadas até a interrupção) | production_authors pa1: ALL, 14,7 mi de linhas; Using temporary; Using filesort; pa2 ref (2 por item); r1, r2 e pi eq_ref | Sim: todos os autores com match |
| 2 | Linha do tempo do termo: LIKE '%termo%' em title e em CAST(extra_data AS CHAR), UNION com orientations (/temas?t=) | 44,1 s (soma 128,5 s em 3) | production_items: index em idx_prod_year, 7,46 mi; orientations: index, 2,67 mi; tabela temporária + filesort | Sim: varre produções e orientações inteiras por termo |
| 3 | Pesquisadores em destaque: researchers LEFT JOIN produções A1 LEFT JOIN todas as produções, GROUP BY r.id (home) | 23,1 s (89,5 mi de linhas examinadas) | researchers: index PK, ~33,7 mil; p1_ ref 158; p2_ ref 158 (Using index); Using temporary; Using filesort | Sim: produto A1 x todas por pesquisador |
| 4 | Produções únicas: SELECT COUNT(*) FROM (SELECT 1 FROM production_items GROUP BY CASE doi/título) (home e indicadores) | 21,0 s (6 execuções, 119,9 s) | production_items: ALL, 7,46 mi; Using temporary (tmp de 16 MB vai para disco) | Sim |
| 5 | Análise editorial do termo: SELECT journal_name, MAX(qualis), COUNT(DISTINCT título) ... LIKE '%termo%' ... GROUP BY journal_name (/temas?t=) | 13,3 s (soma 39,2 s em 3) | production_items: ALL, 7,46 mi, filtered 81%; Using temporary; Using filesort | Sim |

Maior tempo acumulado: a consulta de /periodicos (famílias de periódicos com COUNT(DISTINCT) sobre artigos; 33 execuções, 247 s, máx. 9,1 s): production_items ref em idx_prod_type='ARTIGO', 2,73 mi de linhas, Using temporary; Using filesort. A busca (/busca?q=) usa LIKE '%termo%' em production_items (ALL, 7,46 mi de linhas; 7,8 s só a contagem).

## Classificação

- Escala linearmente: tamanho em disco (0,436 a 0,445 MB por currículo nas tabelas proporcionais; x100 = 18,1 GB, banco inteiro 18,3 GB); tempo e linhas lidas de home, /indicadores, aba training, /periodicos (3 variantes), /busca?q=, /temas?t=, /departamentos (razão de 7,7 a 12,2 por salto de 10x); vínculos temáticos reconstruídos (3,40 mi em x10 e 34,0 mi em x100, exatamente 10x).
- Escala pior que linear: app:index:topics (8,4 s e 393 MB em x1; 77 s e 1,8 GB em x10; 1.656 s e 16,4 GB em x100), porque o filtro de termos únicos descarta menos quando os dados se repetem (de x1 a x10 os vínculos foram 13,9x e os termos passaram de 130.733 para 225.877); aba production de /indicadores (14,6x de x1 a x10, quebra em x100); /temas?t= de x1 a x10 (14,6x).
- Plano ou quase plano: app:sync:keywords por XML (0,2 s em x1 e x10, 0,4 s em x100), lista de professores (0,05 a 0,12 s; página alta 0,10 a 0,41 s), perfil com dados intactos (0,35 s em x1 e 0,34 s em x100), /temas e autocomplete (0,04 a 0,10 s), importação de um currículo (2,9 s em x1, 3,4 s em x10, 5,9 s em x100; 2ª execução 1,5, 1,8 e 3,9 s), acerto do cache de páginas (4 a 10 ms; 53 ms para o HTML de 52 MB de /indicadores).
- Quebrou: aba network de /indicadores (x10: HTTP 500, "Allowed memory size of 536870912 bytes exhausted (tried to allocate 490749952 bytes)" ao montar o HTML, que em x1 já tem 72 MB; x100: 300 s sem resposta, também com 1 GB e 16 GB); aba production (x100: HTTP 500); /departamento/PS (x10 e x100: HTTP 500); /sitemap.xml (x100: HTTP 500); app:index:journals usa 856 MB de RSS dos 1024 MB que o próprio comando fixa (extrapolação, não medida: estoura perto de x120); app:index:topics levou 27,6 min dos 30 permitidos e 16,4 GB; com nomes distintos, o perfil de professor dá HTTP 500 entre 20.750 e 31.125 pesquisadores (índice de autores de 116 MB e 172 MB em cache; pico do PHP 405 e 513 MB).
- Buffer pool em x100: 1 GB não ajudou (0,8x a 1,3x do tempo de 128 MB, dentro do ruído); 16 GB reduziu de 13% a 40% o tempo das páginas que varrem tabelas (home 57,3 -> 49,7 s; /periodicos 41,7 -> 30,3 s; /temas?t= 74,1 -> 44,8 s) e não consertou nenhuma que falhava. O tempo é de CPU e varredura, não de disco: o cache de arquivos do macOS já mantinha tudo em RAM.
- Extrapolação linear do tamanho (não medida): 10 milhões de currículos (24.096 vezes o x1) pediria cerca de 4,4 TB só nas tabelas proporcionais.

