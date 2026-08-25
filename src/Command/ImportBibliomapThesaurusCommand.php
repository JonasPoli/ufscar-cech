<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\DBAL\Connection;

#[AsCommand(
    name: 'app:import:bibliomap',
    description: 'Imports thesaurus data (Countries, States, Cities, Institutions, Authors) directly from the BiblioMap database'
)]
class ImportBibliomapThesaurusCommand extends Command
{
    public function __construct(
        private readonly Connection $conn
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source-db', null, InputOption::VALUE_OPTIONAL, 'Source MySQL database name', 'bibliomap');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importing Thesaurus & Controlled Data from BiblioMap');

        $srcDb = $input->getOption('source-db');

        try {
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');

            // 1. Countries
            $io->section('1. Importing Countries and Variants...');
            $this->conn->executeStatement('TRUNCATE TABLE country_name_variants');
            $this->conn->executeStatement('TRUNCATE TABLE countries');

            $this->conn->executeStatement("
                INSERT INTO countries (id, common_name, official_name, iso_alpha2, iso_alpha3, iso_numeric, status, foundation_year, extinction_year, created_at, updated_at)
                SELECT id, common_name, official_name, sigla, iso_code, NULL, status, ano_fundacao, ano_extincao, created_at, updated_at
                FROM `{$srcDb}`.paises
            ");
            $countriesCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM countries');
            $io->text("Transferred {$countriesCount} countries.");

            $this->conn->executeStatement("
                INSERT INTO country_name_variants (id, country_id, variation_name, normalized_name, variation_type, status, created_at, updated_at)
                SELECT id, country_id, variation_name, normalized_name, variation_type, status, NOW(), NOW()
                FROM `{$srcDb}`.pais_variacoes_nome
            ");
            $countryVariantsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM country_name_variants');
            $io->text("Transferred {$countryVariantsCount} country variants.");

            // 2. States
            $io->section('2. Importing States and Variants...');
            $this->conn->executeStatement('TRUNCATE TABLE state_name_variants');
            $this->conn->executeStatement('TRUNCATE TABLE states');

            $this->conn->executeStatement("
                INSERT INTO states (id, country_id, name, code, status, created_at, updated_at)
                SELECT id, country_id, official_name, sigla, status, created_at, updated_at
                FROM `{$srcDb}`.estados
            ");
            $statesCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM states');
            $io->text("Transferred {$statesCount} states.");

            $this->conn->executeStatement("
                INSERT INTO state_name_variants (id, state_id, variation_name, normalized_name, variation_type, status, created_at, updated_at)
                SELECT id, state_id, variation_name, normalized_name, variation_type, status, NOW(), NOW()
                FROM `{$srcDb}`.estado_variacoes_nome
            ");
            $stateVariantsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM state_name_variants');
            $io->text("Transferred {$stateVariantsCount} state variants.");

            // 3. Cities
            $io->section('3. Importing Cities and Variants...');
            $this->conn->executeStatement('TRUNCATE TABLE city_name_variants');
            $this->conn->executeStatement('TRUNCATE TABLE cities');

            $this->conn->executeStatement("
                INSERT INTO cities (id, state_id, name, latitude, longitude, status, created_at, updated_at)
                SELECT id, state_id, official_name, NULL, NULL, status, created_at, updated_at
                FROM `{$srcDb}`.cidades
            ");
            $citiesCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM cities');
            $io->text("Transferred {$citiesCount} cities.");

            $this->conn->executeStatement("
                INSERT INTO city_name_variants (id, city_id, variation_name, normalized_name, variation_type, status, created_at, updated_at)
                SELECT id, city_id, variation_name, normalized_name, variation_type, status, NOW(), NOW()
                FROM `{$srcDb}`.cidade_variacoes_nome
            ");
            $cityVariantsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM city_name_variants');
            $io->text("Transferred {$cityVariantsCount} city variants.");

            // 4. Institutions
            $io->section('4. Importing Institutions and Variants...');
            $this->conn->executeStatement('TRUNCATE TABLE institution_name_variants');
            $this->conn->executeStatement('TRUNCATE TABLE institutions');

            $this->conn->executeStatement("
                INSERT INTO institutions (
                    id, country_id, state_id, city_id, official_name, short_name, acronym, institution_type, legal_nature,
                    corporate_name, tax_id, sponsor_code, higher_education_code, latitude, longitude, phone, headquarters_address,
                    academic_organization, accreditation_type, category, administrative_category, institutional_concept,
                    institutional_concept_year, distance_learning_concept, distance_learning_concept_year, general_course_index,
                    general_course_index_year, rector, legal_representative, active_regulations, higher_education_status,
                    vantagepoint, official_website, institutional_email, status, notes, foundation_year, extinction_year,
                    created_at, updated_at
                )
                SELECT 
                    id, country_id, state_id, city_id, official_name, short_name, sigla, institution_type, natureza,
                    razao_social, cnpj, codigo_mantenedora, codigo_ies, latitude, longitude, telefone, endereco_sede,
                    organizacao_academica, tipo_credenciamento, categoria, categoria_administrativa, ci,
                    ano_ci, ci_ead, ano_ci_ead, igc,
                    ano_igc, reitor, representante_legal, sinalizacoes_vigentes, situacao_ies,
                    vantagepoint, official_website, institutional_email, status, notes, ano_fundacao, ano_extincao,
                    created_at, updated_at
                FROM `{$srcDb}`.instituicoes_ensino
            ");
            $institutionsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM institutions');
            $io->text("Transferred {$institutionsCount} institutions.");

            $this->conn->executeStatement("
                INSERT INTO institution_name_variants (id, institution_id, variation_name, normalized_name, variation_type, status, created_at, updated_at)
                SELECT id, institution_id, variation_name, normalized_name, variation_type, status, NOW(), NOW()
                FROM `{$srcDb}`.instituicao_variacoes_nome
            ");
            $institutionVariantsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM institution_name_variants');
            $io->text("Transferred {$institutionVariantsCount} institution variants.");

            // 5. Authors
            $io->section('5. Importing Authors and Variants...');
            $this->conn->executeStatement('TRUNCATE TABLE author_name_variants');
            $this->conn->executeStatement('TRUNCATE TABLE author_external_identifiers');
            $this->conn->executeStatement('TRUNCATE TABLE author_identities');

            $this->conn->executeStatement("
                INSERT INTO author_identities (id, preferred_name, normalized_name, status, review_reasons, created_at, updated_at)
                SELECT id, preferred_name, normalized_name, status, review_reasons, created_at, updated_at
                FROM `{$srcDb}`.author_identity
            ");
            $authorsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_identities');
            $io->text("Transferred {$authorsCount} authors.");

            $this->conn->executeStatement("
                INSERT INTO author_name_variants (id, author_identity_id, original_name, normalized_name, display_name, source, status, created_at, updated_at)
                SELECT id, author_identity_id, original_name, normalized_name, display_name, source, 1, created_at, updated_at
                FROM `{$srcDb}`.author_name_variant
            ");
            $authorVariantsCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM author_name_variants');
            $io->text("Transferred {$authorVariantsCount} author variants.");

            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            $io->success('All thesaurus records imported in ultra-fast mode!');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
            $io->error("Error during BiblioMap import: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
