<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorIdentity;
use App\Entity\ProductionAuthor;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Serviço responsável pela resolução, desambiguação e renderização de autores e coautores.
 *
 * Utiliza um índice ultraleve em memória contendo os docentes do CECH (~400 registros)
 * e resolve autores externos via consultas parametrizadas sob demanda com cache de ciclo de vida.
 */
class AuthorResolverService
{
    /** Chave de cache do índice de docentes CECH */
    public const CACHE_KEY = 'thesaurus_author_cech_index_v3';

    /**
     * Índice em memória mapeando nomes e variantes normalizadas para dados dos docentes do CECH.
     * @var array<string, array{id: int, fullName: string, slug: string, idLattes: string, department: ?string, departmentCode: ?string}>|null
     */
    private ?array $cechResearchersIndex = null;

    /**
     * Cache de resoluções de autores durante o ciclo de vida da requisição.
     * @var array<string, array{identityId: int, preferredName: string, researcher: ?array{id: int, fullName: string, slug: string, idLattes: string, department: ?string, departmentCode: ?string}}|null>
     */
    private array $runtimeResolveCache = [];

    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param CacheInterface|null $cache Serviço opcional de cache do Symfony
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?CacheInterface $cache = null
    ) {}

    /**
     * Invalida o cache do índice de autores em memória e no cache do Symfony.
     */
    public function clearCache(): void
    {
        $this->cechResearchersIndex = null;
        $this->runtimeResolveCache = [];
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
     * Inicializa a estrutura de índice de docentes do CECH em memória.
     */
    private function initIndex(): void
    {
        if ($this->cechResearchersIndex !== null) {
            return;
        }

        if ($this->cache !== null) {
            $this->cechResearchersIndex = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(86400 * 30);
                return $this->buildCechResearchersIndex();
            });
            return;
        }

        $this->cechResearchersIndex = $this->buildCechResearchersIndex();
    }

    /**
     * Inverte um nome no padrão ABNT (Sobrenome, Prenome <-> Prenome Sobrenome).
     */
    public static function invertName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);
            $surname = trim($parts[0]);
            $given = trim($parts[1]);
            if ($surname !== '' && $given !== '') {
                return $given . ' ' . $surname;
            }
        } else {
            $lastSpace = mb_strrpos($name, ' ');
            if ($lastSpace !== false) {
                $given = mb_substr($name, 0, $lastSpace);
                $surname = mb_substr($name, $lastSpace + 1);
                if (trim($given) !== '' && trim($surname) !== '') {
                    return $surname . ', ' . $given;
                }
            }
        }

        return null;
    }

    /**
     * Constrói o mapa de docentes do CECH em memória (~400 registros, < 200 KB de RAM).
     *
     * @return array<string, array{id: int, fullName: string, slug: string, idLattes: string, department: ?string, departmentCode: ?string}>
     */
    private function buildCechResearchersIndex(): array
    {
        $index = [];
        $conn = $this->em->getConnection();

        $researchers = $conn->fetchAllAssociative('
            SELECT id, full_name, slug, id_lattes, department, department_code, citation_names
            FROM researchers
        ');

        foreach ($researchers as $r) {
            $rData = [
                'id' => (int)$r['id'],
                'fullName' => (string)$r['full_name'],
                'slug' => (string)$r['slug'],
                'idLattes' => (string)$r['id_lattes'],
                'department' => $r['department'] ? (string)$r['department'] : null,
                'departmentCode' => $r['department_code'] ? (string)$r['department_code'] : null,
            ];

            $fullName = (string)$r['full_name'];
            $this->addResearcherVariantsToIndex($index, $fullName, $rData);

            $citNames = (string)($r['citation_names'] ?? '');
            if ($citNames !== '') {
                $tokens = array_filter(array_map('trim', explode(';', $citNames)));
                foreach ($tokens as $tok) {
                    $this->addResearcherVariantsToIndex($index, $tok, $rData);
                }
            }
        }

        return $index;
    }

    /**
     * Auxiliar para registrar variações de nome de docente no índice CECH.
     *
     * @param array<string, array{id: int, fullName: string, slug: string, idLattes: string, department: ?string, departmentCode: ?string}> $index
     * @param array{id: int, fullName: string, slug: string, idLattes: string, department: ?string, departmentCode: ?string} $rData
     */
    private function addResearcherVariantsToIndex(array &$index, string $name, array $rData): void
    {
        $norm = StringNormalizer::normalizeString($name, false);
        if ($norm !== '') {
            $index[$norm] = $rData;
        }

        $normUpper = StringNormalizer::normalizeString($name, true);
        if ($normUpper !== '') {
            $index[$normUpper] = $rData;
        }

        $clean = preg_replace('/[.,;]/', ' ', $norm);
        $clean = preg_replace('/\s+/', ' ', trim($clean));
        if ($clean !== '' && $clean !== $norm) {
            $index[$clean] = $rData;
        }

        $inv = self::invertName($name);
        if ($inv) {
            $normInv = StringNormalizer::normalizeString($inv, false);
            if ($normInv !== '') {
                $index[$normInv] = $rData;
            }
            $normInvUpper = StringNormalizer::normalizeString($inv, true);
            if ($normInvUpper !== '') {
                $index[$normInvUpper] = $rData;
            }
            $cleanInv = preg_replace('/[.,;]/', ' ', $normInv);
            $cleanInv = preg_replace('/\s+/', ' ', trim($cleanInv));
            if ($cleanInv !== '' && $cleanInv !== $normInv) {
                $index[$cleanInv] = $rData;
            }
        }
    }

    /**
     * Resolve um nome textual de autor para sua entidade no tesauro e, se aplicável, para o docente CECH correspondente.
     *
     * @param string|null $name Nome ou variante de citação do autor
     * @return array{identityId: int, preferredName: string, researcher: ?array{id: int, fullName: string, slug: string, idLattes: string, department: ?string, departmentCode: ?string}}|null
     */
    public function resolveAuthorData(?string $name): ?array
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }

        $this->initIndex();

        $norm = StringNormalizer::normalizeString($name, false);
        if (array_key_exists($norm, $this->runtimeResolveCache)) {
            return $this->runtimeResolveCache[$norm];
        }

        $normUpper = StringNormalizer::normalizeString($name, true);
        $clean = preg_replace('/[.,;]/', ' ', $norm);
        $clean = preg_replace('/\s+/', ' ', trim($clean));

        $inv = self::invertName($name);
        $normInv = $inv ? StringNormalizer::normalizeString($inv, false) : '';
        $normInvUpper = $inv ? StringNormalizer::normalizeString($inv, true) : '';
        $cleanInv = $normInv !== '' ? preg_replace('/\s+/', ' ', trim(preg_replace('/[.,;]/', ' ', $normInv))) : '';

        // 1. Verificação imediata em memória contra docentes do CECH (O(1))
        $matchedResearcher = $this->cechResearchersIndex[$norm]
            ?? ($this->cechResearchersIndex[$normUpper]
            ?? ($this->cechResearchersIndex[$clean]
            ?? ($normInv !== '' ? ($this->cechResearchersIndex[$normInv] ?? null) : null)
            ?? ($normInvUpper !== '' ? ($this->cechResearchersIndex[$normInvUpper] ?? null) : null)
            ?? ($cleanInv !== '' ? ($this->cechResearchersIndex[$cleanInv] ?? null) : null)));

        if ($matchedResearcher !== null) {
            $result = [
                'identityId' => 0,
                'preferredName' => $matchedResearcher['fullName'],
                'researcher' => $matchedResearcher,
            ];
            $this->runtimeResolveCache[$norm] = $result;
            return $result;
        }

        // 2. Consulta direcionada ao banco de dados nas tabelas do Tesauro
        $candidates = array_values(array_unique(array_filter([
            $norm,
            $normUpper,
            $clean,
            $normInv !== '' ? $normInv : null,
            $normInvUpper !== '' ? $normInvUpper : null,
            $cleanInv !== '' ? $cleanInv : null,
        ])));

        if (empty($candidates)) {
            $this->runtimeResolveCache[$norm] = null;
            return null;
        }

        $conn = $this->em->getConnection();

        // Busca em author_name_variants com join em author_identities
        $row = $conn->fetchAssociative('
            SELECT ai.id as identityId, ai.preferred_name as preferredName
            FROM author_name_variants anv
            JOIN author_identities ai ON ai.id = anv.author_identity_id
            WHERE anv.normalized_name IN (?) AND anv.status = 1 AND ai.status = 1
            LIMIT 1
        ', [$candidates], [ArrayParameterType::STRING]);

        if (!$row) {
            // Busca direta em author_identities
            $row = $conn->fetchAssociative('
                SELECT id as identityId, preferred_name as preferredName
                FROM author_identities
                WHERE normalized_name IN (?) AND status = 1
                LIMIT 1
            ', [$candidates], [ArrayParameterType::STRING]);
        }

        if ($row) {
            $pref = (string)$row['preferredName'];
            $prefNorm = StringNormalizer::normalizeString($pref, false);
            $prefNormUpper = StringNormalizer::normalizeString($pref, true);

            $matchedR = $this->cechResearchersIndex[$prefNorm]
                ?? ($this->cechResearchersIndex[$prefNormUpper] ?? null);

            $result = [
                'identityId' => (int)$row['identityId'],
                'preferredName' => $pref,
                'researcher' => $matchedR,
            ];
            $this->runtimeResolveCache[$norm] = $result;
            return $result;
        }

        $this->runtimeResolveCache[$norm] = null;
        return null;
    }

    /**
     * Retorna a lista de todas as variações de nomes de citação cadastradas no tesauro para um determinado pesquisador.
     *
     * @param Researcher $researcher Entidade do pesquisador
     * @return array<string> Lista de nomes de citação
     */
    public function getCitationVariantsForResearcher(Researcher $researcher): array
    {
        $fullName = trim((string)$researcher->getFullName());
        if ($fullName !== '') {
            $norm = StringNormalizer::normalizeString($fullName, true);
            $variants = $this->em->getConnection()->fetchFirstColumn('
                SELECT anv.original_name
                FROM author_name_variants anv
                JOIN author_identities ai ON ai.id = anv.author_identity_id
                WHERE (ai.normalized_name = :norm OR ai.preferred_name = :full)
                  AND anv.status = 1
                ORDER BY CASE WHEN anv.source = "citation" THEN 0 ELSE 1 END, anv.id ASC
            ', ['norm' => $norm, 'full' => $fullName]);

            if (!empty($variants)) {
                $citationOnly = array_filter($variants, fn($v) => $v !== $fullName);
                return array_values(array_unique(!empty($citationOnly) ? $citationOnly : $variants));
            }
        }

        // Fallback para citationNames da entidade
        $cit = (string)$researcher->getCitationNames();
        if ($cit !== '') {
            return array_values(array_filter(array_map('trim', explode(';', $cit))));
        }

        return [];
    }

    /**
     * Resolve um autor e gera o snippet HTML formatado para exibição.
     * Se o autor corresponder a um docente do CECH, gera link clicável para o perfil público com ícone indicador.
     *
     * @param string $authorName Nome completo do autor como veio na produção
     * @param string|null $citationName Nome em citação (se disponível)
     * @param int|null $currentResearcherId ID do pesquisador dono do currículo sendo visualizado (para aplicar destaque visual)
     * @return string Código HTML seguro formatado
     */
    public function renderAuthorHtml(string $authorName, ?string $citationName = null, ?int $currentResearcherId = null): string
    {
        $display = trim($citationName ?: $authorName);
        if ($display === '') {
            return '';
        }

        $resolved = $this->resolveAuthorData($display)
            ?: ($authorName !== '' ? $this->resolveAuthorData($authorName) : null)
            ?: ($citationName !== '' ? $this->resolveAuthorData($citationName) : null);

        if ($resolved && $resolved['researcher'] !== null) {
            $r = $resolved['researcher'];
            $isCurrent = $currentResearcherId !== null && $r['id'] === $currentResearcherId;
            $slug = htmlspecialchars($r['slug'] ?: $r['idLattes'], ENT_QUOTES, 'UTF-8');
            $fullName = htmlspecialchars($r['fullName'], ENT_QUOTES, 'UTF-8');
            $dept = htmlspecialchars($r['department'] ?: 'CECH UFSCar', ENT_QUOTES, 'UTF-8');
            $dispEsc = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');

            if ($isCurrent) {
                return sprintf(
                    '<span class="font-bold text-slate-900 dark:text-white underline decoration-sky-500/50 underline-offset-2" title="Docente deste Currículo: %s (%s)">%s</span>',
                    $fullName,
                    $dept,
                    $dispEsc
                );
            }

            return sprintf(
                '<a href="/professor/%s" class="font-semibold text-sky-700 dark:text-sky-400 hover:text-sky-900 dark:hover:text-sky-300 hover:underline transition-colors inline-flex items-center gap-0.5" title="Docente CECH UFSCar: %s (%s)">%s<sl-icon name="arrow-up-right" class="text-[10px] opacity-70"></sl-icon></a>',
                $slug,
                $fullName,
                $dept,
                $dispEsc
            );
        }

        if ($resolved) {
            $pref = htmlspecialchars($resolved['preferredName'], ENT_QUOTES, 'UTF-8');
            $dispEsc = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
            return sprintf(
                '<span class="text-slate-700 dark:text-slate-300" title="Autor: %s">%s</span>',
                $pref,
                $dispEsc
            );
        }

        return sprintf(
            '<span class="text-slate-600 dark:text-slate-400">%s</span>',
            htmlspecialchars($display, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Renderiza a lista completa de coautores de uma produção científica aplicando desambiguação ontológica em cada um.
     *
     * @param mixed $productionOrAuthors Objeto ProductionItem ou coleção/array de ProductionAuthor ou strings
     * @param int|null $currentResearcherId ID do pesquisador atual (para destaque visual)
     * @return string String HTML com os autores separados por ponto-e-vírgula
     */
    public function renderProductionAuthors(mixed $productionOrAuthors, ?int $currentResearcherId = null): string
    {
        $authors = [];
        if ($productionOrAuthors instanceof ProductionItem) {
            $authors = $productionOrAuthors->getAuthors()->toArray();
        } elseif (is_iterable($productionOrAuthors)) {
            $authors = is_array($productionOrAuthors) ? $productionOrAuthors : iterator_to_array($productionOrAuthors);
        }

        if (empty($authors)) {
            return '';
        }

        // Sort by author order if available
        usort($authors, function($a, $b) {
            $orderA = $a instanceof ProductionAuthor ? ($a->getAuthorOrder() ?? 999) : 999;
            $orderB = $b instanceof ProductionAuthor ? ($b->getAuthorOrder() ?? 999) : 999;
            return $orderA <=> $orderB;
        });

        $rendered = [];
        foreach ($authors as $auth) {
            if ($auth instanceof ProductionAuthor) {
                // If already indexed with direct matched researcher, render directly
                if ($auth->getMatchedResearcher() !== null) {
                    $matched = $auth->getMatchedResearcher();
                    $isCurrent = $currentResearcherId !== null && $matched->getId() === $currentResearcherId;
                    $slug = htmlspecialchars($matched->getSlug() ?: $matched->getIdLattes(), ENT_QUOTES, 'UTF-8');
                    $fullName = htmlspecialchars($matched->getFullName(), ENT_QUOTES, 'UTF-8');
                    $dept = htmlspecialchars($matched->getDepartment() ?: 'CECH UFSCar', ENT_QUOTES, 'UTF-8');
                    $dispEsc = htmlspecialchars(trim($auth->getCitationName() ?: $auth->getAuthorName()), ENT_QUOTES, 'UTF-8');

                    $html = $isCurrent
                        ? sprintf('<span class="font-bold text-slate-900 dark:text-white underline decoration-sky-500/50 underline-offset-2" title="Docente deste Currículo: %s (%s)">%s</span>', $fullName, $dept, $dispEsc)
                        : sprintf('<a href="/professor/%s" class="font-semibold text-sky-700 dark:text-sky-400 hover:text-sky-900 dark:hover:text-sky-300 hover:underline transition-colors inline-flex items-center gap-0.5" title="Docente CECH UFSCar: %s (%s)">%s<sl-icon name="arrow-up-right" class="text-[10px] opacity-70"></sl-icon></a>', $slug, $fullName, $dept, $dispEsc);
                } elseif ($auth->getAuthorIdentity() !== null) {
                    $pref = htmlspecialchars($auth->getAuthorIdentity()->getPreferredName(), ENT_QUOTES, 'UTF-8');
                    $dispEsc = htmlspecialchars(trim($auth->getCitationName() ?: $auth->getAuthorName()), ENT_QUOTES, 'UTF-8');
                    $html = sprintf('<span class="text-slate-700 dark:text-slate-300" title="Autor: %s">%s</span>', $pref, $dispEsc);
                } else {
                    $html = $this->renderAuthorHtml($auth->getAuthorName(), $auth->getCitationName(), $currentResearcherId);
                }
            } elseif (is_string($auth)) {
                $html = $this->renderAuthorHtml($auth, null, $currentResearcherId);
            } else {
                continue;
            }

            if ($html !== '') {
                $rendered[] = $html;
            }
        }

        return implode('; ', $rendered);
    }
}

