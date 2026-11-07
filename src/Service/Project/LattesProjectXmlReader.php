<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * Lê a equipe e os financiadores de um `PROJETO-DE-PESQUISA` do XML do Lattes, com os valores como vieram (só as pontas
 * aparadas e o corte do que não cabe na coluna, como o resto do parser). Usado pela importação
 * ({@see \App\Service\Import\LattesXmlParserService}) e pelo preenchimento dos projetos já importados
 * ({@see \App\Command\BackfillProjectMembersCommand}), para os dois gravarem exatamente o mesmo.
 */
final class LattesProjectXmlReader
{
    /**
     * Os nós de projeto do currículo, na ordem em que a importação os grava: qualquer `PROJETO-DE-PESQUISA` dentro de
     * `DADOS-GERAIS` com nome preenchido. O mesmo projeto pode aparecer mais de uma vez (sob duas atuações).
     *
     * @return list<\SimpleXMLElement>
     */
    public static function projectNodes(?\SimpleXMLElement $generalData): array
    {
        if ($generalData === null) {
            return [];
        }

        $nodes = [];
        foreach ($generalData->xpath('.//PROJETO-DE-PESQUISA') ?: [] as $node) {
            if (trim((string)($node['NOME-DO-PROJETO'] ?? '')) !== '') {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return list<array{full_name: string, citation_name: ?string, member_order: ?int, is_responsible: bool, id_lattes: ?string}>
     */
    public static function members(\SimpleXMLElement $project): array
    {
        $members = [];
        if (!isset($project->{'EQUIPE-DO-PROJETO'}->{'INTEGRANTES-DO-PROJETO'})) {
            return $members;
        }
        foreach ($project->{'EQUIPE-DO-PROJETO'}->{'INTEGRANTES-DO-PROJETO'} as $node) {
            $fullName = self::cut((string)($node['NOME-COMPLETO'] ?? ''), 255);
            if ($fullName === '') {
                continue;
            }
            $order = (int)($node['ORDEM-DE-INTEGRACAO'] ?? 0);
            $citation = trim((string)($node['NOME-PARA-CITACAO'] ?? ''));

            $members[] = [
                'full_name' => $fullName,
                'citation_name' => $citation !== '' ? $citation : null,
                'member_order' => $order > 0 ? $order : null,
                'is_responsible' => strtoupper(trim((string)($node['FLAG-RESPONSAVEL'] ?? ''))) === 'SIM',
                'id_lattes' => self::cut((string)($node['NRO-ID-CNPQ'] ?? ''), 30) ?: null,
            ];
        }

        return $members;
    }

    /**
     * Financiadores sem nome nem código são omitidos: o Lattes grava um nó vazio (NATUREZA="NAO_INFORMADO") quando o
     * docente não informa nenhum, e ele não diz nada.
     *
     * @return list<array{name: string, institution_code: ?string, nature: ?string, funder_order: ?int}>
     */
    public static function funders(\SimpleXMLElement $project): array
    {
        $funders = [];
        if (!isset($project->{'FINANCIADORES-DO-PROJETO'}->{'FINANCIADOR-DO-PROJETO'})) {
            return $funders;
        }
        foreach ($project->{'FINANCIADORES-DO-PROJETO'}->{'FINANCIADOR-DO-PROJETO'} as $node) {
            $name = self::cut((string)($node['NOME-INSTITUICAO'] ?? ''), 255);
            $code = self::cut((string)($node['CODIGO-INSTITUICAO'] ?? ''), 50);
            if ($name === '' && $code === '') {
                continue;
            }
            $order = (int)($node['SEQUENCIA-FINANCIADOR'] ?? 0);

            $funders[] = [
                'name' => $name,
                'institution_code' => $code !== '' ? $code : null,
                'nature' => self::cut((string)($node['NATUREZA'] ?? ''), 50) ?: null,
                'funder_order' => $order > 0 ? $order : null,
            ];
        }

        return $funders;
    }

    private static function cut(string $value, int $length): string
    {
        $value = trim($value);

        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
    }
}
