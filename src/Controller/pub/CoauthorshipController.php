<?php

namespace App\Controller\pub;

use App\Service\Research\CoauthorshipGraphService;
use App\Service\Research\ResearchCatalogService;
use App\Service\Research\ResearchFilterFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rede de coautoria interativa entre docentes do CECH, com os filtros da pesquisa e a lista dos pares mais fortes.
 */
class CoauthorshipController extends AbstractController
{
    /** Rótulo => quantidade de ligações desenhadas (0 = todas). */
    public const EDGE_LIMITS = ['60' => 60, '120' => 120, '250' => 250, 'Todas' => 0];

    public function __construct(
        private readonly ResearchFilterFactory $filterFactory,
        private readonly CoauthorshipGraphService $graph,
        private readonly ResearchCatalogService $catalog,
    ) {
    }

    #[Route('/coautorias', name: 'app_pub_coauthorship', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = $this->filterFactory->fromRequest($request);
        $params = $filter->toQuery();

        // Aceita o rótulo ("Todas") ou o número de uma das opções; qualquer outro valor (inclusive texto, que (int)
        // transformaria em 0, o "Todas") volta ao padrão de 120 ligações.
        $edgeLabel = (string)$request->query->get('ligacoes', '120');
        if (!array_key_exists($edgeLabel, self::EDGE_LIMITS)) {
            $found = ctype_digit($edgeLabel) ? array_search((int)$edgeLabel, self::EDGE_LIMITS, true) : false;
            $edgeLabel = $found !== false ? (string)$found : '120';
        }
        $edgeLimit = self::EDGE_LIMITS[$edgeLabel];

        $pairsShown = max(6, min(300, $request->query->getInt('pares', 6)));
        $allPairs = $this->graph->pairs($filter);
        $people = [];
        foreach ($allPairs as $p) {
            $people[$p['a']] = true;
            $people[$p['b']] = true;
        }

        $limits = [];
        foreach (self::EDGE_LIMITS as $label => $value) {
            $limits[$label] = $this->generateUrl('app_pub_coauthorship', $params + ['ligacoes' => $label === 'Todas' ? 'Todas' : $value, 'pares' => $pairsShown]) . '#rede';
        }

        return $this->render('pub/coauthorship/index.html.twig', [
            'filter' => $filter,
            'departments' => $this->catalog->departments(),
            'topics' => $this->catalog->topics(),
            'graphSrc' => $this->generateUrl('app_api_v1_graph', $params + ['ligacoes' => $edgeLimit]),
            'pairSrc' => $this->generateUrl('app_api_v1_graph_pair', $params),
            'pairs' => $this->graph->topPairs($filter, $pairsShown),
            'totalPairs' => count($allPairs),
            'totalPeople' => count($people),
            'sharedWorks' => $this->graph->sharedWorks($filter),
            'morePairs' => $pairsShown < count($allPairs)
                ? $this->generateUrl('app_pub_coauthorship', $params + ['ligacoes' => $edgeLabel, 'pares' => $pairsShown + 12]) . '#rede'
                : null,
            'limits' => $limits,
            'currentLimit' => $edgeLabel,
        ]);
    }
}
