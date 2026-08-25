<?php

namespace App\Service\Thesaurus;

use App\Entity\Institution;
use Doctrine\ORM\EntityManagerInterface;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Serviço responsável pela resolução, desambiguação e exibição formatada de instituições de ensino e pesquisa.
 *
 * Mapeia siglas (ex: 'UFSCar', 'USP', 'UNICAMP'), variações textuais (ex: 'Universidade Federal de São Carlos')
 * e nomes com traços para a entidade canônica Institution e respectivo país com bandeira emoji.
 */
class InstitutionResolverService
{
    /** Chave de cache do índice do tesauro institucional */
    public const CACHE_KEY = 'thesaurus_institution_index_v2';

    /**
     * Índice em memória de instituições indexadas por siglas, nomes oficiais e variações normalizadas.
     * @var array<string, array{id: int, officialName: string, shortName: ?string, acronym: ?string, countryIso: ?string, countryName: ?string}>|null
     */
    private ?array $institutionIndex = null;

    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param CountryResolverService $countryResolver Serviço de resolução de países e bandeiras
     * @param CacheInterface|null $cache Serviço opcional de cache do Symfony
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CountryResolverService $countryResolver,
        private readonly ?CacheInterface $cache = null
    ) {}

    /**
     * Invalida o cache em memória e o cache persistido do índice institucional.
     */
    public function clearCache(): void
    {
        $this->institutionIndex = null;
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
     * Inicializa a estrutura de índice em memória buscando do cache ou reconstruindo do banco.
     */
    private function initIndex(): void
    {
        if ($this->institutionIndex !== null) {
            return;
        }

        if ($this->cache !== null) {
            $this->institutionIndex = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(86400 * 30);
                return $this->buildIndexData();
            });
            return;
        }

        $this->institutionIndex = $this->buildIndexData();
    }

    /**
     * Constrói o índice associativo mapeando siglas, nomes oficiais e variações textuais para a instituição canônica.
     *
     * @return array<string, array{id: int, officialName: string, shortName: ?string, acronym: ?string, countryIso: ?string, countryName: ?string}>
     */
    private function buildIndexData(): array
    {
        $institutionIndex = [];
        $conn = $this->em->getConnection();

        // 1. Load canonical institutions
        $institutions = $conn->fetchAllAssociative('
            SELECT i.id, i.official_name, i.short_name, i.acronym, c.iso_alpha2 as country_iso, c.common_name as country_name
            FROM institutions i
            LEFT JOIN countries c ON i.country_id = c.id
            WHERE i.status = 1
        ');

        foreach ($institutions as $inst) {
            $data = [
                'id' => (int)$inst['id'],
                'officialName' => (string)$inst['official_name'],
                'shortName' => $inst['short_name'] ? (string)$inst['short_name'] : null,
                'acronym' => $inst['acronym'] ? (string)$inst['acronym'] : null,
                'countryIso' => $inst['country_iso'] ? strtoupper(trim((string)$inst['country_iso'])) : null,
                'countryName' => $inst['country_name'] ? (string)$inst['country_name'] : null,
            ];

            // Index by acronym
            if (!empty($data['acronym'])) {
                $normAcr = StringNormalizer::normalizeString($data['acronym'], false);
                if ($normAcr !== '') {
                    $institutionIndex[$normAcr] = $data;
                }
            }

            // Index by official name
            $normOfficial = StringNormalizer::normalizeString($data['officialName'], false);
            if ($normOfficial !== '') {
                $institutionIndex[$normOfficial] = $data;
            }

            // Index by short name
            if (!empty($data['shortName'])) {
                $normShort = StringNormalizer::normalizeString($data['shortName'], false);
                if ($normShort !== '') {
                    $institutionIndex[$normShort] = $data;
                }
            }
        }

        // 2. Load institution variations
        $variants = $conn->fetchAllAssociative('
            SELECT v.institution_id, v.variation_name, v.normalized_name, i.official_name, i.short_name, i.acronym, c.iso_alpha2 as country_iso, c.common_name as country_name
            FROM institution_name_variants v
            JOIN institutions i ON v.institution_id = i.id
            LEFT JOIN countries c ON i.country_id = c.id
            WHERE v.status = 1 AND i.status = 1
        ');

        foreach ($variants as $v) {
            $data = [
                'id' => (int)$v['institution_id'],
                'officialName' => (string)$v['official_name'],
                'shortName' => $v['short_name'] ? (string)$v['short_name'] : null,
                'acronym' => $v['acronym'] ? (string)$v['acronym'] : null,
                'countryIso' => $v['country_iso'] ? strtoupper(trim((string)$v['country_iso'])) : null,
                'countryName' => $v['country_name'] ? (string)$v['country_name'] : null,
            ];

            $normVar = StringNormalizer::normalizeString($v['variation_name'], false);
            if ($normVar !== '' && !isset($institutionIndex[$normVar])) {
                $institutionIndex[$normVar] = $data;
            }
            $normDb = StringNormalizer::normalizeString($v['normalized_name'], false);
            if ($normDb !== '' && !isset($institutionIndex[$normDb])) {
                $institutionIndex[$normDb] = $data;
            }
        }

        return $institutionIndex;
    }

    /**
     * Resolve um termo de busca para os dados da instituição canônica indexada.
     *
     * @param string|null $query Nome, sigla ou texto de instituição
     * @return array{id: int, officialName: string, shortName: ?string, acronym: ?string, countryIso: ?string, countryName: ?string}|null
     */
    public function resolveInstitutionData(?string $query): ?array
    {
        if ($query === null || trim($query) === '') {
            return null;
        }

        $this->initIndex();

        $clean = trim($query);
        $norm = StringNormalizer::normalizeString($clean, false);

        if (isset($this->institutionIndex[$norm])) {
            return $this->institutionIndex[$norm];
        }

        // Tenta limpar separadores (ex: "UFSCar - Universidade Federal de São Carlos")
        if (str_contains($clean, '-')) {
            $parts = explode('-', $clean);
            foreach ($parts as $part) {
                $partNorm = StringNormalizer::normalizeString(trim($part), false);
                if ($partNorm !== '' && isset($this->institutionIndex[$partNorm])) {
                    return $this->institutionIndex[$partNorm];
                }
            }
        }

        // Fallback por correspondência de prefixo se o termo for longo
        if (strlen($norm) > 4) {
            foreach ($this->institutionIndex as $key => $data) {
                if (strlen($key) > 4 && (str_starts_with($norm, $key) || str_starts_with($key, $norm))) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * Resolve o termo para a entidade canônica Institution do Doctrine.
     *
     * @param string|null $query Nome ou sigla
     * @return Institution|null
     */
    public function resolveInstitution(?string $query): ?Institution
    {
        $data = $this->resolveInstitutionData($query);
        if (!$data) return null;
        return $this->em->getRepository(Institution::class)->find($data['id']);
    }

    /**
     * Retorna o nome formatado de exibição para a instituição (ex: 'UFSCar - Universidade Federal de São Carlos').
     *
     * @param string|null $query Texto original
     * @return string Nome formatado
     */
    public function getInstitutionDisplayName(?string $query): string
    {
        $data = $this->resolveInstitutionData($query);
        if ($data) {
            if (!empty($data['acronym']) && !empty($data['officialName']) && !str_contains($data['officialName'], $data['acronym'])) {
                return "{$data['acronym']} - {$data['officialName']}";
            }
            return $data['officialName'] ?: ($data['acronym'] ?: ($query ?: ''));
        }
        return $query ?: '';
    }

    /**
     * Renderiza um badge HTML estilizado com o nome da instituição e a bandeira do país de origem.
     *
     * @param string|null $query Nome ou sigla da instituição
     * @param string $extraClasses Classes CSS adicionais do Tailwind
     * @return string Código HTML seguro do badge
     */
    public function renderInstitutionBadge(?string $query, string $extraClasses = ''): string
    {
        if ($query === null || trim($query) === '') {
            return '';
        }

        $data = $this->resolveInstitutionData($query);
        $displayName = $data ? $this->getInstitutionDisplayName($query) : htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
        $flag = '';

        if ($data && !empty($data['countryIso'])) {
            $flag = CountryResolverService::countryCodeToEmoji($data['countryIso']);
        } elseif ($data && !empty($data['countryName'])) {
            $flag = $this->countryResolver->getCountryFlag($data['countryName']);
        }

        return sprintf(
            '<span class="inline-flex items-center gap-1.5 rounded-lg bg-sky-50 dark:bg-sky-950/40 border border-sky-200/80 dark:border-sky-800/30 px-2 py-0.5 text-xs font-medium text-sky-800 dark:text-sky-300 %s" title="Instituição: %s">%s<span>%s</span></span>',
            htmlspecialchars($extraClasses, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'),
            $flag ? "<span class=\"text-xs leading-none select-none\">{$flag}</span>" : '<sl-icon name="building" class="text-xs"></sl-icon>',
            htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')
        );
    }
}
