# Guia do Painel Administrativo — CECH / UFSCar

Manual de operações, rotinas de gerenciamento e administração do Portal de Produção Científica do CECH.

---

## 🔐 1. Acesso e Autenticação

- **URL de Acesso**: `/admin` (redireciona para `/login` se não autenticado).
- **Credenciais Iniciais Padrão**:
  - Usuário: `admin`
  - Senha: `wab12345678`

### Criação de Novos Administradores via Console
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
    *O comando mapeia as imagens automaticamente por ID Lattes (`1012351287140134.jpg`), slug ou nome do docente.*
  - **Crawler em Lote via CLI**:
    ```bash
    php bin/console app:crawl:lattes-photos --limit=50
    ```
- **Exportação Multi-Formato**:
  - **CSV**: Tabela completa com contagem de produções por docente.
  - **JSON**: Estrutura detalhada para interoperabilidade de dados.
  - **XML**: Formato compatível com bancos de dados acadêmicos.
  - **PDF Individual Formatado (Dompdf)**: Relatório curricular com layout oficial para impressão em formato PDF (A4).
- **Excluir Registro**: Um diálogo modal em HTML (`<sl-dialog>`) confirma a exclusão sem uso de alertas nativos do navegador.

---

## ☁️ 3. Importação Web de Arquivos Lattes XML (`/admin/curriculum/import`)

1. Acesse o menu **"Importar Lattes XML"**.
2. Arraste ou selecione um ou mais arquivos `.xml` exportados da Plataforma Lattes.
3. Clique em **"Iniciar Importação"**.
4. O sistema processará automaticamente os currículos, aplicando a regra de *upsert* (inserindo novos e atualizando os existentes).

---

## 🏛️ 4. Tesauros e Vocabulários Controlados (BiblioMap)

### 🌍 Tesauro de Países (`/admin/countries`)
- Permite cadastrar nomes preferidos de países e códigos ISO Alpha-2 e Alpha-3.
- Permite vincular dezenas de variantes de grafia (ex: `Brasil`, `Brazil`, `Republica Federativa do Brasil`).
- **Fusão de Países**: Selecione dois ou mais países com caixas de seleção, clique em **"Mesclar Selecionados"** e escolha o país principal na caixa modal.
- **Importação/Exportação**: Suporta VantagePoint (`.the`), CSV, JSON e XML.

### 🏢 Tesauro de Instituições (`/admin/institutions`)
- Padronização de universidades, centros de pesquisa e institutos.
- Cadastro de siglas (ex: `UFSCar`), tipos de instituição e natureza jurídica.
- **Fusão de Instituições**: Unifica duplicatas e consolida todas as variantes históricas no registro principal.
- **Importação/Exportação**: Suporta VantagePoint (`.the`), CSV, JSON e XML.

### ✍️ Tesauro de Autores (`/admin/authors`)
- Autoridades de nomes de autores para desambiguação bibliográfica.
- Vínculo de variantes de citação (ex: `SILVA, C. A.`, `SILVA, Carlos Alberto`).
- **Fusão de Autores**: Unificação de registros duplicados de autores.

---

## 📊 5. Relatórios Institucionais (`/admin/reports`)

Relatórios estratégicos consolidados para gestão acadêmica e avaliação institucional:
1. **Produção Científica & Corpo Docente por Departamento**:
   - Tabela com total de docentes, volume de produções e média de produções por docente.
   - Botões para **Exportar CSV** e **Exportar JSON**.
2. **Distribuição de Artigos por Estrato Qualis (CAPES)**:
   - Contagem de artigos por estrato (`A1`, `A2`, `A3`, `A4`, `B1`, `B2`, `B3`, `B4`, `C`).
   - Botões para **Exportar CSV** e **Exportar JSON**.

---

## 🔍 6. Gerenciamento de SEO, Analytics & Indexação (`/admin/seo`)

Painel dedicado para controle completo da presença nos mecanismos de busca:
- **Google Analytics 4 (GA4)**: Insira o ID de medição (`G-XXXXXXXXXX`) para ativar o rastreamento em tempo real com anonimização de IP.
- **Google Search Console**: Configuração direta da meta tag de verificação do site.
- **Meta Tags Globais**: Personalização do Título Padrão, Meta Description e Meta Keywords.
- **Open Graph & Redes Sociais**: Upload da imagem de destaque para compartilhamento no WhatsApp, Twitter/X, LinkedIn e Facebook com pré-visualização ao vivo.
- **Diretrizes Robots.txt**: Editor das regras do arquivo `robots.txt` para robôs de busca.
- **Sitemap XML Dinâmico**: Visualização e link direto para o mapa do site gerado dinamicamente em `/sitemap.xml`.

---

## 🛡️ 7. Gestão de Usuários (`/admin/users`)

- Adicione novos operadores com papéis:
  - `ROLE_ADMIN`: Acesso completo ao painel administrativo.
  - `ROLE_USER`: Acesso a consultas e operações básicas.
- Alteração segura de senhas com algoritmo Bcrypt/Argon2i.
