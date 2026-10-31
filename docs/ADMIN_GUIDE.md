# Guia do Painel Administrativo — CECH / UFSCar

Manual de operações, rotinas de gerenciamento, indexação cienciométrica e administração do Portal de Produção Científica do CECH.

---

## 🔐 1. Acesso e Autenticação

- **URL de Acesso**: `/admin` (redireciona para `/login` se não autenticado).
- **Credenciais Iniciais Padrão**:
  - Usuário: `admin`
  - Senha: `wab12345678`

### Criação e Gerenciamento de Administradores via Console
Para criar ou redefinir a senha de um administrador diretamente pelo terminal:
```bash
php bin/console app:admin-user <nome_usuario> <senha>
```

---

## 👥 2. Gestão de Currículos Docentes (`/admin/curriculum`)

Na seção **Currículos Docentes**, você pode:
- **Pesquisar & Filtrar**: Busca instantânea por nome, ID Lattes e filtro por departamento (`DEn`, `DFil`, `DPsi`, `DCSo`, `DL`, `DCI`, `DFis`, `DGeo`).
- **Gerenciamento de Fotos & Crawling**:
  - **Upload Individual**: Na página do currículo (`/admin/curriculum/{id}`), envie a foto oficial de qualquer pesquisador.
  - **Crawler Automático do Lattes**: Botão *Buscar no Lattes* para tentar resgatar a foto oficial via servlets do CNPq.
  - **Importação em Lote via CLI**:
    ```bash
    php bin/console app:import:photos --dir=/caminho/pasta_com_fotos
    # ou com arquivo comprimido ZIP:
    php bin/console app:import:photos --zip=/caminho/fotos.zip
    ```
  - **Crawler em Lote via CLI**:
    ```bash
    php bin/console app:crawl:lattes-photos --limit=50
    ```
- **Exportação Multi-Formato**:
  - **CSV**: Tabela completa com contagem de produções por docente.
  - **JSON**: Estrutura detalhada para interoperabilidade de dados.
  - **XML**: Formato compatível com bancos de dados acadêmicos.
  - **PDF Individual Formatado (Dompdf)**: Relatório curricular com layout oficial para impressão em formato PDF (A4).

---

## ⚡ 3. Indexação & Normatização de Dados (`/admin/indexing`)

O painel de indexação coordena a inteligência de dados e a resolução de entidades em lote.

### 3.1. Resolução e Vinculação de Entidades
- **Coautoria CECH**: Identifica quando um coautor citado em uma produção é também um docente cadastrado no CECH (`is_cech_researcher = true`, `matched_researcher_id`).
- **Identidade de Autores**: Vincula variantes de citação ao tesauro de autoridades (`author_identity_id`).
- **Instituições**: Normaliza afiliações institucionais de orientações e formações acadêmicas (`institution_id`).

### 3.2. Indexação de Periódicos & Bases Internacionais
- **Botão "Indexar Periódicos & Bases Internacionais"**:
  - Cruza todos os 13.469 artigos científicos com a tabela canônica de periódicos (`qualis_journal_id`).
  - Mapeia ISSNs eletrônicos (`issn_e`), impressos (`issn_imp`) e de ligação (`issn_l`).
  - Preenche a coluna `indexed_databases` de cada artigo com os metadados JSON das bases internacionais em que a revista está indexada (Scopus, Web of Science, PubMed, Latindex, SciELO, DOAJ, OpenAlex).
  - Execução ultra-rápida via CLI:
    ```bash
    php bin/console app:index:journals
    ```

### 3.3. Ingestão e Enriquecimento do Repositório Institucional (TeD-UFSCar)
- **Importação de Teses e Dissertações Oficiais da UFSCar**:
  - Processa o catálogo oficial (`docs/banco/TeD-UFSCar.csv`), cruza os docentes orientadores e coorientadores, enriquece as orientações com o link permanente (`Handle`), resumo e programa de pós-graduação, e adiciona trabalhos inéditos não cadastrados no Lattes.
  - Execução via CLI:
    ```bash
    # Execução real:
    php bin/console app:import:repository
    # ou usando o alias:
    php bin/console app:import:ted

    # Modo simulação (sem alterar o banco):
    php bin/console app:import:repository --dry-run

    # Opções com filtros:
    php bin/console app:import:repository --center=CECH --limit=1000
    ```
  - Documentação completa disponível em [`docs/REPOSITORY_IMPORT.md`](file:///Users/jonaspoli/work/html/ufscar-cech/docs/REPOSITORY_IMPORT.md).

---

## 📚 4. Gestão de Bases de Indexação Internacional (`/admin/academic-databases`)

Módulo para cadastrar e gerenciar bases de dados científicas e importar listas de periódicos indexados:
- **Cadastro de Bases**: Nome, sigla, logotipo oficial, cor temática e URL institucional.
- **Importação de Periódicos por Base**:
  - Permite importar arquivos CSV, TXT ou VantagePoint contendo as listas oficiais de periódicos indexados na base (como Scopus, Web of Science, DOAJ, Latindex).
  - O sistema associa automaticamente o periódico à base no banco de dados.

---

## 💾 5. Backup & Superdump do Sistema (`/admin/database/backup`)

Ferramenta para exportação completa, migração e segurança do banco de dados MySQL:
- **Superdump em Tempo Real**:
  - Executa o dump via streaming SSE (*Server-Sent Events*), exibindo terminal ao vivo e barra de progresso.
  - Gera arquivo `.sql` completo com esquema (`CREATE TABLE`), dados brutos Lattes e tabelas normalizadas.
  - Compacta automaticamente em arquivo `.sql.zip` de alta taxa de compressão.
- **Download Seguro**:
  - Botão de download direto do arquivo gerado.
  - Rota de download automático do backup mais recente: `/admin/database/backup/download`.
- **Execução via Console (CLI)**:
  ```bash
  php bin/console app:database:dump
  # Opções: --no-zip ou --filename=meu_backup
  ```
- **Restauração em Outro Servidor / Máquina Nova**:
  ```bash
  unzip backup_cech_*.sql.zip
  mysql -u root -p cech < backup_cech_*.sql
  php bin/console app:admin-user admin 12345678
  ./build.sh
  ```

---

## 🏛️ 6. Tesauros e Vocabulários Controlados (BiblioMap)

- **Tesauro de Países (`/admin/countries`)**: Padronização ISO Alpha-2/3, sinônimos, fusão de duplicatas e importação/exportação.
- **Tesauro de Instituições (`/admin/institutions`)**: Siglas, naturezas jurídicas, variantes de grafia e fusão.
- **Tesauro de Autores (`/admin/authors`)**: Autoridades de nomes de autores, identificadores externos (ORCID, Lattes ID) e variantes de citação.
- **Tesauro de Periódicos (`/admin/journals`)**: 63.122 periódicos com estratos Qualis e ISSNs triplos.

---

## 📊 7. Relatórios Institucionais (`/admin/reports`)

- **Produção Científica por Departamento**: Total de docentes, volume de produções e média por docente com exportação CSV/JSON.
- **Distribuição de Artigos por Estrato Qualis (CAPES)**: Estratos A1 a C com exportação CSV/JSON.

---

## 🔍 8. Gerenciamento de SEO & Analytics (`/admin/seo`)

- **Google Analytics 4 (GA4)**: Configuração do Measurement ID.
- **Google Search Console**: Verificação de propriedade do domínio.
- **Meta Tags & Open Graph**: Personalização de títulos, descrições e imagem de compartilhamento social.
- **Sitemap XML & Robots.txt**: Controle de indexação pelos motores de busca.

---

## 🎓 9. Áreas CAPES no cadastro de periódicos (`/admin/journals`)

- **Preenchimento automático**: o campo **Área de Avaliação / Conhecimento** recebe o nome CAPES em maiúsculas, no formato `EDUCAÇÃO / CIÊNCIAS HUMANAS` (área / grande área). Quando a família do periódico já tem áreas, o campo fica somente leitura e a dica manda editar no bloco logo abaixo.
- **Texto livre e valor original**: enquanto a família não tem áreas CAPES, o campo aceita texto livre. Se o texto não for um nome CAPES, ele fica guardado e aparece abaixo do campo como *Valor original importado*. A classificação automática não apaga esse texto.
- **Edição**: no bloco **Área de Avaliação / Conhecimento (CAPES)**, marque as áreas, escolha a *área principal* e salve. Se a seleção mudou, ela vira curadoria manual e fica travada: a classificação automática deixa de alterar a família. Salvar sem mexer nas áreas não trava nada.
- **Travar (curadoria manual)**: impede a classificação automática de alterar a família, mesmo sem áreas marcadas.
- **Voltar ao automático**: aparece quando há curadoria manual ou trava. Apaga a curadoria manual da família, destrava e roda a classificação automática só para ela. Sem evidência suficiente, a área volta ao valor original importado.
- **Situação e evidências**: o bloco mostra se a curadoria é manual ou automática, a execução que a gerou, a confiança de cada área (alta, média, baixa ou manual) e a tabela de evidências das fontes, só para leitura.
- **Periódicos agrupados**: impressos e online de uma mesma revista formam uma família. A linha agrupada mostra *Agrupado sob #id*; as áreas se editam na linha canônica, pelo link do aviso.
- **Nome de exibição**: campo logo abaixo do título, é o nome mostrado em `/periodicos` para a família inteira. Preenchido, vale como manual e não é recalculado; em branco, volta ao cálculo a partir das fontes (SciELO, DOAJ, Latindex e demais bases).

---

## 🗂️ 10. Fila de nomes de periódicos (`/admin/journal-names`)

- **Por que um nome cai na fila**: o nome de periódico de um artigo do Lattes entra na fila quando não tem vínculo seguro com o cadastro. O sistema não adivinha pelas palavras-chave: elas geram só uma sugestão, que nunca vincula sozinha.
- **Quando entra**: ao importar um currículo, os nomes sem vínculo que ainda não estavam na fila entram nela, e os que já estavam têm contagens e títulos de amostra atualizados. A reindexação completa recalcula a fila inteira. Um nome novo cai como *Pendente* ou, se casar com periódicos de famílias diferentes, como *Ambíguo*.
- **Sugestão**: a coluna mostra o periódico provável e o método usado. *ISSN confirmado* quer dizer que o ISSN de um artigo confirmou o periódico, mas ainda não há artigos suficientes para criar a variação sem revisão. Pode ser aceito com um clique.
- **Aceitar**: informe o ID do periódico (qualquer linha da família serve) e clique em *Aceitar*. O nome vira variação desse periódico, os artigos com esse nome são revinculados e o nome sai da fila.
- **Rejeitar**: os artigos continuam sem vínculo, e o nome vai para *Rejeitados*.
- **Reabrir**: só para nomes rejeitados, que voltam a *Pendentes*. Nomes aceitos não voltam, porque a variação e os vínculos já foram gravados.
- **Ambíguos**: o nome casa com periódicos de famílias diferentes, e o sistema não escolhe entre eles. Só o ISSN do artigo resolve esse caso. Antes de informar um ID, confira o ISSN e os títulos de amostra; se o nome já pertence a outra família, a tela mostra o conflito e nada muda.
- **Abas e contagens**: *Pendentes*, *Ambíguos*, *Aceitos* e *Rejeitados* separam os casos, e o topo da tela mostra quantos nomes aguardam revisão. Nomes pendentes e ambíguos têm o link *Procurar no cadastro*, que abre a busca de periódicos em outra aba.
- **Atualização**: um nome pendente que deixa de aparecer nos artigos (porque ganhou vínculo, por exemplo) sai da fila na próxima atualização. Nomes já decididos não mudam.
