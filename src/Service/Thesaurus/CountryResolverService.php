<?php

namespace App\Service\Thesaurus;

use App\Entity\Country;
use Doctrine\ORM\EntityManagerInterface;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CountryResolverService
{
    public const CACHE_KEY = 'thesaurus_country_index_v2';

    /** @var array<string, array{id: int, commonName: string, isoAlpha2: ?string, isoAlpha3: ?string}>|null */
    private ?array $countryIndex = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?CacheInterface $cache = null
    ) {}

    public function clearCache(): void
    {
        $this->countryIndex = null;
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    public static function countryCodeToEmoji(?string $code): string
    {
        if (!$code || strlen($code) !== 2) {
            return '🌐';
        }
        $code = strtoupper($code);
        // ASCII A = 65 -> Regional Indicator Symbol A = 0x1F1E6
        $first = ord($code[0]) - 65 + 0x1F1E6;
        $second = ord($code[1]) - 65 + 0x1F1E6;
        return mb_chr($first, 'UTF-8') . mb_chr($second, 'UTF-8');
    }

    private function initIndex(): void
    {
        if ($this->countryIndex !== null) {
            return;
        }

        if ($this->cache !== null) {
            $this->countryIndex = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(86400 * 30);
                return $this->buildIndexData();
            });
            return;
        }

        $this->countryIndex = $this->buildIndexData();
    }

    /**
     * @return array<string, array{id: int, commonName: string, isoAlpha2: ?string, isoAlpha3: ?string}>
     */
    private function buildIndexData(): array
    {
        $countryIndex = [];
        $conn = $this->em->getConnection();

        // 1. Canonical countries
        $countries = $conn->fetchAllAssociative('
            SELECT id, common_name, official_name, iso_alpha2, iso_alpha3 
            FROM countries 
            WHERE status = 1
        ');

        foreach ($countries as $c) {
            $data = [
                'id' => (int)$c['id'],
                'commonName' => (string)$c['common_name'],
                'isoAlpha2' => $c['iso_alpha2'] ? strtoupper(trim((string)$c['iso_alpha2'])) : null,
                'isoAlpha3' => $c['iso_alpha3'] ? strtoupper(trim((string)$c['iso_alpha3'])) : null,
            ];

            // Index by ISO2
            if ($data['isoAlpha2']) {
                $countryIndex[strtolower($data['isoAlpha2'])] = $data;
            }
            // Index by ISO3
            if ($data['isoAlpha3']) {
                $countryIndex[strtolower($data['isoAlpha3'])] = $data;
            }

            // Index by normalized common name
            $normCommon = StringNormalizer::normalizeString($c['common_name'], false);
            if ($normCommon !== '') {
                $countryIndex[$normCommon] = $data;
            }

            // Index by normalized official name
            if (!empty($c['official_name'])) {
                $normOfficial = StringNormalizer::normalizeString($c['official_name'], false);
                if ($normOfficial !== '') {
                    $countryIndex[$normOfficial] = $data;
                }
            }
        }

        // 2. Variations
        $variants = $conn->fetchAllAssociative('
            SELECT v.country_id, v.variation_name, v.normalized_name, c.common_name, c.iso_alpha2, c.iso_alpha3
            FROM country_name_variants v
            JOIN countries c ON v.country_id = c.id
            WHERE v.status = 1 AND c.status = 1
        ');

        foreach ($variants as $v) {
            $data = [
                'id' => (int)$v['country_id'],
                'commonName' => (string)$v['common_name'],
                'isoAlpha2' => $v['iso_alpha2'] ? strtoupper(trim((string)$v['iso_alpha2'])) : null,
                'isoAlpha3' => $v['iso_alpha3'] ? strtoupper(trim((string)$v['iso_alpha3'])) : null,
            ];

            $normVar = StringNormalizer::normalizeString($v['variation_name'], false);
            if ($normVar !== '' && !isset($countryIndex[$normVar])) {
                $countryIndex[$normVar] = $data;
            }
            $normDb = StringNormalizer::normalizeString($v['normalized_name'], false);
            if ($normDb !== '' && !isset($countryIndex[$normDb])) {
                $countryIndex[$normDb] = $data;
            }
        }

        // Common defaults / aliases
        $aliases = [
            'brasil' => 'BR',
            'brazil' => 'BR',
            'bra' => 'BR',
            'brasileira' => 'BR',
            'brasileiro' => 'BR',
            'eua' => 'US',
            'usa' => 'US',
            'estados unidos' => 'US',
            'estados unidos da america' => 'US',
            'united states' => 'US',
            'reino unido' => 'GB',
            'uk' => 'GB',
            'united kingdom' => 'GB',
            'inglaterra' => 'GB',
            'england' => 'GB',
            'franca' => 'FR',
            'france' => 'FR',
            'alemanha' => 'DE',
            'germany' => 'DE',
            'deutschland' => 'DE',
            'espanha' => 'ES',
            'spain' => 'ES',
            'espana' => 'ES',
            'italia' => 'IT',
            'italy' => 'IT',
            'portugal' => 'PT',
            'argentina' => 'AR',
            'chile' => 'CL',
            'uruguai' => 'UY',
            'uruguay' => 'UY',
            'canada' => 'CA',
            'mexico' => 'MX',
            'australia' => 'AU',
            'japao' => 'JP',
            'japan' => 'JP',
            'china' => 'CN',
            'holanda' => 'NL',
            'netherlands' => 'NL',
            'suica' => 'CH',
            'switzerland' => 'CH',
            'colombia' => 'CO',
            'peru' => 'PE',
            'cuba' => 'CU',
            'india' => 'IN',
            'russia' => 'RU',
            'africa do sul' => 'ZA',
            'south africa' => 'ZA',
            'suecia' => 'SE',
            'sweden' => 'SE',
            'noruega' => 'NO',
            'norway' => 'NO',
            'dinamarca' => 'DK',
            'denmark' => 'DK',
            'belgica' => 'BE',
            'belgium' => 'BE',
        ];

        $names = [
            'US' => 'Estados Unidos',
            'BR' => 'Brasil',
            'GB' => 'Reino Unido',
            'FR' => 'França',
            'DE' => 'Alemanha',
            'ES' => 'Espanha',
            'IT' => 'Itália',
            'PT' => 'Portugal',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'UY' => 'Uruguai',
            'CA' => 'Canadá',
            'MX' => 'México',
            'AU' => 'Austrália',
            'JP' => 'Japão',
            'CN' => 'China',
            'NL' => 'Holanda',
            'CH' => 'Suíça',
            'CO' => 'Colômbia',
            'PE' => 'Peru',
            'CU' => 'Cuba',
            'IN' => 'Índia',
            'RU' => 'Rússia',
            'ZA' => 'África do Sul',
            'SE' => 'Suécia',
            'NO' => 'Noruega',
            'DK' => 'Dinamarca',
            'BE' => 'Bélgica',
        ];

        foreach ($aliases as $alias => $iso) {
            $norm = StringNormalizer::normalizeString($alias, false);
            if (!isset($countryIndex[$norm])) {
                if (isset($countryIndex[strtolower($iso)])) {
                    $countryIndex[$norm] = $countryIndex[strtolower($iso)];
                } else {
                    $countryIndex[$norm] = [
                        'id' => 0,
                        'commonName' => $names[$iso] ?? ucfirst($alias),
                        'isoAlpha2' => $iso,
                        'isoAlpha3' => null,
                    ];
                }
            }
        }

        return $countryIndex;
    }

    /**
     * Resolves a country data array by query (name, code, variant).
     * @return array{id: int, commonName: string, isoAlpha2: ?string, isoAlpha3: ?string}|null
     */
    public function resolveCountryData(?string $query): ?array
    {
        if ($query === null || trim($query) === '') {
            return null;
        }

        $this->initIndex();

        $clean = trim($query);
        $norm = StringNormalizer::normalizeString($clean, false);

        if (isset($this->countryIndex[$norm])) {
            return $this->countryIndex[$norm];
        }

        $normLower = strtolower($clean);
        if (isset($this->countryIndex[$normLower])) {
            return $this->countryIndex[$normLower];
        }

        // Substring / partial match fallback
        foreach ($this->countryIndex as $key => $data) {
            if (strlen($key) > 3 && (str_contains($norm, $key) || str_contains($key, $norm))) {
                return $data;
            }
        }

        return null;
    }

    public function resolveCountry(?string $query): ?Country
    {
        $data = $this->resolveCountryData($query);
        if (!$data) return null;
        return $this->em->getRepository(Country::class)->find($data['id']);
    }

    public function getCountryFlag(?string $query): string
    {
        $data = $this->resolveCountryData($query);
        if ($data && !empty($data['isoAlpha2'])) {
            return self::countryCodeToEmoji($data['isoAlpha2']);
        }
        return '🌐';
    }

    public function getCountryDisplayName(?string $query): string
    {
        $data = $this->resolveCountryData($query);
        if ($data) {
            return $data['commonName'];
        }
        return $query ?: '';
    }

    public function renderCountryBadge(?string $query, string $extraClasses = ''): string
    {
        if ($query === null || trim($query) === '') {
            return '';
        }

        $data = $this->resolveCountryData($query);
        $flag = $data && !empty($data['isoAlpha2']) ? self::countryCodeToEmoji($data['isoAlpha2']) : '🌐';
        $name = $data ? htmlspecialchars($data['commonName'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
        $code = $data && !empty($data['isoAlpha2']) ? $data['isoAlpha2'] : '';

        return sprintf(
            '<span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-white/10 px-2 py-0.5 text-xs font-medium text-slate-700 dark:text-slate-300 %s" title="País: %s %s"><span class="text-sm leading-none select-none">%s</span><span>%s</span></span>',
            htmlspecialchars($extraClasses, ENT_QUOTES, 'UTF-8'),
            $name,
            $code ? "({$code})" : '',
            $flag,
            $name
        );
    }
}
