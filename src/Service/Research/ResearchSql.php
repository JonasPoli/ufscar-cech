<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\ProductionItem;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Trechos de SQL compartilhados pela pesquisa pública.
 *
 * Tudo é leitura: as condições comparam o dado bruto do Lattes (título, palavras-chave, periódico) sem alterá-lo.
 * As comparações usam a collation das colunas (`utf8mb4_unicode_ci`), que ignora caixa e acento: "educacao" acha
 * "Educação".
 */
final class ResearchSql
{
    /**
     * Chave da publicação única: a mesma obra declarada em vários currículos (ou duas vezes no mesmo) conta uma vez.
     * Com DOI, o DOI; sem DOI, tipo + ano + título. É a regra do resumo institucional do {@see \App\Service\StatisticsService}.
     */
    public static function workKey(string $pi = 'pi'): string
    {
        // Coluna gerada pelo MySQL (migration Version20261112150000) com esta fórmula:
        // MD5(DOI em minúsculas) ou MD5('T:' tipo ':' ano ':' título em minúsculas)
        return "{$pi}.work_key";
    }

    /** Palavras-chave do trabalho (lista JSON em `extra_data`), como texto comparável sem caixa e acento. */
    public static function keywords(string $pi = 'pi'): string
    {
        // Coluna gerada pelo MySQL a partir de `extra_data.keywords`; vazia ('') quando não há palavras-chave
        return "{$pi}.keywords_text";
    }

    /** Veículo para exibição: periódico, evento ou editora, como veio do Lattes. */
    public static function venue(string $pi = 'pi'): string
    {
        return "COALESCE(NULLIF({$pi}.journal_name, ''), NULLIF({$pi}.event_name, ''), NULLIF({$pi}.publisher, ''))";
    }

    public static function likePattern(string $text): string
    {
        return '%' . addcslashes($text, '\\%_') . '%';
    }

    /**
     * Condição WHERE do filtro sobre `production_items` ($pi) e o docente dono do currículo ($r).
     *
     * Com `$withVenue`, a busca por texto também acha o trabalho pelo periódico, pelo evento, pelo DOI e pelo nome de
     * qualquer revista da família (`$journalRowIds`, só para artigos): é a busca de publicações, que precisa achar
     * "tudo o que saiu na revista X". A busca de especialistas usa só título e palavras-chave.
     *
     * @param list<int> $journalRowIds
     * @return array{sql: string, params: array<string, mixed>, types: array<string, mixed>}
     */
    public static function where(ResearchFilter $f, bool $withVenue = false, array $journalRowIds = [], string $pi = 'pi', string $r = 'r'): array
    {
        $parts = [];
        $params = [];
        $types = [];
        $kw = self::keywords($pi);

        $terms = $f->terms();
        if ($terms !== []) {
            $termParts = [];
            foreach ($terms as $i => $term) {
                $params["rf_t{$i}"] = self::likePattern($term);
                $termParts[] = "({$pi}.title LIKE :rf_t{$i} OR {$kw} LIKE :rf_t{$i})";
            }
            $textSql = '(' . implode(' AND ', $termParts) . ')';

            if ($withVenue) {
                $params['rf_q'] = self::likePattern($f->q);
                $alternatives = [
                    $textSql,
                    "{$pi}.journal_name LIKE :rf_q",
                    "{$pi}.event_name LIKE :rf_q",
                    "{$pi}.doi LIKE :rf_q",
                ];
                if ($journalRowIds !== []) {
                    $params['rf_article'] = ProductionItem::TYPE_ARTIGO;
                    $params['rf_journals'] = array_values($journalRowIds);
                    $types['rf_journals'] = ArrayParameterType::INTEGER;
                    $alternatives[] = "({$pi}.item_type = :rf_article AND {$pi}.qualis_journal_id IN (:rf_journals))";
                }
                $textSql = '(' . implode(' OR ', $alternatives) . ')';
            }

            $parts[] = $textSql;
        }

        if ($f->topicText !== '') {
            $params['rf_topic'] = self::likePattern($f->topicText);
            $parts[] = "({$pi}.title LIKE :rf_topic OR {$kw} LIKE :rf_topic)";
        }

        if ($f->department !== '') {
            $params['rf_depts'] = \App\Repository\ResearcherRepository::getDepartmentFilterValues($f->department);
            $types['rf_depts'] = ArrayParameterType::STRING;
            $parts[] = "({$r}.department_code IN (:rf_depts) OR {$r}.department IN (:rf_depts))";
        }

        if ($f->majorArea !== '') {
            $params['rf_major'] = MajorAreaCatalog::storedValues($f->majorArea);
            $types['rf_major'] = ArrayParameterType::STRING;
            $parts[] = "EXISTS (SELECT 1 FROM knowledge_areas rf_ka1 WHERE rf_ka1.researcher_id = {$r}.id AND rf_ka1.major_area IN (:rf_major))";
        }

        if ($f->area !== '') {
            $params['rf_area'] = $f->area;
            $parts[] = "EXISTS (SELECT 1 FROM knowledge_areas rf_ka2 WHERE rf_ka2.researcher_id = {$r}.id AND rf_ka2.area = :rf_area)";
        }

        if ($f->affiliation === 'vigente') {
            $parts[] = "{$r}.status = 1";
        } elseif ($f->affiliation === 'encerrado') {
            $parts[] = "{$r}.status = 0";
        }

        if ($f->itemType() !== null) {
            $params['rf_type'] = $f->itemType();
            $parts[] = "{$pi}.item_type = :rf_type";
        }

        if ($f->from !== null) {
            $params['rf_from'] = $f->from;
            $parts[] = "{$pi}.year >= :rf_from";
        }

        if ($f->to !== null) {
            $params['rf_to'] = $f->to;
            $parts[] = "{$pi}.year <= :rf_to";
        }

        if ($f->researcherId !== null) {
            $params['rf_rid'] = $f->researcherId;
            $parts[] = "{$pi}.researcher_id = :rf_rid";
        }

        return [
            'sql' => $parts === [] ? '1 = 1' : implode(' AND ', $parts),
            'params' => $params,
            'types' => $types,
        ];
    }

    /**
     * Pontuação de relevância de um trabalho, com os mesmos parâmetros de {@see where()}.
     *
     * O termo no título vale 3 e nas palavras-chave 1; com mais de uma palavra, a expressão completa no título soma 5.
     * Na busca exata, a frase no título vale 8 e nas palavras-chave 3. O tema soma como um termo. Sem texto, todos
     * os trabalhos valem 1 e a ordem fica por ano.
     *
     * @param array<string, mixed> $params parâmetros já montados por {@see where()}; o da expressão completa é acrescentado
     */
    public static function score(ResearchFilter $f, array &$params, string $pi = 'pi'): string
    {
        $kw = self::keywords($pi);
        $parts = [];
        $terms = $f->terms();

        if ($f->exact && $terms !== []) {
            $parts[] = "({$pi}.title LIKE :rf_t0) * 8 + ({$kw} LIKE :rf_t0) * 3";
        } elseif ($terms !== []) {
            foreach (array_keys($terms) as $i) {
                $parts[] = "({$pi}.title LIKE :rf_t{$i}) * 3 + ({$kw} LIKE :rf_t{$i})";
            }
            if (count($terms) > 1) {
                $params['rf_phrase'] = self::likePattern($f->q);
                $parts[] = "({$pi}.title LIKE :rf_phrase) * 5";
            }
        }

        if ($f->topicText !== '') {
            $parts[] = "({$pi}.title LIKE :rf_topic) * 3 + ({$kw} LIKE :rf_topic)";
        }

        if (isset($params['rf_q'])) {
            $parts[] = "({$pi}.journal_name LIKE :rf_q OR {$pi}.event_name LIKE :rf_q OR {$pi}.doi LIKE :rf_q)";
        }

        return $parts === [] ? '1' : '(' . implode(' + ', $parts) . ')';
    }
}
