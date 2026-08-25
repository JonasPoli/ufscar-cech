<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorIdentity;
use App\Entity\ProductionAuthor;
use App\Entity\ProductionItem;
use App\Entity\Researcher;
use Doctrine\ORM\EntityManagerInterface;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AuthorResolverService
{
    public const CACHE_KEY = 'thesaurus_author_index_v2';

    /** @var array<string, array{identityId: int, preferredName: string, researcher: ?array{id: int, fullName: string, slug: string, idLattes: string, department: ?string}}>|null */
    private ?array $authorIndex = null;

    /** @var array<int, array<string>>|null */
    private ?array $researcherCitationVariants = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?CacheInterface $cache = null
    ) {}

    public function clearCache(): void
    {
        $this->authorIndex = null;
        $this->researcherCitationVariants = null;
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    private function initIndex(): void
    {
        if ($this->authorIndex !== null) {
            return;
        }

        if ($this->cache !== null) {
            [$this->authorIndex, $this->researcherCitationVariants] = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(86400 * 30);
                return $this->buildIndexData();
            });
            return;
        }

        [$this->authorIndex, $this->researcherCitationVariants] = $this->buildIndexData();
    }

    /**
     * @return array{0: array<string, array{identityId: int, preferredName: string, researcher: ?array{id: int, fullName: string, slug: string, idLattes: string, department: ?string}}>, 1: array<int, array<string>>}
     */
    private function buildIndexData(): array
    {
        $authorIndex = [];
        $researcherCitationVariants = [];
        $conn = $this->em->getConnection();

        // 1. Index all CECH Researchers
        $researchers = $conn->fetchAllAssociative('
            SELECT id, full_name, slug, id_lattes, department, department_code, citation_names
            FROM researchers
        ');

        $researcherByNorm = [];
        foreach ($researchers as $r) {
            $rData = [
                'id' => (int)$r['id'],
                'fullName' => (string)$r['full_name'],
                'slug' => (string)$r['slug'],
                'idLattes' => (string)$r['id_lattes'],
                'department' => $r['department'] ? (string)$r['department'] : null,
                'departmentCode' => $r['department_code'] ? (string)$r['department_code'] : null,
            ];

            $normFull = StringNormalizer::normalizeString($r['full_name'], false);
            if ($normFull !== '') {
                $researcherByNorm[$normFull] = $rData;
            }

            $normFullUpper = StringNormalizer::normalizeString($r['full_name'], true);
            if ($normFullUpper !== '') {
                $researcherByNorm[$normFullUpper] = $rData;
            }

            // Also index citation names for researcher matching
            $citNames = (string)($r['citation_names'] ?? '');
            if ($citNames !== '') {
                $tokens = array_filter(array_map('trim', explode(';', $citNames)));
                foreach ($tokens as $tok) {
                    $normTok = StringNormalizer::normalizeString($tok, false);
                    if ($normTok !== '') {
                        $researcherByNorm[$normTok] = $rData;
                    }
                    $normTokUpper = StringNormalizer::normalizeString($tok, true);
                    if ($normTokUpper !== '') {
                        $researcherByNorm[$normTokUpper] = $rData;
                    }
                }
            }
        }

        // 2. Load Author Identities & Variants from Thesaurus
        $identitiesStmt = $conn->executeQuery('
            SELECT id, preferred_name, normalized_name 
            FROM author_identities 
            WHERE status = 1
        ');

        $identityById = [];
        while ($ident = $identitiesStmt->fetchAssociative()) {
            $id = (int)$ident['id'];
            $pref = (string)$ident['preferred_name'];
            $norm = StringNormalizer::normalizeString($pref, false);
            $normUpper = StringNormalizer::normalizeString($pref, true);

            $matchedResearcher = $researcherByNorm[$norm] 
                ?? ($researcherByNorm[$normUpper] 
                ?? ($researcherByNorm[$ident['normalized_name']] ?? null));

            $identityData = [
                'identityId' => $id,
                'preferredName' => $pref,
                'researcher' => $matchedResearcher,
            ];

            $identityById[$id] = $identityData;
            if ($norm !== '') {
                $authorIndex[$norm] = $identityData;
            }
            if ($normUpper !== '') {
                $authorIndex[$normUpper] = $identityData;
            }
        }

        // 3. Load Variants
        $variantsStmt = $conn->executeQuery('
            SELECT author_identity_id, original_name, normalized_name, display_name, source
            FROM author_name_variants
            WHERE status = 1
        ');

        while ($v = $variantsStmt->fetchAssociative()) {
            $identityId = (int)$v['author_identity_id'];
            if (!isset($identityById[$identityId])) continue;

            $identityData = $identityById[$identityId];
            $origName = (string)$v['original_name'];
            $source = (string)($v['source'] ?? '');

            // Store in researcher citation variants if linked to researcher and is a citation variant
            if ($identityData['researcher'] !== null) {
                $rId = $identityData['researcher']['id'];
                if (!isset($researcherCitationVariants[$rId])) {
                    $researcherCitationVariants[$rId] = [];
                }
                if ($source === 'citation' || ($origName !== $identityData['preferredName'] && !in_array($origName, $researcherCitationVariants[$rId], true))) {
                    $researcherCitationVariants[$rId][] = $origName;
                }
            }

            $normOrig = StringNormalizer::normalizeString($origName, false);
            if ($normOrig !== '') {
                // If this variant specifically matched a researcher, ensure researcher is attached
                if (!isset($authorIndex[$normOrig])) {
                    $authorIndex[$normOrig] = $identityData;
                } elseif ($identityData['researcher'] !== null && $authorIndex[$normOrig]['researcher'] === null) {
                    $authorIndex[$normOrig]['researcher'] = $identityData['researcher'];
                }
            }

            $normDb = StringNormalizer::normalizeString($v['normalized_name'], false);
            if ($normDb !== '' && !isset($authorIndex[$normDb])) {
                $authorIndex[$normDb] = $identityData;
            }
        }

        return [$authorIndex, $researcherCitationVariants];
    }

    /**
     * @return array{identityId: int, preferredName: string, researcher: ?array{id: int, fullName: string, slug: string, idLattes: string, department: ?string}}|null
     */
    public function resolveAuthorData(?string $name): ?array
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $this->initIndex();

        $norm = StringNormalizer::normalizeString(trim($name), false);
        if (isset($this->authorIndex[$norm])) {
            return $this->authorIndex[$norm];
        }

        $normUpper = StringNormalizer::normalizeString(trim($name), true);
        if (isset($this->authorIndex[$normUpper])) {
            return $this->authorIndex[$normUpper];
        }

        // Try format with or without punctuation (e.g. "MARTINEZ, V. C." vs "MARTINEZ VC")
        $clean = preg_replace('/[.,;]/', ' ', $norm);
        $clean = preg_replace('/\s+/', ' ', trim($clean));
        if (isset($this->authorIndex[$clean])) {
            return $this->authorIndex[$clean];
        }

        return null;
    }

    /**
     * Returns the array of citation name variants from the Thesaurus for a given Researcher.
     * @return array<string>
     */
    public function getCitationVariantsForResearcher(Researcher $researcher): array
    {
        $this->initIndex();
        $rId = $researcher->getId();

        if ($rId && isset($this->researcherCitationVariants[$rId]) && !empty($this->researcherCitationVariants[$rId])) {
            return array_values(array_unique($this->researcherCitationVariants[$rId]));
        }

        // Direct lookup from author thesaurus tables
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

        // Fallback to researcher citationNames string
        $cit = (string)$researcher->getCitationNames();
        if ($cit !== '') {
            return array_values(array_filter(array_map('trim', explode(';', $cit))));
        }

        return [];
    }

    /**
     * Resolves an author and renders rich HTML.
     * If the author is a CECH researcher, it returns a clickable link to their public profile.
     */
    public function renderAuthorHtml(string $authorName, ?string $citationName = null, ?int $currentResearcherId = null): string
    {
        $display = trim($citationName ?: $authorName);
        if ($display === '') return '';

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
     * Renders a full list of production authors, passing each through the Author Thesaurus.
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
