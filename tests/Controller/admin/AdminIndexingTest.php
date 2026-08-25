<?php

namespace App\Tests\Controller\admin;

use App\Entity\ProfessionalExperience;
use App\Entity\Researcher;
use App\Entity\User;
use App\Service\Indexing\CurriculumNormalizationService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminIndexingTest extends WebTestCase
{
    private function getOrCreateAdminUser($em): User
    {
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if (!$user) {
            $user = new User();
            $user->setUsername('admin');
            $user->setRoles(['ROLE_ADMIN']);
            $hasher = static::getContainer()->get('security.user_password_hasher');
            $user->setPassword($hasher->hashPassword($user, 'wab12345678'));
            $em->persist($user);
            $em->flush();
        }
        return $user;
    }

    public function testAdminIndexingDashboardLoadsSuccessfully(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $this->getOrCreateAdminUser($em);

        $client->loginUser($user);
        $client->request('GET', '/admin/indexing');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Indexação & Normatização de Dados');
        $this->assertSelectorExists('#stat-active-researchers');
        $this->assertSelectorExists('#stat-retired-researchers');
    }

    public function testAdminIndexingQueueReturnsAffiliationData(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $this->getOrCreateAdminUser($em);

        $client->loginUser($user);
        $client->request('GET', '/admin/indexing/queue');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data);
        if (!empty($data['items'])) {
            $first = $data['items'][0];
            $this->assertArrayHasKey('isActive', $first);
            $this->assertArrayHasKey('periodLabel', $first);
            $this->assertArrayHasKey('admissionYear', $first);
            $this->assertArrayHasKey('leaveYear', $first);
        }
    }

    public function testResearcherAffiliationAndPeriodLogic(): void
    {
        static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $normService = static::getContainer()->get(CurriculumNormalizationService::class);

        // Clean up any stale records from previous runs
        $em->getConnection()->executeStatement("DELETE FROM professional_experiences WHERE researcher_id IN (SELECT id FROM researchers WHERE id_lattes LIKE '9999%')");
        $em->getConnection()->executeStatement("DELETE FROM researchers WHERE id_lattes LIKE '9999%'");

        // 1. Active researcher
        $rActive = new Researcher();
        $rActive->setIdLattes('9999000011112222');
        $rActive->setFullName('Prof Ativo Teste');
        $rActive->setSlug('prof-ativo-teste');
        $rActive->setAdmissionYear(2015);
        $rActive->setLeaveYear(null);
        $rActive->setStatus(true);

        $this->assertTrue($rActive->isActiveInCech());
        $this->assertSame('No CECH desde 2015', $rActive->getCechPeriodLabel());

        // 2. Retired researcher
        $rRetired = new Researcher();
        $rRetired->setIdLattes('9999000011113333');
        $rRetired->setFullName('Prof Aposentado Teste');
        $rRetired->setSlug('prof-aposentado-teste');
        $rRetired->setAdmissionYear(1995);
        $rRetired->setLeaveYear(2018);
        $rRetired->setStatus(false);

        $this->assertFalse($rRetired->isActiveInCech());
        $this->assertSame('No CECH de 1995 a 2018', $rRetired->getCechPeriodLabel());

        // 3. Normalization detection from experiences
        $rExp = new Researcher();
        $rExp->setIdLattes('9999000011114444');
        $rExp->setFullName('Prof Com Experiencias Teste');
        $rExp->setSlug('prof-com-experiencias-teste');
        $em->persist($rExp);

        $exp1 = new ProfessionalExperience();
        $exp1->setResearcher($rExp);
        $exp1->setInstitutionName('Universidade Federal de São Carlos');
        $exp1->setRoleName('Professor Adjunto');
        $exp1->setStartYear(2012);
        $exp1->setEndYear(null);
        $exp1->setIsCurrent(true);
        $rExp->addProfessionalExperience($exp1);
        $em->persist($exp1);
        $em->flush();

        $stats = $normService->normalizeResearcher($rExp);

        $this->assertSame(2012, $rExp->getAdmissionYear());
        $this->assertTrue($rExp->isActiveInCech());
        $this->assertTrue($stats['isActiveInCech']);
        $this->assertSame(2012, $stats['admissionYear']);

        // Clean up
        $em->remove($exp1);
        $em->remove($rExp);
        $em->flush();
    }

    public function testAdminIndexingStepProcessesResearcherSuccessfully(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = $this->getOrCreateAdminUser($em);

        // Find or create a test researcher
        $researcher = $em->getRepository(Researcher::class)->findOneBy([]) 
            ?: (function() use ($em) {
                $r = new Researcher();
                $r->setIdLattes('8888777766665555');
                $r->setFullName('Docente Teste Step Index');
                $r->setCitationNames('INDEX, D. T.; Docente Teste, I.');
                $em->persist($r);
                $em->flush();
                return $r;
            })();

        $client->loginUser($user);
        $client->request('POST', '/admin/indexing/step/' . $researcher->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame($researcher->getId(), $data['researcherId']);
        $this->assertSame($researcher->getFullName(), $data['researcherName']);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('thesaurusVariantsAdded', $data['stats']);
    }
}

