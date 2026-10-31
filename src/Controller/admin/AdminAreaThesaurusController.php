<?php

declare(strict_types=1);

namespace App\Controller\admin;

use App\Entity\CapesArea;
use App\Entity\CapesAreaVariant;
use App\Repository\CapesAreaRepository;
use App\Repository\CapesAreaVariantRepository;
use App\Service\Journal\CapesTaxonomy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tesauro de categorias de revistas (docs/THESAURUS.md): o que cada rótulo de categoria (ASJC, WoS, DOAJ, Latindex,
 * SciELO, CNPq, CAPES ou texto livre) significa em áreas CAPES.
 *
 * Lista os rótulos por status (ativos, pendentes, ignorados), com busca e filtros por vocabulário e área. As correções são
 * manuais e valem sobre a semente dos arquivos: definir a área de um rótulo, ignorá-lo, cadastrar um sinônimo e excluir uma
 * correção. Nenhuma delas reclassifica as revistas: a classificação só muda na próxima execução de
 * `app:journals:classify` (ou do pipeline). Toda ação é POST com CSRF: token inválido não altera nada.
 *
 * O prefixo dos nomes das rotas é `app_admin_area_thesaurus` (e não `app_admin_journal`) de propósito, para o menu de
 * Periódicos não acender nesta tela.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/journal-categories')]
class AdminAreaThesaurusController extends AbstractController
{
    private const PER_PAGE = 50;
    private const MAX_SEARCH_LENGTH = 200;

    /** Abas da tela, na ordem de exibição: status => rótulo. */
    private const TABS = [
        CapesAreaVariant::STATUS_ACTIVE => 'Ativos',
        CapesAreaVariant::STATUS_PENDING => 'Pendentes',
        CapesAreaVariant::STATUS_IGNORED => 'Ignorados',
    ];

    private const CLASSIFY_NOTICE = 'A correção entra na classificação das revistas na próxima execução de app:journals:classify (ou do pipeline, app:journals:pipeline).';

    public function __construct(
        private readonly CapesAreaVariantRepository $variants,
        private readonly CapesAreaRepository $areas,
        private readonly CapesTaxonomy $taxonomy,
    ) {
    }

    #[Route('/', name: 'app_admin_area_thesaurus_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $this->statusFrom($request->query->get('status'));
        $search = $this->searchFrom($request->query->get('q'));
        $vocabulary = $this->vocabularyFrom($request->query->get('vocabulary'));
        $area = $this->areaFrom($request->query->get('area'));

        $list = $this->variants->paginate($status, $search, $vocabulary, $area, $request->query->getInt('page', 1), self::PER_PAGE);
        $counts = $this->variants->countsByStatus();

        return $this->render('admin/area_thesaurus/index.html.twig', [
            'items' => $list['items'],
            'total' => $list['total'],
            'currentPage' => $list['page'],
            'totalPages' => $list['totalPages'],
            'status' => $status,
            'q' => $search,
            'vocabulary' => $vocabulary,
            'areaSlug' => $area?->getSlug(),
            'tabs' => self::TABS,
            'counts' => $counts,
            'manualCount' => $this->variants->countManual(),
            'vocabularies' => CapesAreaVariant::VOCABULARIES,
            'groups' => $this->taxonomy->grouped(),
        ]);
    }

    #[Route('/{id}/area', name: 'app_admin_area_thesaurus_define', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function define(Request $request, CapesAreaVariant $variant): Response
    {
        if (!$this->isCsrfTokenValid('area_thesaurus_define_' . $variant->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }

        $area = $this->areaFrom($request->request->get('area'));
        if ($area === null) {
            $this->addFlash('danger', 'Escolha uma das áreas da lista.');

            return $this->backToList($request);
        }

        $label = $variant->getVariationName();
        $this->variants->defineArea($variant, $area);

        $this->addFlash('success', sprintf('O rótulo «%s» (%s) agora vale «%s». %s', $label, $variant->getVocabulary(), $area->getName(), self::CLASSIFY_NOTICE));

        return $this->backToList($request);
    }

    #[Route('/{id}/ignore', name: 'app_admin_area_thesaurus_ignore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignore(Request $request, CapesAreaVariant $variant): Response
    {
        if (!$this->isCsrfTokenValid('area_thesaurus_ignore_' . $variant->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }

        $label = $variant->getVariationName();
        $vocabulary = $variant->getVocabulary();
        $this->variants->ignore($variant);

        $this->addFlash('success', sprintf('O rótulo «%s» (%s) foi ignorado: não diz nada sobre o assunto e não dá área a nenhuma revista. %s', $label, $vocabulary, self::CLASSIFY_NOTICE));

        return $this->backToList($request);
    }

    #[Route('/synonym', name: 'app_admin_area_thesaurus_synonym', methods: ['POST'])]
    public function synonym(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('area_thesaurus_synonym', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }

        $area = $this->areaFrom($request->request->get('area'));
        if ($area === null) {
            $this->addFlash('danger', 'Escolha uma das áreas da lista.');

            return $this->backToList($request);
        }

        $label = $request->request->get('label');
        try {
            $synonym = $this->variants->addSynonym(is_string($label) ? $label : '', $area);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->backToList($request);
        }

        if ($synonym === null) {
            $this->addFlash('warning', sprintf('O sinônimo «%s» para «%s» já existe.', trim((string) $label), $area->getName()));

            return $this->backToList($request);
        }

        $this->addFlash('success', sprintf('Sinônimo «%s» cadastrado para «%s». %s', $synonym->getVariationName(), $area->getName(), self::CLASSIFY_NOTICE));

        return $this->backToList($request);
    }

    #[Route('/{id}/delete', name: 'app_admin_area_thesaurus_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, CapesAreaVariant $variant): Response
    {
        if (!$this->isCsrfTokenValid('area_thesaurus_delete_' . $variant->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }

        $label = $variant->getVariationName();
        if (!$this->variants->deleteManual($variant)) {
            $this->addFlash('warning', 'Só correções manuais podem ser excluídas; a semente dos arquivos volta a cada execução do comando.');

            return $this->backToList($request);
        }

        $this->addFlash('success', sprintf('Correção do rótulo «%s» excluída. A semente dos arquivos, se houver, volta na próxima execução de app:journals:areas:thesaurus. %s', $label, self::CLASSIFY_NOTICE));

        return $this->backToList($request);
    }

    #[Route('/export', name: 'app_admin_area_thesaurus_export', methods: ['GET'])]
    public function export(): Response
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['rótulo', 'vocabulário', 'slug', 'área', 'grande área', 'status', 'origem', 'revistas'], ';', '"', '\\');

        foreach ($this->variants->findAllForExport() as $variant) {
            $area = $variant->getArea();
            $parent = $area?->getParent();
            fputcsv($handle, [
                self::csvText($variant->getVariationName()),
                $variant->getVocabulary(),
                $area?->getSlug() ?? '',
                $area?->getName() ?? '',
                $parent?->getName() ?? ($area !== null ? $area->getName() : ''),
                $variant->getStatus(),
                $variant->getOrigin(),
                $variant->getOccurrences(),
            ], ';', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // UTF-8 com BOM, para o Excel abrir os acentos
        $response = new Response("\xEF\xBB\xBF" . $csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="tesauro_categorias_revistas.csv"');

        return $response;
    }

    private function statusFrom(mixed $value): string
    {
        return is_string($value) && array_key_exists($value, self::TABS) ? $value : CapesAreaVariant::STATUS_ACTIVE;
    }

    private function searchFrom(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, self::MAX_SEARCH_LENGTH, 'UTF-8') : '';
    }

    private function vocabularyFrom(mixed $value): ?string
    {
        return is_string($value) && in_array($value, CapesAreaVariant::VOCABULARIES, true) ? $value : null;
    }

    /**
     * Área pelo slug; null se vazio ou desconhecido.
     */
    private function areaFrom(mixed $slug): ?CapesArea
    {
        if (!is_string($slug) || $slug === '' || !$this->taxonomy->isValidSlug($slug)) {
            return null;
        }

        return $this->areas->findOneBy(['slug' => $slug]);
    }

    /**
     * Texto de uma célula do CSV: o que começa com `=`, `+`, `-`, `@`, tabulação ou retorno de carro seria lido como
     * fórmula pelo Excel; o apóstrofo à frente o mantém como texto.
     */
    private static function csvText(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /**
     * Volta à aba, à busca, aos filtros e à página de onde a ação foi feita (campos ocultos nos formulários).
     */
    private function backToList(Request $request): Response
    {
        $params = ['status' => $this->statusFrom($request->request->get('status'))];

        $search = $this->searchFrom($request->request->get('q'));
        if ($search !== '') {
            $params['q'] = $search;
        }

        $vocabulary = $this->vocabularyFrom($request->request->get('vocabulary'));
        if ($vocabulary !== null) {
            $params['vocabulary'] = $vocabulary;
        }

        $areaFilter = $request->request->get('filter_area');
        if (is_string($areaFilter) && $this->taxonomy->isValidSlug($areaFilter)) {
            $params['area'] = $areaFilter;
        }

        $page = $request->request->getInt('page', 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->redirectToRoute('app_admin_area_thesaurus_index', $params);
    }
}
