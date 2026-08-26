<?php

namespace App\Command;

use App\Service\Thesaurus\JournalDatabaseExporterService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export-journals-thesaurus',
    description: 'Exports the full journal database, including Qualis, academic databases, and thesaurus variations (CSV or JSON).'
)]
class ExportJournalThesaurusCommand extends Command
{
    public function __construct(
        private readonly JournalDatabaseExporterService $exporter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Export format: csv or json', 'csv')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file path (e.g. var/export_journals.csv)')
            ->addOption('qualis', null, InputOption::VALUE_OPTIONAL, 'Filter by Qualis grade (A1, A2, B1, etc., or ALL)')
            ->addOption('base', 'b', InputOption::VALUE_OPTIONAL, 'Filter by Academic Database acronym (scopus, wos, scielo, etc.)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Exportação do Catálogo de Periódicos com Bases e Tesauro');

        $format = strtolower((string)$input->getOption('format'));
        if (!in_array($format, ['csv', 'json'], true)) {
            $io->error('Formato inválido. Use "csv" ou "json".');
            return Command::FAILURE;
        }

        $outputPath = $input->getOption('output');
        if (!$outputPath) {
            $outputPath = sprintf('var/journals_thesaurus_export_%s.%s', date('Ymd_His'), $format);
        }

        $qualisFilter = $input->getOption('qualis');
        $baseFilter = $input->getOption('base');

        $io->text([
            sprintf('Formato: <info>%s</info>', strtoupper($format)),
            sprintf('Filtro Qualis: <info>%s</info>', $qualisFilter ?: 'Todos'),
            sprintf('Filtro Base: <info>%s</info>', $baseFilter ?: 'Todas'),
            sprintf('Destino: <info>%s</info>', $outputPath),
        ]);

        $result = $this->exporter->export($qualisFilter, $baseFilter, $format, $outputPath);

        $io->success([
            'Exportação concluída com sucesso!',
            sprintf('Total de periódicos exportados: %d', $result['totalExported']),
            sprintf('Arquivo salvo em: %s (%s)', $outputPath, $this->formatBytes(filesize($outputPath))),
        ]);

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
