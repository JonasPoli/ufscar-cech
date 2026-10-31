<?php

namespace App\Command;

use App\Entity\ProductionItem;
use App\Entity\Researcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:sync:keywords',
    description: 'Só para dados antigos: grava as palavras-chave dos XMLs do Lattes nas produções que ainda não as têm (o importador já as grava)'
)]
class SyncKeywordsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'Diretório com os arquivos XML', 'docs/banco/CECH')
            ->addOption('file', null, InputOption::VALUE_OPTIONAL, 'Arquivo XML específico para sincronizar')
            ->setHelp(<<<'HELP'
                O importador de currículos (app:import:lattes) já grava as palavras-chave de cada produção. Este comando serve só
                para dados importados antes disso. Ele casa a produção do banco com a do XML pelo título e, por segurança:

                  - ignora título repetido dentro do mesmo docente (não há como saber qual item é qual);
                  - não sobrescreve produção que já tem palavras-chave;
                  - termina com erro se algum arquivo falhar.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sincronizador de Palavras-Chave Oficiais do Lattes — CECH');

        $singleFile = $input->getOption('file');
        $dir = $input->getOption('dir');

        $files = [];
        if ($singleFile) {
            if (!file_exists($singleFile)) {
                $io->error("Arquivo não encontrado: {$singleFile}");
                return Command::FAILURE;
            }
            $files = [new \SplFileInfo($singleFile)];
        } else {
            if (!is_dir($dir)) {
                $io->error("Diretório não encontrado: {$dir}");
                return Command::FAILURE;
            }
            $finder = new Finder();
            $finder->files()->in($dir)->name('*.xml')->sortByName();
            $files = iterator_to_array($finder, false);
        }

        $totalFiles = count($files);
        if ($totalFiles === 0) {
            $io->warning('Nenhum arquivo XML encontrado.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Processando <info>%d</info> arquivos XML para sincronização de palavras-chave...', $totalFiles));
        $progressBar = new ProgressBar($output, $totalFiles);
        $progressBar->start();

        $conn = $this->em->getConnection();
        $updatedCount = 0;
        $totalFoundKws = 0;
        $ambiguousTitles = 0;
        $alreadyHadKeywords = 0;
        $unmatchedFiles = 0;
        $errors = [];

        foreach ($files as $index => $file) {
            try {
                $xml = @simplexml_load_file($file->getRealPath());
                if (!$xml || $xml->getName() !== 'CURRICULO-VITAE') {
                    throw new \RuntimeException('XML inválido: não é um currículo Lattes (sem CURRICULO-VITAE).');
                }

                $idLattes = trim((string)($xml['NUMERO-IDENTIFICADOR'] ?? ''));
                if ($idLattes === '') {
                    $dadosGerais = $xml->{'DADOS-GERAIS'} ?? null;
                    if ($dadosGerais) {
                        $idLattes = trim((string)($dadosGerais['NUMERO-IDENTIFICADOR-CNPQ'] ?? ''));
                    }
                }

                if ($idLattes === '') {
                    // Try filename fallback (e.g. lattes6958372164719600.xml)
                    if (preg_match('/lattes(\d+)\.xml/i', $file->getFilename(), $m)) {
                        $idLattes = $m[1];
                    }
                }

                // Find researcher ID in database
                $researcherId = $idLattes === '' ? false : $conn->fetchOne(
                    'SELECT id FROM researchers WHERE id_lattes = ? OR REPLACE(id_lattes, " ", "") = ? LIMIT 1',
                    [$idLattes, $idLattes]
                );

                if (!$researcherId) {
                    $unmatchedFiles++;
                    $progressBar->advance();
                    continue;
                }

                // Fetch all production items for this researcher
                $dbItems = $conn->fetchAllAssociative(
                    'SELECT id, title, extra_data FROM production_items WHERE researcher_id = ?',
                    [$researcherId]
                );

                // Itens do banco por título normalizado: um título que aparece mais de uma vez não identifica um item só
                $itemsByTitle = [];
                $existingExtras = [];
                foreach ($dbItems as $dbItem) {
                    $normTitle = $this->normalizeTitle($dbItem['title']);
                    if ($normTitle === '') {
                        continue;
                    }
                    $itemsByTitle[$normTitle][] = (int)$dbItem['id'];
                    $extra = $dbItem['extra_data'] ? json_decode($dbItem['extra_data'], true) : [];
                    $existingExtras[(int)$dbItem['id']] = is_array($extra) ? $extra : [];
                }

                // Idem no XML: dois itens com o mesmo título e palavras-chave diferentes não dizem qual vai para qual item
                $xmlProductions = [];
                $this->collectXmlProductions($xml, $xmlProductions);
                $keywordsByTitle = [];
                foreach ($xmlProductions as $xp) {
                    $normXpTitle = $this->normalizeTitle($xp['title']);
                    if ($normXpTitle !== '') {
                        $keywordsByTitle[$normXpTitle][] = $xp['keywords'];
                    }
                }

                foreach ($keywordsByTitle as $normXpTitle => $keywordLists) {
                    $dbIds = $itemsByTitle[$normXpTitle] ?? [];
                    if ($dbIds === []) {
                        continue;
                    }
                    if (count($keywordLists) !== 1 || count($dbIds) !== 1) {
                        $ambiguousTitles++;
                        continue;
                    }

                    $keywords = $keywordLists[0];
                    if ($keywords === []) {
                        continue;
                    }

                    $prodId = $dbIds[0];
                    $currentExtra = $existingExtras[$prodId] ?? [];
                    // Palavras-chave que já estão no item (gravadas pelo importador ou à mão) não são trocadas
                    if (!empty($currentExtra['keywords'])) {
                        $alreadyHadKeywords++;
                        continue;
                    }

                    $currentExtra['keywords'] = $keywords;
                    $conn->executeStatement(
                        'UPDATE production_items SET extra_data = ? WHERE id = ?',
                        [json_encode($currentExtra, JSON_UNESCAPED_UNICODE), $prodId]
                    );
                    $existingExtras[$prodId] = $currentExtra;
                    $updatedCount++;
                    $totalFoundKws += count($keywords);
                }
            } catch (\Throwable $e) {
                // O erro não pode sumir: o arquivo seguinte continua, mas o comando termina com falha
                $errors[] = sprintf('%s: %s', $file->getFilename(), $e->getMessage());
            }

            $progressBar->advance();
            if ($index % 25 === 0) {
                gc_collect_cycles();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Sincronização concluída! %d produções atualizadas com um total de %d palavras-chave oficiais cadastradas.',
            $updatedCount,
            $totalFoundKws
        ));

        if ($alreadyHadKeywords > 0) {
            $io->note(sprintf('%d produção(ões) já tinham palavras-chave e foram mantidas como estavam.', $alreadyHadKeywords));
        }
        if ($ambiguousTitles > 0) {
            $io->note(sprintf('%d título(s) repetido(s) no mesmo docente foram ignorados: não dá para saber qual item recebe as palavras-chave.', $ambiguousTitles));
        }
        if ($unmatchedFiles > 0) {
            $io->note(sprintf('%d arquivo(s) sem docente correspondente no banco foram ignorados.', $unmatchedFiles));
        }

        if ($errors !== []) {
            $io->warning(sprintf('%d arquivo(s) apresentaram erro:', count($errors)));
            $io->listing(array_slice($errors, 0, 10));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function collectXmlProductions(\SimpleXMLElement $xml, array &$list): void
    {
        // 1. Artigos
        if (isset($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'ARTIGOS-PUBLICADOS'})) {
            foreach ($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'ARTIGOS-PUBLICADOS'}->{'ARTIGO-PUBLICADO'} as $item) {
                $basic = $item->{'DADOS-BASICOS-DO-ARTIGO'} ?? null;
                if ($basic) {
                    $list[] = [
                        'title' => (string)($basic['TITULO-DO-ARTIGO'] ?? ''),
                        'keywords' => $this->extractKeywords($item),
                    ];
                }
            }
        }

        // 2. Livros
        if (isset($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'LIVROS-E-CAPITULOS'}->{'LIVROS-PUBLICADOS-OU-ORGANIZADOS'})) {
            foreach ($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'LIVROS-E-CAPITULOS'}->{'LIVROS-PUBLICADOS-OU-ORGANIZADOS'}->{'LIVRO-PUBLICADO-OU-ORGANIZADO'} as $item) {
                $basic = $item->{'DADOS-BASICOS-DO-LIVRO'} ?? null;
                if ($basic) {
                    $list[] = [
                        'title' => (string)($basic['TITULO-DO-LIVRO'] ?? ''),
                        'keywords' => $this->extractKeywords($item),
                    ];
                }
            }
        }

        // 3. Capítulos
        if (isset($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'LIVROS-E-CAPITULOS'}->{'CAPITULOS-DE-LIVROS-PUBLICADOS'})) {
            foreach ($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'LIVROS-E-CAPITULOS'}->{'CAPITULOS-DE-LIVROS-PUBLICADOS'}->{'CAPITULO-DE-LIVRO-PUBLICADO'} as $item) {
                $basic = $item->{'DADOS-BASICOS-DO-CAPITULO'} ?? null;
                if ($basic) {
                    $list[] = [
                        'title' => (string)($basic['TITULO-DO-CAPITULO-DO-LIVRO'] ?? ''),
                        'keywords' => $this->extractKeywords($item),
                    ];
                }
            }
        }

        // 4. Trabalhos em eventos
        if (isset($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'TRABALHOS-EM-EVENTOS'})) {
            foreach ($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'TRABALHOS-EM-EVENTOS'}->{'TRABALHO-EM-EVENTOS'} as $item) {
                $basic = $item->{'DADOS-BASICOS-DO-TRABALHO'} ?? null;
                if ($basic) {
                    $list[] = [
                        'title' => (string)($basic['TITULO-DO-TRABALHO'] ?? ''),
                        'keywords' => $this->extractKeywords($item),
                    ];
                }
            }
        }

        // 5. Jornais/revistas
        if (isset($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'TEXTOS-EM-JORNAIS-OU-REVISTAS'})) {
            foreach ($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'TEXTOS-EM-JORNAIS-OU-REVISTAS'}->{'TEXTO-EM-JORNAL-OU-REVISTA'} as $item) {
                $basic = $item->{'DADOS-BASICOS-DO-TEXTO'} ?? null;
                if ($basic) {
                    $list[] = [
                        'title' => (string)($basic['TITULO-DO-TEXTO'] ?? ''),
                        'keywords' => $this->extractKeywords($item),
                    ];
                }
            }
        }

        // 6. Demais produções bibliográficas
        if (isset($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'DEMAIS-TIPOS-DE-PRODUCAO-BIBLIOGRAFICA'})) {
            foreach ($xml->{'PRODUCAO-BIBLIOGRAFICA'}->{'DEMAIS-TIPOS-DE-PRODUCAO-BIBLIOGRAFICA'}->children() as $tag => $item) {
                $basic = $item->children()[0] ?? null;
                if ($basic) {
                    $title = (string)($basic['TITULO'] ?? $basic['TITULO-DO-ARTIGO'] ?? $basic['TITULO-DO-TRABALHO'] ?? '');
                    if ($title !== '') {
                        $list[] = [
                            'title' => $title,
                            'keywords' => $this->extractKeywords($item),
                        ];
                    }
                }
            }
        }

        // 7. Produção Técnica
        if (isset($xml->{'PRODUCAO-TECNICA'})) {
            foreach ($xml->{'PRODUCAO-TECNICA'}->children() as $tag => $item) {
                $basic = $item->children()[0] ?? null;
                if ($basic) {
                    $title = (string)($basic['TITULO'] ?? $basic['TITULO-DO-TRABALHO-TECNICO'] ?? $basic['TITULO-DO-SOFTWARE'] ?? '');
                    if ($title !== '') {
                        $list[] = [
                            'title' => $title,
                            'keywords' => $this->extractKeywords($item),
                        ];
                    }
                }
            }
        }
    }

    private function extractKeywords(\SimpleXMLElement $item): array
    {
        $keywords = [];
        $kwNode = $item->{'PALAVRAS-CHAVE'} ?? null;
        if ($kwNode) {
            for ($i = 1; $i <= 6; $i++) {
                $kw = trim((string)($kwNode["PALAVRA-CHAVE-$i"] ?? ''));
                if ($kw !== '') {
                    $keywords[] = $kw;
                }
            }
        }
        return $keywords;
    }

    private function normalizeTitle(string $title): string
    {
        $str = mb_strtolower(trim($title), 'UTF-8');
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', '', $str);
        return preg_replace('/\s+/', ' ', $str);
    }
}
