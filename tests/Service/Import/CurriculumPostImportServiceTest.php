<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Researcher;
use App\Service\Import\CurriculumPostImportService;
use App\Service\Import\RepositoryImportService;
use App\Service\PageCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Rotina que as três vias de importação pela web chamam depois de gravar o currículo: reaplica as teses do Repositório
 * (só com o CSV no servidor) e limpa o cache de páginas. Falha nela é aviso, nunca desfaz a importação.
 *
 * Usa uma raiz de projeto temporária, então não toca no cache de páginas nem no CSV do ambiente.
 */
class CurriculumPostImportServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/postimport_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/cache/public_pages', 0777, true);
        file_put_contents($this->pageFile(), 'página em cache');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testClearsThePageCacheAndSkipsTheRepositoryWhenTheCsvDoesNotExist(): void
    {
        $repository = $this->createMock(RepositoryImportService::class);
        $repository->expects($this->never())->method('import');

        $result = $this->service($repository)->run([$this->researcher('9911000000000001')]);

        $this->assertSame('sem_csv', $result['repository']);
        $this->assertTrue($result['cacheCleared']);
        $this->assertSame([], $result['warnings'], 'Sem o CSV não é falha: o importador só roda se ele existir.');
        $this->assertFileDoesNotExist($this->pageFile());
    }

    public function testReappliesTheRepositoryThesesOfTheImportedResearcher(): void
    {
        $csv = $this->writeCsv();
        $researcher = $this->researcher('9911000000000002');
        $calls = [];
        $repository = $this->createMock(RepositoryImportService::class);
        $repository->method('import')->willReturnCallback(function (string $csvFilePath, bool $dryRun = false, ?int $limit = null, ?string $centerFilter = null, ?callable $progressCallback = null, ?Researcher $onlyResearcher = null) use (&$calls): array {
            $calls[] = [$csvFilePath, $onlyResearcher, $dryRun];

            return [];
        });

        $result = $this->service($repository)->run([$researcher]);

        $this->assertSame('reaplicado', $result['repository']);
        $this->assertCount(1, $calls);
        $this->assertSame($csv, $calls[0][0]);
        $this->assertSame($researcher, $calls[0][1], 'Só as teses daquele docente.');
        $this->assertFalse($calls[0][2]);
        $this->assertFileDoesNotExist($this->pageFile());
    }

    public function testAFailureInTheRepositoryBecomesAWarningAndTheCacheIsStillCleared(): void
    {
        $this->writeCsv();
        $repository = $this->createMock(RepositoryImportService::class);
        $repository->method('import')->willThrowException(new \RuntimeException('CSV corrompido'));

        $result = $this->service($repository)->run([$this->researcher('9911000000000003')]);

        $this->assertSame('falhou', $result['repository']);
        $this->assertTrue($result['cacheCleared'], 'A falha nas teses não impede a limpeza do cache.');
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('CSV corrompido', $result['warnings'][0]);
        $this->assertStringContainsString('app:import:repository', $result['warnings'][0]);
        $this->assertFileDoesNotExist($this->pageFile());
    }

    public function testAFailureToClearThePageCacheBecomesAWarning(): void
    {
        $cache = $this->createMock(PageCacheService::class);
        $cache->method('clearCache')->willReturn(false);

        $service = new CurriculumPostImportService($cache, $this->createMock(RepositoryImportService::class), $this->root);
        $result = $service->run([$this->researcher('9911000000000004')]);

        $this->assertFalse($result['cacheCleared']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('cache de páginas públicas', $result['warnings'][0]);
    }

    public function testASmallBatchGetsOnePassPerResearcherAndALargeOneASingleFullPass(): void
    {
        $this->writeCsv();
        $only = [];
        $repository = $this->createMock(RepositoryImportService::class);
        $repository->method('import')->willReturnCallback(function (string $csvFilePath, bool $dryRun = false, ?int $limit = null, ?string $centerFilter = null, ?callable $progressCallback = null, ?Researcher $onlyResearcher = null) use (&$only): array {
            $only[] = $onlyResearcher;

            return [];
        });
        $service = $this->service($repository);

        $small = [$this->researcher('9911000000000011'), $this->researcher('9911000000000012'), $this->researcher('9911000000000013')];
        $service->run($small);
        $this->assertSame($small, $only, 'Até três docentes: uma passada para cada um.');

        $only = [];
        $service->run([...$small, $this->researcher('9911000000000014')]);
        $this->assertSame([null], $only, 'Lote maior: uma passada só, sobre todos (cada passada lê o CSV inteiro).');
    }

    public function testNoResearchersMeansNoRepositoryPass(): void
    {
        $this->writeCsv();
        $repository = $this->createMock(RepositoryImportService::class);
        $repository->expects($this->never())->method('import');

        $result = $this->service($repository)->run([]);

        $this->assertSame('sem_csv', $result['repository']);
    }

    private function service(RepositoryImportService $repository): CurriculumPostImportService
    {
        return new CurriculumPostImportService(new PageCacheService($this->root, true), $repository, $this->root);
    }

    private function researcher(string $idLattes): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes($idLattes);
        $researcher->setFullName('Docente ' . $idLattes);

        return $researcher;
    }

    private function writeCsv(): string
    {
        $path = $this->root . '/' . CurriculumPostImportService::REPOSITORY_CSV_PATH;
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, "Tipo,Titulo\n");

        return $path;
    }

    private function pageFile(): string
    {
        return $this->root . '/var/cache/public_pages/pagina.html';
    }
}
