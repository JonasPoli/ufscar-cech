<?php

namespace App\Controller\pub;

use App\Entity\Orientation;
use App\Service\StatisticsService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Páginas institucionais do portal público: "Sobre nós", "Sobre os dados" e "Dúvidas frequentes".
 *
 * Só "Sobre os dados" consulta o banco: os números são contados quando a página é gerada (e ficam no cache de
 * páginas públicas até a próxima limpeza, como as demais páginas do portal).
 */
class InstitutionalController extends AbstractController
{
    public function __construct(
        private readonly StatisticsService $statisticsService,
        private readonly Connection $connection,
    ) {}

    #[Route('/sobre', name: 'app_pub_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('pub/institutional/about.html.twig');
    }

    #[Route('/sobre-os-dados', name: 'app_pub_about_data', methods: ['GET'])]
    public function data(): Response
    {
        $summary = $this->statisticsService->getGlobalSummary();

        // Data em que o próprio docente atualizou o currículo na Plataforma Lattes (não é a data da importação)
        $lattesDates = $this->connection->fetchAssociative(
            'SELECT MAX(last_lattes_update) AS newest, MIN(last_lattes_update) AS oldest
             FROM researchers
             WHERE last_lattes_update IS NOT NULL'
        ) ?: ['newest' => null, 'oldest' => null];

        $ongoingOrientations = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM orientations WHERE NOT " . Orientation::SQL_CONCLUDED_NO_ALIAS
        );
        $thematicTerms = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM thematic_terms');

        return $this->render('pub/institutional/data.html.twig', [
            'activeResearchers' => (int) $summary['totalResearchers'],
            'allResearchers' => (int) $summary['allResearchers'],
            'formerResearchers' => max(0, (int) $summary['allResearchers'] - (int) $summary['totalResearchers']),
            'uniqueProductions' => (int) $summary['uniqueProductions'],
            'concludedOrientations' => (int) $summary['totalOrientations'],
            'ongoingOrientations' => $ongoingOrientations,
            'thematicTerms' => $thematicTerms,
            'newestLattesUpdate' => $lattesDates['newest'] ?? null,
            'oldestLattesUpdate' => $lattesDates['oldest'] ?? null,
        ]);
    }

    #[Route('/duvidas', name: 'app_pub_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('pub/institutional/faq.html.twig');
    }
}
