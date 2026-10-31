# Arquitetura para crescer: da UFSCar a todo o Lattes

Proposta de 31/10/2026. Nada disto foi construído. As medidas do portal estão em [CAPACIDADE_DO_SERVIDOR.md](CAPACIDADE_DO_SERVIDOR.md). Acima de 41,5 mil currículos, tudo é estimativa e deve ser recalculado a cada etapa.

## Resumo

| Etapa | Currículos | Banco | Arquitetura | Custo por mês |
|---|---:|---:|---|---:|
| Hoje | 416 | 0,23 GB | Um servidor para tudo | US$ 12 |
| 1. Docentes da UFSCar | 1.300 a 3 mil | 1 a 2 GB | Um servidor, com o código corrigido | US$ 58 |
| 2. UFSCar e ESOCITE.BR | de 2 mil a 8 mil (estimativa) | 1 a 5 GB | Um servidor, com indicadores pré-calculados e redes de pesquisadores | de US$ 63 a US$ 120 |
| 3a. Lattes, passo de 100 mil | 100 mil | 56 GB | Banco em máquina própria, busca separada, importação em fila | US$ 390 |
| 3b. Lattes, passo de 1 milhão | 1 milhão | até 560 GB | Réplica do banco, banco analítico, duas máquinas de aplicação | US$ 2.150 |
| 3c. Todo o Lattes | 10 milhões | até ~1 TB; planejar 2 TB | A mesma do passo anterior, com máquinas maiores | US$ 4.200, mais a carga inicial |

- **Banco de dados:** o MySQL continua como banco principal em todas as etapas. O que muda é o que sai dele: a busca textual vai para o OpenSearch, e os indicadores, para tabelas de resumo e depois para o ClickHouse.
- **Trabalho:** as etapas 1 e 2 são sobretudo código. Máquina maior sem essas mudanças não resolve: 16 GB para o MySQL não consertaram nenhuma página que falhava.
- **ESOCITE.BR:** é uma associação científica, não uma instituição. Ela muda pouco a máquina, mas muda o modelo de dados e a forma de obter os XML. O tamanho é estimativa nossa, porque a associação não publica o número de associados.
- **Maior risco da etapa 3:** obter os XML. Isso depende do CNPq, não de máquina (ver "Riscos").

## Quanto dado são 10 milhões de currículos

A análise anterior falava em 4,4 a 5,6 TB, porque multiplicava o currículo de um docente do CECH por 10 milhões. **Isso exagerava.** A maioria dos currículos do Lattes é muito menor. Há três indícios:

| Indício | Valor | Currículo médio em relação ao do CECH |
|---|---:|---:|
| Currículos baixados em 2011: [1.236.548 em pouco mais de 16 GB de HTML](https://sol.sbc.org.br/index.php/brasnam/article/download/6864/6757) | 13 KB por currículo; os XML do CECH têm cerca de 900 KB | ~1,5% |
| Mesmo estudo: 176.114 pesquisadores de Ciências Exatas e da Terra com 3.724.536 publicações de 1990 a 2010 | 21 publicações por pessoa; no CECH, 125 dos mesmos tipos | ~17% |
| [Em 2015, mais de 220 mil doutores em mais de 4 milhões de currículos](https://revistapesquisa.fapesp.br/en/valuable-records/) | cerca de 5,5% de doutores; seriam ~550 mil em 10 milhões | ver conta abaixo |

**Estimativa:**

- 550 mil doutores × 0,56 MB = cerca de 310 GB.
- Os outros 9,45 milhões, com 20 a 60 KB cada = de 190 a 570 GB.
- **Total: de 0,5 a 0,9 TB. Até ~1 TB como teto provável; 2 TB para planejar**, com crescimento, binlog e folga.
- Linhas: de 250 a 450 milhões de produções e de 500 a 900 milhões de autores.

**Limites da estimativa:**

- Os estudos são antigos, e os currículos cresceram desde então.
- O CECH é de Humanas. Saúde e Exatas têm mais coautores por artigo, o que aumenta `production_authors`. A importação da UFSCar inteira vai medir isso.

## Que banco usar

| Papel | Tecnologia | A partir de | Por quê |
|---|---|---|---|
| Banco principal: currículos, produções, tesauros, administração | **MySQL (InnoDB)** | sempre | Os dados são relacionais, e o sistema já usa esse banco. Consultas por índice não pioram: o perfil levou 0,35 s com 415 currículos e 0,34 s com 41,5 mil |
| Busca textual: `/busca`, `/temas`, autocompletar | **FULLTEXT** do MySQL; depois **OpenSearch** | etapa 2; OpenSearch no passo de 100 mil | `LIKE '%termo%'` lê a tabela inteira e já leva 10 s com 41,5 mil currículos. O FULLTEXT não exige sistema novo, mas não foi medido aqui. O OpenSearch tem versão gerenciada na DigitalOcean |
| Indicadores e somas: início, `/indicadores`, `/periodicos` | **Tabelas de resumo** no MySQL; depois **ClickHouse** | etapa 2; ClickHouse perto de 1 milhão | A página inicial lê 141 milhões de linhas por abertura com 41,5 mil currículos. Tabela de resumo responde na hora. Recalcular os resumos sobre bilhões de linhas é o trabalho para o qual o ClickHouse existe |
| XML originais | **Spaces** | etapa 2 | Preserva o dado do Lattes e permite reprocessar sem baixar de novo |
| Cache de páginas | **Nginx com limite de tamanho** | passo de 100 mil | O cache atual guarda cada página por 30 dias e é limpo por inteiro. Não comporta milhões de perfis |
| Fila de importação | **Symfony Messenger**, já configurado; depois Valkey | passo de 100 mil; Valkey perto de 1 milhão | Evita um processo único e permite retomar falhas |

**O que não vale a pena:**

- **PostgreSQL:** não resolve o que hoje falha, porque as somas continuariam lentas e a busca continuaria fora do banco. A migração custaria semanas de reescrita de SQL.
- **MongoDB ou outro NoSQL como banco principal:** os dados têm muitas relações (docente, produção, autor, revista, tema), e as junções passariam para o código.
- **Banco de grafos para coautoria:** uma tabela pronta de pares de coautores responde ao que a página precisa.
- **Dividir o MySQL em várias máquinas (Vitess):** só se os dados passarem de ~5 TB ou as escritas saturarem o servidor. Com a estimativa acima, não deve ser necessário.

## Até quanto o MySQL aguenta

| Limite | Valor | Origem |
|---|---|---|
| Do próprio InnoDB | [64 TB por tabela](https://dev.mysql.com/doc/refman/8.4/en/innodb-limits.html), com a página padrão de 16 KB. No ext4, 16 TiB por arquivo | Manual do MySQL |
| Numa máquina da DigitalOcean | 4,69 TB de NVMe local e 256 GiB de RAM nos planos listados; ou volumes de até 16 TiB, com [10 mil IOPS, 15 mil em pico](https://docs.digitalocean.com/products/volumes/details/limits/) | DigitalOcean |
| Busca por índice (perfil, listas) | O tempo não mudou de 415 para 41,5 mil currículos | Medido |
| Somas e `LIKE` com o código atual | Começam a falhar ou passar de 1 minuto entre 800 e 10 mil currículos | Medido e convertido para o servidor |
| Chaves `INT` usadas hoje | 2,1 bilhões de ids. **Este limite chega antes dos outros** (ver abaixo) | Esquema de produção |
| Na prática, numa máquina só | De 2 a 5 TB, se nenhuma página somar a base inteira | Avaliação nossa, não medida |

**Conclusão:** o MySQL aguenta os 10 milhões como banco principal, porque a estimativa cabe numa máquina só. Ele não aguenta, em nenhuma etapa grande, ser também o motor de busca e de indicadores.

**As chaves `INT` precisam mudar logo.** A importação apaga e regrava os filhos de cada currículo, e cada regravação gasta ids novos. Em produção, `production_authors` tem 242.658 linhas e já chegou ao id 1.020.015, ou 4,2 vezes o número de linhas. Com 500 a 900 milhões de autores, a mesma proporção daria de 2,1 a 3,8 bilhões. Trocar para `BIGINT` agora leva minutos; numa tabela de centenas de milhões de linhas, leva horas ou dias.

## Arquitetura da etapa 3

```text
Visitante
  → Load Balancer
  → 2 ou mais máquinas Symfony, com cache Nginx de tamanho limitado
       ├─ MySQL principal ──réplica──→ MySQL réplica ──backup──→ Spaces
       ├─ OpenSearch, 3 nós: busca
       ├─ ClickHouse: origem dos indicadores
       └─ Valkey: cache e fila

Importação
  XML do CNPq → Spaces → fila → máquinas temporárias → MySQL
  MySQL → OpenSearch e ClickHouse → tabelas de resumo no MySQL
```

As etapas anteriores são versões menores deste desenho, em que várias caixas ainda ficam na mesma máquina.

## Uma máquina só ou várias

"Dividir" pode significar duas coisas:

- **Separar por função:** aplicação, banco, busca e indicadores em máquinas diferentes. É o que este plano faz nas faixas maiores.
- **Repartir os mesmos dados entre vários MySQL (Vitess):** não deve ser necessário, porque a estimativa cabe em um servidor.

Pelas estimativas, uma máquina só comportaria até os 10 milhões, com disco e memória suficientes. Concorrência não foi medida.

| Faixa | Máquina única | Custo | Plano separado | Decisão |
|---:|---|---:|---:|---|
| até 10 mil | Basic, 16 GiB, 8 vCPU | US$ 96 | a mesma máquina: US$ 120, com backup e Spaces | Uma máquina basta |
| até 100 mil | Storage-Optimized, 64 GiB, 8 vCPU, 1,17 TB | US$ 524 | US$ 390 | Separar: é mais barato e a importação não disputa recursos com o site |
| até 1 milhão | Storage-Optimized, 128 GiB, 16 vCPU, 2,34 TB | US$ 1.048 | US$ 2.150 | Separar |
| 10 milhões | Storage-Optimized, 256 GiB, 32 vCPU, 4,69 TB | US$ 2.096 | US$ 4.200 | Separar |

Os custos de máquina única não incluem backup nem Spaces.

**Por que não usar uma máquina só nas faixas grandes:**

- **Falha:** se a máquina cair, sai tudo do ar, e o disco local pode se perder. Restaurar 1 TB ou mais e refazer a busca levaria horas, talvez mais de um dia (estimativa).
- **Disputa de recursos:** importação e recálculo de indicadores usam muita CPU e disco, ao mesmo tempo em que o site atende os visitantes. Com 32 vCPU para tudo, os picos ficam apertados.
- **Manutenção:** atualizar qualquer parte pode derrubar as outras.

**A economia some ao incluir a cópia.** O mínimo seguro são duas máquinas, a principal e uma réplica. Duas de 256 GiB custam US$ 4.192, quase o mesmo do plano separado (US$ 4.196), que ainda isola as funções e cresce por partes.

## O que muda no sistema

### Etapa 1: docentes da UFSCar

A UFSCar tinha [1.276 docentes em 2023](https://www.cech.ufscar.br/pt-br/conselho-de-centro/convocacoes/2025/documento_1732692_ufscar_sjrp__bases_para_a_implantacao_de_um_novo_campus.pdf). Com aposentados e ex-docentes, estimamos até 3 mil.

**Modelo de dados:**

- Criar instituição, centro e departamento como tabelas, já prevendo as redes da etapa 2. Hoje são textos em `Researcher`, e `unit` tem o CECH como valor padrão.
- Generalizar `is_cech_researcher` para "pesquisador do portal", sem o nome do centro.
- Trocar as chaves das tabelas que crescem para `BIGINT`, por migration.
- Criar índice em `author_name_variants.normalized_name`, que não tem nenhum. O perfil faz dezenas de buscas nessa coluna.

**Páginas:**

- Criar páginas e filtros por centro. Os textos citam "CECH" em dezenas de templates.
- Limitar a aba de rede aos maiores nós e guardar o resultado.
- Paginar `/departamento`.
- Gerar `/sitemap.xml` sem carregar tudo em memória.

**Comandos:** fazer `app:index:topics` parar de guardar tudo em memória (393 MB hoje, 1,8 GB com 4 mil currículos).

**Importação:** `bin/import-curriculos.sh` continua servindo. Com cerca de 15 s por currículo, são cerca de 5 horas. Os XML podem vir do [Extrator Lattes](https://www.gov.br/pt-br/servicos/obter-acesso-ao-extrator-da-plataforma-lattes), pedido pela UFSCar.

**Medir no fim da etapa:** MB por currículo, autores por produção e tempo de importação nos centros de Exatas e Saúde. Esses números recalibram este documento.

### Etapa 2: ESOCITE.BR

A [ESOCITE.BR](https://www.esocite.org.br/) é a Associação Brasileira de Estudos Sociais das Ciências e das Tecnologias, fundada em 2010. O site não informa o número de associados. O [simpósio é bienal desde 2005 e teve "centenas de apresentações" em 2021](https://elections.registro.br/static/eleicoes2023/cartas/360.pdf), então estimamos de 1 mil a 5 mil pessoas. **Esse número precisa ser confirmado com a associação.** Em produção, 8 docentes do CECH já citam eventos Esocite no currículo, em 23 participações, então parte dessas pessoas já está na base.

**O que muda por ser uma associação, e não uma instituição:**

- **Rede de pesquisadores:** criar grupos em que uma pessoa pode estar em mais de um. O mesmo id Lattes fica na UFSCar e na ESOCITE.BR, sem duplicar o currículo. Os indicadores passam a poder ser filtrados por grupo.
- **Quem entra:** definir com a associação se são os associados, os autores dos anais ou ambos.
- **De onde vêm os XML:** associações científicas não aparecem na [lista do Extrator](https://www.gov.br/pt-br/servicos/obter-acesso-ao-extrator-da-plataforma-lattes), e uma instituição só não cobre pessoas de muitas outras. O caminho proposto é cada pessoa enviar o próprio XML por uma página do portal, que registra a autorização dela. Essa página é nova; o [marcador do navegador](LATTES_IMPORT.md) e a importação pelo painel continuam disponíveis para a equipe.
- **Associados de outros países:** a [ESOCITE latino-americana](https://www.esocite.la/wp-content/uploads/2020/12/Acta-02_2020_Membresia.pdf) tem acadêmicos e estudantes de vários países, e quem não é do Brasil normalmente não tem Lattes. Esta etapa cobre a ESOCITE.BR. Incluir os demais exigiria importar outros formatos de currículo.
- **Textos:** trocar "docente" por "pesquisador" onde a página mostrar mais do que docentes, porque a associação também tem estudantes.

**O que também entra, porque o total chega a milhares de currículos:**

- **Indicadores:** tabelas de resumo para início, `/indicadores`, `/periodicos` e departamentos, atualizadas por comando.
- **Artigo único:** coluna nova com o hash do DOI ou de ano + título, sem tocar no dado do Lattes. Hoje a contagem usa `COUNT(DISTINCT CASE ...)` a cada abertura. Quanto mais coautores entram na base, mais o mesmo artigo se repete; no CECH, 11% das produções já são repetições.
- **Busca:** `FULLTEXT` no lugar de `LIKE '%termo%'`, que aparece 27 vezes em 14 arquivos do código. As buscas passam por um serviço único, para que a troca pelo OpenSearch, se a etapa 3 acontecer, seja feita em um lugar só. Esta etapa não precisa do OpenSearch, e a etapa 3 depende do CNPq, então não vale montar esse sistema antes.
- **Autores:** trocar o índice de docentes em cache, que falha entre 20 mil e 31 mil nomes, por consulta a uma tabela. Usar primeiro o id Lattes do coautor: 36% dos autores no CECH já têm esse id.

`bin/import-curriculos.sh` ainda serve nesse volume.

### Etapa 3: todo o Lattes, por passos

Ir de 8 mil para 10 milhões de uma vez multiplicaria tudo por mais de mil. Por isso, a etapa tem passos, com nova medição em cada um. Um passo intermediário natural são os doutores, cerca de 550 mil pela estimativa acima.

**Passo de 100 mil:**

- **Banco:** MySQL em máquina própria.
- **Importação em fila:**
  - XML guardado no Spaces.
  - Atualização só de quem mudou.
  - Normalização em lote, em vez dos milhares de consultas por currículo.
- **Busca:** OpenSearch no lugar do `FULLTEXT`.
- **Cache:** Nginx com limite de tamanho, limpando por página em vez de limpar tudo.
- **Backup:** cópia física do banco (XtraBackup) quando passar de ~100 GB.

**Passo de 1 milhão:**

- **Banco:** réplica para failover e backup.
- **Indicadores:** ClickHouse como origem dos resumos e da rede de coautoria.
- **Aplicação:** duas máquinas com balanceador, e Valkey para cache e fila.

**10 milhões:**

- **Conceito do site:** com 10 milhões de pessoas, a maioria não é docente. É preciso definir o que o público vê e o que entra nos indicadores, por exemplo: só quem tem vínculo, ou só a partir de certo título.
- **Identidade:** homônimos passam a ser um problema central. O id Lattes dos coautores resolve parte; o resto exige regras em lote e revisão por amostra.
- **Carga inicial:** lote por `LOAD DATA`, sem ORM, em máquinas temporárias.
- **Sitemap:** índice com vários arquivos, de até 50 mil URLs cada.
- **Robôs:** com 10 milhões de páginas públicas, os robôs de busca serão a maior parte do tráfego. É preciso limitar a taxa de acesso e considerar um CDN com filtro de robôs, como o Cloudflare, que fica fora da DigitalOcean.

## Máquinas na DigitalOcean

Preços da [lista de Droplets](https://www.digitalocean.com/pricing/droplets) e de [bancos gerenciados](https://www.digitalocean.com/pricing/managed-databases), consultadas em 31/10/2026. O tráfego é uma suposição: de 5 a 20 milhões de requisições por mês na etapa 3, com a maioria respondida pelo cache. Hoje são cerca de 16 mil. Medir antes de comprar.

### Etapa 1

| Função | Plano | Por mês |
|---|---|---:|
| Tudo. Basta redimensionar o Droplet atual | Basic, 8 GiB, 4 vCPU, 160 GiB | US$ 48 |
| Backup semanal | 20% do Droplet | US$ 9,60 |
| **Total** | | **US$ 58** |

Depois do redimensionamento, é preciso aumentar o `innodb_buffer_pool_size`, que hoje está em 128 MB, para cerca de 3 GB.

### Etapa 2

Continua uma máquina só. A escolha sai das medidas do fim da etapa 1 e do número de pessoas que a ESOCITE.BR confirmar.

| Total de currículos | Plano | Backup | Spaces | Por mês |
|---:|---|---:|---:|---:|
| até ~5 mil | Continuar no Basic, 8 GiB, 4 vCPU | US$ 9,60 | US$ 5 | **US$ 63** |
| até ~10 mil | Basic, 16 GiB, 8 vCPU, 320 GiB | US$ 19 | US$ 5 | **US$ 120** |

### Etapa 3: passos de 100 mil e 1 milhão

| Função | 100 mil | 1 milhão |
|---|---|---|
| Aplicação | Basic, 8 GiB, 4 vCPU: US$ 48 | 2 × CPU-Optimized, 8 GiB, 4 vCPU: US$ 168, mais balanceador: US$ 12 |
| MySQL | Storage-Optimized, 32 GiB, 4 vCPU, 600 GiB: US$ 262 | 2 × Storage-Optimized, 64 GiB, 8 vCPU, 1,17 TB: US$ 1.048 |
| OpenSearch | Gerenciado, 8 GiB, 4 vCPU: US$ 76 | 3 × Memory-Optimized, 32 GiB, 4 vCPU, mais 300 GiB em volume: US$ 594 |
| ClickHouse | não precisa | General Purpose, 32 GiB, 8 vCPU, mais 300 GiB em volume: US$ 282 |
| Valkey | não precisa | Gerenciado, 2 GiB: US$ 30 |
| Spaces | US$ 5 | US$ 15 |
| **Total** | **US$ 390** | **US$ 2.150** |

### Etapa 3: 10 milhões

| Função | Plano | Por mês |
|---|---|---:|
| Aplicação | 2 × CPU-Optimized, 16 GiB, 8 vCPU | US$ 336 |
| Balanceador | 1 nó HTTP | US$ 12 |
| MySQL principal e réplica | 2 × Storage-Optimized, 128 GiB, 16 vCPU, 2,34 TB de NVMe | US$ 2.096 |
| OpenSearch | 3 × Memory-Optimized, 64 GiB, 8 vCPU, 200 GiB, mais 300 GiB em volume cada | US$ 1.098 |
| ClickHouse | General Purpose, 64 GiB, 16 vCPU, 200 GiB, mais 500 GiB em volume | US$ 554 |
| Valkey | Gerenciado, 4 GiB | US$ 60 |
| Spaces | XML e backups, cerca de 2 TB | US$ 40 |
| **Total** | | **US$ 4.200** |

**Carga inicial:** 2 × CPU-Optimized de 48 vCPU, a US$ 1,50 por hora, por cerca de duas semanas, somam cerca de US$ 1.000. A DigitalOcean [cobra por segundo](https://www.digitalocean.com/pricing/droplets), então essas máquinas são apagadas ao terminar. A conta supõe a importação em lote; com o código atual, seriam 390 dias em um processo no Mac.

Se os dados passarem de 2 TB, o próximo plano é o Storage-Optimized de 256 GiB, 32 vCPU e 4,69 TB, a US$ 2.096 cada.

### Escolhas e alternativas

- **Por que Storage-Optimized no MySQL:** o disco fica [ligado direto à máquina](https://docs.digitalocean.com/products/droplets/concepts/choosing-a-plan/). Volumes são mais baratos por GB, mas têm o limite de IOPS citado acima. A DigitalOcean não publica o IOPS dos discos locais, então é preciso medir antes de fechar. O disco local não substitui a réplica nem o backup.
- **Droplets v5:** chegam a 64 vCPU e 512 GB de RAM, mas [não têm disco local além do de boot](https://docs.digitalocean.com/products/droplets/concepts/choosing-a-plan/). Valem se a RAM for o gargalo.
- **MySQL gerenciado:**
  - A edição Standard [deixa de aceitar novos bancos acima de 4 GiB em 30/11/2026](https://docs.digitalocean.com/products/databases/mysql/details/limits/).
  - A [Advanced](https://docs.digitalocean.com/products/databases/mysql/details/pricing/) começa em US$ 130 por nó (8 GiB, 2 vCPU), e a alta disponibilidade exige 3 nós, ou US$ 390.
  - **Decisão:** usar Droplets até a etapa 2, porque dão muito mais RAM pelo mesmo preço.
  - Na etapa 3, pedir o preço dos planos grandes no painel antes de comprar. Não conseguimos vê-lo nas páginas públicas. Sem um especialista em banco na equipe, trocar o servidor principal e restaurar backup com 1 TB são os maiores riscos de operação, e o gerenciado cuida dos dois.
- **OpenSearch gerenciado:** a página pública lista planos só até 8 GiB, por isso as etapas maiores usam Droplets.
- **ClickHouse:** [não está entre os bancos gerenciados da DigitalOcean](https://www.digitalocean.com/pricing/managed-databases) e precisa rodar em Droplet.
- **Região:** a DigitalOcean [não tem região na América do Sul](https://docs.digitalocean.com/platform/regional-availability/). Isso afeta a latência e entra na análise de LGPD.

## Riscos que máquina não resolve

1. **De onde vêm os XML.**
   - O [Extrator Lattes](https://www.gov.br/pt-br/servicos/obter-acesso-ao-extrator-da-plataforma-lattes) é regulado pela Resolução Normativa CNPq 01/2023. Atende instituições de pesquisa, fundações de apoio e agências de fomento, para integração com os sistemas delas. A WAB não se enquadra sozinha: o pedido precisa vir de uma dessas instituições.
   - A etapa 1 depende da UFSCar.
   - A etapa 2 depende da ESOCITE.BR e de cada pessoa aceitar enviar o currículo. A cobertura pode ficar parcial.
   - A etapa 3 depende de um acordo com o CNPq. Segundo a [Pesquisa FAPESP (2015)](https://revistapesquisa.fapesp.br/en/valuable-records/), cada instituição recebe os dados dos seus próprios pesquisadores, a base completa não é de fácil acesso, e o CNPq colocou código de confirmação nas consultas para impedir coleta automatizada. Esse pedido deve vir antes de qualquer gasto com a etapa 3.
2. **LGPD.** Guardar e republicar dados de 10 milhões de pessoas, inclusive estudantes, precisa de análise jurídica e de um caminho para correção e remoção.
3. **Atualização.** A carga inicial é só o começo. Sem uma forma de saber quem mudou, manter a base atualizada exige reprocessar milhões de XML.
4. **Equipe.** A etapa 3 tem cinco sistemas em produção. O custo de operá-los pode superar o das máquinas.

## Ordem recomendada

1. Fazer as mudanças da etapa 1 na máquina atual, testando com os XML que já temos.
2. Pedir à UFSCar o acesso ao Extrator e redimensionar o Droplet só ao importar.
3. Importar a UFSCar e medir de novo. Corrigir este documento com os números reais.
4. Combinar com a ESOCITE.BR quem entra, quantas pessoas são e como os XML chegam.
5. Levar ao CNPq e à análise jurídica o pedido da etapa 3 enquanto a etapa 2 é construída.

## Fontes

- [InnoDB Limits, manual do MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/innodb-limits.html)
- DigitalOcean: [Droplets](https://www.digitalocean.com/pricing/droplets), [escolha de plano](https://docs.digitalocean.com/products/droplets/concepts/choosing-a-plan/), [bancos gerenciados](https://www.digitalocean.com/pricing/managed-databases), [preços do MySQL](https://docs.digitalocean.com/products/databases/mysql/details/pricing/), [limites do MySQL](https://docs.digitalocean.com/products/databases/mysql/details/limits/), [limites de volumes](https://docs.digitalocean.com/products/volumes/details/limits/), [preço de volumes](https://docs.digitalocean.com/products/volumes/details/pricing/), [Spaces](https://docs.digitalocean.com/products/spaces/details/pricing/), [balanceador](https://docs.digitalocean.com/products/networking/load-balancers/details/pricing/), [backups](https://docs.digitalocean.com/products/backups/details/pricing/), [regiões](https://docs.digitalocean.com/platform/regional-availability/)
- [Mena-Chalco, Digiampietri e Cesar-Jr., "Caracterizando as redes de coautoria de currículos Lattes"](https://sol.sbc.org.br/index.php/brasnam/article/download/6864/6757)
- [Pesquisa FAPESP, "Valuable records" (2015)](https://revistapesquisa.fapesp.br/en/valuable-records/)
- [Obter acesso ao Extrator da Plataforma Lattes, gov.br](https://www.gov.br/pt-br/servicos/obter-acesso-ao-extrator-da-plataforma-lattes)
- [UFSCar: bases para a implantação de um novo campus](https://www.cech.ufscar.br/pt-br/conselho-de-centro/convocacoes/2025/documento_1732692_ufscar_sjrp__bases_para_a_implantacao_de_um_novo_campus.pdf)
- [ESOCITE.BR](https://www.esocite.org.br/), [carta da associação sobre o simpósio](https://elections.registro.br/static/eleicoes2023/cartas/360.pdf) e [ata de associação da ESOCITE latino-americana](https://www.esocite.la/wp-content/uploads/2020/12/Acta-02_2020_Membresia.pdf)
