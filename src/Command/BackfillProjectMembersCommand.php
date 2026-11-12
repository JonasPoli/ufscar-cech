<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Project\LattesProjectXmlReader;
use App\Service\Project\ProjectIndexService;
use App\Service\Thesaurus\StringNormalizer;
use App\Support\LattesText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * Completa os projetos já importados com a equipe e os financiadores, relendo os XML do Lattes, sem reimportar.
 *
 * Os projetos importados antes da tabela de integrantes existir estão inteiros em `research_projects`, mas a equipe
 * ficou no XML. Reimportar resolveria, mas refaz o currículo todo (produção, orientações, revistas, temas). Este comando
 * só escreve em `research_project_members` e `research_project_funders`:
 *
 * 1. acha o docente pelo ID Lattes do arquivo (atributo NUMERO-IDENTIFICADOR, senão os 16 dígitos do nome);
 * 2. pula o arquivo se a DATA-ATUALIZACAO dele não for a do currículo no banco: a equipe de outro XML pode não ser a
 *    dos projetos gravados (`--ignore-date` desliga a conferência);
 * 3. casa cada projeto do XML com um registro do docente pelo nome exatamente como gravado + ano de início, na ordem em
 *    que a importação grava (o mesmo projeto pode aparecer duas vezes no currículo); o que sobra tenta o nome
 *    normalizado + ano. Projeto sem par não é criado nem alterado;
 * 4. troca a equipe e os financiadores dos projetos casados pelos do XML (rodar de novo dá o mesmo resultado);
 * 5. no fim, roda a indexação dos projetos (`app:index:projects`).
 */
#[AsCommand(
    name: 'app:projects:backfill-members',
    description: 'Completa os projetos já importados com integrantes e financiadores, relendo os XML do Lattes'
)]
class BackfillProjectMembersCommand extends Command
{
    private const INSERT_CHUNK = 200;

    public function __construct(
        private readonly Connection $conn,
        private readonly ProjectIndexService $indexService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Pasta com os XML do Lattes (inclui subpastas, como as lote-* do servidor)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Um XML só')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Só conta o que seria gravado')
            ->addOption('ignore-date', null, InputOption::VALUE_NONE, 'Usa o XML mesmo se a data de atualização for diferente da do currículo no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $ignoreDate = (bool)$input->getOption('ignore-date');

        $files = $this->collectFiles($input->getOption('dir'), $input->getOption('file'));
        if ($files === null) {
            $io->error('Informe --dir=<pasta> ou --file=<arquivo.xml> existentes.');

            return Command::FAILURE;
        }

        $io->title('Integrantes e financiadores dos projetos já importados' . ($dryRun ? ' (simulação)' : ''));

        $researchersByLattes = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, id_lattes, last_lattes_update FROM researchers') as $row) {
            $researchersByLattes[(string)$row['id_lattes']] = $row;
        }

        $totals = array_fill_keys([
            'files', 'updated', 'no_researcher', 'date_mismatch', 'invalid', 'already_done',
            'xml_projects', 'matched_exact', 'matched_normalized', 'unmatched_xml', 'records_without_xml', 'members', 'funders',
        ], 0);
        $warnings = [];
        $done = [];

        $io->progressStart(count($files));
        foreach ($files as $path) {
            $io->progressAdvance();
            $totals['files']++;

            $xml = @simplexml_load_file($path, options: LIBXML_NONET);
            if (!$xml || $xml->getName() !== 'CURRICULO-VITAE') {
                $totals['invalid']++;
                $warnings[] = basename($path) . ': não é um XML do Lattes';
                continue;
            }

            $idLattes = $this->idLattes($xml, $path);
            $researcher = $idLattes !== null ? ($researchersByLattes[$idLattes] ?? null) : null;
            if ($researcher === null) {
                $totals['no_researcher']++;
                continue;
            }
            if (isset($done[$idLattes])) {
                $totals['already_done']++;
                continue;
            }

            if (!$ignoreDate && !$this->sameUpdateDate($xml, $researcher['last_lattes_update'])) {
                $totals['date_mismatch']++;
                $warnings[] = sprintf('%s: data de atualização diferente da do currículo no banco (%s)', basename($path), substr((string)$researcher['last_lattes_update'], 0, 10));
                continue;
            }

            $plan = $this->plan($xml, (int)$researcher['id'], $researchersByLattes);
            $done[$idLattes] = true;
            foreach (['xml_projects', 'matched_exact', 'matched_normalized', 'unmatched_xml', 'records_without_xml', 'members', 'funders'] as $k) {
                $totals[$k] += $plan[$k];
            }
            $totals['updated']++;

            if (!$dryRun && $plan['pairs'] !== []) {
                $this->write($plan['pairs']);
            }
        }
        $io->progressFinish();

        $n = static fn (int $v): string => number_format($v, 0, ',', '.');
        $io->definitionList(
            ['Arquivos lidos' => $n($totals['files'])],
            ['Currículos completados' => $n($totals['updated'])],
            ['Sem docente no banco' => $n($totals['no_researcher'])],
            ['Pulados: data diferente da do banco' => $n($totals['date_mismatch'])],
            ['Pulados: outro arquivo do mesmo docente já usado' => $n($totals['already_done'])],
            ['Inválidos' => $n($totals['invalid'])],
            ['Projetos no XML' => $n($totals['xml_projects'])],
            ['Casados pelo nome gravado + ano' => $n($totals['matched_exact'])],
            ['Casados pelo nome normalizado + ano' => $n($totals['matched_normalized'])],
            ['Projetos do XML sem registro no banco' => $n($totals['unmatched_xml'])],
            ['Registros do banco sem projeto no XML' => $n($totals['records_without_xml'])],
            ['Integrantes ' . ($dryRun ? 'a gravar' : 'gravados') => $n($totals['members'])],
            ['Financiadores ' . ($dryRun ? 'a gravar' : 'gravados') => $n($totals['funders'])],
        );

        if ($warnings !== []) {
            $io->section('Avisos');
            $io->listing(array_slice($warnings, 0, 50));
            if (count($warnings) > 50) {
                $io->text(sprintf('… e mais %d.', count($warnings) - 50));
            }
        }

        if ($dryRun) {
            $io->note('Simulação: nada foi gravado.');

            return Command::SUCCESS;
        }

        $result = $this->indexService->index();
        $io->text(sprintf('Indexação: %s chaves de agrupamento e %s vínculos de integrantes atualizados.', $n($result['group_keys_updated']), $n($result['members_relinked'])));
        $io->success('Projetos completados. Limpe o cache das páginas (app:cache:clear).');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function collectFiles(mixed $dir, mixed $file): ?array
    {
        if (is_string($file) && $file !== '') {
            return is_file($file) ? [$file] : null;
        }
        if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
            return null;
        }

        $files = [];
        foreach ((new Finder())->files()->in($dir)->name('/\.xml$/i')->sortByName() as $f) {
            $files[] = $f->getPathname();
        }

        return $files;
    }

    private function idLattes(\SimpleXMLElement $xml, string $path): ?string
    {
        foreach ([trim((string)($xml['NUMERO-IDENTIFICADOR'] ?? '')), (string)preg_replace('/\D/', '', pathinfo($path, PATHINFO_FILENAME))] as $candidate) {
            if (preg_match('/^\d{16}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function sameUpdateDate(\SimpleXMLElement $xml, mixed $stored): bool
    {
        if ($stored === null || $stored === '') {
            return true;
        }
        if (!preg_match('/^(\d{2})(\d{2})(\d{4})$/', trim((string)($xml['DATA-ATUALIZACAO'] ?? '')), $m)) {
            return false;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}" === substr((string)$stored, 0, 10);
    }

    /**
     * @param array<string, array{id: int|string}> $researchersByLattes
     *
     * @return array{pairs: list<array{0: int, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}>, xml_projects: int, matched_exact: int, matched_normalized: int, unmatched_xml: int, records_without_xml: int, members: int, funders: int}
     */
    private function plan(\SimpleXMLElement $xml, int $researcherId, array $researchersByLattes): array
    {
        $records = $this->conn->fetchAllAssociative('SELECT id, name, start_year FROM research_projects WHERE researcher_id = ? ORDER BY id', [$researcherId]);

        // Fila de registros por chave: o mesmo projeto pode estar duas vezes no currículo, e a importação grava na ordem do XML
        $exactQueue = [];
        foreach ($records as $record) {
            $exactQueue[$this->exactKey((string)$record['name'], $record['start_year'] !== null ? (int)$record['start_year'] : null)][] = (int)$record['id'];
        }
        $used = [];
        $assigned = [];
        $nodes = LattesProjectXmlReader::projectNodes($xml->{'DADOS-GERAIS'} ?? null);
        $exact = 0;
        foreach ($nodes as $i => $node) {
            $start = (int)($node['ANO-INICIO'] ?? 0);
            $key = $this->exactKey(mb_substr(trim((string)$node['NOME-DO-PROJETO']), 0, 500), $start > 0 ? $start : null);
            if (!empty($exactQueue[$key])) {
                $id = array_shift($exactQueue[$key]);
                $assigned[$i] = $id;
                $used[$id] = true;
                $exact++;
            }
        }

        // Segunda passada, para registros gravados por um parser antigo com o texto um pouco diferente
        $normalizedQueue = [];
        foreach ($records as $record) {
            if (!isset($used[(int)$record['id']])) {
                $normalizedQueue[$this->normalizedKey((string)$record['name'], $record['start_year'] !== null ? (int)$record['start_year'] : null)][] = (int)$record['id'];
            }
        }
        $normalized = 0;
        foreach ($nodes as $i => $node) {
            if (isset($assigned[$i])) {
                continue;
            }
            $start = (int)($node['ANO-INICIO'] ?? 0);
            $key = $this->normalizedKey((string)$node['NOME-DO-PROJETO'], $start > 0 ? $start : null);
            if (!empty($normalizedQueue[$key])) {
                $id = array_shift($normalizedQueue[$key]);
                $assigned[$i] = $id;
                $used[$id] = true;
                $normalized++;
            }
        }

        $pairs = [];
        $memberCount = 0;
        $funderCount = 0;
        foreach ($assigned as $i => $projectId) {
            $members = [];
            foreach (LattesProjectXmlReader::members($nodes[$i]) as $row) {
                $row['matched_researcher_id'] = $row['id_lattes'] !== null ? (isset($researchersByLattes[$row['id_lattes']]) ? (int)$researchersByLattes[$row['id_lattes']]['id'] : null) : null;
                $members[] = $row;
            }
            $funders = LattesProjectXmlReader::funders($nodes[$i]);
            $memberCount += count($members);
            $funderCount += count($funders);
            $pairs[] = [$projectId, $members, $funders];
        }

        return [
            'pairs' => $pairs,
            'xml_projects' => count($nodes),
            'matched_exact' => $exact,
            'matched_normalized' => $normalized,
            'unmatched_xml' => count($nodes) - count($assigned),
            'records_without_xml' => count($records) - count($used),
            'members' => $memberCount,
            'funders' => $funderCount,
        ];
    }

    /**
     * @param list<array{0: int, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}> $pairs
     */
    private function write(array $pairs): void
    {
        $this->conn->transactional(function (Connection $conn) use ($pairs): void {
            $ids = array_map(static fn (array $pair): int => $pair[0], $pairs);
            $conn->executeStatement('DELETE FROM research_project_members WHERE research_project_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM research_project_funders WHERE research_project_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);

            $memberRows = [];
            $funderRows = [];
            foreach ($pairs as [$projectId, $members, $funders]) {
                foreach ($members as $m) {
                    $memberRows[] = [$projectId, $m['matched_researcher_id'], $m['full_name'], $m['citation_name'], $m['member_order'], $m['is_responsible'] ? 1 : 0, $m['id_lattes']];
                }
                foreach ($funders as $f) {
                    $funderRows[] = [$projectId, $f['name'], $f['institution_code'], $f['nature'], $f['funder_order']];
                }
            }

            $this->insertRows($conn, 'research_project_members', ['research_project_id', 'matched_researcher_id', 'full_name', 'citation_name', 'member_order', 'is_responsible', 'id_lattes'], $memberRows);
            $this->insertRows($conn, 'research_project_funders', ['research_project_id', 'name', 'institution_code', 'nature', 'funder_order'], $funderRows);
        });
    }

    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     */
    private function insertRows(Connection $conn, string $table, array $columns, array $rows): void
    {
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $conn->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, count($chunk), $placeholder))),
                array_merge(...$chunk)
            );
        }
    }

    private function exactKey(string $name, ?int $startYear): string
    {
        return $name . '|' . ($startYear ?? '');
    }

    private function normalizedKey(string $name, ?int $startYear): string
    {
        return StringNormalizer::normalizeString(LattesText::display($name), true) . '|' . ($startYear ?? '');
    }
}
