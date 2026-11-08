## 1. Graves

- [x] 1.1 Importação por HTML: contador próprio nos laços de integrantes e financiadores (teste com três projetos)
- [x] 1.2 Restauração: formato pelo nome original do upload e falhas contadas (testes com zip sem extensão e SQL inválido)
- [x] 1.3 Repositório: `nature`/`course_name` intactos, `repository_program_name`, `Orientation::isConcluded()` nas telas e indicadores
- [x] 1.4 Entidades HTML decodificadas na busca, Explorar, coautorias e API
- [x] 1.5 `/temas`: limpar a busca restaura a nuvem inicial

## 2. Importantes

- [x] 2.1 Projetos sem título fora do agrupamento
- [x] 2.2 `ligacoes` inválido volta a 120
- [x] 2.3 Cache: texto livre fora do `cache.research`; parâmetros desconhecidos e filtros inexistentes fora do cache de páginas
- [x] 2.4 Índice de instituições refeito após alterações no admin
- [x] 2.5 Modo Teses e orientações com todos os filtros aplicáveis
- [x] 2.6 Uploads do admin só com imagens e formulários com CSRF
- [x] 2.7 Repositório: correspondência por nome inteiro, sem absorver obra com outro Handle, exatas primeiro
- [x] 2.8 Excluir periódico limpa Qualis e bases dos artigos

## 3. Menores

- [x] 3.1 Total real de publicações em comum de um par
- [x] 3.2 Grandes áreas contadas uma vez com as duas grafias
- [x] 3.3 Exportação da aba Redes liga os ouvintes uma vez
- [x] 3.4 "Ver todas" da capa com recorte de período
- [x] 3.5 Ano corrente nos egressos e nas séries anuais
- [x] 3.6 `app:cache:clear --all` limpa o `cache.app`
- [x] 3.7 Nome de usuário repetido com mensagem
- [x] 3.8 `/departamento/CS` com as grafias equivalentes

## 4. Documentação e verificação

- [x] 4.1 Divergências entre docs/ e o código corrigidas (51 itens)
- [x] 4.2 Suíte completa, `lint:twig` e `lint:yaml` passando
