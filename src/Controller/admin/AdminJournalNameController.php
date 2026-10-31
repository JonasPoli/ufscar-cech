<?php

declare(strict_types=1);

namespace App\Controller\admin;

use App\Entity\JournalNameCandidate;
use App\Entity\QualisJournal;
use App\Repository\JournalNameCandidateRepository;
use App\Repository\QualisJournalRepository;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Journal\Issn;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fila de revisão de nomes de periódicos (docs/evolutions/periodicos-por-base.md, A5.4).
 *
 * Aceitar grava uma variante `curated` na linha canônica do periódico escolhido (pela trava JournalVariantGuard),
 * revincula os artigos com aquele nome e marca o nome como aceito. Rejeitar e reabrir só mudam o status. Toda ação
 * é POST com CSRF: token inválido não altera nada. A busca de periódico por nome (GET, só leitura) alimenta o campo
 * de busca da tela, que preenche o ID do periódico.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/journal-names')]
class AdminJournalNameController extends AbstractController
{
    private const PER_PAGE = 50;
    private const MAX_SEARCH_LENGTH = 200;
    private const MAX_CHAIN_DEPTH = 10;
    private const JOURNAL_SEARCH_LIMIT = 15;

    /** Abas da tela, na ordem de exibição: status => rótulo. */
    private const TABS = [
        JournalNameCandidate::STATUS_PENDING => 'Pendentes',
        JournalNameCandidate::STATUS_AMBIGUOUS => 'Ambíguos',
        JournalNameCandidate::STATUS_ACCEPTED => 'Aceitos',
        JournalNameCandidate::STATUS_REJECTED => 'Rejeitados',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalNameCandidateRepository $candidates,
        private readonly JournalVariantGuard $guard,
        private readonly CurriculumNormalizationService $normalization,
        private readonly QualisJournalRepository $journals,
    ) {
    }

    #[Route('/', name: 'app_admin_journal_name_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $this->statusFrom($request->query->get('status'));
        $search = $this->searchFrom($request->query->get('q'));
        $list = $this->candidates->paginateByStatus($status, $search, $request->query->getInt('page', 1), self::PER_PAGE);

        $counts = [];
        foreach (array_keys(self::TABS) as $key) {
            $counts[$key] = $this->candidates->countByStatus($key);
        }

        return $this->render('admin/journal_names/index.html.twig', [
            'items' => $list['items'],
            'total' => $list['total'],
            'currentPage' => $list['page'],
            'totalPages' => $list['totalPages'],
            'status' => $status,
            'q' => $search,
            'tabs' => self::TABS,
            'counts' => $counts,
            'pendingCount' => $counts[JournalNameCandidate::STATUS_PENDING],
        ]);
    }

    /**
     * Periódicos pelo nome, para o campo de busca da tela (que preenche o ID do periódico): até 15 famílias cujo título,
     * nome de exibição ou variante contém o termo, na ordem de relevância, cada uma pela linha canônica (que é a que
     * `accept` usa). Termo com menos de 3 letras devolve a lista vazia.
     */
    #[Route('/search', name: 'app_admin_journal_name_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $term = $this->searchFrom($request->query->get('q'));

        return new JsonResponse([
            'q' => $term,
            'journals' => $this->journalFamilies($this->journals->findFamilyRowIdsByName($term, self::JOURNAL_SEARCH_LIMIT)),
        ]);
    }

    #[Route('/{id}/accept', name: 'app_admin_journal_name_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(Request $request, JournalNameCandidate $candidate): Response
    {
        if (!$this->isCsrfTokenValid('name_accept_' . $candidate->getId(), (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }
        if (!$this->isOpen($candidate)) {
            $this->addFlash('warning', 'Este nome já foi revisado.');

            return $this->backToList($request);
        }

        $chosen = $this->em->find(QualisJournal::class, $request->request->getInt('journal_id'));
        if ($chosen === null) {
            $this->addFlash('danger', 'Informe o ID de um periódico cadastrado.');

            return $this->backToList($request);
        }

        $journal = $this->canonicalOf($chosen);
        $name = $candidate->getRawName();

        try {
            $linked = $this->em->wrapInTransaction(function () use ($candidate, $journal, $name): int {
                // A variante é gravada antes da revinculação: o resolver lê o cadastro pelo banco
                $this->guard->addVariant($journal, $name, 'curated');
                $this->em->flush();

                $relinked = $this->normalization->reindexArticlesByNormalizedJournalName($candidate->getNormalizedName());

                $candidate
                    ->setStatus(JournalNameCandidate::STATUS_ACCEPTED)
                    ->setResolvedJournal($journal)
                    ->setReviewedAt(new \DateTimeImmutable())
                    ->setReviewedBy($this->reviewer());

                return $relinked;
            });
        } catch (JournalVariantConflictException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->backToList($request);
        }

        $this->addFlash('success', sprintf(
            'Nome «%s» aceito como variação de «%s» (#%d). Artigos revinculados: %d.',
            $name,
            (string)$journal->getTitle(),
            (int)$journal->getId(),
            $linked
        ));

        return $this->backToList($request);
    }

    #[Route('/{id}/reject', name: 'app_admin_journal_name_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Request $request, JournalNameCandidate $candidate): Response
    {
        if (!$this->isCsrfTokenValid('name_reject_' . $candidate->getId(), (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }
        if (!$this->isOpen($candidate)) {
            $this->addFlash('warning', 'Este nome já foi revisado.');

            return $this->backToList($request);
        }

        $candidate
            ->setStatus(JournalNameCandidate::STATUS_REJECTED)
            ->setReviewedAt(new \DateTimeImmutable())
            ->setReviewedBy($this->reviewer());
        $this->em->flush();

        $this->addFlash('success', sprintf('Nome «%s» rejeitado. Os artigos continuam sem vínculo.', $candidate->getRawName()));

        return $this->backToList($request);
    }

    #[Route('/{id}/reopen', name: 'app_admin_journal_name_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(Request $request, JournalNameCandidate $candidate): Response
    {
        if (!$this->isCsrfTokenValid('name_reopen_' . $candidate->getId(), (string)$request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->backToList($request);
        }
        // Um nome aceito não volta a pendente: a variante e os vínculos já foram gravados, e desfazer é outra operação
        if ($candidate->getStatus() !== JournalNameCandidate::STATUS_REJECTED) {
            $this->addFlash('warning', 'Só nomes rejeitados podem ser reabertos.');

            return $this->backToList($request);
        }

        $candidate
            ->setStatus(JournalNameCandidate::STATUS_PENDING)
            ->setResolvedJournal(null)
            ->setReviewedAt(null)
            ->setReviewedBy(null);
        $this->em->flush();

        $this->addFlash('success', sprintf('Nome «%s» reaberto e de volta aos pendentes.', $candidate->getRawName()));

        return $this->backToList($request);
    }

    private function isOpen(JournalNameCandidate $candidate): bool
    {
        return in_array($candidate->getStatus(), [JournalNameCandidate::STATUS_PENDING, JournalNameCandidate::STATUS_AMBIGUOUS], true);
    }

    private function statusFrom(mixed $value): string
    {
        return is_string($value) && array_key_exists($value, self::TABS) ? $value : JournalNameCandidate::STATUS_PENDING;
    }

    private function searchFrom(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, self::MAX_SEARCH_LENGTH, 'UTF-8') : '';
    }

    private function reviewer(): string
    {
        return mb_substr($this->getUser()?->getUserIdentifier() ?? 'desconhecido', 0, 100, 'UTF-8');
    }

    /**
     * Uma entrada por família, na ordem em que aparecem em $rowIds (agrupados por família, a canônica primeiro): id da
     * canônica, nome de exibição, ISSNs válidos e estrato (o da canônica ou, sem ele, o da primeira linha da família que
     * tem Qualis, como o resolver faz).
     *
     * @param list<int> $rowIds
     *
     * @return list<array{id: int, name: string, issn: string|null, qualis: string|null}>
     */
    private function journalFamilies(array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        $rows = [];
        foreach ($this->em->getConnection()->fetchAllAssociative(
            'SELECT id, canonical_journal_id, title, display_title, issn, issn_e, issn_imp, qualis FROM qualis_journals WHERE id IN (:ids) ORDER BY id',
            ['ids' => $rowIds],
            ['ids' => ArrayParameterType::INTEGER]
        ) as $row) {
            $rows[(int)$row['id']] = $row;
        }

        $familyOf = static fn (array $row): int => $row['canonical_journal_id'] !== null ? (int)$row['canonical_journal_id'] : (int)$row['id'];

        $order = [];
        $members = [];
        foreach ($rowIds as $rowId) {
            if (!isset($rows[$rowId])) {
                continue;
            }
            $family = $familyOf($rows[$rowId]);
            $order[$family] = true;
            $members[$family][] = $rows[$rowId];
        }

        $families = [];
        foreach (array_keys($order) as $family) {
            $canonical = $rows[$family] ?? $members[$family][0];

            $qualis = '';
            foreach (array_merge([$canonical], $members[$family]) as $row) {
                $qualis = strtoupper(trim((string)$row['qualis']));
                if ($qualis !== '') {
                    break;
                }
            }

            $display = trim((string)$canonical['display_title']);
            $families[] = [
                'id' => $family,
                'name' => $display !== '' ? $display : trim((string)$canonical['title']),
                'issn' => $this->issnsOf($canonical),
                'qualis' => $qualis !== '' ? $qualis : null,
            ];
        }

        return $families;
    }

    /**
     * ISSNs válidos da linha, formatados (`0103-863X`) e sem repetição: impresso, genérico e eletrônico, nessa ordem,
     * separados por « / ». Sem nenhum ISSN válido, nulo.
     *
     * @param array<string, mixed> $row
     */
    private function issnsOf(array $row): ?string
    {
        $found = [];
        foreach (['issn_imp', 'issn', 'issn_e'] as $column) {
            foreach (Issn::extractAll(isset($row[$column]) ? (string)$row[$column] : null) as $n8) {
                if (!in_array($n8, $found, true)) {
                    $found[] = $n8;
                }
            }
        }

        return $found === [] ? null : implode(' / ', array_map(static fn (string $n8): string => Issn::format($n8), $found));
    }

    /**
     * Linha canônica da família de $journal, com o mesmo limite de profundidade da trava.
     */
    private function canonicalOf(QualisJournal $journal): QualisJournal
    {
        $current = $journal;
        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH; $depth++) {
            $next = $current->getCanonical();
            if ($next === null || $next === $current) {
                break;
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * Volta à aba, à busca e à página de onde a ação foi feita (campos ocultos nos formulários).
     */
    private function backToList(Request $request): Response
    {
        $params = ['status' => $this->statusFrom($request->request->get('status'))];

        $search = $this->searchFrom($request->request->get('q'));
        if ($search !== '') {
            $params['q'] = $search;
        }

        $page = $request->request->getInt('page', 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->redirectToRoute('app_admin_journal_name_index', $params);
    }
}
