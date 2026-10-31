<?php

declare(strict_types=1);

namespace App\Tests\Service\Journal;

use App\Entity\CapesArea;
use App\Service\Journal\CapesTaxonomy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Garante a taxonomia CAPES: o JSON versionado (config/journal_areas/taxonomy.json), a tabela capes_areas
 * semeada pela migration e as consultas do serviço CapesTaxonomy. Só lê o banco, não grava nada.
 */
class CapesTaxonomyTest extends KernelTestCase
{
    private CapesTaxonomy $taxonomy;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Sem argumentos usa o JSON do projeto, o mesmo que o container injeta no serviço.
        $this->taxonomy = new CapesTaxonomy();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testTaxonomiaTem59AreasEmDoisNiveis(): void
    {
        $areas = $this->taxonomy->all();

        $this->assertCount(59, $areas);
        $this->assertCount(9, array_filter($areas, static fn (array $a) => $a['level'] === 1));
        $this->assertCount(50, array_filter($areas, static fn (array $a) => $a['level'] === 2));

        $slugs = array_column($areas, 'slug');
        $this->assertCount(59, array_unique($slugs), 'Os slugs devem ser únicos.');
        $this->assertCount(59, array_unique(array_column($areas, 'id')), 'Os ids devem ser únicos.');

        foreach ($areas as $area) {
            $this->assertSame($area['id'], $this->taxonomy->bySlug($area['slug'])['id']);
            $this->assertSame($area['slug'], $this->taxonomy->byId($area['id'])['slug']);

            if ($area['level'] === 1) {
                $this->assertNull($area['parent'], sprintf('A grande área %s não tem pai.', $area['slug']));
            } else {
                $this->assertSame(1, $this->taxonomy->byId($area['parent'])['level'], sprintf('O pai de %s deve ser uma grande área.', $area['slug']));
            }
        }
    }

    public function testTabelaCapesAreasIgualAoJson(): void
    {
        $connection = $this->em->getConnection();

        $this->assertSame(59, (int) $connection->fetchOne('SELECT COUNT(*) FROM capes_areas'));

        $rows = $connection->fetchAllAssociativeIndexed(
            'SELECT id, parent_id, level, slug, name, college, sort_order FROM capes_areas'
        );

        foreach ($this->taxonomy->all() as $area) {
            $this->assertArrayHasKey($area['id'], $rows, sprintf('capes_areas não tem o id %d (%s).', $area['id'], $area['slug']));

            $row = $rows[$area['id']];
            $this->assertSame($area['slug'], $row['slug']);
            $this->assertSame($area['name'], $row['name']);
            $this->assertSame($area['parent'], $row['parent_id'] === null ? null : (int) $row['parent_id']);
            $this->assertSame($area['level'], (int) $row['level']);
            $this->assertSame($area['college'], $row['college']);
            $this->assertSame($area['id'], (int) $row['sort_order'], 'sort_order é igual ao id.');
        }
    }

    public function testEntidadeCapesAreaMapeiaATabela(): void
    {
        $educacao = $this->em->getRepository(CapesArea::class)->find(404);

        $this->assertInstanceOf(CapesArea::class, $educacao);
        $this->assertSame('educacao', $educacao->getSlug());
        $this->assertSame('Educação', $educacao->getName());
        $this->assertSame(CapesArea::LEVEL_AREA, $educacao->getLevel());
        $this->assertSame('Humanidades', $educacao->getCollege());
        $this->assertSame(404, $educacao->getSortOrder());
        $this->assertSame('ciencias-humanas', $educacao->getParent()?->getSlug());
        $this->assertNull($educacao->getParent()->getParent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rotulosCanonicosProvider(): iterable
    {
        yield 'área de avaliação' => ['educacao', 'EDUCAÇÃO / CIÊNCIAS HUMANAS'];
        yield 'grande área sozinha' => ['ciencias-humanas', 'CIÊNCIAS HUMANAS'];
        yield 'interdisciplinar' => ['interdisciplinar', 'INTERDISCIPLINAR / MULTIDISCIPLINAR'];
        yield 'nome com barra' => ['zootecnia-recursos-pesqueiros', 'ZOOTECNIA / RECURSOS PESQUEIROS / CIÊNCIAS AGRÁRIAS'];
        yield 'nome com vírgulas' => [
            'educacao-fisica',
            'EDUCAÇÃO FÍSICA, FISIOTERAPIA, FONOAUDIOLOGIA E TERAPIA OCUPACIONAL / CIÊNCIAS DA SAÚDE',
        ];
    }

    /**
     * @dataProvider rotulosCanonicosProvider
     */
    public function testRotuloCanonicoDaArea(string $slug, string $esperado): void
    {
        $this->assertSame($esperado, $this->taxonomy->areaLabel($slug));
        $this->assertTrue($this->taxonomy->isCanonicalLabel($esperado));
    }

    public function testTodosOsRotulosCanonicosSaoDistintos(): void
    {
        $labels = array_map(
            fn (array $area) => $this->taxonomy->areaLabel($area['slug']),
            $this->taxonomy->all()
        );

        $this->assertCount(59, array_unique($labels));
        foreach ($labels as $label) {
            $this->assertSame(mb_strtoupper($label, 'UTF-8'), $label, 'O rótulo canônico é todo em maiúsculas.');
        }
    }

    public function testIsCanonicalLabel(): void
    {
        $this->assertTrue($this->taxonomy->isCanonicalLabel('CIÊNCIAS HUMANAS'));
        $this->assertTrue($this->taxonomy->isCanonicalLabel('  EDUCAÇÃO / CIÊNCIAS HUMANAS '), 'Espaços nas pontas são ignorados.');

        $this->assertFalse($this->taxonomy->isCanonicalLabel(''));
        $this->assertFalse($this->taxonomy->isCanonicalLabel('INTERDISCIPLINAR'), 'Falta o rótulo da grande área.');
        $this->assertFalse($this->taxonomy->isCanonicalLabel('Educação / Ciências Humanas'), 'Só o texto exato, em maiúsculas, é canônico.');
        $this->assertFalse($this->taxonomy->isCanonicalLabel('EDUCATION'));
        $this->assertFalse($this->taxonomy->isCanonicalLabel('Education & Educational Research'));
    }

    public function testConsultasPorSlug(): void
    {
        $this->assertTrue($this->taxonomy->isValidSlug('educacao'));
        $this->assertFalse($this->taxonomy->isValidSlug('Educacao'));
        $this->assertFalse($this->taxonomy->isValidSlug('nao-existe'));

        $this->assertSame(
            ['id' => 404, 'parent' => 400, 'level' => 2, 'slug' => 'educacao', 'name' => 'Educação', 'college' => 'Humanidades'],
            $this->taxonomy->bySlug('educacao')
        );
        $this->assertSame('educacao', $this->taxonomy->byId(404)['slug']);
        $this->assertNull($this->taxonomy->bySlug('nao-existe'));
        $this->assertNull($this->taxonomy->byId(1));

        $this->assertSame('ciencias-humanas', $this->taxonomy->parentOf('educacao'));
        $this->assertSame('multidisciplinar', $this->taxonomy->parentOf('interdisciplinar'));
        $this->assertNull($this->taxonomy->parentOf('ciencias-humanas'));
        $this->assertNull($this->taxonomy->parentOf('nao-existe'));

        $this->assertSame(1, $this->taxonomy->levelOf('ciencias-humanas'));
        $this->assertSame(2, $this->taxonomy->levelOf('educacao'));
    }

    public function testFilhasEAgrupamento(): void
    {
        $this->assertSame(
            ['antropologia-arqueologia', 'ciencia-politica-relacoes-internacionais', 'ciencias-da-religiao-teologia', 'educacao', 'filosofia', 'geografia', 'historia', 'psicologia', 'sociologia'],
            array_column($this->taxonomy->childrenOf('ciencias-humanas'), 'slug')
        );
        $this->assertSame([], $this->taxonomy->childrenOf('educacao'));
        $this->assertSame([], $this->taxonomy->childrenOf('nao-existe'));

        $grouped = $this->taxonomy->grouped();

        $this->assertCount(9, $grouped);
        $this->assertSame(
            ['ciencias-agrarias', 'ciencias-biologicas', 'ciencias-da-saude', 'ciencias-humanas', 'ciencias-sociais-aplicadas', 'linguistica-letras-artes', 'ciencias-exatas-terra', 'engenharias', 'multidisciplinar'],
            array_keys($grouped)
        );
        $this->assertSame('Ciências Humanas', $grouped['ciencias-humanas']['area']['name']);
        $this->assertCount(9, $grouped['ciencias-humanas']['children']);

        $filhas = 0;
        foreach ($grouped as $slug => $group) {
            $this->assertSame($slug, $group['area']['slug']);
            foreach ($group['children'] as $child) {
                $this->assertSame($group['area']['id'], $child['parent']);
                ++$filhas;
            }
        }
        $this->assertSame(50, $filhas, 'Todas as áreas de avaliação aparecem em algum grupo.');
    }

    public function testSlugDesconhecidoEmMetodosSemRetornoNulo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nao-existe');

        $this->taxonomy->areaLabel('nao-existe');
    }

    public function testNivelDeSlugDesconhecidoLancaExcecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->taxonomy->levelOf('nao-existe');
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function arquivosInvalidosProvider(): iterable
    {
        yield 'arquivo inexistente' => [null, 'Não foi possível ler'];
        yield 'JSON inválido' => ['{"areas": [', 'JSON inválido'];
        yield 'sem a chave areas' => ['{"itens": []}', 'chave "areas"'];
        yield 'item incompleto' => ['{"areas": [{"id": 1, "slug": "a"}]}', 'item inválido na posição 0'];
        yield 'nível inválido' => [
            '{"areas": [{"id": 1, "parent": null, "level": 3, "slug": "a", "name": "A", "college": "C"}]}',
            'item inválido',
        ];
        yield 'slug repetido' => [
            '{"areas": [{"id": 1, "parent": null, "level": 1, "slug": "a", "name": "A", "college": "C"},'
            . '{"id": 2, "parent": null, "level": 1, "slug": "a", "name": "B", "college": "C"}]}',
            'repetido',
        ];
        yield 'pai inexistente' => [
            '{"areas": [{"id": 2, "parent": 1, "level": 2, "slug": "b", "name": "B", "college": "C"}]}',
            'pai inexistente',
        ];
    }

    /**
     * @dataProvider arquivosInvalidosProvider
     */
    public function testArquivoDeTaxonomiaInvalidoLancaExcecaoClara(?string $conteudo, string $mensagem): void
    {
        $caminho = sys_get_temp_dir() . '/taxonomia_invalida_' . bin2hex(random_bytes(6)) . '.json';
        if ($conteudo !== null) {
            file_put_contents($caminho, $conteudo);
            $this->temporaryFiles[] = $caminho;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($mensagem);

        (new CapesTaxonomy($caminho))->all();
    }
}
