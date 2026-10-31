<?php

declare(strict_types=1);

namespace App\Controller\admin;

use App\Entity\JournalNameCandidate;
use App\Entity\QualisJournal;
use App\Repository\JournalNameCandidateRepository;
use App\Service\Indexing\CurriculumNormalizationService;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fila de revisão de nomes de periódicos (docs/evolutions/periodicos-por-base.md, A5.4).
 *
 * Aceitar grava uma variante `curated` na linha canônica do periódico escolhido (pela trava JournalVariantGuard),
 * revincula os artigos com aquele nome e marca o nome como aceito. Rejeitar e reabrir só mudam o status. Toda ação
 * é POST com CSRF: token inválido não altera nada.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/journal-names')]
class AdminJournalNameController extends AbstractController
{
    private const PER_PAGE = 50;
    private const MAX_SEARCH_LENGTH = 200;
    private const MAX_CHAIN_DEPTH = 10;

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
