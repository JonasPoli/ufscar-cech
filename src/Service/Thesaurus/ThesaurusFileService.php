<?php

namespace App\Service\Thesaurus;

use League\Csv\Reader;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serviço de importação e exportação de arquivos de Tesauro em múltiplos formatos.
 *
 * Suporta formatos de interoperabilidade científica:
 * - VantagePoint (.the): Formato padrão de tesauro bibliométrico com termos principais e regras de matching ^...$.
 * - CSV (.csv): Formato tabular com colunas preferred_name e variant_name (com suporte a variantes com pipe |).
 * - JSON (.json): Estruturas hierárquicas de termos preferenciais e arrays de variações.
 * - XML (.xml): Esquemas estruturados para intercâmbio de ontologias.
 */
class ThesaurusFileService
{
    /**
     * Normaliza a codificação do conteúdo para UTF-8 sem BOM.
     */
    public function normalizeEncoding(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16BE');
        } elseif (mb_detect_encoding($content, 'UTF-16LE', true) && str_contains($content, "\x00")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        } elseif (mb_detect_encoding($content, 'UTF-16BE', true) && str_contains($content, "\x00")) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16BE');
        } elseif (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        return preg_replace('/^\x{FEFF}/u', '', $content);
    }

    /**
     * Realiza o parsing de um arquivo de tesauro identificando o formato pela extensão ou parâmetro.
     *
     * @param string $filePath Caminho absoluto ou relativo do arquivo no disco
     * @param string|null $extension Extensão forçada ('the', 'csv', 'json', 'xml')
     * @return array<int, array{header: string, preferred_name: string, variations: array<string>, variants: array<string>}>
     */
    public function parseFile(string $filePath, ?string $extension = null): array
    {
        if ($extension === null) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        }

        $raw = file_get_contents($filePath);
        $content = $this->normalizeEncoding($raw);

        return match ($extension) {
            'csv' => $this->parseCsvContent($content),
            'json' => $this->parseJsonContent($content),
            'xml' => $this->parseXmlContent($content),
            default => $this->parseTheContent($content),
        };
    }

    /**
     * Faz o parsing do conteúdo no formato VantagePoint (.the).
     *
     * @param string $content Conteúdo bruto em texto
     * @return array<int, array{header: string, preferred_name: string, variations: array<string>, variants: array<string>}>
     */
    public function parseTheContent(string $content): array
    {
        $content = $this->normalizeEncoding($content);

        $lines = explode("\n", str_replace("\r", "", $content));
        $currentHeader = null;
        $currentVars = [];
        $entries = [];
        $hasAsteriskHeaders = str_contains($content, "\n**") || str_starts_with($content, "**");

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if (str_starts_with($line, '**')) {
                if ($currentHeader !== null) {
                    $entries[] = [
                        'header' => $currentHeader,
                        'preferred_name' => $currentHeader,
                        'variations' => array_values(array_unique($currentVars)),
                        'variants' => array_values(array_unique($currentVars)),
                    ];
                }
                $currentHeader = trim(ltrim($line, '*#'));
                $currentVars = [];
            } elseif (str_starts_with($line, ';')) {
                $v = trim(ltrim($line, ';'));
                if ($v !== '') $currentVars[] = $v;
            } elseif (preg_match('/\\^(.*?)\\$?$/', $line, $m)) {
                $v = trim($m[1]);
                if ($v !== '') $currentVars[] = $v;
            } else {
                if ($hasAsteriskHeaders && $currentHeader !== null) {
                    $v = trim(rtrim($line, '$'));
                    if ($v !== '') $currentVars[] = $v;
                } else {
                    if ($currentHeader !== null) {
                        $entries[] = [
                            'header' => $currentHeader,
                            'preferred_name' => $currentHeader,
                            'variations' => array_values(array_unique($currentVars)),
                            'variants' => array_values(array_unique($currentVars)),
                        ];
                    }
                    $currentHeader = $line;
                    $currentVars = [];
                }
            }
        }

        if ($currentHeader !== null) {
            $entries[] = [
                'header' => $currentHeader,
                'preferred_name' => $currentHeader,
                'variations' => array_values(array_unique($currentVars)),
                'variants' => array_values(array_unique($currentVars)),
            ];
        }

        return $entries;
    }

    public function parseCsvContent(string $content): array
    {
        $csv = Reader::fromString($content);
        $firstLine = strtok($content, "\r\n") ?: '';
        if (str_contains($firstLine, ';') && !str_contains($firstLine, ',')) {
            $csv->setDelimiter(';');
        } elseif (str_contains($firstLine, "\t")) {
            $csv->setDelimiter("\t");
        }
        $csv->setHeaderOffset(0);

        $grouped = [];
        foreach ($csv->getRecords() as $record) {
            $header = trim($record['preferred_name'] ?? $record['preferred'] ?? $record['header'] ?? $record['official_name'] ?? $record['termo_preferido'] ?? '');
            $variant = trim($record['variant_name'] ?? $record['variation'] ?? $record['variant'] ?? $record['variantes'] ?? '');

            if ($header === '') continue;

            if (!isset($grouped[$header])) {
                $grouped[$header] = [];
            }

            if ($variant !== '') {
                if (str_contains($variant, '|')) {
                    foreach (explode('|', $variant) as $v) {
                        $v = trim($v);
                        if ($v !== '') $grouped[$header][] = $v;
                    }
                } else {
                    $grouped[$header][] = $variant;
                }
            }
        }

        $entries = [];
        foreach ($grouped as $h => $vars) {
            $entries[] = [
                'header' => $h,
                'preferred_name' => $h,
                'variations' => array_values(array_unique($vars)),
                'variants' => array_values(array_unique($vars)),
            ];
        }

        return $entries;
    }

    public function parseJsonContent(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) return [];

        $entries = [];
        foreach ($data as $key => $item) {
            if (isset($item['header']) || isset($item['preferred_name'])) {
                $header = trim((string)($item['header'] ?? $item['preferred_name']));
                $vars = $item['variations'] ?? $item['variants'] ?? [];
                $entries[] = [
                    'header' => $header,
                    'preferred_name' => $header,
                    'variations' => is_array($vars) ? array_map('trim', $vars) : [],
                    'variants' => is_array($vars) ? array_map('trim', $vars) : [],
                ];
            } elseif (is_string($key) && is_array($item)) {
                $entries[] = [
                    'header' => trim($key),
                    'preferred_name' => trim($key),
                    'variations' => array_map('trim', $item),
                    'variants' => array_map('trim', $item),
                ];
            }
        }
        return $entries;
    }

    public function parseXmlContent(string $content): array
    {
        $xml = @simplexml_load_string($content);
        if (!$xml) return [];

        $entries = [];
        foreach ($xml->children() as $child) {
            $header = trim((string)($child->header ?? $child->name ?? $child->preferred_name ?? ''));
            if ($header === '') continue;

            $variations = [];
            if (isset($child->variations)) {
                foreach ($child->variations->children() as $v) {
                    $val = trim((string)$v);
                    if ($val !== '') $variations[] = $val;
                }
            } elseif (isset($child->variation)) {
                foreach ($child->variation as $v) {
                    $val = trim((string)$v);
                    if ($val !== '') $variations[] = $val;
                }
            }

            $entries[] = [
                'header' => $header,
                'preferred_name' => $header,
                'variations' => array_values(array_unique($variations)),
                'variants' => array_values(array_unique($variations)),
            ];
        }
        return $entries;
    }

    /**
     * Generates VantagePoint .the file content.
     */
    public function generateTheContent(array $data): string
    {
        $out = [];
        foreach ($data as $item) {
            $header = trim($item['header'] ?? $item['preferred_name'] ?? '');
            if ($header === '') continue;

            $out[] = "**#" . mb_strtolower($header, 'UTF-8');
            $vars = $item['variations'] ?? $item['variants'] ?? [];
            foreach ($vars as $v) {
                $v = trim($v);
                if ($v === '') continue;
                $out[] = "100 1 ^" . mb_strtolower($v, 'UTF-8') . "$";
            }
        }
        return implode("\r\n", $out);
    }

    /**
     * Generates CSV file content.
     */
    public function generateCsvContent(array $data): string
    {
        $csv = Writer::createFromString('');
        $csv->insertOne(['preferred_name', 'variant_name']);

        foreach ($data as $item) {
            $header = trim($item['header'] ?? $item['preferred_name'] ?? '');
            if ($header === '') continue;

            $vars = $item['variations'] ?? $item['variants'] ?? [];
            if (empty($vars)) {
                $csv->insertOne([$header, '']);
            } else {
                foreach ($vars as $v) {
                    $v = trim($v);
                    if ($v === '') continue;
                    $csv->insertOne([$header, $v]);
                }
            }
        }

        return $csv->toString();
    }

    /**
     * Generates JSON file content.
     */
    public function generateJsonContent(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Generates XML file content.
     */
    public function generateXmlContent(array $data, string $rootElement = 'thesaurus', string $itemElement = 'term'): string
    {
        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$rootElement}></{$rootElement}>");
        foreach ($data as $item) {
            $header = $item['header'] ?? $item['preferred_name'] ?? '';
            $node = $xml->addChild($itemElement);
            $node->addChild('header', htmlspecialchars($header));
            $varsNode = $node->addChild('variations');
            foreach ($item['variations'] ?? $item['variants'] ?? [] as $v) {
                $varsNode->addChild('variation', htmlspecialchars($v));
            }
        }
        return (string)$xml->asXML();
    }

    public function exportThesaurusStream(array $records, string $format, string $filenameBase): Response
    {
        return match (strtolower($format)) {
            'csv' => new Response("\xEF\xBB\xBF" . $this->generateCsvContent($records), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => sprintf('attachment; filename="%s.csv"', $filenameBase),
            ]),
            'json' => new Response($this->generateJsonContent($records), 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => sprintf('attachment; filename="%s.json"', $filenameBase),
            ]),
            'xml' => new Response($this->generateXmlContent($records), 200, [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Content-Disposition' => sprintf('attachment; filename="%s.xml"', $filenameBase),
            ]),
            default => new Response($this->generateTheContent($records), 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => sprintf('attachment; filename="%s.the"', $filenameBase),
            ]),
        };
    }
}
