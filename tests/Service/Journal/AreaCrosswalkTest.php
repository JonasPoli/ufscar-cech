<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Service\Journal\AreaCrosswalk;
use App\Service\Journal\CapesTaxonomy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Garante os crosswalks de área: os 334 códigos ASJC contra o vocabulário versionado (com os exemplos de C.1),
 * as 31 regras de título (C.6), os 8 pares SciELO por área (C.5), as áreas do CNPq e o comportamento do serviço com
 * arquivos temporários. Não usa o MySQL nem o kernel: as correções manuais do tesauro de categorias são testadas com
 * um SQLite em memória, que só tem as duas tabelas lidas pelo serviço.
 */
class AreaCrosswalkTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            }
        }
        $this->temporaryPaths = [];

        parent::tearDown();
    }

    public function testVocabularioAsjcTem334CodigosCobertos(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());
        $taxonomy = new CapesTaxonomy();

        $lines = file(self::projectDirectory() . '/vocab/asjc.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(334, $lines);

        $codes = [];
        foreach ($lines as $line) {
            $columns = explode("\t", $line);
            $this->assertCount(2, $columns, sprintf('Linha fora do formato código<TAB>descrição: "%s".', $line));
            $this->assertNotSame('', trim($columns[1]));
            $codes[] = $columns[0];
        }

        $this->assertCount(334, array_unique($codes));
        $this->assertCount(334, $crosswalk->knownLabels('asjc'));

        foreach ($codes as $code) {
            $this->assertTrue($crosswalk->isKnown('asjc', $code), sprintf('O código ASJC %s não tem entrada no crosswalk.', $code));
            foreach ($crosswalk->map('asjc', $code) as $slug) {
                $this->assertTrue($taxonomy->isValidSlug($slug), sprintf('O código %s aponta para o slug inválido "%s".', $code, $slug));
            }
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function exemplosAsjcProvider(): iterable
    {
        // Exemplos do aceite da T03.
        yield '3304 Educação' => ['3304', ['educacao']];
        yield '3312 Sociologia e ciência política' => ['3312', ['sociologia', 'ciencia-politica-relacoes-internacionais']];
        yield '1000 Multidisciplinary' => ['1000', ['interdisciplinar']];
        yield '2746 medicina II' => ['2746', ['medicina-ii']];
        yield '1105 biodiversidade' => ['1105', ['biodiversidade']];
        // Sem exceção: vale o padrão do prefixo, inclusive quando o padrão é uma lista.
        yield '1101 padrão do prefixo 11' => ['1101', ['ciencias-agrarias']];
        yield '1200 padrão do prefixo 12 (lista)' => ['1200', ['ciencias-humanas', 'linguistica-letras-artes']];
        yield '1710 Information Systems' => ['1710', ['computacao']];
        yield '2718 exceção em lista' => ['2718', ['saude-coletiva', 'computacao']];
    }

    /**
     * @dataProvider exemplosAsjcProvider
     *
     * @param list<string> $esperado
     */
    public function testMapDoAsjcSegueAsRegrasDeC1(string $codigo, array $esperado): void
    {
        $this->assertSame($esperado, (new AreaCrosswalk(self::projectDirectory()))->map('asjc', $codigo));
    }

    public function testMapDoAsjcIgnoraEspacosNasPontas(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());

        $this->assertSame(['educacao'], $crosswalk->map('asjc', ' 3304 '));
        $this->assertTrue($crosswalk->isKnown('asjc', ' 3304 '));
    }

    public function testSaoTrintaEUmaRegrasDeTituloComSlugsValidos(): void
    {
        $taxonomy = new CapesTaxonomy();
        $rules = (new AreaCrosswalk(self::projectDirectory()))->titleRules();

        $this->assertCount(31, $rules);
        foreach ($rules as $rule) {
            $this->assertNotSame('', $rule['pattern']);
            $this->assertStringNotContainsString('~', $rule['pattern'], 'O serviço usa ~ como delimitador da regex.');
            $this->assertTrue($taxonomy->isValidSlug($rule['slug']), sprintf('Slug inválido na regra: "%s".', $rule['slug']));
        }
    }

    public function testMatchTitleUsaTodasAsRegrasQueCasam(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());

        $slugs = $crosswalk->matchTitle('revista de educacao matematica');
        $this->assertContains('ensino', $slugs);
        $this->assertContains('educacao', $slugs);
        $this->assertContains('matematica-estatistica', $slugs);
        $this->assertSame(array_values(array_unique($slugs)), $slugs, 'Os slugs não se repetem.');

        $this->assertSame([], $crosswalk->matchTitle('sem palavras de area'));
        $this->assertSame([], $crosswalk->matchTitle('ensinamentos'), 'As regras casam palavras inteiras (\b).');
        $this->assertSame([], $crosswalk->matchTitle(''));
    }

    public function testScieloAreaTemOsOitoParesDeC5(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());

        $this->assertCount(8, $crosswalk->knownLabels('scielo_area'));
        $this->assertSame(['linguistica-letras-artes'], $crosswalk->map('scielo_area', 'Linguistics, Letters and Arts'));
        $this->assertSame(['ciencias-exatas-terra'], $crosswalk->map('scielo_area', 'EXACT AND EARTH SCIENCES'));
    }

    public function testCrosswalksPresentesTemSlugsValidos(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());
        $taxonomy = new CapesTaxonomy();

        foreach (AreaCrosswalk::SOURCES as $source) {
            foreach ($crosswalk->knownLabels($source) as $label) {
                foreach ($crosswalk->map($source, $label) as $slug) {
                    $this->assertTrue($taxonomy->isValidSlug($slug), sprintf('%s: "%s" aponta para "%s".', $source, $label, $slug));
                }
            }
        }
    }

    public function testCnpqEhFonteAceitaEMapeiaAsAreasDoLattes(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());
        $taxonomy = new CapesTaxonomy();

        $this->assertContains('cnpq', AreaCrosswalk::SOURCES);
        $this->assertSame(['linguistica-literatura'], $crosswalk->map('cnpq', 'Lingüística'), 'A grafia antiga que o Lattes ainda usa.');
        $this->assertSame(['linguistica-literatura'], $crosswalk->map('cnpq', 'LINGUÍSTICA'));
        $this->assertSame(['medicina-i', 'medicina-ii', 'medicina-iii'], $crosswalk->map('cnpq', 'Medicina'));
        $this->assertTrue($crosswalk->isKnown('cnpq', 'Outros'), 'Outros é decisão explícita.');
        $this->assertSame([], $crosswalk->map('cnpq', 'Outros'));
        $this->assertFalse($crosswalk->isKnown('cnpq', 'Divulgação Científica'));

        foreach ($crosswalk->knownLabels('cnpq') as $label) {
            foreach ($crosswalk->map('cnpq', $label) as $slug) {
                $this->assertTrue($taxonomy->isValidSlug($slug), sprintf('cnpq: "%s" aponta para "%s".', $label, $slug));
            }
        }
    }

    public function testFileEntriesTrazOQueOArquivoTemSemAsCorrecoesManuais(): void
    {
        $dir = $this->diretorioComArquivos([
            'crosswalk_wos.json' => $this->json(['map' => ['Acoustics' => 'engenharias-iv', 'Sem área temática' => null, 'Sociology' => ['sociologia', 'educacao']]]),
            'crosswalk_asjc.json' => $this->json(['map' => ['1702' => 'computacao']]),
        ]);
        $conn = $this->conexaoComTabelas();
        $this->inserirCorrecao($conn, 'wos', 'Acoustics', 'ACOUSTICS', 'direito', 'manual', 'active');
        $crosswalk = new AreaCrosswalk($dir, null, $conn);

        $this->assertSame(['direito'], $crosswalk->map('wos', 'Acoustics'), 'A correção vale na consulta.');
        $this->assertSame(
            [
                ['label' => 'Acoustics', 'slugs' => ['engenharias-iv']],
                ['label' => 'Sem área temática', 'slugs' => null],
                ['label' => 'Sociology', 'slugs' => ['sociologia', 'educacao']],
            ],
            $crosswalk->fileEntries('wos'),
            'fileEntries ignora a correção e devolve o valor null do arquivo como null.'
        );
        $this->assertSame([['label' => '1702', 'slugs' => ['computacao']]], $crosswalk->fileEntries('asjc'), 'O rótulo 1702 continua texto.');
        $this->assertSame([], $crosswalk->fileEntries('doaj'));

        $this->expectException(\InvalidArgumentException::class);
        $crosswalk->fileEntries('scopus');
    }

    public function testCorrecaoManualSubstituiOMapaEIgnoradoViraVazioMasConhecido(): void
    {
        $dir = $this->diretorioComArquivos([
            'crosswalk_wos.json' => $this->json(['map' => [
                'Acoustics' => 'engenharias-iv',
                'Sociology' => 'sociologia',
                'Law' => 'direito',
                'History' => 'historia',
                'Education' => 'educacao',
            ]]),
        ]);
        $conn = $this->conexaoComTabelas();
        // Duas linhas ativas do mesmo rótulo: os dois slugs, na ordem das linhas
        $this->inserirCorrecao($conn, 'wos', 'Acoustics', 'ACOUSTICS', 'educacao', 'manual', 'active');
        $this->inserirCorrecao($conn, 'wos', 'Acoustics', 'ACOUSTICS', 'psicologia', 'manual', 'active');
        // Ignorado: decisão explícita sem área
        $this->inserirCorrecao($conn, 'wos', 'Sociology', 'SOCIOLOGY', null, 'manual', 'ignored');
        // Rótulo que o arquivo não tem: passa a existir
        $this->inserirCorrecao($conn, 'wos', 'Nova Categoria', 'NOVA CATEGORIA', 'filosofia', 'manual', 'active');
        // Não valem: semente, pendente, fonte que o serviço não conhece e vocabulário que não é fonte (livre)
        $this->inserirCorrecao($conn, 'wos', 'Law', 'LAW', 'economia', 'seed', 'active');
        $this->inserirCorrecao($conn, 'wos', 'History', 'HISTORY', null, 'manual', 'pending');
        $this->inserirCorrecao($conn, 'scopus', 'Education', 'EDUCATION', 'economia', 'manual', 'active');
        $this->inserirCorrecao($conn, 'livre', 'Education', 'EDUCATION', 'economia', 'manual', 'active');

        $crosswalk = new AreaCrosswalk($dir, null, $conn);

        $this->assertSame(['educacao', 'psicologia'], $crosswalk->map('wos', 'Acoustics'));
        $this->assertSame(['educacao', 'psicologia'], $crosswalk->map('wos', ' ACOUSTICS '), 'A chave continua sendo a caixa e os espaços.');
        $this->assertTrue($crosswalk->isKnown('wos', 'Sociology'));
        $this->assertSame([], $crosswalk->map('wos', 'Sociology'));
        $this->assertTrue($crosswalk->isKnown('wos', 'Nova Categoria'));
        $this->assertSame(['filosofia'], $crosswalk->map('wos', 'nova categoria'));
        $this->assertSame(['direito'], $crosswalk->map('wos', 'Law'), 'Semente do tesauro não troca o arquivo.');
        $this->assertSame(['historia'], $crosswalk->map('wos', 'History'), 'Pendente não vale.');
        $this->assertSame(['educacao'], $crosswalk->map('wos', 'Education'), 'Só as fontes aceitas e só as linhas manuais valem.');
        $this->assertSame(['Acoustics', 'Sociology', 'Law', 'History', 'Education', 'Nova Categoria'], $crosswalk->knownLabels('wos'));
    }

    public function testCorrecaoManualVaiParaTodoRotuloDoArquivoComAMesmaFormaDoTesauro(): void
    {
        $dir = $this->diretorioComArquivos([
            'crosswalk_scielo_desc.json' => $this->json(['map' => ['HISTÓRIA' => 'historia', 'HISTORIA' => 'historia', 'FÍSICA' => 'astronomia-fisica']]),
        ]);
        $conn = $this->conexaoComTabelas();
        // O tesauro guarda uma linha só para HISTÓRIA e HISTORIA (mesma forma); o rótulo da linha é o de uma das grafias
        $this->inserirCorrecao($conn, 'scielo_desc', 'HISTORIA', 'HISTORIA', 'filosofia', 'manual', 'active');

        $crosswalk = new AreaCrosswalk($dir, null, $conn);

        $this->assertSame(['filosofia'], $crosswalk->map('scielo_desc', 'HISTORIA'));
        $this->assertSame(['filosofia'], $crosswalk->map('scielo_desc', 'HISTÓRIA'));
        $this->assertSame(['astronomia-fisica'], $crosswalk->map('scielo_desc', 'FÍSICA'), 'Outro rótulo não é tocado.');
        $this->assertSame(['HISTÓRIA', 'HISTORIA', 'FÍSICA'], $crosswalk->knownLabels('scielo_desc'));
    }

    public function testSemConexaoOuComATabelaInexistenteRespondemSoOsArquivos(): void
    {
        $dir = $this->diretorioComArquivos([
            'crosswalk_wos.json' => $this->json(['map' => ['Acoustics' => 'engenharias-iv']]),
        ]);

        $semConexao = new AreaCrosswalk($dir);
        $this->assertSame(['engenharias-iv'], $semConexao->map('wos', 'Acoustics'));

        // Banco sem a migration do tesauro: a consulta falha com TableNotFoundException, que não derruba nada
        $semTabela = new AreaCrosswalk($dir, null, DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->assertSame(['engenharias-iv'], $semTabela->map('wos', 'Acoustics'));
        $this->assertFalse($semTabela->isKnown('wos', 'Outro'));
    }

    public function testArquivoAusenteEhToleradoSemMapeamento(): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([]));

        $this->assertSame([], $crosswalk->map('asjc', '3304'));
        $this->assertFalse($crosswalk->isKnown('doaj', 'Anything'));
        $this->assertSame([], $crosswalk->knownLabels('latindex'));
        $this->assertSame([], $crosswalk->titleRules());
        $this->assertSame([], $crosswalk->matchTitle('revista de educacao'));
    }

    public function testRotuloNuloEhConhecidoMasNaoTemSlug(): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([
            'crosswalk_wos.json' => $this->json(['map' => ['Sem área temática' => null, 'Acoustics' => 'engenharias-iv']]),
        ]));

        $this->assertTrue($crosswalk->isKnown('wos', 'Sem área temática'));
        $this->assertSame([], $crosswalk->map('wos', 'Sem área temática'));
        $this->assertFalse($crosswalk->isKnown('wos', 'Rótulo que não existe'));
        $this->assertSame([], $crosswalk->map('wos', 'Rótulo que não existe'));
        $this->assertSame(['Sem área temática', 'Acoustics'], $crosswalk->knownLabels('wos'));
    }

    public function testComparacaoIgnoraCaixaEEspacosNoArquivoENaConsulta(): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([
            'crosswalk_wos.json' => $this->json(['map' => ['Acoustics  and   Physics' => 'engenharias-iv']]),
        ]));

        $this->assertSame(['engenharias-iv'], $crosswalk->map('wos', 'acoustics and physics'));
        $this->assertSame(['engenharias-iv'], $crosswalk->map('wos', '  ACOUSTICS AND PHYSICS '));
        $this->assertTrue($crosswalk->isKnown('wos', 'Acoustics and Physics'));
        $this->assertSame(['Acoustics  and   Physics'], $crosswalk->knownLabels('wos'), 'O rótulo é devolvido como está no arquivo.');
    }

    public function testSlugInvalidoLancaExcecaoComORotuloCulpado(): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([
            'crosswalk_doaj.json' => $this->json(['map' => ['Rótulo culpado' => 'nao-existe']]),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Rótulo culpado');

        $crosswalk->map('doaj', 'Outro');
    }

    public function testSlugInvalidoEmRegraDeTituloLancaExcecao(): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([
            'crosswalk_title_rules.json' => $this->json(['rules' => [['pattern' => 'historia', 'slug' => 'nao-existe']]]),
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nao-existe');

        $crosswalk->titleRules();
    }

    public function testFonteNaoAceitaLancaExcecao(): void
    {
        $crosswalk = new AreaCrosswalk(self::projectDirectory());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scopus');

        $crosswalk->map('scopus', '3304');
    }

    /**
     * @return iterable<string, array{string, string, class-string<\Throwable>, string}>
     */
    public static function arquivosInvalidosProvider(): iterable
    {
        yield 'JSON inválido' => ['crosswalk_wos.json', '{"map": [', \RuntimeException::class, 'JSON inválido'];
        yield 'sem a chave map' => ['crosswalk_wos.json', '{"_meta": {"source": "wos"}}', \RuntimeException::class, 'chave "map"'];
        yield 'fonte divergente no _meta' => ['crosswalk_wos.json', '{"_meta": {"source": "doaj"}, "map": {}}', \RuntimeException::class, '"_meta.source"'];
        yield 'valor vazio' => ['crosswalk_wos.json', '{"map": {"X": []}}', \RuntimeException::class, 'deve ter um slug'];
        yield 'valor numérico' => ['crosswalk_wos.json', '{"map": {"X": 5}}', \RuntimeException::class, 'deve ter um slug'];
        yield 'rótulo repetido depois de normalizado' => [
            'crosswalk_wos.json',
            '{"map": {"Acoustics": "engenharias-iv", "ACOUSTICS": "engenharias-iv"}}',
            \RuntimeException::class,
            'repete outro',
        ];
        yield 'arquivo de fonte não aceita' => ['crosswalk_scopus.json', '{"map": {}}', \RuntimeException::class, 'não é aceita'];
    }

    /**
     * @dataProvider arquivosInvalidosProvider
     *
     * @param class-string<\Throwable> $excecao
     */
    public function testArquivoDeCrosswalkInvalidoLancaExcecaoClara(string $nome, string $conteudo, string $excecao, string $mensagem): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([$nome => $conteudo]));

        $this->expectException($excecao);
        $this->expectExceptionMessage($mensagem);

        $crosswalk->knownLabels('wos');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function regrasInvalidasProvider(): iterable
    {
        yield 'regex inválida' => [['rules' => [['pattern' => '(historia', 'slug' => 'historia']]], 'não é uma expressão regular'];
        yield 'tilde no padrão' => [['rules' => [['pattern' => 'a~b', 'slug' => 'historia']]], 'não é uma expressão regular'];
        yield 'padrão vazio' => [['rules' => [['pattern' => '', 'slug' => 'historia']]], '"pattern"'];
        yield 'sem a chave rules' => [['regras' => []], 'chave "rules"'];
    }

    /**
     * @dataProvider regrasInvalidasProvider
     *
     * @param array<string, mixed> $conteudo
     */
    public function testRegraDeTituloInvalidaLancaRuntimeException(array $conteudo, string $mensagem): void
    {
        $crosswalk = new AreaCrosswalk($this->diretorioComArquivos([
            'crosswalk_title_rules.json' => $this->json($conteudo),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($mensagem);

        $crosswalk->titleRules();
    }

    /**
     * SQLite em memória com as duas tabelas que o serviço lê (só as colunas usadas), com as áreas da taxonomia.
     */
    private function conexaoComTabelas(): Connection
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE capes_areas (id INTEGER PRIMARY KEY, slug VARCHAR(80) NOT NULL)');
        $conn->executeStatement(
            'CREATE TABLE capes_area_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, area_id INTEGER DEFAULT NULL, vocabulary VARCHAR(20) NOT NULL, '
            . 'variation_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, origin VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL)'
        );
        foreach ((new CapesTaxonomy())->all() as $area) {
            $conn->insert('capes_areas', ['id' => $area['id'], 'slug' => $area['slug']]);
        }

        return $conn;
    }

    private function inserirCorrecao(Connection $conn, string $vocabulario, string $rotulo, string $normalizado, ?string $slug, string $origem, string $status): void
    {
        $conn->insert('capes_area_variants', [
            'area_id' => $slug === null ? null : (new CapesTaxonomy())->bySlug($slug)['id'],
            'vocabulary' => $vocabulario,
            'variation_name' => $rotulo,
            'normalized_name' => $normalizado,
            'origin' => $origem,
            'status' => $status,
        ]);
    }

    private static function projectDirectory(): string
    {
        return dirname(__DIR__, 3) . '/config/journal_areas';
    }

    /**
     * @param array<string, string> $arquivos nome do arquivo => conteúdo
     */
    private function diretorioComArquivos(array $arquivos): string
    {
        $dir = sys_get_temp_dir() . '/crosswalk_test_' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->temporaryPaths[] = $dir;

        foreach ($arquivos as $nome => $conteudo) {
            file_put_contents($dir . '/' . $nome, $conteudo);
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function json(array $dados): string
    {
        return json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
