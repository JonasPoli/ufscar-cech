# Repository Guidelines

## Project Structure & Module Organization
- `src/` (PSR-4 `App\\`) contains controllers, services, forms, and Doctrine entities; mirror feature folders between `src/` and `tests/`.
- `templates/` holds Twig views, `assets/` stores Tailwind inputs and Stimulus controllers, and compiled output lands in `public/`.
- Runtime configuration sits in `config/` and `.env`; overrides belong in `.env.local`. Database migrations live under `migrations/`, translations in `translations/`.
- Shell helpers stay in `bin/`, and `build.sh` is the single entry point for asset compilation.

## Build, Test, and Development Commands
- `composer install` — install dependencies and refresh the autoloader.
- `symfony serve --no-tls` (or `php -S localhost:8000 -t public`) — boot a local server.
- `php bin/console doctrine:migrations:migrate` — apply schema changes.
- `php bin/console tailwind:build --minify` and `php bin/console asset-map:compile` — rebuild CSS and the asset map (`build.sh` runs both).
- `./vendor/bin/phpunit` — execute the PHPUnit suite defined in `phpunit.xml.dist`.

## Coding Style & Naming Conventions
- Follow PSR-12: 4-space indentation, one class per file, and typed properties in new code.
- Controllers, services, and listeners use StudlyCase names; Twig templates remain kebab-case (e.g., `admin-dashboard.html.twig`).
- DTOs end with `Dto`, form types with `Type`, and Doctrine repositories with `Repository`.
- Run `php bin/console lint:twig templates` and `php bin/console lint:yaml config` when those areas change.

## Testing Guidelines
- Place tests under `tests/` mirroring the namespace under test; name files `<ClassName>Test.php`.
- Use the Symfony BrowserKit client for HTTP flows and PHPUnit data providers for business rules.
- Cover new business logic, security checks, and persistence branches; keep fixtures lightweight so tests run without external services.
- Execute `APP_ENV=test ./vendor/bin/phpunit --testdox` before requesting review.

## Commit & Pull Request Guidelines
- History is empty, so establish short imperative subjects such as `feat: add gallery CRUD` and include a body with motivation plus breaking changes.
- Reference issue IDs or specs, and list schema/config/env changes explicitly.
- Every PR should describe the change, summarize tests, attach screenshots for UI tweaks, and call out rollout steps for migrations or assets.
- Request a reviewer familiar with the affected module and limit each PR to a single concern (feature, bug fix, or refactor).

## Regra Fixa de Preservação de Dados Lattes
- **Nunca alterar, formatar, apagar ou adulterar o dado que veio do Lattes**. Todos os dados brutos recebidos da plataforma Lattes (como `author_name`, `citation_name`, `title`, `journal_name`, `institution_name`, etc.) permanecem estritamente intactos.
- Qualquer processo de normatização, enriquecimento, indexação ou resolução deve ser gravado exclusivamente em **colunas novas/adicionais** no banco de dados (ex: `matched_researcher_id`, `author_identity_id`, `qualis_journal_id`, `institution_id`, `is_cech_researcher`, `is_indexed`).

## Regra Estrita de Banco de Dados: Uso Exclusivo de Migrations
- **Nunca use `doctrine:schema:update`**. Todas as alterações de estrutura e esquema de banco de dados devem ser feitas exclusivamente através de Migrations versionadas (`php bin/console make:migration` ou `php bin/console doctrine:migrations:diff`).
- A aplicação das alterações no banco deve ser executada sempre via `php bin/console doctrine:migrations:migrate` (tanto no ambiente de desenvolvimento quanto em `APP_ENV=test`).

## Configurações do Servidor de Produção (RunCloud)
- **Host / IP**: `104.236.71.49` (`ssh runcloud@104.236.71.49`)
- **Caminho da Aplicação no Servidor**: `/home/runcloud/webapps/ufscar-cech`
- **Binário PHP em Produção**: `/RunCloud/Packages/php84rc/bin/php`
- **Execução de Comandos em Produção**: `/RunCloud/Packages/php84rc/bin/php bin/console <comando> --env=prod`
- **Guia Completo de Deploy**: Consulte [`docs/DEPLOY_E_PRODUCAO.md`](file:///Users/jonaspoli/work/html/ufscar-cech/docs/DEPLOY_E_PRODUCAO.md).

