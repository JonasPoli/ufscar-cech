<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\ThematicTerm;
use App\Repository\ThematicTermRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Monta o {@see ResearchFilter} a partir da URL e resolve o tema (`tema=slug`) no índice temático.
 */
final class ResearchFilterFactory
{
    public function __construct(private readonly ThematicTermRepository $termRepository)
    {
    }

    public function fromRequest(Request $request): ResearchFilter
    {
        $filter = ResearchFilter::fromRequest($request);
        $this->resolveTopic($filter);

        return $filter;
    }

    /** Preenche `topicText` a partir do slug; slug desconhecido é descartado (o filtro não pode ficar inválido). */
    public function resolveTopic(ResearchFilter $filter): ?ThematicTerm
    {
        if ($filter->topicSlug === '') {
            return null;
        }

        $term = $this->termRepository->findBySlugOrId($filter->topicSlug);
        if ($term === null || trim((string)$term->getTerm()) === '') {
            $filter->topicSlug = '';
            $filter->topicText = '';

            return null;
        }

        $filter->topicSlug = (string)($term->getSlug() ?: $term->getId());
        $filter->topicText = trim((string)$term->getTerm());

        return $term;
    }

    public function forTerm(ThematicTerm $term): ResearchFilter
    {
        $filter = new ResearchFilter();
        $filter->topicSlug = (string)($term->getSlug() ?: $term->getId());
        $filter->topicText = trim((string)$term->getTerm());

        return $filter;
    }
}
