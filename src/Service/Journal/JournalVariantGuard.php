<?php

declare(strict_types=1);

namespace App\Service\Journal;

use App\Entity\JournalVariation;
use App\Entity\QualisJournal;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Trava de integridade do tesauro de periódicos (docs/evolutions/periodicos-por-base.md, A5.3).
 *
 * Um nome (forma do tesauro) só pode pertencer a uma família: `journal_name_variants.normalized_name` é único e o
 * mesmo nome não pode ser variante de uma família e título de outra, senão o resolver (A5.2, etapas 2 a 4) nunca o
 * resolveria sozinho. Todo criador de variantes (formulário admin, merge, importador, aprendizado de grafias, fila de
 * revisão) deve passar por aqui: `findConflict()` para avisar antes, `addVariant()` para gravar.
 *
 * Família = COALESCE(canonical_journal_id, id). Dentro da mesma família o nome repetido não é conflito.
 *
 * Limite: o título é procurado em `qualis_journals.normalized_title`, que precisa estar preenchido
 * (`app:journals:normalize-titles`; as entidades e os importadores o mantêm em dia).
 */
class JournalVariantGuard
{
    public const KIND_VARIANT = 'variant';
    public const KIND_TITLE = 'title';

    /** Tamanho das colunas `variation_name` e `normalized_name`. */
    private const MAX_NAME_LENGTH = 500;

    /** Tamanho de `variation_type`. */
    private const MAX_TYPE_LENGTH = 50;

    /** Proteção contra cadeias de canônicas (a canônica de uma canônica) e ciclos. */
    private const MAX_CHAIN_DEPTH = 10;

    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    /**
     * Procura o nome em outra família: como variante (`journal_name_variants`, qualquer status, porque o índice único
     * vale para todas) ou como título de uma linha (`qualis_journals.normalized_title`). Variantes persistidas nesta
     * unidade de trabalho e ainda sem `flush` também contam.
     *
     * @param string $normalizedName nome na forma do tesauro (a função é idempotente: uma grafia bruta também funciona)
     * @param int|null $journalId periódico a que o nome se destina (qualquer linha da família serve); null quando o
     *        periódico ainda não existe, e então qualquer ocorrência é conflito
     *
     * @return array{kind: string, journalId: int, title: string}|null `kind` = `variant` | `title`; `journalId` e
     *         `title` são da linha onde o nome já existe; null quando não há conflito
     */
    public function findConflict(string $normalizedName, ?int $journalId): ?array
    {
        $key = self::key($normalizedName);
        if ($key === '') {
            return null;
        }

        return $this->conflictIn($this->findMatches($key), $journalId !== null ? $this->familyIdOf($journalId) : null);
    }

    /**
     * Grava uma variante do periódico na linha canônica da família. O chamador faz o `flush`.
     *
     * @param string $rawName grafia como veio (Lattes, formulário...); a forma do tesauro é calculada aqui
     * @param string $type `variation_type`: alternative | lattes | curated | manual | ...
     *
     * @return JournalVariation|null a variante criada; null quando o nome não tem letras ou números, quando é código e não
     *         nome (HTML, CSS, pilha de erro) ou quando a família já o conhece (como variante, mesmo desativada, ou como
     *         título de uma de suas linhas)
     *
     * @throws JournalVariantConflictException quando o nome já pertence a outra família
     */
    public function addVariant(QualisJournal $journal, string $rawName, string $type): ?JournalVariation
    {
        $rawName = trim($rawName);
        $key = self::key($rawName);
        if ($key === '' || !self::looksLikeAName($rawName)) {
            return null;
        }

        $canonical = self::canonicalOf($journal);
        $familyId = $canonical->getId();
        $matches = $this->findMatches($key);

        $conflict = $this->conflictIn($matches, $familyId);
        if ($conflict !== null) {
            throw new JournalVariantConflictException($conflict, $rawName);
        }
        if ($matches !== []) {
            return null;
        }

        $variation = new JournalVariation();
        $variation->setVariationName(mb_substr($rawName, 0, self::MAX_NAME_LENGTH, 'UTF-8'));
        $variation->setNormalizedName($key);
        $variation->setVariationType(self::cleanType($type));
        $variation->setStatus(true);

        $canonical->addVariation($variation);
        $this->em->persist($variation);

        return $variation;
    }

    /**
     * Recusa texto que é claramente código: uma página de erro já entrou no tesauro linha a linha
     * (docs/evolutions/periodicos-como-descobrimos.md, 3.11). Conferido contra os títulos e variantes do cadastro: os nomes
     * reais com sinais parecidos ("#PerDebate", "Stimmen der Zeit <Freiburg, Breisgau>") passam.
     */
    private static function looksLikeAName(string $rawName): bool
    {
        return preg_match('~</?[a-z][a-z0-9]*(\s[^>]*)?>|<!|[{}]|^--[a-z-]+\s*:|:\s*[^;]*;\s*$|^\.[a-z][\w-]*[\s,.:]|^#\d+\s+/|/vendor/|^/\*|^HTTP \d{3}\b~', $rawName) !== 1;
    }

    /**
     * Forma do tesauro limitada ao tamanho da coluna.
     */
    private static function key(string $name): string
    {
        return mb_substr(StringNormalizer::normalizeString($name, true), 0, self::MAX_NAME_LENGTH, 'UTF-8');
    }

    private static function cleanType(string $type): string
    {
        $type = trim($type);

        return $type === '' ? 'alternative' : mb_substr($type, 0, self::MAX_TYPE_LENGTH, 'UTF-8');
    }

    /**
     * Linha canônica da família de $journal (a primeira da cadeia `canonical` sem canônica).
     */
    private static function canonicalOf(QualisJournal $journal): QualisJournal
    {
        $current = $journal;
        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH; $depth++) {
            $next = $current->getCanonical();
            if ($next === null || $next === $current) {
                break;
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * Id da família da linha $journalId. Linha que não existe (mais) vale por si mesma.
     */
    private function familyIdOf(int $journalId): int
    {
        $family = $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(canonical_journal_id, id) FROM qualis_journals WHERE id = ?',
            [$journalId]
        );

        return $family !== false && $family !== null ? (int)$family : $journalId;
    }

    /**
     * Primeira ocorrência fora da família $familyId (null = família desconhecida: toda ocorrência conta).
     *
     * @param list<array{kind: string, journalId: int, title: string, family: int}> $matches
     *
     * @return array{kind: string, journalId: int, title: string}|null
     */
    private function conflictIn(array $matches, ?int $familyId): ?array
    {
        foreach ($matches as $match) {
            if ($familyId === null || $match['family'] !== $familyId) {
                return ['kind' => $match['kind'], 'journalId' => $match['journalId'], 'title' => $match['title']];
            }
        }

        return null;
    }

    /**
     * Todas as ocorrências do nome: variantes, depois títulos, depois variantes ainda não gravadas.
     *
     * @return list<array{kind: string, journalId: int, title: string, family: int}>
     */
    private function findMatches(string $key): array
    {
        $conn = $this->em->getConnection();
        $matches = [];

        $variants = $conn->fetchAllAssociative(
            'SELECT v.journal_id AS journal_id, q.title AS title, COALESCE(q.canonical_journal_id, q.id) AS fam
               FROM journal_name_variants v
               JOIN qualis_journals q ON q.id = v.journal_id
              WHERE v.normalized_name = ?
              ORDER BY v.id',
            [$key]
        );
        foreach ($variants as $row) {
            $matches[] = [
                'kind' => self::KIND_VARIANT,
                'journalId' => (int)$row['journal_id'],
                'title' => (string)$row['title'],
                'family' => (int)$row['fam'],
            ];
        }

        $titles = $conn->fetchAllAssociative(
            'SELECT id, title, COALESCE(canonical_journal_id, id) AS fam
               FROM qualis_journals
              WHERE normalized_title = ?
              ORDER BY id',
            [$key]
        );
        foreach ($titles as $row) {
            $matches[] = [
                'kind' => self::KIND_TITLE,
                'journalId' => (int)$row['id'],
                'title' => (string)$row['title'],
                'family' => (int)$row['fam'],
            ];
        }

        // Variantes persistidas na unidade de trabalho e ainda sem flush: o banco ainda não as vê
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof JournalVariation || $entity->getNormalizedName() !== $key) {
                continue;
            }
            $journal = $entity->getJournal();
            $matches[] = [
                'kind' => self::KIND_VARIANT,
                'journalId' => (int)$journal?->getId(),
                'title' => $journal?->getTitle() ?? '',
                'family' => $journal !== null ? (int)self::canonicalOf($journal)->getId() : 0,
            ];
        }

        return $matches;
    }
}
