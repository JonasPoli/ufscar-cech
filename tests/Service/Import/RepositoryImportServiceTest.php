<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Orientation;
use App\Entity\Researcher;
use App\Service\Import\RepositoryImportService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RepositoryImportServiceTest extends KernelTestCase
{
    private RepositoryImportService $importService;
    private $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->importService = $container->get(RepositoryImportService::class);
        $this->em = $container->get('doctrine.orm.entity_manager');

        $existing = $this->em->getRepository(Researcher::class)->findOneBy(['idLattes' => '9999888877776666']);
        if ($existing) {
            foreach ($this->em->getRepository(Orientation::class)->findBy(['researcher' => $existing]) as $o) {
                $this->em->remove($o);
            }
            $this->em->remove($existing);
            $this->em->flush();
        }
    }

    public function testImportWithSampleCsv(): void
    {
        // 1. Create a test researcher
        $researcher = new Researcher();
        $researcher->setIdLattes('9999888877776666');
        $researcher->setFullName('Prof Teste da Silva');
        $researcher->setSlug('prof-teste-da-silva');
        $this->em->persist($researcher);

        // 2. Create an existing orientation from Lattes (initially EM_ANDAMENTO)
        $existingOrient = new Orientation();
        $existingOrient->setResearcher($researcher);
        $existingOrient->setOrientationType(Orientation::TYPE_MESTRADO);
        $existingOrient->setNature(Orientation::NATURE_EM_ANDAMENTO);
        $existingOrient->setStudentName('Aluno Teste Existente');
        $existingOrient->setTitle('Trabalho Antigo Teste');
        $existingOrient->setYear(2023);
        $existingOrient->setSource(Orientation::SOURCE_LATTES);
        $this->em->persist($existingOrient);

        $this->em->flush();

        // 3. Create a temporary CSV file with 2 rows:
        // Row 1: Matches existing orientation (will be enriched)
        // Row 2: Brand new work (will be created)
        $csvContent = implode("\n", [
            'Tipo,Título,Títulos alternativos,Autores (Nome Sobrenome),Lattes dos autores,ORCID dos autores,Orientadores (Nome Sobrenome),Lattes dos orientadores,ORCID dos orientadores,Coorientadores (Nome Sobrenome),Lattes dos coorientadores,ORCID dos coorientadores,Membros da banca (Nome Sobrenome),Lattes dos membros da banca,ORCID dos membros da banca,Programas de pós-graduação,Centros,Campus,Comunidades,Coleções,Caminhos completos no repositório,Datas de publicação/defesa,Resumos,Assuntos,Idiomas,Direitos e licenças,DOI,URI(s) registrada(s),URL persistente,Handle,UUID do item,Última modificação',
            'Dissertação,Trabalho Antigo Teste Enriquecido,Alt Title 1,Aluno Teste Existente,http://lattes.cnpq.br/1111222233334444,,Prof Teste da Silva,http://lattes.cnpq.br/9999888877776666,,,,,,,,Programa de Pós-Graduação em Educação - PPGE,Centro de Educação e Ciências Humanas - CECH,Campus São Carlos,Campus São Carlos,Teses e Dissertações,Caminho,2023-05-15,Resumo do trabalho 1,Palavra1; Palavra2,por,CC-BY,10.1234/test1,uri1,https://repositorio.ufscar.br/handle/20.500.14289/99901,20.500.14289/99901,uuid-test-01,2023-06-01',
            'Tese,Trabalho Novo Inédito,Alt Title 2,Aluno Novo Inédito,http://lattes.cnpq.br/5555666677778888,,Prof Teste da Silva,http://lattes.cnpq.br/9999888877776666,,,,,,,,Programa de Pós-Graduação em Educação - PPGE,Centro de Educação e Ciências Humanas - CECH,Campus São Carlos,Campus São Carlos,Teses e Dissertações,Caminho,2024-08-20,Resumo do trabalho novo,AssuntoNovo1; AssuntoNovo2,por,CC-BY,10.1234/test2,uri2,https://repositorio.ufscar.br/handle/20.500.14289/99902,20.500.14289/99902,uuid-test-02,2024-09-01',
        ]);

        $tmpCsv = sys_get_temp_dir() . '/test_ted_ufscar_' . uniqid() . '.csv';
        file_put_contents($tmpCsv, $csvContent);

        try {
            // First run: 1 enriched, 1 created
            $stats1 = $this->importService->import($tmpCsv, false);

            $this->assertEquals(2, $stats1['totalCsvRows']);
            $this->assertEquals(2, $stats1['matchedAdvisorRows']);
            $this->assertEquals(1, $stats1['enrichedOrientations']);
            $this->assertEquals(1, $stats1['newOrientationsCreated']);
            $this->assertEquals(0, $stats1['skippedOrientations']);

            // Verify enriched record in DB
            $this->em->refresh($existingOrient);
            $this->assertEquals('https://repositorio.ufscar.br/handle/20.500.14289/99901', $existingOrient->getHandleUrl());
            $this->assertEquals('20.500.14289/99901', $existingOrient->getHandle());
            $this->assertEquals('uuid-test-01', $existingOrient->getRepositoryUuid());
            $this->assertEquals('Programa de Pós-Graduação em Educação - PPGE', $existingOrient->getCourseName());
            $this->assertEquals(Orientation::NATURE_CONCLUIDA, $existingOrient->getNature());

            // Second run (Idempotency test): 2 skipped, 0 new created
            $stats2 = $this->importService->import($tmpCsv, false);
            $this->assertEquals(2, $stats2['skippedOrientations']);
            $this->assertEquals(0, $stats2['newOrientationsCreated']);
        } finally {
            if (file_exists($tmpCsv)) {
                unlink($tmpCsv);
            }
            // Cleanup test entities
            $toClean = $this->em->getRepository(Researcher::class)->findOneBy(['idLattes' => '9999888877776666']);
            if ($toClean) {
                foreach ($this->em->getRepository(Orientation::class)->findBy(['researcher' => $toClean]) as $o) {
                    $this->em->remove($o);
                }
                $this->em->remove($toClean);
                $this->em->flush();
            }
        }
    }
}
