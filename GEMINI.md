# Gemini Project Guidelines

This document provides instructions for interacting with the Symfony 7 site-base project.

## Project Overview

This is a boilerplate project for building new applications using Symfony. It comes pre-configured with a backend structure, an admin panel, and a public-facing area. Key technologies include Symfony 7, Doctrine ORM, Twig, Tailwind CSS, and Stimulus (via AssetMapper/Importmap).

## Key Technologies

-   **Backend**: Symfony 7, PHP 8.2+
-   **Database**: Doctrine ORM
-   **Frontend**: Twig, Tailwind CSS, Shoelace, Stimulus
-   **Asset Management**: Symfony AssetMapper & Importmap
-   **Testing**: PHPUnit

## Directory Structure

-   `src/`: PHP source code (Controllers, Entities, Services, etc.), following PSR-4 `App\`.
-   `templates/`: Twig templates.
    -   `templates/admin/`: Templates for the admin area.
    -   `templates/pub/`: Templates for the public-facing site.
-   `assets/`: Frontend source files (CSS, JS).
-   `config/`: Application configuration.
-   `migrations/`: Doctrine database migrations.
-   `public/`: Web root and compiled assets.
-   `tests/`: PHPUnit tests, mirroring the `src/` structure.

## Development Workflow & Commands

### Initial Setup

1.  Copy `.env` to `.env.local` for sensitive data: `cp .env .env.local`
2.  Install dependencies: `composer install`
3.  Create the database and run migrations:
    -   `php bin/console doctrine:database:create`
    -   `php bin/console doctrine:migrations:migrate`

### Running the Application

-   **Local Server**: `symfony serve --no-tls` or `php -S localhost:8000 -t public`
-   **Asset Building (Dev)**: `php bin/console tailwind:build --watch` (watches for changes).
-   **Asset Building (Prod)**: `build.sh` (runs `tailwind:build --minify` and `asset-map:compile`).

### Testing

-   Run the full test suite: `./vendor/bin/phpunit`
-   Run with detailed output: `APP_ENV=test ./vendor/bin/phpunit --testdox`

### Linting

-   Lint Twig templates: `php bin/console lint:twig templates`
-   Lint YAML configuration: `php bin/console lint:yaml config`

### Admin User

-   Create an admin user interactively: `php bin/console app:admin-user <username> <password>`

### JavaScript Dependencies

-   Add a new JS package: `php bin/console importmap:require package-name`
-   Install all required packages: `php bin/console importmap:install`

## Architectural Patterns & Conventions

### Coding Style

-   **PHP**: Follows PSR-12. Use 4-space indentation. Use typed properties.
-   **Naming**:
    -   Classes (Controllers, Services): `StudlyCase`
    -   Form Types: Suffix with `Type` (e.g., `MyFormType.php`)
    -   DTOs: Suffix with `Dto`
    -   Repositories: Suffix with `Repository`
    -   Twig Files: `kebab-case` (e.g., `admin-dashboard.html.twig`)

### Creating Admin CRUDs

This project has a standardized look and feel for admin sections. To create a new CRUD, follow the pattern established by `TestDatabase`:

1.  **Generate the CRUD**: Use the custom generator: `php bin/console make:custom-crud`
2.  **Update Menu**: Add a link to the new section in the admin sidebar in `templates/admin/base.html.twig`. Use the `TestDatabase` link as a template to ensure the active state is highlighted correctly.
3.  **Copy Templates**: Duplicate the templates from `templates/admin/test_database/` to your new CRUD's template directory. These templates use Shoelace components (`sl-card`, `sl-button`) for a consistent, modern look.
4.  **Adapt Forms**: Modify the `_form.html.twig` to include the fields for your entity.
5.  **Implement DataTables**: The `index.html.twig` uses DataTables for listing records. Copy the `stylesheets` and `javascripts` blocks and adapt the `<table>` structure for your entity's columns.
6.  **Update Controller**: Use the `#[MapEntity]` attribute in your controller methods to automatically fetch Doctrine entities, as seen in `TestDatabaseController`.

### Adding Frontend UI Blocks

-   When adding complex HTML components from sources like Flowbite or Tailwind UI, place them inside the `{% block tailwind_ui %}` in `templates/pub/main/home.html.twig`.
-   This block is wrapped in a `div.tw-block` that handles dark mode adjustments automatically.
-   Always run `php bin/console tailwind:build` after adding new HTML to ensure the necessary CSS classes are generated.

### Regra Fixa de Preservação de Dados Lattes

-   **Nunca alterar, formatar, apagar ou adulterar o dado que veio do Lattes**. Todos os dados brutos recebidos da plataforma Lattes (como `author_name`, `citation_name`, `title`, `journal_name`, `institution_name`, etc.) permanecem estritamente intactos.
-   Qualquer processo de normatização, enriquecimento, indexação ou resolução deve ser gravado exclusivamente em **colunas novas/adicionais** no banco de dados (ex: `matched_researcher_id`, `author_identity_id`, `qualis_journal_id`, `institution_id`, `is_cech_researcher`, `is_indexed`).

## Commits & Pull Requests

-   **Commit Messages**: Use short, imperative subjects (e.g., `feat: add user profile page`). The body should explain the "why" and list any breaking changes.
-   **Pull Requests**: A PR should be a single unit of work (one feature, bug fix, or refactor). The description must summarize the changes, testing strategy, and any manual deployment steps (e.g., running migrations). Include screenshots for UI changes.
