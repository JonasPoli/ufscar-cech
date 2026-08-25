# Mecanismo de Ingestão de Currículos Lattes (XML) — CECH / UFSCar

Este documento descreve a especificação técnica do parser de arquivos XML da Plataforma Lattes do CNPq implementado na classe `App\Service\Import\LattesXmlParserService`.

---

## 🔍 Mapeamento das Tags XML do CNPq

Os arquivos XML exportados da Plataforma Lattes seguem um schema proprietário do CNPq. O parser lê os nós utilizando `SimpleXML` com tolerância a nós ausentes e limpeza de strings:

| Entidade / Conceito | Caminho no XML do Lattes | Atributos Extraídos |
|---|---|---|
| **Pesquisador** | `/CURRICULO-VITAE` | `NUMERO-IDENTIFICADOR` (ID Lattes de 16 dígitos), `DATA-ATUALIZACAO` |
| **Dados Gerais** | `/CURRICULO-VITAE/DADOS-GERAIS` | `NOME-COMPLETO`, `NOME-EM-CITACOES-BIBLIOGRAFICAS`, `PAIS-DE-NASCIMENTO`, `UF-NASCIMENTO`, `CIDADE-NASCIMENTO`, `NACIONALIDADE`, `ORCID-ID` |
| **Resumo CV** | `/CURRICULO-VITAE/DADOS-GERAIS/RESUMO-CV` | `TEXTO-RESUMO-CV-RH` |
| **Artigos em Periódicos** | `PRODUCAO-BIBLIOGRAFICA/ARTIGOS-PUBLICADOS/ARTIGO-PUBLICADO` | `TITULO-DO-ARTIGO`, `ANO-DO-ARTIGO`, `DOI`, `TITULO-DO-PERIODICO-OU-REVISTA`, `ISSN`, `VOLUME`, `FASCICULO`, `PAGINA-INICIAL` e `PAGINA-FINAL` |
| **Livros Publicados** | `PRODUCAO-BIBLIOGRAFICA/LIVROS-E-CAPITULOS/LIVROS-PUBLICADOS-OU-ORGANIZADOS/LIVRO-PUBLICADO-OU-ORGANIZADO` | `TITULO-DO-LIVRO`, `ANO`, `NOME-DA-EDITORA`, `ISBN`, `DOI` |
| **Capítulos de Livros** | `PRODUCAO-BIBLIOGRAFICA/LIVROS-E-CAPITULOS/CAPITULOS-DE-LIVROS-PUBLICADOS/CAPITULO-DE-LIVRO-PUBLICADO` | `TITULO-DO-CAPITULO-DO-LIVRO`, `TITULO-DO-LIVRO`, `ANO`, `NOME-DA-EDITORA`, `ISBN`, `DOI` |
| **Trabalhos em Eventos** | `PRODUCAO-BIBLIOGRAFICA/TRABALHOS-EM-EVENTOS/TRABALHO-EM-EVENTOS` | `TITULO-DO-TRABALHO`, `ANO-DO-TRABALHO`, `NOME-DO-EVENTO`, `CIDADE-DO-EVENTO`, `DOI` |
| **Produção Técnica** | `PRODUCAO-TECNICA/DEMAIS-TIPOS-DE-PRODUCAO-TECNICA/*` | `TITULO`, `ANO`, `NATUREZA` |
| **Softwares** | `PRODUCAO-TECNICA/SOFTWARE` | `TITULO-DO-SOFTWARE`, `ANO` |
| **Patentes** | `PRODUCAO-TECNICA/PATENTE` | `TITULO`, `ANO-DESENVOLVIMENTO` |
| **Formação Acadêmica** | `DADOS-GERAIS/FORMACAO-ACADEMICA-TITULACAO/*` | `NOME-CURSO`, `NOME-INSTITUICAO`, `ANO-DE-INICIO`, `ANO-DE-CONCLUSAO`, `TITULO-DA-DISSERTACAO-TESE`, `NOME-DO-ORIENTADOR` |
| **Orientações Concluídas** | `OUTRA-PRODUCAO/ORIENTACOES-CONCLUIDAS/*` | `TIPO-DE-ORIENTACAO`, `NOME-DO-ORIENTADO`, `TITULO`, `ANO`, `NOME-DA-INSTITUICAO`, `NOME-DO-CURSO` |
| **Orientações em Andamento** | `DADOS-COMPLEMENTARES/ORIENTACOES-EM-ANDAMENTO/*` | `TIPO-DE-ORIENTACAO-EM-ANDAMENTO`, `NOME-DO-ORIENTANDO`, `TITULO-DO-TRABALHO`, `ANO`, `NOME-INSTITUICAO`, `NOME-CURSO` |
| **Prêmios e Títulos** | `DADOS-GERAIS/PREMIOS-TITULOS/PREMIO-TITULO` | `NOME-DO-PREMIO-OU-TITULO`, `ANO-DA-PREMIACAO`, `NOME-DA-ENTIDADE-PROMOTORA` |
| **Áreas de Atuação** | `DADOS-GERAIS/AREAS-DE-ATUACAO/AREA-DE-ATUACAO` | `NOME-GRANDE-AREA-DO-CONHECIMENTO`, `NOME-DA-AREA-DO-CONHECIMENTO`, `NOME-DA-SUBAREA-DO-CONHECIMENTO`, `NOME-DA-ESPECIALIDADE` |

---

## ⚡ Regra de Upsert (Inserir ou Atualizar)

O parser opera com a regra estrita de **Idempotência / Upsert**:
1. O parser busca pelo `id_lattes` no banco de dados.
2. Se o pesquisador já existe:
   - Os metadados biográficos são atualizados.
   - As coleções dependentes existentes (produções, titulações, orientações, etc.) são limpas e re-indexadas a partir do novo arquivo XML para garantir que itens excluídos na Plataforma Lattes reflitam no portal.
3. Se não existe:
   - Um novo registro de `Researcher` é instanciado e persistido junto com todos os seus produtos.

---

## 🧠 Gerenciamento de Memória para Grandes Lotes

Durante a importação em lote (ex: 414 currículos simultâneos contendo mais de 73.000 publicações e 150.000 autores):
- A cada arquivo processado, o serviço executa `$this->em->flush()` seguido de `$this->em->clear()`.
- Isso esvazia o *Identity Map* do Doctrine ORM, liberando memória RAM e evitando *out-of-memory errors* (OOM).
- O comando `ImportLattesCommand` deve ser executado com o parâmetro `--no-debug` para desativar o buffer de queries SQL do profiler/Monolog.
