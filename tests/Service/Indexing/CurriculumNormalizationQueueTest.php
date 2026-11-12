<?php

declare(strict_types=1);

namespace App\Tests\Service\Indexing;

use App\Entity\Researcher;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Journal\JournalNameQueueService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A fila de nomes de periódicos é atualizada a cada currículo, a menos que o lote peça para adiar. */
class CurriculumNormalizationQueueTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->conn = $this->em->getConnection();
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
        parent::tearDown();
    }

    public function testQueueIsRebuiltPerResearcherByDefaultAndDeferredOnRequest(): void
    {
        $queue = $this->createMock(JournalNameQueueService::class);
        $queue->expects($this->exactly(2))->method('rebuild')->with(false)->willReturn([]);
        static::getContainer()->set(JournalNameQueueService::class, $queue);

        $service = static::getContainer()->get(CurriculumNormalizationService::class);
        $researcher = $this->createResearcher();

        $service->normalizeResearcher($researcher);        // padrão: reconstrói (1)
        $service->normalizeResearcher($researcher, false); // adiado: não reconstrói
        $service->rebuildNameQueue();                      // fim do lote (2)
    }

    private function createResearcher(): Researcher
    {
        $researcher = new Researcher();
        $researcher->setIdLattes('9923' . str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT));
        $researcher->setFullName('Docente Teste Fila Adiada');
        $researcher->setSlug('docente-teste-fila-adiada-' . bin2hex(random_bytes(4)));
        $this->em->persist($researcher);
        $this->em->flush();

        return $researcher;
    }
}
