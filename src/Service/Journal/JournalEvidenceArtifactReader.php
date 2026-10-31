<?php

declare(strict_types=1);

namespace App\Service\Journal;

/**
 * Leitor do artefato de evidências de área (`journal_evidence.jsonl.gz`, ver A6.1 do documento de evolução).
 *
 * O artefato é um gzip com uma linha JSON por registro de fonte. A primeira linha é `{"_meta": {...}}`
 * (`built_at` e a contagem de registros por fonte); as demais são os registros
 * (`src`, `rec`, `issns`, `title`, `labels`, `extra`). A leitura é em fluxo, linha a linha, então o arquivo
 * inteiro nunca fica em memória.
 */
final class JournalEvidenceArtifactReader
{
    /**
     * Devolve os registros do artefato, um por linha, na ordem em que foram gravados. A linha `_meta` é pulada.
     *
     * @return \Generator<int, array<string, mixed>> chave = número da linha no arquivo (a primeira linha é a 1)
     *
     * @throws \RuntimeException arquivo ausente, ilegível ou com linha que não é JSON
     */
    public function read(string $file): \Generator
    {
        $handle = $this->open($file);

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = $this->decode($line, $file, $lineNumber);
                if (array_key_exists('_meta', $record)) {
                    continue;
                }

                yield $lineNumber => $record;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Devolve o conteúdo de `_meta` (a primeira linha do artefato), sem o envoltório:
     * `['built_at' => '2026-10-30T20:15:00+00:00', 'sources' => ['scopus' => ['file' => '...', 'records' => 48888], ...]]`.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException arquivo ausente ou ilegível, ou primeira linha que não é `_meta`
     */
    public function meta(string $file): array
    {
        $handle = $this->open($file);

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                ++$lineNumber;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = $this->decode($line, $file, $lineNumber);
                if (!isset($record['_meta']) || !is_array($record['_meta'])) {
                    break;
                }

                return $record['_meta'];
            }
        } finally {
            fclose($handle);
        }

        throw new \RuntimeException(sprintf('O artefato %s não começa com a linha "_meta".', $file));
    }

    /**
     * @return resource
     */
    private function open(string $file)
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(sprintf('Artefato de evidências não encontrado ou ilegível: %s', $file));
        }

        // O wrapper compress.zlib:// aceita linhas de qualquer tamanho em fgets(), o que gzgets() não garante.
        $handle = @fopen('compress.zlib://' . $file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Não foi possível abrir o artefato (gzip inválido?): %s', $file));
        }

        return $handle;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $line, string $file, int $lineNumber): array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Linha %d de %s não é JSON válido: %s', $lineNumber, $file, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Linha %d de %s não é um objeto JSON.', $lineNumber, $file));
        }

        return $decoded;
    }
}
