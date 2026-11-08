<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\ThematicTerm;
use App\Repository\ResearcherRepository;
use App\Repository\ThematicTermRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Monta o {@see ResearchFilter} a partir da URL e resolve o tema (`tema=slug`) no índice temático.
 */
final class ResearchFilterFactory
{
    /** Atributo de requisição que tira a página do cache de páginas públicas ({@see \App\Service\PageCacheService}). */
    public const NO_PAGE_CACHE = '_no_page_cache';

    /** Parâmetros de URL do filtro, como chegam na requisição. */
    private const FILTER_PARAMS = ['q', 'exata', 'departamento', 'dept', 'tema', 'grande_area', 'area', 'vinculo', 'tipo', 'de', 'ate'];

    public function __construct(
        private readonly ThematicTermRepository $termRepository,
        private readonly ?Connection $connection = null,
    ) {
    }

    /**
     * Filtro da requisição, com tema, departamento e área conferidos no banco: valor que não existe é descartado (a
     * página mostra o conjunto inteiro). Quando a URL traz um valor descartado ou reescrito, a página não entra no cache
     * de páginas, para que valores inventados (`?area=x1`, `?area=x2`…) não gravem um arquivo cada.
     */
    public function fromRequest(Request $request): ResearchFilter
    {
        $filter = ResearchFilter::fromRequest($request);
        $this->resolveTopic($filter);
        $this->validateDepartmentAndArea($filter);

        if (!$this->isCanonical($request, $filter)) {
            $request->attributes->set(self::NO_PAGE_CACHE, true);
        }

        return $filter;
    }

    private function validateDepartmentAndArea(ResearchFilter $filter): void
    {
        if ($this->connection === null) {
            return;
        }
        if ($filter->department !== '') {
            $exists = $this->connection->fetchOne(
                'SELECT 1 FROM researchers WHERE department_code IN (:v) OR department IN (:v) LIMIT 1',
                ['v' => ResearcherRepository::getDepartmentFilterValues($filter->department)],
                ['v' => ArrayParameterType::STRING]
            );
            if ($exists === false) {
                $filter->department = '';
            }
        }
        if ($filter->area !== '' && $this->connection->fetchOne('SELECT 1 FROM knowledge_areas WHERE area = ? LIMIT 1', [$filter->area]) === false) {
            $filter->area = '';
        }
    }

    /** Os parâmetros de filtro da URL são exatamente os do filtro aceito? */
    private function isCanonical(Request $request, ResearchFilter $filter): bool
    {
        $given = [];
        foreach (self::FILTER_PARAMS as $name) {
            if ($request->query->has($name)) {
                $given[$name] = (string)$request->query->get($name);
            }
        }
        $canonical = array_map('strval', $filter->toQuery());
        ksort($given);
        ksort($canonical);

        return $given === $canonical;
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
