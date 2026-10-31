# Capacidade do servidor: quantos currículos o portal aguenta

Análise de 31/10/2026. Até 41,5 mil currículos os números são medidos; acima disso, são estimativas e estão marcados como tal.

## Resposta

| Pergunta | Resposta |
|---|---|
| Quantos currículos este servidor aguenta? | Cerca de **800** sem nada quebrar. Hoje são 416. Corrigindo o código, sem trocar de máquina, cerca de **5 mil**, com páginas lentas |
| Chega a 10 milhões? | **Não.** Nem este servidor nem um servidor maior com o código atual. Seriam de 4,4 a 5,6 TB, e cada abertura da página inicial leria dezenas de bilhões de linhas |
| Tem que mudar o banco? | **Não trocar o MySQL agora.** Ele não é o gargalo: abrir o perfil de um docente custa o mesmo com 415 ou 41,5 mil currículos. O problema são as consultas que varrem tudo. Em algum ponto entre 100 mil e 1 milhão, precisa de sistemas ao lado dele, para busca e para indicadores |
| Tem que pôr mais memória? | **Sozinha, não resolve.** Com 41,5 mil currículos, 16 GB para o MySQL reduziram o tempo em 13% a 40% e não consertaram nenhuma página que falhava. Mais memória e CPU só valem junto com as mudanças de código |

**O limite de 10 milhões não é só técnico.** Uma coluna de jornal sem data fala em [mais de 8 milhões de currículos](https://tribunademinas.com.br/colunas/maistendencias/?p=14663) em todo o Lattes, então a meta pode ser maior que a própria plataforma. A extração institucional, segundo a [UFRGS](https://www.ufrgs.br/saberviver/plataforma-lattes-o-que-e-e-como-participar/), cobre os currículos da própria instituição. Antes de investir em máquina, é preciso saber de onde esses XML viriam e se há base legal, pela LGPD, para guardar dados de milhões de pessoas.

## O servidor hoje

| Item | Valor |
|---|---|
| Máquina | Droplet da DigitalOcean: 1 vCPU, 2 GB de RAM, 2 GB de swap, 50 GB de SSD |
| Disco | 22 GB usados, 27 GB livres |
| Memória | cerca de 800 MB disponíveis, com 220 MB de swap em uso |
| Quem divide a máquina | `ufscar-cech`, `app-bibliomap` (2,2 GB em disco), `app-n8n` e um processo Node de 220 MB |
| Banco | MySQL 8.4.7, `innodb_buffer_pool_size` de 128 MB (o padrão), binlog com `sync_binlog=1` guardado por 30 dias |
| Dados do CECH | 416 currículos, 121.073 produções e 242.658 autores, depois da reimportação de 31/10/2026 |
| Limite de memória das páginas | 512 MB, em `public/index.php` |
| Tráfego | cerca de 16 mil requisições por mês, com pico de 1.944 por dia |

As páginas públicas ficam em cache por até 30 dias. Com cache, respondem em 4 a 10 ms em qualquer escala testada. Os tempos abaixo são do pior caso: a primeira abertura depois de limpar o cache. A `/busca` não usa esse cache: ela sempre consulta o banco.

## Como medimos

Montamos um MySQL separado no Mac, com as mesmas configurações do servidor, e multiplicamos os dados por 10 e por 100. Em cada escala, medimos páginas, importação e comandos.

**Para converter para o servidor,** comparamos os mesmos trabalhos nos dois lugares: a página inicial levou 2,16 s no servidor e 0,49 s no Mac; `/indicadores`, 1,52 s e 0,37 s; reimportar um currículo, cerca de 15 s e 3,4 s. Por isso, as estimativas de servidor usam **4 vezes** o tempo do Mac. É uma aproximação.

Resultados completos: [laboratorio-resultados.md](evolutions/capacidade/laboratorio-resultados.md) e [laboratorio-resultados.csv](evolutions/capacidade/laboratorio-resultados.csv).

### Tamanho

| Medida | ×1 | ×10 | ×100 |
|---|---:|---:|---:|
| Currículos | 415 | 4.150 | 41.500 |
| Produções | 76.652 | 766.520 | 7,67 milhões |
| Autores | 156.645 | 1,57 milhão | 15,7 milhões |
| Tabelas que crescem com os currículos | 181 MB | 1,85 GB | 18,1 GB |
| Por currículo | 0,44 MB | 0,44 MB | 0,44 MB |

Reimportar com o parser corrigido leva esse custo para cerca de 0,56 MB por currículo.

### Páginas sem cache, em segundos, no Mac

| Página | ×1 | ×10 | ×100 | ×100 com 1 GB | ×100 com 16 GB |
|---|---:|---:|---:|---:|---:|
| Início | 0,49 | 5,8 | 57,3 | 64,2 | 49,7 |
| `/indicadores` | 0,37 | 3,6 | 36,8 | 38,2 | 31,1 |
| Aba de produção | 0,28 | 4,1 | erro | erro | erro |
| Aba de rede de coautoria | 2,8 | erro | não termina | não termina | não termina |
| `/periodicos` | 0,38 | 3,75 | 41,7 | 46,2 | 30,3 |
| `/busca?q=educação` | 0,10 | 1,03 | 10,1 | 9,8 | 8,8 |
| `/temas?t=educacao` | 0,46 | 6,7 | 74,1 | 94,0 | 44,8 |
| `/departamento/PS` | 0,35 | erro | erro | erro | erro |
| `/sitemap.xml` | 0,04 | 0,32 | erro | erro | erro |
| Lista de docentes, página 1 | 0,05 | 0,06 | 0,12 | 0,12 | 0,12 |
| Perfil do maior docente, 2.015 produções | 0,35 | não medido | 0,34 | não medido | não medido |

O que escala bem: tudo o que busca por docente, como perfil e listas. O tempo fica igual, porque o MySQL usa índice. O que não escala: tudo o que soma o banco inteiro. Com ×100, a página inicial lê 141 milhões de linhas por requisição, e o tempo cresce 10 vezes a cada 10 vezes mais dados.

### Importação e comandos, no Mac

| Medida | ×1 | ×10 | ×100 |
|---|---:|---:|---:|
| Importar 1 XML mediano, sem a normalização | 1,4 s | 1,4 s | 3,6 s |
| `app:index:topics` | 8 s, 393 MB | 77 s, 1,8 GB | 27,6 min, 16,4 GB |
| `app:index:journals` | 3 s, 141 MB | 14 s, 201 MB | 128 s, 856 MB |
| `app:journals:families` | 9 s, 438 MB | 10 s, 451 MB | 18 s, 579 MB |

Com a normalização corrigida, reimportar os 414 XML levou 23,5 minutos no Mac (**3,4 s por currículo**, com pico de 187 MB) e cerca de 1h45 no servidor (**15 s por currículo**), com o site no ar.

## O que quebra primeiro neste servidor

Estimativas a partir das medidas. Currículos reais variam de tamanho.

| Currículos | O que acontece | Causa | Conserta com |
|---:|---|---|---|
| ~800 | A aba de rede de `/indicadores` dá erro 500 | Monta um HTML de 84 MB já com 416 currículos, leva 20 s no servidor e usa cerca de 260 MB, com limite de 512 MB | código |
| ~1.500 | `app:index:topics` deixa de caber na RAM livre e passa a usar swap | 393 MB hoje, 1,8 GB com ×10 | código ou memória |
| ~2.600 | `/departamento/PS` dá erro 500 | Usa 82 MB hoje, com limite de 512 MB | código |
| ~4.000 | Primeira abertura da página inicial leva cerca de 25 s | Soma tudo a cada abertura | código |
| ~5.000 | `app:index:topics` não cabe mais na RAM com swap | Memória | código ou memória |
| ~9.000 | `/sitemap.xml` dá erro 500 | Monta tudo em memória | código |
| ~10.000 | Primeira abertura da página inicial passa de 1 minuto; `/busca` leva cerca de 10 s | 1 vCPU e varredura | código e CPU |
| 20 a 30 mil | Perfil do docente dá erro 500 | O índice de docentes em cache é lido inteiro a cada página | código |
| ~34.000 | Acaba o disco, deixando 30% de folga para backups e binlog | 0,56 MB por currículo | disco |

**O que não conseguimos ver:** o uso de disco do MySQL em `/var/lib/mysql`, por falta de acesso. Os binlogs ficam 30 dias e crescem: `app:index:topics` gera 44 MB por execução hoje e 4,4 GB com ×100.

## Caminho por etapas

Preços: [lista de Droplets da DigitalOcean](https://www.digitalocean.com/pricing/droplets), consultada em 31/10/2026, sem contar backups nem disco extra. A página não traz o preço de volumes adicionais.

| Etapa | Currículos | O que muda | Máquina | Custo por mês |
|---|---:|---|---|---:|
| Hoje | até ~800 | nada | 2 GB, 1 vCPU | US$ 12 |
| 1 | até ~10 mil | Limitar a aba de rede aos maiores nós e guardar o resultado; paginar departamentos; gerar o sitemap sem carregar tudo; `app:index:topics` sem guardar tudo em memória; tirar n8n e Bibliomap da máquina | 8 GB, 4 vCPUs, 160 GB | US$ 48 |
| 2 | até ~100 mil (estimativa) | Tabelas de resumo para início, indicadores e `/periodicos`, atualizadas por comando; índice FULLTEXT para `/busca` e temas; importação em fila; MySQL em máquina própria | Banco com 32 GB, 8 vCPUs dedicadas, e aplicação com 8 GB; 56 GB de dados | US$ 300 |
| 3 | até ~1 milhão (estimativa) | Motor de busca (OpenSearch ou Meilisearch); indicadores em banco analítico (ClickHouse) ou resumos incrementais; réplica de leitura; 560 GB | 64 a 128 GB para o banco, mais máquinas de busca e aplicação | US$ 1.000 a 2.000, mais disco |
| 4 | 10 milhões (estimativa) | Tudo isso, com o banco particionado ou dividido em várias máquinas, importação paralela e backup próprio; 5,6 TB | Vários servidores | vários milhares |

A etapa 1 é sobretudo código. Só ela já multiplica a capacidade por 10.

**Importar 10 milhões** levaria cerca de 390 dias em um processo só, no Mac, e 4,7 anos neste servidor. Exigiria dezenas de processos em paralelo e uma revisão da normalização, que hoje faz milhares de consultas por currículo.

**O currículo médio pode ser menor.** Os 0,56 MB são de docentes do CECH, com mediana de 140 produções. Se a base incluir muitos currículos curtos, de estudantes por exemplo, o total em disco cai. Não medimos isso, e o custo das consultas que somam tudo cairia na mesma proporção, sem mudar a conclusão.

## Limites desta análise

- O Mac tem 32 CPUs e 64 GB, e o cache de arquivos do macOS não pôde ser esvaziado. Os tempos são otimistas, e o fator de 4 vezes é uma aproximação.
- Medimos uma requisição por vez. Concorrência não foi medida; com 1 vCPU, duas páginas pesadas ao mesmo tempo levam o dobro.
- Os clones repetem nomes e títulos. Isso favorece o índice de temas e esconde parte do custo do índice de docentes. Com nomes distintos, o perfil do docente falhou entre 20.750 e 31.125 currículos.
- As medidas de ×1 a ×100 usaram o código do commit `b108c09`, anterior às correções de hoje, e os dados de antes da reimportação. As correções não tocam nas consultas que varrem tudo, exceto `/busca` e o "Top revistas do tema", que ficaram com tempo parecido no local. A reimportação aumentou em cerca de 30% a 40% o tempo das páginas que somam tudo, no Mac. A linha da aba de rede e o disco já consideram isso; as outras linhas da tabela de limites foram estimadas antes e ficam proporcionalmente mais perto.
- O MySQL do laboratório é 8.0.40; o de produção é 8.4.7.
