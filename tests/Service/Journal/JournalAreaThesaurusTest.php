<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Command\JournalsPipelineCommand;
use App\Entity\KnowledgeArea;
use App\Entity\QualisJournal;
use App\Entity\Researcher;
use App\Service\Journal\AreaCrosswalk;
use App\Service\Journal\CapesTaxonomy;
use App\Service\Journal\JournalAreaThesaurus;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tesauro de categorias de revistas: busca com prioridade (lookup), texto composto (resolve), a regra de grafia da
 * evidência `legacy` e o `sync` que semeia a tabela e abre os pendentes.
 *
 * A busca é testada com um SQLite em memória, onde as linhas da tabela são todas do teste. O `sync` roda no MySQL de
 * teste (`cech_test`, compartilhado com outros testes e agentes) dentro de uma transação revertida no tearDown: nada fica
 * gravado e nenhuma linha alheia é apagada. Os dados criados têm sufixo único e as asserções olham só para eles ou para a
 * diferença entre duas execuções da mesma transação (o MySQL dá a ela um retrato estável).
 */
class JournalAreaThesaurusTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?Connection $conn = null;
    private ?JournalAreaThesaurus $thesaurus = null;
    private string $uniq = '';

    protected function tearDown(): void
    {
        if ($this->conn !== null && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        if ($this->em !== null) {
            $this->em->clear();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------------------
    // lookup
    // ------------------------------------------------------------------------------------------------------------

    public function testLookupReconheceSlugNomeERotuloCanonicoMesmoComATabelaInexistente(): void
    {
        $taxonomy = new CapesTaxonomy();
        // Banco sem a migration: a tabela não existe e o tesauro vale só pela taxonomia
        $thesaurus = new JournalAreaThesaurus(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $taxonomy,
            new AreaCrosswalk(null, $taxonomy)
        );

        foreach ($taxonomy->all() as $area) {
            $slug = $area['slug'];
            foreach ([$slug, $area['name'], mb_strtoupper($area['name'], 'UTF-8'), $taxonomy->areaLabel($slug)] as $text) {
                $this->assertSame([$slug], $thesaurus->lookup($text), "«{$text}» deveria ser {$slug}");
                $this->assertSame([$slug], $thesaurus->lookup($text, true), "«{$text}» é confiável: vem do capes");
            }
        }

        $this->assertSame(['educacao'], $thesaurus->lookup('  EDUCAÇÃO  '));
        $this->assertSame(['educacao'], $thesaurus->lookup('educacao'));
        $this->assertNull($thesaurus->lookup('Astrologia'));
        $this->assertNull($thesaurus->lookup(''));
        $this->assertNull($thesaurus->lookup(' - '));
    }

    public function testLookupDevolveOsSlugsDeUmSoVocabularioPelaOrdemDePrioridade(): void
    {
        $label = 'Rotulo em muitos vocabularios';
        $thesaurus = $this->sqliteThesaurus([
            ['livre', $label, 'direito'],
            ['asjc', $label, 'economia'],
            ['scielo_area', $label, 'artes'],
            ['scielo_desc', $label, 'historia'],
            ['doaj', $label, 'filosofia'],
            ['doaj', $label, 'geografia'],
            ['latindex', $label, 'psicologia'],
            ['wos', $label, 'sociologia'],
            ['wos', $label, 'antropologia-arqueologia'],
        ]);
        // O que o de maior prioridade leva é tudo, sem misturar com os outros
        $this->assertSame(['sociologia', 'antropologia-arqueologia'], $thesaurus->lookup($label));

        $order = ['cnpq', 'capes'];
        foreach ($order as $vocabulary) {
            $thesaurus = $this->sqliteThesaurus([
                ['livre', $label, 'direito'],
                ['wos', $label, 'sociologia'],
                [$vocabulary, $label, 'educacao'],
            ]);
            $this->assertSame(['educacao'], $thesaurus->lookup($label), "{$vocabulary} vence o wos e o livre");
        }

        $thesaurus = $this->sqliteThesaurus([
            ['capes', $label, 'educacao'],
            ['cnpq', $label, 'psicologia'],
            ['wos', $label, 'sociologia'],
        ]);
        $this->assertSame(['educacao'], $thesaurus->lookup($label), 'capes vence o cnpq');

        // A ordem completa, do último ao primeiro: cada linha que entra passa na frente das anteriores
        $sequence = ['livre', 'asjc', 'scielo_area', 'scielo_desc', 'doaj', 'latindex', 'wos', 'cnpq', 'capes'];
        $slugs = ['livre' => 'direito', 'asjc' => 'economia', 'scielo_area' => 'artes', 'scielo_desc' => 'historia', 'doaj' => 'filosofia', 'latindex' => 'psicologia', 'wos' => 'sociologia', 'cnpq' => 'geografia', 'capes' => 'educacao'];
        $rows = [];
        foreach ($sequence as $vocabulary) {
            $rows[] = [$vocabulary, $label, $slugs[$vocabulary]];
            $this->assertSame([$slugs[$vocabulary]], $this->sqliteThesaurus($rows)->lookup($label), "{$vocabulary} passa na frente dos anteriores");
        }

        // A linha manual vence todas, de qualquer vocabulário
        $thesaurus = $this->sqliteThesaurus(array_merge($rows, [['asjc', $label, 'enfermagem', 'manual']]));
        $this->assertSame(['enfermagem'], $thesaurus->lookup($label));
    }

    public function testLookupSoConsideraLinhasAtivasOuIgnoradasENuncaAsPendentes(): void
    {
        $thesaurus = $this->sqliteThesaurus([
            ['wos', 'Categoria ignorada', null, 'seed', 'ignored'],
            ['wos', 'Categoria pendente', null, 'seed', 'pending'],
            ['doaj', 'Categoria com decisao', 'direito', 'seed', 'active'],
        ]);

        $this->assertSame([], $thesaurus->lookup('Categoria ignorada'), 'Ignorada é "sem área", e não desconhecida.');
        $this->assertNull($thesaurus->lookup('Categoria pendente'));
        $this->assertSame(['direito'], $thesaurus->lookup('categoria COM decisão'));

        // Ignorar à mão um rótulo da semente vale mais que a semente
        $thesaurus = $this->sqliteThesaurus([
            ['wos', 'Mathematics', 'matematica-estatistica'],
            ['wos', 'Mathematics', null, 'manual', 'ignored'],
        ]);
        $this->assertSame([], $thesaurus->lookup('Mathematics'));
    }

    public function testLookupSoConfiaEmCapesCnpqEManual(): void
    {
        $thesaurus = $this->sqliteThesaurus([
            ['wos', 'Categoria do WoS', 'direito'],
            ['doaj', 'Categoria do DOAJ', 'economia'],
            ['livre', 'Categoria livre', 'artes'],
            ['cnpq', 'Categoria do CNPq', 'historia'],
            ['asjc', 'Categoria corrigida', 'geografia', 'manual'],
        ]);

        foreach (['Categoria do WoS', 'Categoria do DOAJ', 'Categoria livre'] as $label) {
            $this->assertNotNull($thesaurus->lookup($label));
            $this->assertNull($thesaurus->lookup($label, true), "«{$label}» não é confiável");
        }
        $this->assertSame(['historia'], $thesaurus->lookup('Categoria do CNPq', true));
        $this->assertSame(['geografia'], $thesaurus->lookup('Categoria corrigida', true), 'A linha manual é confiável em qualquer vocabulário.');
        $this->assertSame(['educacao'], $thesaurus->lookup('Educação', true), 'O capes vem da taxonomia.');
    }

    public function testRotuloSoComDigitosEhCodigoAsjcENaoEntraNaBuscaPorTexto(): void
    {
        $thesaurus = $this->sqliteThesaurus([
            ['asjc', '1702', 'computacao'],
            ['livre', '2024', 'direito', 'manual'],
        ]);

        $this->assertNull($thesaurus->lookup('1702'));
        $this->assertNull($thesaurus->lookup(' 1702 '));
        $this->assertNull($thesaurus->lookup('2024'));
        $this->assertSame(['slugs' => [], 'unknown' => ['1702']], $thesaurus->resolve('1702'));
    }

    public function testResetFazOIndiceLerATabelaDeNovo(): void
    {
        $thesaurus = $this->sqliteThesaurus([]);
        $this->assertNull($thesaurus->lookup('Categoria nova'));

        $this->insertSqliteRow($thesaurus, 'livre', 'Categoria nova', 'direito', 'manual', 'active');
        $this->assertNull($thesaurus->lookup('Categoria nova'), 'O índice fica em memória até o reset.');

        $thesaurus->reset();
        $this->assertSame(['direito'], $thesaurus->lookup('Categoria nova'));
    }

    // ------------------------------------------------------------------------------------------------------------
    // resolve
    // ------------------------------------------------------------------------------------------------------------

    public function testResolveDesmontaTextoCompostoNaOrdemInteiroPartesESubpartes(): void
    {
        $thesaurus = $this->sqliteThesaurus([
            ['wos', 'Mathematics, Applied', 'matematica-estatistica'],
            ['wos', 'Mathematics', 'matematica-estatistica'],
            ['cnpq', 'Medicina', 'medicina-i'],
            ['cnpq', 'Medicina', 'medicina-ii'],
            ['cnpq', 'Medicina', 'medicina-iii'],
            ['latindex', 'Salud pública', 'saude-coletiva'],
            ['wos', 'Sociology', 'sociologia'],
            ['wos', 'Law', 'direito'],
            ['wos', 'Sem tema', null, 'seed', 'ignored'],
        ]);

        // Mathematics, Applied é um rótulo inteiro, com vírgula: a vírgula só desmonta o que não é rótulo
        $this->assertSame(
            ['slugs' => ['matematica-estatistica'], 'unknown' => []],
            $thesaurus->resolve('Mathematics, Applied | Mathematics')
        );
        $this->assertSame(
            ['slugs' => ['matematica-estatistica'], 'unknown' => []],
            $thesaurus->resolve('Mathematics, Applied')
        );
        $this->assertSame(
            ['slugs' => ['medicina-i', 'medicina-ii', 'medicina-iii', 'saude-coletiva'], 'unknown' => []],
            $thesaurus->resolve('Medicina, Salud pública')
        );
        // Texto com `;`, repetições e um trecho que ninguém conhece
        $this->assertSame(
            ['slugs' => ['sociologia', 'direito'], 'unknown' => ['Astrologia Aplicada', 'Alquimia']],
            $thesaurus->resolve(' Sociology ; Astrologia Aplicada | Law, Alquimia | sociology')
        );
        // Subparte sem rótulo e rótulo "sem área": não dá slug e só o desconhecido aparece em unknown
        $this->assertSame(
            ['slugs' => ['direito'], 'unknown' => ['Rotulo sem dono']],
            $thesaurus->resolve('Sem tema | Law, Rotulo sem dono')
        );
        // A taxonomia vale mesmo sem linhas na tabela
        $this->assertSame(['slugs' => ['educacao', 'direito'], 'unknown' => []], $thesaurus->resolve('EDUCAÇÃO / CIÊNCIAS HUMANAS ; direito'));
        // Parte desconhecida sem vírgula: o trecho inteiro
        $this->assertSame(['slugs' => [], 'unknown' => ['Rotulo Solto']], $thesaurus->resolve('Rotulo Solto'));
        $this->assertSame(['slugs' => [], 'unknown' => []], $thesaurus->resolve(null));
        $this->assertSame(['slugs' => [], 'unknown' => []], $thesaurus->resolve('  '));
        $this->assertSame(['slugs' => [], 'unknown' => []], $thesaurus->resolve(' | ; '));
    }

    // ------------------------------------------------------------------------------------------------------------
    // Evidência legacy
    // ------------------------------------------------------------------------------------------------------------

    public function testLegacySlugAceitaGrafiasDoTesauroConfiavelEPreservaAsRegrasAtuais(): void
    {
        $thesaurus = $this->sqliteThesaurus([
            ['cnpq', 'Linguística', 'linguistica-literatura'],
            ['cnpq', 'Medicina', 'medicina-i'],
            ['cnpq', 'Medicina', 'medicina-ii'],
            ['latindex', 'Sociología', 'sociologia'],
            ['latindex', 'Psicología', 'psicologia'],
            ['wos', 'Mathematics', 'matematica-estatistica'],
            ['doaj', 'Social Sciences', 'ciencias-humanas'],
            ['livre', 'Ciência Inventada', 'artes', 'manual'],
        ]);

        // A grafia que o Lattes usa: o rótulo está no arquivo do cnpq, mesmo sem estar na linha da tabela
        $this->assertSame('linguistica-literatura', $thesaurus->legacySlug('Lingüística'));
        $this->assertSame('linguistica-literatura', $thesaurus->legacySlug('Linguística'));
        // Todo em maiúsculas, como o Qualis grafa (às vezes sem acento)
        $this->assertSame('psicologia', $thesaurus->legacySlug('PSICOLOGIA'));
        $this->assertSame('saude-coletiva', $thesaurus->legacySlug('SAUDE COLETIVA'));
        $this->assertSame('educacao', $thesaurus->legacySlug('Educação'));
        // Sinônimo manual: a grafia exata e a caixa alta valem; a variante sem acento em caixa baixa, não
        $this->assertSame('artes', $thesaurus->legacySlug('Ciência Inventada'));
        $this->assertSame('artes', $thesaurus->legacySlug('CIÊNCIA INVENTADA'));
        $this->assertNull($thesaurus->legacySlug('ciencia inventada'));

        // Rótulos do Latindex já chegam pela própria fonte e contariam duas vezes
        $this->assertNull($thesaurus->legacySlug('Sociología'));
        $this->assertNull($thesaurus->legacySlug('Psicología'));
        // Rótulo de fonte que não é confiável, e sobretudo em inglês, nunca entra
        $this->assertNull($thesaurus->legacySlug('Social Sciences'));
        $this->assertNull($thesaurus->legacySlug('Mathematics'));
        // Mais de uma área: não dá para dizer qual
        $this->assertNull($thesaurus->legacySlug('Medicina'));
        // Desconhecido e vazio
        $this->assertNull($thesaurus->legacySlug('Astrologia'));
        $this->assertNull($thesaurus->legacySlug(''));
    }

    // ------------------------------------------------------------------------------------------------------------
    // sync
    // ------------------------------------------------------------------------------------------------------------

    public function testSyncSemeiaATabelaDosArquivosEARepeticaoNaoMudaNada(): void
    {
        $this->bootMysql();

        // Simulação: relata os números da carga real e não grava
        $before = $this->countRows();
        $dry = $this->thesaurus->sync(true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame($before, $this->countRows(), 'A simulação não grava.');

        $first = $this->thesaurus->sync();
        $this->assertFalse($first['dry_run']);
        $this->assertSame($dry['inserted'], $first['inserted']);
        $this->assertSame($dry['pending_inserted'], $first['pending_inserted']);
        $this->assertGreaterThan(2000, $first['inserted'] + $before);

        // As sementes: nome e rótulo canônico das áreas, linhas dos arquivos e o null que vira "sem área"
        $this->assertSame([['educacao', 'seed', 'active']], $this->rowsOf('capes', 'Educação'));
        $this->assertSame([['educacao', 'seed', 'active']], $this->rowsOf('capes', 'EDUCAÇÃO / CIÊNCIAS HUMANAS'));
        $this->assertSame([['matematica-estatistica', 'seed', 'active']], $this->rowsOf('wos', 'Mathematics, Applied'));
        $this->assertSame([['medicina-i', 'seed', 'active'], ['medicina-ii', 'seed', 'active'], ['medicina-iii', 'seed', 'active']], $this->rowsOf('cnpq', 'Medicina'));
        $this->assertSame([[null, 'seed', 'ignored']], $this->rowsOf('cnpq', 'Outros'));
        $this->assertSame([['computacao', 'seed', 'active']], $this->rowsOf('asjc', '1702'));
        // O que o arquivo grafa de dois jeitos (Linguística e Lingüística) vira uma linha só, com a primeira grafia
        $this->assertSame(['Linguística'], $this->conn->fetchFirstColumn("SELECT variation_name FROM capes_area_variants WHERE vocabulary = 'cnpq' AND normalized_name = 'LINGUISTICA'"));

        $this->thesaurus->reset();
        $this->assertSame(['matematica-estatistica'], $this->thesaurus->lookup('Mathematics, Applied'));
        $this->assertSame(['linguistica-literatura'], $this->thesaurus->lookup('Lingüística', true));
        $this->assertSame([], $this->thesaurus->lookup('Outros'));
        $this->assertSame(
            ['slugs' => ['matematica-estatistica'], 'unknown' => []],
            $this->thesaurus->resolve('Mathematics, Applied | Mathematics')
        );

        $snapshot = $this->snapshot();
        $second = $this->thesaurus->sync();
        foreach (['inserted', 'updated', 'deleted', 'pending_inserted', 'pending_deleted'] as $key) {
            $this->assertSame(0, $second[$key], "A segunda execução não pode mudar nada ({$key}).");
        }
        $this->assertSame($snapshot, $this->snapshot(), 'Nem a tabela, nem o updated_at.');
        $this->assertSame($first['vocabularies'], $second['vocabularies']);
    }

    public function testSyncNaoMexeNoRotuloComCorrecaoManual(): void
    {
        $this->bootMysql();
        $u = $this->uniq;
        $this->thesaurus->sync();

        // O admin definiu outra área para o rótulo da semente e cadastrou um rótulo que nenhum arquivo tem
        $this->conn->executeStatement("DELETE FROM capes_area_variants WHERE vocabulary = 'wos' AND normalized_name = 'MATHEMATICS APPLIED'");
        $this->insertRow('wos', 'Mathematics, Applied', 'educacao', 'manual', 'active');
        $this->insertRow('doaj', "Categoria Manual {$u}", 'direito', 'manual', 'active');
        // Rótulo com semente e correção ao mesmo tempo (sobra de uma execução antiga): a semente também fica como está
        $this->insertRow('wos', 'Sociology', null, 'manual', 'ignored');
        $oldSeed = (int) $this->conn->fetchOne("SELECT id FROM capes_area_variants WHERE vocabulary = 'wos' AND normalized_name = 'SOCIOLOGY' AND origin = 'seed'");
        $this->assertGreaterThan(0, $oldSeed);

        $report = $this->thesaurus->sync();

        $this->assertGreaterThanOrEqual(3, $report['protected']);
        $this->assertSame([['educacao', 'manual', 'active']], $this->rowsOf('wos', 'Mathematics, Applied'), 'A semente não volta.');
        $this->assertSame([['direito', 'manual', 'active']], $this->rowsOf('doaj', "Categoria Manual {$u}"), 'A correção sem semente não é apagada.');
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM capes_area_variants WHERE id = ?', [$oldSeed]));
        $this->assertSame([0, 0], [$report['inserted'], $report['deleted']], 'Nada foi recriado nem apagado por causa das correções.');

        $this->thesaurus->reset();
        $this->assertSame(['educacao'], $this->thesaurus->lookup('Mathematics, Applied'), 'A correção vale na busca.');

        // De novo: nada muda
        $again = $this->thesaurus->sync();
        $this->assertSame([0, 0, 0], [$again['inserted'], $again['updated'], $again['deleted']]);
        $this->assertSame([['educacao', 'manual', 'active']], $this->rowsOf('wos', 'Mathematics, Applied'));

        // Excluída a correção, a próxima execução recria a semente
        $this->conn->executeStatement("DELETE FROM capes_area_variants WHERE vocabulary = 'wos' AND normalized_name = 'MATHEMATICS APPLIED'");
        $this->thesaurus->sync();
        $this->assertSame([['matematica-estatistica', 'seed', 'active']], $this->rowsOf('wos', 'Mathematics, Applied'));
    }

    public function testSyncApagaASementeQueSaiuDoArquivoECorrigeORotulo(): void
    {
        $this->bootMysql();
        $u = $this->uniq;
        $this->thesaurus->sync();

        $this->insertRow('doaj', "Rotulo Que Saiu {$u}", 'direito', 'seed', 'active');
        $this->insertRow('doaj', "Rotulo Que Saiu {$u}", null, 'seed', 'ignored');
        $this->conn->executeStatement("UPDATE capes_area_variants SET variation_name = 'EDUCACAO (outra grafia)' WHERE vocabulary = 'capes' AND normalized_name = 'EDUCACAO'");

        $report = $this->thesaurus->sync();

        $this->assertSame(2, $report['deleted']);
        $this->assertSame([], $this->rowsOf('doaj', "Rotulo Que Saiu {$u}"));
        $this->assertSame(1, $report['updated']);
        $this->assertSame('Educação', $this->conn->fetchOne("SELECT variation_name FROM capes_area_variants WHERE vocabulary = 'capes' AND normalized_name = 'EDUCACAO'"));
    }

    public function testSyncCriaOPendenteDoTextoLivreEOApagaQuandoDeixaDeSerDesconhecido(): void
    {
        $this->bootMysql();
        $u = $this->uniq;
        $fragment = "Categoria Inventada {$u}";

        $journal = $this->createJournal("Revista Pendente {$u}", "{$fragment} | Educação");
        $this->createJournal("Revista Pendente Dois {$u}", $fragment);
        $this->createJournal("Revista Curta {$u}", 'ab | 12 | ' . str_repeat('x', 300));

        $this->assertSame(['slugs' => ['educacao'], 'unknown' => [$fragment]], $this->thesaurus->resolve("{$fragment} | Educação"));

        $report = $this->thesaurus->sync();
        $this->assertGreaterThanOrEqual(1, $report['pending_inserted']);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT area_id, vocabulary, variation_name, origin, status, occurrences FROM capes_area_variants WHERE normalized_name = ?',
            [self::norm($fragment)]
        );
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['area_id']);
        $this->assertSame(['livre', $fragment, 'seed', 'pending', 2], [$rows[0]['vocabulary'], $rows[0]['variation_name'], $rows[0]['origin'], $rows[0]['status'], (int) $rows[0]['occurrences']]);
        $this->assertSame([], $this->rowsOf('livre', 'ab'), 'Trecho com menos de 3 letras fica de fora.');
        $this->assertSame([], $this->rowsOf('livre', '12'), 'Trecho sem letras fica de fora.');
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM capes_area_variants WHERE vocabulary = 'livre' AND normalized_name LIKE 'XXXXXXXXXX%'"), 'Trecho de mais de 255 caracteres fica de fora.');

        // O texto livre conta os usos do que é reconhecido: Educação (capes) aparece no area_raw de uma revista
        $this->assertGreaterThanOrEqual(1, (int) $this->conn->fetchOne("SELECT occurrences FROM capes_area_variants WHERE vocabulary = 'capes' AND normalized_name = 'EDUCACAO'"));

        // Uma correção manual que chega sem passar pelo admin: o pendente passa a ser reconhecido e sai
        $this->insertRow('livre', $fragment, 'sociologia', 'manual', 'active');
        $second = $this->thesaurus->sync();
        $this->assertGreaterThanOrEqual(1, $second['pending_deleted']);
        $this->assertSame([['sociologia', 'manual', 'active']], $this->rowsOf('livre', $fragment));
        $this->assertSame(2, (int) $this->conn->fetchOne('SELECT occurrences FROM capes_area_variants WHERE vocabulary = ? AND normalized_name = ?', ['livre', self::norm($fragment)]), 'A correção manual também tem o uso contado.');

        // O pendente que ninguém usa mais também sai
        $other = "Outra Categoria Inventada {$u}";
        $this->conn->executeStatement('UPDATE qualis_journals SET area_raw = ? WHERE id = ?', [$other, $journal]);
        $this->thesaurus->sync();
        $this->assertSame([[null, 'seed', 'pending']], $this->rowsOf('livre', $other));
        $this->conn->executeStatement('UPDATE qualis_journals SET area_raw = NULL WHERE id = ?', [$journal]);
        $third = $this->thesaurus->sync();
        $this->assertSame([], $this->rowsOf('livre', $other));
        $this->assertGreaterThanOrEqual(1, $third['pending_deleted']);
    }

    public function testSyncContaRevistasDistintasDaEvidenciaEAbreOPendenteDoRotuloSemMapa(): void
    {
        $this->bootMysql();
        $u = $this->uniq;

        $this->thesaurus->sync();
        $asjc = $this->occurrencesOf('asjc', '1702');
        $wos = $this->occurrencesOf('wos', 'Mathematics, Applied');

        $first = $this->createJournal("Revista Evidencia Um {$u}");
        $second = $this->createJournal("Revista Evidencia Dois {$u}");
        // Duas fontes do mesmo vocabulário na mesma revista contam uma vez
        $this->insertEvidence($first, 'openalex', '1702');
        $this->insertEvidence($first, 'scopus', '1702');
        $this->insertEvidence($first, 'wos', 'Mathematics, Applied');
        $this->insertEvidence($first, 'scielo_wos', 'Mathematics, Applied');
        $this->insertEvidence($second, 'wos', 'Mathematics, Applied');
        // ai, title_rule e legacy não têm rótulo de categoria
        $this->insertEvidence($first, 'ai', 'Mathematics, Applied');
        $this->insertEvidence($second, 'legacy', 'Mathematics, Applied');
        $this->insertEvidence($second, 'title_rule', 'Mathematics, Applied');
        // Rótulo que o crosswalk não conhece, em duas grafias, e um código ASJC desconhecido
        $this->insertEvidence($first, 'wos', "Categoria Wos {$u}");
        $this->insertEvidence($second, 'wos', "CATEGORIA WOS {$u}");
        $unknownCode = '9' . random_int(10000, 99999);
        $this->insertEvidence($second, 'scopus', $unknownCode);

        $report = $this->thesaurus->sync();

        $this->assertSame($asjc + 1, $this->occurrencesOf('asjc', '1702'));
        $this->assertSame($wos + 2, $this->occurrencesOf('wos', 'Mathematics, Applied'));
        $this->assertGreaterThanOrEqual(2, $report['pending_inserted']);

        $pending = $this->conn->fetchAllAssociative(
            "SELECT vocabulary, variation_name, occurrences, status, origin, area_id FROM capes_area_variants WHERE normalized_name = ? AND vocabulary = 'wos'",
            [self::norm("Categoria Wos {$u}")]
        );
        $this->assertCount(1, $pending);
        $this->assertSame(['pending', 'seed', null, 2], [$pending[0]['status'], $pending[0]['origin'], $pending[0]['area_id'], (int) $pending[0]['occurrences']]);
        $this->assertSame([[null, 'seed', 'pending']], $this->rowsOf('asjc', $unknownCode), 'O código ASJC desconhecido também fica pendente.');
        $this->assertSame(1, $this->occurrencesOf('asjc', $unknownCode));

        // O admin define a área do rótulo: o crosswalk passa a conhecê-lo e o pendente some
        $this->insertRow('wos', "Categoria Wos {$u}", 'direito', 'manual', 'active');
        $this->thesaurus->sync();
        $this->assertSame([['direito', 'manual', 'active']], $this->rowsOf('wos', "Categoria Wos {$u}"));
    }

    public function testSyncContaOsRegistrosDeAreaDoLattesNoCnpqEAbreOPendenteDoQueNaoReconhece(): void
    {
        $this->bootMysql();
        $u = $this->uniq;

        $this->thesaurus->sync();
        $educacao = $this->occurrencesOf('cnpq', 'Educação');

        $researcher = (new Researcher())
            ->setIdLattes('99' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT))
            ->setFullName("Docente Tesauro {$u}");
        $this->em->persist($researcher);
        $this->em->flush();
        foreach ([['CIENCIAS_HUMANAS', 'Educação'], ['CIENCIAS_HUMANAS', 'Educação'], ['CIENCIAS_HUMANAS', "Area Lattes Inventada {$u}"]] as [$major, $area]) {
            $this->em->persist((new KnowledgeArea())->setResearcher($researcher)->setMajorArea($major)->setArea($area));
        }
        $this->em->flush();

        $report = $this->thesaurus->sync();

        $this->assertSame($educacao + 2, $this->occurrencesOf('cnpq', 'Educação'));
        $this->assertGreaterThanOrEqual(1, $report['pending_inserted']);
        $this->assertSame([[null, 'seed', 'pending']], $this->rowsOf('cnpq', "Area Lattes Inventada {$u}"));
        $this->assertSame(1, (int) $this->conn->fetchOne("SELECT occurrences FROM capes_area_variants WHERE vocabulary = 'cnpq' AND normalized_name = ?", [self::norm("Area Lattes Inventada {$u}")]));
        // CIENCIAS_HUMANAS (a grande área escrita como o Lattes grafa) é reconhecida pelo capes
        $this->assertSame([], $this->rowsOf('cnpq', 'CIENCIAS_HUMANAS'));
    }

    public function testComandoMostraARelatorioESimulaSemGravar(): void
    {
        $this->bootMysql();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:journals:areas:thesaurus'));

        $before = $this->countRows();
        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Tabela depois da execução (simulada)', $display);
        $this->assertStringContainsString('capes', $display);
        $this->assertStringContainsString('Modo simulação', $display);
        $this->assertSame($before, $this->countRows());

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Pendentes', $tester->getDisplay());
        $this->assertGreaterThan($before, $this->countRows());
    }

    public function testOPassoAreaThesaurusVemDepoisDeAuditAfterEAntesDeTitleRulesERodaPeloPipeline(): void
    {
        $steps = JournalsPipelineCommand::STEPS;
        $position = array_search('area-thesaurus', $steps, true);
        $this->assertNotFalse($position);
        $this->assertSame('audit-after', $steps[$position - 1]);
        $this->assertSame('title-rules', $steps[$position + 1]);

        $this->bootMysql();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:journals:pipeline'));

        $this->assertSame(0, $tester->execute(['--only' => 'area-thesaurus']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Tesauro de categorias de revistas', $display);
        $this->assertMatchesRegularExpression('/area-thesaurus\s+ok/', $display);
        $this->assertGreaterThan(2000, $this->countRows(), 'O passo semeou a tabela (dentro da transação do teste)');
    }

    // ------------------------------------------------------------------------------------------------------------
    // Montagem
    // ------------------------------------------------------------------------------------------------------------

    private function bootMysql(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->thesaurus = $container->get(JournalAreaThesaurus::class);
        $this->uniq = 'qz' . bin2hex(random_bytes(4));

        $this->conn->beginTransaction();
    }

    /**
     * Tesauro sobre um SQLite em memória com estas linhas (vocabulário, rótulo, slug e, opcionais, origem e status).
     *
     * @param list<array{0: string, 1: string, 2: string|null, 3?: string, 4?: string}> $rows
     */
    private function sqliteThesaurus(array $rows): JournalAreaThesaurus
    {
        $taxonomy = new CapesTaxonomy();
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('CREATE TABLE capes_areas (id INTEGER PRIMARY KEY, slug VARCHAR(80) NOT NULL)');
        $conn->executeStatement(
            'CREATE TABLE capes_area_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, area_id INTEGER DEFAULT NULL, vocabulary VARCHAR(20) NOT NULL, '
            . 'variation_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, origin VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL)'
        );
        foreach ($taxonomy->all() as $area) {
            $conn->insert('capes_areas', ['id' => $area['id'], 'slug' => $area['slug']]);
        }

        $thesaurus = new JournalAreaThesaurus($conn, $taxonomy, new AreaCrosswalk(null, $taxonomy));
        foreach ($rows as $row) {
            $this->insertSqliteRow($thesaurus, $row[0], $row[1], $row[2], $row[3] ?? 'seed', $row[4] ?? 'active');
        }

        return $thesaurus;
    }

    /**
     * Insere uma linha no SQLite do tesauro de teste (pela conexão, que o serviço guarda em `conn`).
     */
    private function insertSqliteRow(JournalAreaThesaurus $thesaurus, string $vocabulary, string $label, ?string $slug, string $origin, string $status): void
    {
        $conn = (new \ReflectionProperty($thesaurus, 'conn'))->getValue($thesaurus);
        $conn->insert('capes_area_variants', [
            'area_id' => $slug === null ? null : (new CapesTaxonomy())->bySlug($slug)['id'],
            'vocabulary' => $vocabulary,
            'variation_name' => $label,
            'normalized_name' => self::norm($label),
            'origin' => $origin,
            'status' => $status,
        ]);
    }

    private function insertRow(string $vocabulary, string $label, ?string $slug, string $origin, string $status): void
    {
        $this->conn->executeStatement(
            'INSERT INTO capes_area_variants (area_id, vocabulary, variation_name, normalized_name, origin, status, occurrences, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
            [$slug === null ? null : (new CapesTaxonomy())->bySlug($slug)['id'], $vocabulary, $label, self::norm($label), $origin, $status]
        );
        $this->thesaurus->reset();
    }

    private function createJournal(string $title, ?string $areaRaw = null): int
    {
        $journal = (new QualisJournal())->setTitle($title)->setAreaRaw($areaRaw);
        $this->em->persist($journal);
        $this->em->flush();

        return (int) $journal->getId();
    }

    private function insertEvidence(int $journalId, string $source, string $label): void
    {
        $this->conn->executeStatement(
            'INSERT INTO journal_area_evidence (journal_id, source, record_key, label, weight, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$journalId, $source, 'teste', $label, '1.0000']
        );
    }

    // ------------------------------------------------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Linhas de um rótulo (por vocabulário e forma do tesauro): [slug, origem, status], por id.
     *
     * @return list<array{0: string|null, 1: string, 2: string}>
     */
    private function rowsOf(string $vocabulary, string $label): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT a.slug, v.origin, v.status FROM capes_area_variants v LEFT JOIN capes_areas a ON a.id = v.area_id WHERE v.vocabulary = ? AND v.normalized_name = ? ORDER BY v.id',
            [$vocabulary, self::norm($label)]
        );

        return array_map(static fn (array $row): array => [$row['slug'], $row['origin'], $row['status']], $rows);
    }

    private function occurrencesOf(string $vocabulary, string $label): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT MAX(occurrences) FROM capes_area_variants WHERE vocabulary = ? AND normalized_name = ?',
            [$vocabulary, self::norm($label)]
        );
    }

    private function countRows(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM capes_area_variants');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(): array
    {
        return $this->conn->fetchAllAssociative('SELECT * FROM capes_area_variants ORDER BY id');
    }

    private static function norm(string $label): string
    {
        return StringNormalizer::normalizeString($label, true);
    }
}
