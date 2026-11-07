<?php

declare(strict_types=1);

namespace App\Tests\Service\Project;

use App\Service\Project\ProjectGroupKey;
use App\Service\Project\ProjectIndexService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:index:projects`: agrupa o mesmo projeto declarado em vários currículos e liga os integrantes aos docentes pelo
 * ID Lattes. Os dados ficam numa transação revertida no tearDown.
 */
class ProjectIndexServiceTest extends KernelTestCase
{
    private Connection $conn;
    private string $t;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
        $this->t = 'zq' . strtr(bin2hex(random_bytes(4)), '0123456789', 'ghijklmnop');
        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && $this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function testGroupKeyIgnoresAccentsCaseAndPunctuationButNotTheStartYear(): void
    {
        $this->assertSame(ProjectGroupKey::compute('Educação e Sociedade', 2019), ProjectGroupKey::compute('  EDUCACAO  E SOCIEDADE. ', 2019));
        $this->assertSame(ProjectGroupKey::compute('Leitura &quot;crítica&quot;', 2019), ProjectGroupKey::compute('Leitura "crítica"', 2019), 'Entidade HTML do Lattes é decodificada antes');
        $this->assertNotSame(ProjectGroupKey::compute('Educação e Sociedade', 2019), ProjectGroupKey::compute('Educação e Sociedade', 2020));
        $this->assertNotSame(ProjectGroupKey::compute('Educação e Sociedade', 2019), ProjectGroupKey::compute('Educação e Sociedade', null));
        $this->assertNull(ProjectGroupKey::compute(' ... ', 2019));
        $this->assertSame(40, strlen((string)ProjectGroupKey::compute('X', null)));
    }

    public function testCommandGroupsTheSameProjectAndLinksMembersByLattesId(): void
    {
        $t = $this->t;
        [$ana, $anaLattes] = $this->insertResearcher("Ana $t");
        [$bia, $biaLattes] = $this->insertResearcher("Bia $t");

        $a = $this->insertProject($ana, "Educação e Sociedade $t", 2019);
        $b = $this->insertProject($bia, "EDUCACAO E SOCIEDADE $t.", 2019);
        $c = $this->insertProject($bia, "Educação e Sociedade $t", 2021);
        $this->insertMember($a, "Ana $t", $anaLattes, true);
        $this->insertMember($a, "Bia $t", $biaLattes, false);
        $this->insertMember($a, 'Pessoa de fora', '1234567890123456', false);
        $this->insertMember($b, 'Sem Lattes', null, false);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:index:projects'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Projetos declarados por mais de um docente', $tester->getDisplay());

        $key = fn (int $id): ?string => $this->conn->fetchOne('SELECT project_group_key FROM research_projects WHERE id = ?', [$id]) ?: null;
        $this->assertNotNull($key($a));
        $this->assertSame($key($a), $key($b), 'Mesmo nome normalizado e mesmo ano de início: mesmo grupo');
        $this->assertNotSame($key($a), $key($c), 'Outro ano de início: outro grupo');

        $matched = $this->conn->fetchAllKeyValue('SELECT full_name, matched_researcher_id FROM research_project_members WHERE research_project_id IN (?, ?)', [$a, $b]);
        $this->assertSame($ana, (int)$matched["Ana $t"]);
        $this->assertSame($bia, (int)$matched["Bia $t"]);
        $this->assertNull($matched['Pessoa de fora'], 'ID Lattes de quem não é docente do banco não liga');
        $this->assertNull($matched['Sem Lattes']);

        // Rodar de novo não muda nada
        $this->assertSame(['group_keys_updated' => 0, 'members_relinked' => 0], static::getContainer()->get(ProjectIndexService::class)->index());

        // Docente excluído: o vínculo cai sozinho (ON DELETE SET NULL)
        $this->conn->executeStatement('DELETE FROM researchers WHERE id = ?', [$bia]);
        $this->assertNull($this->conn->fetchOne('SELECT matched_researcher_id FROM research_project_members WHERE full_name = ?', ["Bia $t"]));
    }

    public function testResearcherOptionOnlyTouchesHisProjectsAndTheMembersWithHisId(): void
    {
        $t = $this->t;
        [$ana, $anaLattes] = $this->insertResearcher("Ana $t");
        [$bia] = $this->insertResearcher("Bia $t");
        $mine = $this->insertProject($ana, "Projeto da Ana $t", 2020);
        $other = $this->insertProject($bia, "Projeto da Bia $t", 2020);
        $this->insertMember($other, "Ana $t", $anaLattes, false);

        $result = static::getContainer()->get(ProjectIndexService::class)->index($anaLattes);

        $this->assertSame(1, $result['group_keys_updated']);
        $this->assertSame(1, $result['members_relinked']);
        $this->assertNotNull($this->conn->fetchOne('SELECT project_group_key FROM research_projects WHERE id = ?', [$mine]));
        $this->assertNull($this->conn->fetchOne('SELECT project_group_key FROM research_projects WHERE id = ?', [$other]), 'Projeto de outro docente fica para a indexação completa');
        $this->assertSame($ana, (int)$this->conn->fetchOne('SELECT matched_researcher_id FROM research_project_members WHERE research_project_id = ?', [$other]));
    }

    public function testUnknownResearcherFails(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:index:projects'));
        $tester->execute(['--researcher' => '0000000000000000']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: string} */
    private function insertResearcher(string $name): array
    {
        $idLattes = '97' . str_pad((string)random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');
        $this->conn->insert('researchers', [
            'id_lattes' => $idLattes,
            'full_name' => $name,
            'slug' => 'docente-' . $idLattes,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [(int)$this->conn->lastInsertId(), $idLattes];
    }

    private function insertProject(int $researcherId, string $name, int $startYear): int
    {
        $this->conn->insert('research_projects', [
            'researcher_id' => $researcherId,
            'name' => $name,
            'nature' => 'PESQUISA',
            'status' => 'EM_ANDAMENTO',
            'start_year' => $startYear,
            'is_coordinator' => 0,
        ]);

        return (int)$this->conn->lastInsertId();
    }

    private function insertMember(int $projectId, string $name, ?string $idLattes, bool $responsible): void
    {
        $this->conn->insert('research_project_members', [
            'research_project_id' => $projectId,
            'full_name' => $name,
            'is_responsible' => $responsible ? 1 : 0,
            'id_lattes' => $idLattes,
        ]);
    }
}
