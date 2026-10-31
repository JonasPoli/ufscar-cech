<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Command\JournalsEvidenceBuildCommand;
use App\Service\Journal\JournalEvidenceArtifactBuilder;
use App\Service\Journal\JournalEvidenceArtifactReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Constrói o artefato de evidências com mini-arquivos de cada fonte (tests/fixtures/journal_sources/) e confere
 * o formato de A6.1: campos exatos de cada registro, descarte de ISSN inválido, deduplicação do WoS, `share` do
 * OpenAlex, ordenação, `_meta`, leitura de volta com o reader e vocabulários. Não usa banco.
 */
class JournalEvidenceArtifactBuilderTest extends TestCase
{
    private string $fixtures;
    private string $workDir;
    private JournalEvidenceArtifactBuilder $builder;
    private JournalEvidenceArtifactReader $reader;

    protected function setUp(): void
    {
        $this->fixtures = dirname(__DIR__, 2) . '/fixtures/journal_sources';
        $this->workDir = sys_get_temp_dir() . '/journal_evidence_test_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0775, true);

        $this->builder = new JournalEvidenceArtifactBuilder();
        $this->reader = new JournalEvidenceArtifactReader();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Formato dos registros
    // ---------------------------------------------------------------------------------------------------------

    public function testCadaFonteGeraRegistrosComOsCamposExatosDeA61(): void
    {
        $by = $this->recordsBySource($this->buildAll()['out']);

        $this->assertSame([
            'src' => 'scopus',
            'rec' => '21101170611',
            'issns' => ['20349130', '22959149'],
            'title' => '@GRH',
            'labels' => ['1407'],
            'extra' => [
                'publisher' => 'Association Francophone de Gestion des Relations Humaines',
                'active' => true,
                'type' => 'Journal',
            ],
        ], $by['scopus']['21101170611']);

        $this->assertSame([
            'src' => 'wos',
            'rec' => '00013072',
            'issns' => ['00013072', '14676281'],
            'title' => 'ABACUS-A JOURNAL OF ACCOUNTING FINANCE AND BUSINESS STUDIES',
            'labels' => ['Business, Finance'],
            'extra' => ['indexes' => ['SSCI']],
        ], $by['wos']['00013072']);

        $this->assertSame([
            'src' => 'doaj',
            'rec' => '27076946',
            'issns' => ['27076946', '27076954'],
            'title' => 'Revista Peruana de Ciencias de la Salud',
            'labels' => ['Medicine: Medicine (General)'],
            'extra' => [
                'keywords' => ['health sciences', 'public health', 'medicine', 'nursing', 'environmental health', 'health education'],
                'country' => 'Peru',
                'publisher' => 'Universidad de Huánuco',
            ],
        ], $by['doaj']['27076946']);

        // O arquivo do Latindex tem BOM e delimitador `;`; `subtemas` vêm separados por vírgula.
        $this->assertSame([
            'src' => 'latindex',
            'rec' => '23289',
            'issns' => ['2683166x'],
            'title' => '(an)ecdótica',
            'labels' => ['Literatura', 'Filología'],
            'extra' => [
                'country' => 'México',
                'publisher' => 'Universidad Nacional Autónoma de México, Instituto de Investigaciones Filológicas',
            ],
        ], $by['latindex']['23289']);

        $this->assertSame([
            'src' => 'scielo',
            'rec' => '0103-863X',
            'issns' => ['0103863x', '19824327'],
            'title' => 'Paidéia (Ribeirão Preto)',
            'labels' => ['Human Sciences'],
            'extra' => [
                'wos' => ['PSYCHOLOGY, MULTIDISCIPLINARY'],
                'desc' => ['PSICOLOGIA'],
                'status' => 'C',
                'issn_types' => ['0103863x' => 'PRINT', '19824327' => 'ONLIN'],
            ],
        ], $by['scielo']['0103-863X']);

        $this->assertSame([
            'src' => 'pubmed',
            'rec' => '7708172',
            'issns' => ['01606999'],
            'title' => "AADE editors' journal",
            'labels' => [],
            'extra' => ['medabbr' => 'AADE Ed J'],
        ], $by['pubmed']['7708172']);

        $this->assertSame([
            'src' => 'openalex',
            'rec' => 'S2764905038',
            'issns' => ['21945357', '21945365'],
            'title' => 'Advances in intelligent systems and computing',
            'labels' => [
                ['code' => '1702', 'share' => 0.7273],
                ['code' => '1710', 'share' => 0.1818],
                ['code' => '1708', 'share' => 0.0909],
            ],
            'extra' => [
                'works' => 73533,
                'country' => 'DE',
                'publisher' => 'Springer Science+Business Media',
                'in_doaj' => false,
                'in_scielo' => false,
                'type' => 'journal',
                'alt_titles' => ['Adv Intell Syst Comput', 'AISC'],
            ],
        ], $by['openalex']['S2764905038']);
    }

    public function testScopusMantemTipoDaFonteStatusAtivoEMultiplosCodigosAsjc(): void
    {
        $scopus = $this->recordsBySource($this->buildAll()['out'])['scopus'];

        $this->assertCount(3, $scopus);
        // Série de livros ativa: o tipo é mantido e os quatro códigos ASJC (texto separado por `;`) viram quatro rótulos.
        $this->assertSame(['1202', '3322', '2215', '2216'], $scopus['21100780699']['labels']);
        $this->assertSame('Book Series', $scopus['21100780699']['extra']['type']);
        $this->assertTrue($scopus['21100780699']['extra']['active']);
        // Periódico inativo, só com ISSN impresso.
        $this->assertFalse($scopus['21100902951']['extra']['active']);
        $this->assertSame(['00181390'], $scopus['21100902951']['issns']);
        $this->assertSame(['2708', '2725'], $scopus['21100902951']['labels']);
    }

    public function testIssnInvalidoEDescartadoEORegistroSemIssnSobraPeloTitulo(): void
    {
        $by = $this->recordsBySource($this->buildAll()['out']);

        // DOAJ: o ISSN impresso 1580-0262 tem dígito verificador inválido e sai; o eletrônico 2350-4234 fica.
        $keria = $by['doaj']['23504234'];
        $this->assertSame(['23504234'], $keria['issns']);

        // WoS: só havia o ISSN inválido 1234-5678; o registro fica com o título e a chave vira um md5.
        $semIssn = array_values(array_filter($by['wos'], static fn (array $r): bool => $r['title'] === 'REVISTA DE TESTE COM ISSN INVALIDO'));
        $this->assertCount(1, $semIssn);
        $this->assertSame([], $semIssn[0]['issns']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $semIssn[0]['rec']);

        // PubMed: bloco sem nenhum ISSN também é mantido (tem título).
        $this->assertSame([], $by['pubmed']['9999999']['issns']);
        $this->assertSame('Periodico de teste sem ISSN', $by['pubmed']['9999999']['title']);
    }

    public function testWosEDeduplicadoPeloConjuntoDeIssnsComUniaoDeCategoriasEIndices(): void
    {
        $result = $this->buildAll();
        $wos = $this->recordsBySource($result['out'])['wos'];

        // 4 linhas em 2 arquivos (SSCI e Current Contents), mas ACADEMIA aparece nos dois: 3 registros.
        $this->assertSame(3, $result['sources']['wos']['records']);
        $this->assertSame(2, $result['sources']['wos']['files']);
        $this->assertCount(3, $wos);

        $academia = $wos['10128255'];
        $this->assertSame(['10128255', '20565127'], $academia['issns']);
        $this->assertSame(['Business', 'Management', 'Management & Organization'], $academia['labels']);
        $this->assertSame(['SSCI', 'Current Contents Business Collection'], $academia['extra']['indexes']);
    }

    public function testOpenAlexIgnoraTypeDiferenteDeJournalESemIssnEOsSharesSomamUm(): void
    {
        $result = $this->buildAll();
        $openalex = $this->recordsBySource($result['out'])['openalex'];

        // O fixture tem 4 fontes: 1 repositório, 1 periódico sem ISSN e 2 periódicos válidos.
        $this->assertSame(['S2764905038', 'S4210175132'], array_keys($openalex));
        $this->assertSame(2, $result['sources']['openalex']['records']);
        $this->assertStringContainsString('1 com type diferente de journal', implode(' ', $result['notes']));

        $shares = array_column($openalex['S2764905038']['labels'], 'share');
        $this->assertEqualsWithDelta(1.0, array_sum($shares), 0.01);
        // Dois tópicos do mesmo subcampo (1702) são somados; maior participação primeiro.
        $this->assertSame(['1702', '1710', '1708'], array_column($openalex['S2764905038']['labels'], 'code'));
        // Periódico sem tópicos: sem rótulos.
        $this->assertSame([], $openalex['S4210175132']['labels']);
    }

    public function testOpenAlexUsaAsPaginasLocaisQuandoNaoHaSnapshotEIgnoraPaginaSemResults(): void
    {
        $sources = $this->workDir . '/sources';
        $pages = $sources . '/openalex/openalex_journals';
        mkdir($pages, 0775, true);

        $journal = [
            'id' => 'https://openalex.org/S1', 'issn_l' => '2194-5357', 'issn' => ['2194-5357'], 'display_name' => 'Journal One',
            'works_count' => 10, 'type' => 'journal', 'country_code' => 'BR',
            'topics' => [['count' => 4, 'subfield' => ['id' => 'https://openalex.org/subfields/3304', 'display_name' => 'Education']]],
        ];
        file_put_contents($pages . '/pagina_1.json', json_encode(['meta' => ['count' => 2], 'results' => [
            $journal,
            ['id' => 'https://openalex.org/S2', 'issn' => ['2331-8422'], 'display_name' => 'Repository', 'type' => 'repository'],
        ]]));
        // Página de erro da API (sem `results`) e página que repete uma fonte já lida.
        file_put_contents($pages . '/pagina_2.json', json_encode(['error' => 'Gateway timeout', 'reason' => 'query_timeout']));
        file_put_contents($pages . '/pagina_3.json', json_encode(['results' => [$journal]]));
        // Arquivo AppleDouble do macOS: tem que ser ignorado sem sequer ser aberto.
        file_put_contents($pages . '/._pagina_1.json', "\x00\x05\x16\x07 lixo binário");

        $result = $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', null);
        $records = iterator_to_array($this->reader->read($result['out']), false);

        $this->assertCount(1, $records);
        $this->assertSame('S1', $records[0]['rec']);
        $this->assertSame([['code' => '3304', 'share' => 1.0]], $records[0]['labels']);
        $this->assertSame('openalex_journals/pagina_*.json', $result['sources']['openalex']['file']);
        $this->assertSame(2, $result['sources']['openalex']['files']);
        $this->assertStringContainsString('pagina_2.json sem "results"', implode(' ', $result['warnings']));
        $this->assertStringContainsString('1 repetidas', implode(' ', $result['notes']));
    }

    public function testSnapshotDoOpenAlexTemPrioridadeSobreAsPaginasLocais(): void
    {
        $sources = $this->workDir . '/sources';
        mkdir($sources . '/openalex/openalex_journals', 0775, true);
        mkdir($sources . '/openalex/snapshot/updated_date=2026-10-14', 0775, true);
        file_put_contents($sources . '/openalex/openalex_journals/pagina_1.json', json_encode(['results' => [
            ['id' => 'https://openalex.org/S9', 'issn' => ['2194-5357'], 'display_name' => 'Da pagina', 'type' => 'journal'],
        ]]));
        file_put_contents(
            $sources . '/openalex/snapshot/updated_date=2026-10-14/part_000.gz',
            gzencode(json_encode(['id' => 'https://openalex.org/S8', 'issn' => ['2194-5365'], 'display_name' => 'Do snapshot', 'type' => 'journal']) . "\n")
        );

        $result = $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', null);
        $records = iterator_to_array($this->reader->read($result['out']), false);

        $this->assertSame(['S8'], array_column($records, 'rec'));
        $this->assertSame('snapshot/**/*.gz', $result['sources']['openalex']['file']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Ordenação, _meta e leitura de volta
    // ---------------------------------------------------------------------------------------------------------

    public function testRegistrosSaemOrdenadosPorSrcRecETitle(): void
    {
        $records = iterator_to_array($this->reader->read($this->buildAll()['out']), false);
        $this->assertCount(17, $records);

        $keys = array_map(static fn (array $r): array => [$r['src'], $r['rec'], $r['title']], $records);
        $sorted = $keys;
        usort($sorted, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]) ?: strcmp($a[2], $b[2]));

        $this->assertSame($sorted, $keys);
        // Fontes em ordem alfabética.
        $this->assertSame(
            ['doaj', 'latindex', 'openalex', 'pubmed', 'scielo', 'scopus', 'wos'],
            array_values(array_unique(array_column($records, 'src')))
        );
    }

    public function testMetaTemAsContagensDeCadaFonteEOReaderLeDeVolta(): void
    {
        $result = $this->buildAll();
        $meta = $this->reader->meta($result['out']);
        $records = iterator_to_array($this->reader->read($result['out']), false);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $meta['built_at']);
        $this->assertSame(['doaj', 'latindex', 'openalex', 'pubmed', 'scielo', 'scopus', 'wos'], array_keys($meta['sources']));
        $this->assertSame(['file' => 'mini.xlsx', 'records' => 3], $meta['sources']['scopus']);
        $this->assertSame(['file' => 'wos/*.csv', 'files' => 2, 'records' => 3], $meta['sources']['wos']);

        $counted = array_count_values(array_column($records, 'src'));
        foreach ($meta['sources'] as $source => $info) {
            $this->assertSame($info['records'], $counted[$source], "contagem de _meta.sources.$source");
        }
        // O reader pula `_meta` e devolve só registros.
        $this->assertSame(17, array_sum(array_column($meta['sources'], 'records')));
        $this->assertSame(17, count($records));
        $this->assertSame($result['records'], count($records));
        foreach ($records as $record) {
            $this->assertArrayNotHasKey('_meta', $record);
            $this->assertSame(['src', 'rec', 'issns', 'title', 'labels', 'extra'], array_keys($record));
        }

        // Primeira linha do arquivo: o envoltório `_meta`.
        $firstLine = (string) strtok((string) shell_exec('gunzip -c ' . escapeshellarg($result['out'])), "\n");
        $this->assertSame(['_meta' => $meta], json_decode($firstLine, true));
    }

    public function testReaderFalhaComMensagemClaraQuandoOArquivoNaoExiste(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('não encontrado');

        iterator_to_array($this->reader->read($this->workDir . '/nao-existe.jsonl.gz'));
    }

    public function testMesmaEntradaGeraOMesmoConteudoAlemDoBuiltAt(): void
    {
        $first = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/a.jsonl.gz', null);
        $second = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/b.jsonl.gz', null);

        $linesA = explode("\n", (string) shell_exec('gunzip -c ' . escapeshellarg($first['out'])));
        $linesB = explode("\n", (string) shell_exec('gunzip -c ' . escapeshellarg($second['out'])));

        // A primeira linha (`_meta`) traz `built_at`; o resto tem que ser idêntico, linha a linha.
        $this->assertGreaterThan(10, count($linesA));
        $this->assertSame(array_slice($linesB, 1), array_slice($linesA, 1));
    }

    public function testScopusRecuperaZerosDoIssnGravadoComoNumeroEIgnoraOutrosArquivosDaPasta(): void
    {
        $sources = $this->workDir . '/sources';
        mkdir($sources . '/scopus', 0775, true);

        // Lista de fontes com o ISSN impresso gravado como número (0018-1390 vira 181390 no Excel) e o ASJC numérico.
        $sources1 = new Spreadsheet();
        $sheet = $sources1->getActiveSheet();
        $sheet->setTitle('Scopus Sources Jul. 2026');
        foreach (['Sourcerecord ID', 'Source Title', 'ISSN', 'EISSN', 'Active or Inactive', 'Source Type', 'Publisher', 'All Science Journal Classification Codes (ASJC)'] as $i => $header) {
            $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->setCellValueExplicit('A2', 1, DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('B2', 'Revista Numerica', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C2', 181390, DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit('E2', 'Active', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('F2', 'Journal', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('G2', 'Editora Teste', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('H2', 3304, DataType::TYPE_NUMERIC);
        IOFactory::createWriter($sources1, 'Xlsx')->save($sources . '/scopus/sources.xlsx');

        // Lista de livros do Scopus: não tem a aba "Scopus Sources" e não pode entrar.
        $books = new Spreadsheet();
        $books->getActiveSheet()->setTitle('Scopus book list_Q2');
        $books->getActiveSheet()->setCellValueExplicit('A1', 'Book Title', DataType::TYPE_STRING);
        IOFactory::createWriter($books, 'Xlsx')->save($sources . '/scopus/book_list.xlsx');

        // Arquivo AppleDouble e arquivo de bloqueio do Excel: ignorados sem tentar abrir.
        file_put_contents($sources . '/scopus/._sources.xlsx', 'lixo binario');
        file_put_contents($sources . '/scopus/~$sources.xlsx', 'bloqueio');

        $result = $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', null);
        $records = iterator_to_array($this->reader->read($result['out']), false);

        $this->assertSame(['file' => 'sources.xlsx', 'records' => 1], $result['sources']['scopus']);
        // Os arquivos extras não foram abertos (senão haveria aviso de arquivo ilegível).
        $this->assertStringNotContainsString('não foi possível abrir', implode(' ', $result['warnings']));
        $this->assertSame(['doaj', 'latindex', 'openalex', 'pubmed', 'scielo', 'wos'], $result['missing']);
        $this->assertSame(['00181390'], $records[0]['issns']);
        $this->assertSame(['3304'], $records[0]['labels']);
        $this->assertSame('1', $records[0]['rec']);
    }

    public function testCsvSemAsColunasEsperadasFalhaComMensagemClara(): void
    {
        $sources = $this->workDir . '/sources';
        mkdir($sources . '/doaj', 0775, true);
        file_put_contents($sources . '/doaj/doaj.csv', "Titulo,Outra coluna\nUm periodico,x\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Colunas ausentes em doaj.csv');

        $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', null);
    }

    public function testSnapshotTruncadoDoOpenAlexGeraAvisoEMantemOsRegistrosCompletos(): void
    {
        $sources = $this->workDir . '/sources';
        mkdir($sources . '/openalex/snapshot/updated_date=2026-10-14', 0775, true);

        // 600 periódicos distintos, cada um com um ISSN de dígito verificador correto.
        $checkDigit = static function (string $first7): string {
            $sum = 0;
            for ($i = 0; $i < 7; ++$i) {
                $sum += (int) $first7[$i] * (8 - $i);
            }
            $digit = (11 - $sum % 11) % 11;

            return $digit === 10 ? 'x' : (string) $digit;
        };
        $lines = [];
        for ($n = 1000000; $n < 1000600; ++$n) {
            $issn = $n . $checkDigit((string) $n);
            $lines[] = json_encode(['id' => 'https://openalex.org/S' . $n, 'issn' => [$issn], 'display_name' => 'Periodico ' . $n, 'type' => 'journal', 'works_count' => $n]);
        }
        $whole = gzencode(implode("\n", $lines) . "\n", 9);
        file_put_contents($sources . '/openalex/snapshot/updated_date=2026-10-14/part_000.gz', substr($whole, 0, (int) (strlen($whole) * 0.6)));

        $result = $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', null);

        $this->assertGreaterThan(100, $result['sources']['openalex']['records']);
        $this->assertLessThan(600, $result['sources']['openalex']['records']);
        $this->assertStringContainsString('part_000.gz tem 1 linha(s) que não são JSON (download truncado?)', implode(' ', $result['warnings']));
    }

    public function testSnapshotSemTopicsAvisaENaoEsvaziaOVocabularioExistente(): void
    {
        $sources = $this->workDir . '/sources';
        mkdir($sources . '/openalex/snapshot/updated_date=2026-10-14', 0775, true);
        file_put_contents(
            $sources . '/openalex/snapshot/updated_date=2026-10-14/part_000.gz',
            gzencode(json_encode(['id' => 'https://openalex.org/S8', 'issn' => ['2194-5365'], 'display_name' => 'Sem topics', 'type' => 'journal']) . "\n")
        );
        $vocab = $this->workDir . '/vocab';
        mkdir($vocab);
        file_put_contents($vocab . '/openalex_subfields.txt', "1702\tArtificial Intelligence\n");

        $result = $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', $vocab);

        $this->assertStringContainsString('nenhuma fonte trouxe "topics"', implode(' ', $result['warnings']));
        $this->assertStringContainsString('openalex_subfields.txt ficou vazio', implode(' ', $result['warnings']));
        $this->assertArrayNotHasKey('openalex_subfields', $result['vocab']);
        $this->assertSame("1702\tArtificial Intelligence\n", file_get_contents($vocab . '/openalex_subfields.txt'));
    }

    public function testFonteEncontradaMasSemRegistrosAbortaOBuild(): void
    {
        $sources = $this->workDir . '/sources';
        mkdir($sources . '/doaj', 0775, true);
        // Cabeçalho certo, nenhuma linha: gravar uma fonte vazia faria o carregamento apagar as evidências dela.
        file_put_contents($sources . '/doaj/doaj.csv', "Journal title,Journal ISSN (print version),Journal EISSN (online version),Subjects\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('doaj: o arquivo foi encontrado mas não gerou nenhum registro');

        $this->builder->build($sources, $sources, $this->workDir . '/out.jsonl.gz', null);
    }

    // ---------------------------------------------------------------------------------------------------------
    // --skip, fontes ausentes
    // ---------------------------------------------------------------------------------------------------------

    public function testSkipDeixaAFonteForaDoArtefatoEDoMetaEDosVocabularios(): void
    {
        $vocab = $this->workDir . '/vocab';
        $result = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out.jsonl.gz', $vocab, null, ['openalex', 'scopus']);
        $records = iterator_to_array($this->reader->read($result['out']), false);

        $this->assertSame(['openalex', 'scopus'], $result['skipped']);
        $this->assertSame(['doaj', 'latindex', 'pubmed', 'scielo', 'wos'], array_keys($this->reader->meta($result['out'])['sources']));
        $this->assertNotContains('openalex', array_column($records, 'src'));
        $this->assertNotContains('scopus', array_column($records, 'src'));
        // Sem as fontes, não há de onde tirar os vocabulários de ASJC e de subcampos do OpenAlex.
        $this->assertFileDoesNotExist($vocab . '/asjc.txt');
        $this->assertFileDoesNotExist($vocab . '/openalex_subfields.txt');
        $this->assertFileExists($vocab . '/wos.txt');
    }

    public function testSkipAceitaListaSeparadaPorVirgulaERejeitaFonteDesconhecida(): void
    {
        $result = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out.jsonl.gz', null, null, ['openalex,scopus,pubmed']);
        $this->assertSame(['openalex', 'pubmed', 'scopus'], $result['skipped']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fonte desconhecida');
        $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out2.jsonl.gz', null, null, ['jcr']);
    }

    public function testFonteScieloAusenteEApenasUmAvisoENaoAbortaOBuild(): void
    {
        $emptyDerived = $this->workDir . '/derived';
        mkdir($emptyDerived);

        $result = $this->builder->build($this->fixtures, $emptyDerived, $this->workDir . '/out.jsonl.gz', null);
        $meta = $this->reader->meta($result['out']);

        $this->assertSame(['scielo'], $result['missing']);
        $this->assertArrayNotHasKey('scielo', $meta['sources']);
        $this->assertArrayHasKey('scopus', $meta['sources']);
        $this->assertStringContainsString('scielo_articlemeta.json', implode(' ', $result['warnings']));
    }

    public function testSemNenhumaFonteNaoGravaArtefato(): void
    {
        $empty = $this->workDir . '/vazio';
        mkdir($empty);
        $out = $this->workDir . '/out.jsonl.gz';

        try {
            $this->builder->build($empty, $empty, $out, null);
            $this->fail('Deveria lançar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nenhuma fonte foi lida', $e->getMessage());
        }
        $this->assertFileDoesNotExist($out);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Vocabulários
    // ---------------------------------------------------------------------------------------------------------

    public function testVocabulariosSaoGravadosEArquivoIgualNaoEReescrito(): void
    {
        $vocab = $this->workDir . '/vocab';
        $first = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out.jsonl.gz', $vocab);

        foreach (['wos', 'doaj', 'latindex', 'scielo_desc', 'asjc', 'openalex_subfields'] as $name) {
            $this->assertSame('created', $first['vocab'][$name]['status'], $name);
        }
        $this->assertSame("Business\nBusiness, Finance\nEducation & Educational Research\nManagement\nManagement & Organization\n", file_get_contents($vocab . '/wos.txt'));
        $this->assertSame("Language and Literature: Greek language and literature. Latin language and literature\nMedicine: Medicine (General)\n", file_get_contents($vocab . '/doaj.txt'));
        $this->assertSame("Antropología\nFilología\nLiteratura\n", file_get_contents($vocab . '/latindex.txt'));
        $this->assertSame("EDUCAÇÃO\nEDUCAÇÃO E PESQUISA\nPSICOLOGIA\n", file_get_contents($vocab . '/scielo_desc.txt'));
        // ASJC e subcampos do OpenAlex: `código<TAB>descrição`, por código; só linhas com código numérico.
        $this->assertSame(
            "1000\tMultidisciplinary\n1100\tGeneral Agricultural and Biological Sciences\n1101\tAgricultural and Biological Sciences (miscellaneous)\n3300\tGeneral Social Sciences\n3304\tEducation\n",
            file_get_contents($vocab . '/asjc.txt')
        );
        $this->assertSame(
            "1702\tArtificial Intelligence\n1708\tHardware and Architecture\n1710\tInformation Systems\n",
            file_get_contents($vocab . '/openalex_subfields.txt')
        );

        // Segunda execução: conteúdo igual, nenhum arquivo é regravado (a data de modificação fica a mesma).
        $past = time() - 86400;
        foreach (array_keys($first['vocab']) as $name) {
            touch($vocab . '/' . $name . '.txt', $past);
        }
        clearstatcache();
        $second = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out.jsonl.gz', $vocab);
        clearstatcache();

        foreach (array_keys($first['vocab']) as $name) {
            $this->assertSame('unchanged', $second['vocab'][$name]['status'], $name);
            $this->assertSame($past, filemtime($vocab . '/' . $name . '.txt'), $name);
        }
        $this->assertSame([], $second['warnings']);
    }

    public function testVocabularioDiferenteDoExistenteEGravadoEAvisado(): void
    {
        $vocab = $this->workDir . '/vocab';
        mkdir($vocab);
        file_put_contents($vocab . '/wos.txt', "Business\nRotulo antigo\n");

        $result = $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out.jsonl.gz', $vocab);

        $this->assertSame('changed', $result['vocab']['wos']['status']);
        $this->assertSame(['Rotulo antigo'], $result['vocab']['wos']['removed']);
        $this->assertContains('Management', $result['vocab']['wos']['added']);
        $this->assertStringContainsString('wos.txt difere do existente', implode(' ', $result['warnings']));
        $this->assertStringNotContainsString('Rotulo antigo', (string) file_get_contents($vocab . '/wos.txt'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Comando
    // ---------------------------------------------------------------------------------------------------------

    public function testComandoConstroiOArtefatoComAsOpcoes(): void
    {
        $command = new JournalsEvidenceBuildCommand($this->builder, dirname(__DIR__, 3));
        $tester = new CommandTester($command);
        $out = $this->workDir . '/via-comando.jsonl.gz';

        $exit = $tester->execute([
            '--sources' => $this->fixtures,
            '--derived' => $this->fixtures,
            '--out' => $out,
            '--vocab-dir' => $this->workDir . '/vocab',
            '--skip' => ['openalex,scopus'],
        ]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertStringContainsString('Artefato gravado em', $tester->getDisplay());
        $this->assertStringContainsString('Fontes puladas: openalex, scopus', $tester->getDisplay());
        $this->assertSame(['doaj', 'latindex', 'pubmed', 'scielo', 'wos'], array_keys($this->reader->meta($out)['sources']));
        $this->assertFileExists($this->workDir . '/vocab/wos.txt');
    }

    public function testComandoFalhaComMensagemQuandoAFonteDoSkipNaoExiste(): void
    {
        $tester = new CommandTester(new JournalsEvidenceBuildCommand($this->builder, dirname(__DIR__, 3)));

        $exit = $tester->execute([
            '--sources' => $this->fixtures,
            '--derived' => $this->fixtures,
            '--out' => $this->workDir . '/x.jsonl.gz',
            '--vocab-dir' => $this->workDir . '/vocab',
            '--skip' => ['jcr'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Fonte desconhecida', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->workDir . '/x.jsonl.gz');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Apoio
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Constrói o artefato com todas as fontes dos fixtures (sem gravar vocabulários).
     *
     * @return array<string, mixed>
     */
    private function buildAll(): array
    {
        return $this->builder->build($this->fixtures, $this->fixtures, $this->workDir . '/out.jsonl.gz', null);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> fonte => rec => registro
     */
    private function recordsBySource(string $artifact): array
    {
        $by = [];
        foreach ($this->reader->read($artifact) as $record) {
            $by[$record['src']][$record['rec']] = $record;
        }

        return $by;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $info) {
            $info->isDir() ? rmdir($info->getPathname()) : unlink($info->getPathname());
        }
        rmdir($dir);
    }
}
