<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\AuthorExternalIdentifier;
use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use App\Entity\ProductionItem;
use App\Entity\QualisJournal;
use App\Service\Journal\JournalFamilyBuilder;
use App\Service\Journal\JournalVariantConflictException;
use App\Service\Journal\JournalVariantGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço de fusão e unificação de entidades duplicadas nos diferentes Tesauros.
 *
 * Suporta a mesclagem de:
 * 1. Instituições (Institution / InstitutionVariation)
 * 2. Países (Country / CountryVariation)
 * 3. Identidades de Autores (AuthorIdentity / AuthorNameVariant / AuthorExternalIdentifier)
 * 4. Periódicos Qualis (QualisJournal / JournalVariation)
 *
 * Ao realizar uma mesclagem, as fontes secundárias são excluídas e todos os seus nomes e variações
 * são convertidos em variantes vinculadas à entidade Master eleita.
 */
class EntityMergeService
{
    /** @var array{family: int, rows: int, articles: int, baseLinks: int}|null o que a última mesclagem de periódicos recalculou */
    private ?array $lastMergeRefresh = null;

    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param JournalVariantGuard $variantGuard Trava de integridade das variantes de periódicos (A5.3)
     * @param JournalResolverService $journalResolver Bases de indexação de cada linha do cadastro (refreshFamilyArticles)
     * @param JournalFamilyBuilder $familyBuilder Replicação dos vínculos com as bases na família (refreshFamilyArticles)
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalVariantGuard $variantGuard,
        private readonly JournalResolverService $journalResolver,
        private readonly JournalFamilyBuilder $familyBuilder
    ) {}

    /**
     * Mescla múltiplas instituições secundárias em uma Instituição Master canônica.
     *
     * @param int $masterId ID da instituição principal
     * @param array<int> $sourceIds IDs das instituições que serão fundidas e removidas
     * @param array<string, mixed> $selectedFields Campos opcionais para atualizar a instituição Master
     * @return Institution Entidade Master atualizada
     * @throws \InvalidArgumentException Se a instituição Master não for encontrada
     */
    public function mergeInstitutions(int $masterId, array $sourceIds, array $selectedFields = []): Institution
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $institutions = $this->em->getRepository(Institution::class)->findBy(['id' => $allIds]);
        
        $institutionMap = [];
        foreach ($institutions as $inst) {
            $institutionMap[$inst->getId()] = $inst;
        }

        if (!isset($institutionMap[$masterId])) {
            throw new \InvalidArgumentException("Master institution #{$masterId} not found.");
        }

        $master = $institutionMap[$masterId];
        $sources = array_filter($institutionMap, fn($inst) => $inst->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($institutionMap as $inst) {
            if ($inst->getOfficialName()) $allVariationStrings[] = $inst->getOfficialName();
            if ($inst->getShortName()) $allVariationStrings[] = $inst->getShortName();
            if ($inst->getAcronym()) $allVariationStrings[] = $inst->getAcronym();
            if ($inst->getCorporateName()) $allVariationStrings[] = $inst->getCorporateName();

            foreach ($inst->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        // Apply selected fields
        if (!empty($selectedFields['officialName'])) $master->setOfficialName($selectedFields['officialName']);
        if (isset($selectedFields['shortName'])) $master->setShortName($selectedFields['shortName'] ?: null);
        if (isset($selectedFields['acronym'])) $master->setAcronym($selectedFields['acronym'] ?: null);
        if (isset($selectedFields['sigla'])) $master->setAcronym($selectedFields['sigla'] ?: null);
        if (isset($selectedFields['institutionType'])) $master->setInstitutionType($selectedFields['institutionType'] ?: null);
        if (isset($selectedFields['legalNature'])) $master->setLegalNature($selectedFields['legalNature'] ?: null);
        if (isset($selectedFields['taxId'])) $master->setTaxId($selectedFields['taxId'] ?: null);
        if (isset($selectedFields['corporateName'])) $master->setCorporateName($selectedFields['corporateName'] ?: null);
        if (isset($selectedFields['officialWebsite'])) $master->setOfficialWebsite($selectedFields['officialWebsite'] ?: null);
        if (isset($selectedFields['institutionalEmail'])) $master->setInstitutionalEmail($selectedFields['institutionalEmail'] ?: null);
        if (isset($selectedFields['phone'])) $master->setPhone($selectedFields['phone'] ?: null);
        if (isset($selectedFields['headquartersAddress'])) $master->setHeadquartersAddress($selectedFields['headquartersAddress'] ?: null);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '' || $rawVar === $master->getOfficialName()) continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new InstitutionVariation();
            $varObj->setInstitution($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
        return $master;
    }

    /**
     * Mescla múltiplos países em um País Master canônico e preserva todas as variações históricas.
     *
     * @param int $masterId ID do país principal
     * @param array<int> $sourceIds IDs dos países secundários a serem mesclados
     * @param array<string, mixed> $selectedFields Campos para atualizar no país Master
     * @return Country
     * @throws \InvalidArgumentException Se o país Master não for encontrado
     */
    public function mergeCountries(int $masterId, array $sourceIds, array $selectedFields = []): Country
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $countries = $this->em->getRepository(Country::class)->findBy(['id' => $allIds]);
        
        $countryMap = [];
        foreach ($countries as $c) {
            $countryMap[$c->getId()] = $c;
        }

        if (!isset($countryMap[$masterId])) {
            throw new \InvalidArgumentException("Master country #{$masterId} not found.");
        }

        $master = $countryMap[$masterId];
        $sources = array_filter($countryMap, fn($c) => $c->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($countryMap as $c) {
            if ($c->getCommonName()) $allVariationStrings[] = $c->getCommonName();
            if ($c->getOfficialName()) $allVariationStrings[] = $c->getOfficialName();
            foreach ($c->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (!empty($selectedFields['commonName'])) $master->setCommonName($selectedFields['commonName']);
        if (isset($selectedFields['officialName'])) $master->setOfficialName($selectedFields['officialName'] ?: null);
        if (isset($selectedFields['isoAlpha2'])) $master->setIsoAlpha2($selectedFields['isoAlpha2'] ?: null);
        if (isset($selectedFields['isoAlpha3'])) $master->setIsoAlpha3($selectedFields['isoAlpha3'] ?: null);

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '' || $rawVar === $master->getCommonName()) continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new CountryVariation();
            $varObj->setCountry($master);
            $varObj->setVariationName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setVariationType('alternative');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
        return $master;
    }

    /**
     * Mescla múltiplas identidades de autor em uma identidade Master, consolidando
     * variantes de citação e identificadores externos (ORCID, Scopus, etc.).
     *
     * @param int $masterId ID da identidade Master
     * @param array<int> $sourceIds IDs das identidades que serão fundidas
     * @param array<string, mixed> $selectedFields Campos para atualizar na identidade Master
     * @return AuthorIdentity
     * @throws \InvalidArgumentException Se a identidade Master não for encontrada
     */
    public function mergeAuthors(int $masterId, array $sourceIds, array $selectedFields = []): AuthorIdentity
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $authors = $this->em->getRepository(AuthorIdentity::class)->findBy(['id' => $allIds]);
        
        $authorMap = [];
        foreach ($authors as $auth) {
            $authorMap[$auth->getId()] = $auth;
        }

        if (!isset($authorMap[$masterId])) {
            throw new \InvalidArgumentException("Master author #{$masterId} not found.");
        }

        $master = $authorMap[$masterId];
        $sources = array_filter($authorMap, fn($auth) => $auth->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($authorMap as $auth) {
            if ($auth->getPreferredName()) $allVariationStrings[] = $auth->getPreferredName();
            foreach ($auth->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

        if (!empty($selectedFields['preferredName'])) {
            $master->setPreferredName($selectedFields['preferredName']);
            $master->setNormalizedName(StringNormalizer::normalizeString($selectedFields['preferredName'], true));
        }

        // Consolidate external identifiers
        foreach ($sources as $source) {
            foreach ($source->getIdentifiers() as $ident) {
                $hasIdent = false;
                foreach ($master->getIdentifiers() as $mIdent) {
                    if ($mIdent->getProvider() === $ident->getProvider() && $mIdent->getIdentifier() === $ident->getIdentifier()) {
                        $hasIdent = true;
                        break;
                    }
                }
                if (!$hasIdent) {
                    $newIdent = new AuthorExternalIdentifier();
                    $newIdent->setAuthorIdentity($master);
                    $newIdent->setProvider($ident->getProvider());
                    $newIdent->setIdentifier($ident->getIdentifier());
                    $newIdent->setUrl($ident->getUrl());
                    $this->em->persist($newIdent);
                }
            }
        }

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '' || $rawVar === $master->getPreferredName()) continue;
            
            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new AuthorNameVariant();
            $varObj->setAuthorIdentity($master);
            $varObj->setOriginalName($rawVar);
            $varObj->setDisplayName($rawVar);
            $varObj->setNormalizedName($norm);
            $varObj->setSource('merge');
            $varObj->setStatus(true);

            $this->em->persist($varObj);
            $existingVariations[$norm] = true;
        }

        foreach ($sources as $source) {
            $this->em->remove($source);
        }

        $this->em->flush();
        return $master;
    }

    /**
     * Mescla fisicamente periódicos Qualis em um periódico Master, sem perder artigos, famílias, variantes nem evidências.
     *
     * Tudo acontece numa única transação e as entidades só são gravadas num `flush` final. Antes de remover as fontes,
     * as correções de docs/evolutions/periodicos-por-base.md (A5.3), que existem porque apagar a linha de um periódico
     * dispara `ON DELETE SET NULL` nos artigos e nas famílias e `ON DELETE CASCADE` nas evidências, nas áreas, nas
     * variantes e nas bases:
     *  1. os artigos das fontes (`production_items.qualis_journal_id`) passam para o master;
     *  2. as linhas membro das famílias das fontes passam para a família do master (`canonical_journal_id` = canônica
     *     do master, ou o próprio master quando ele era membro de uma fonte), marcadas `canonical_method = 'manual'`
     *     para a reconstrução de famílias respeitar a decisão;
     *  3. as evidências de área das fontes passam para o master, sem duplicar o que ele já tem;
     *  4. as linhas de `journal_areas` das fontes são apagadas (valem as do master);
     *  5. as variantes das fontes são reaproveitadas (passam a ser do master), e só se cria variante nova, pelo
     *     {@see JournalVariantGuard}, para o título de uma fonte que ainda não existe no tesauro.
     * Além disso, as bases de indexação das fontes são somadas às do master e os candidatos da fila de nomes que
     * apontavam para uma fonte passam a apontar para o master (senão sumiriam com a linha).
     *
     * Ainda dentro da transação, depois do `flush`, os dados derivados do cadastro que os artigos guardam são refeitos
     * ({@see self::refreshFamilyArticles()}): as bases passam a valer para a família toda e cada artigo ligado a ela
     * recebe de novo `qualis` e `indexed_databases`, então um B1 mesclado num A2 deixa os artigos com A2. Os números ficam
     * em {@see self::getLastMergeRefresh()}. Se isso falhar, a mesclagem inteira é desfeita.
     *
     * O que a mesclagem não faz: reclassificar a área da família resultante nem limpar o cache de páginas públicas. Quem a
     * chama depois do commit usa o classificador de áreas (`JournalAreaClassifier::classifyFamily()`) e o
     * `PageCacheService`, como o admin faz.
     *
     * Atenção: as correções são por SQL e o EntityManager é limpo (`clear`) antes de recarregar o master e as fontes;
     * entidades que o chamador tinha carregadas ficam destacadas.
     *
     * @param int $masterId ID do periódico Qualis Master
     * @param array<int> $sourceIds IDs dos periódicos que serão mesclados (os que não existem são ignorados)
     * @param array<string, mixed> $selectedFields Campos para atualizar no periódico Master
     * @return \App\Entity\QualisJournal
     * @throws \InvalidArgumentException Se o periódico Master não for encontrado
     */
    public function mergeJournals(int $masterId, array $sourceIds, array $selectedFields = []): \App\Entity\QualisJournal
    {
        $conn = $this->em->getConnection();

        $masterRow = $conn->fetchAssociative('SELECT id, title, canonical_journal_id FROM qualis_journals WHERE id = ?', [$masterId]);
        if ($masterRow === false) {
            throw new \InvalidArgumentException("Master journal #{$masterId} not found.");
        }

        $requested = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            static fn (int $id): bool => $id > 0 && $id !== $masterId
        )));
        $sourceRows = $requested === []
            ? []
            : $conn->fetchAllAssociative('SELECT id, title FROM qualis_journals WHERE id IN (' . self::idList($requested) . ') ORDER BY id');

        $sourceIds = array_map(static fn (array $row): int => (int) $row['id'], $sourceRows);
        // Os nomes que podem virar variante do master: os títulos das fontes e, se a mesclagem troca o título do master, o antigo
        $titles = array_map(static fn (array $row): string => (string) $row['title'], $sourceRows);
        $newTitle = trim((string) ($selectedFields['title'] ?? ''));
        $titleChanges = $newTitle !== '' && $newTitle !== (string) $masterRow['title'];
        if ($titleChanges) {
            $titles[] = (string) $masterRow['title'];
        }

        $conn->beginTransaction();
        try {
            if ($sourceIds !== []) {
                $this->moveJournalDependents(
                    $conn,
                    $masterId,
                    $masterRow['canonical_journal_id'] !== null ? (int) $masterRow['canonical_journal_id'] : null,
                    $sourceIds
                );
            }

            // Os títulos que vão sumir (fontes e, se for o caso, o título antigo do master) não podem contar como
            // "título de outra linha" para a trava: ela só deve barrar nomes que continuarão existindo
            $vanishing = $titleChanges ? array_merge($sourceIds, [$masterId]) : $sourceIds;
            if ($vanishing !== []) {
                $conn->executeStatement('UPDATE qualis_journals SET normalized_title = NULL WHERE id IN (' . self::idList($vanishing) . ')');
            }

            // O SQL acima deixou desatualizadas as entidades carregadas: recarrega o master e as fontes do banco
            $this->em->clear();
            $master = $this->em->find(QualisJournal::class, $masterId);
            if ($master === null) {
                throw new \InvalidArgumentException("Master journal #{$masterId} not found.");
            }
            $sources = $sourceIds === [] ? [] : $this->em->getRepository(QualisJournal::class)->findBy(['id' => $sourceIds]);

            if (!empty($selectedFields['title'])) {
                $master->setTitle($selectedFields['title']);
            }
            if (isset($selectedFields['issn'])) {
                $master->setIssn($selectedFields['issn'] ?: null);
            }
            if (isset($selectedFields['qualis'])) {
                $master->setQualis($selectedFields['qualis'] ?: null);
            }
            if (isset($selectedFields['area'])) {
                $master->setArea($selectedFields['area'] ?: null);
            }

            foreach ($titles as $rawTitle) {
                $rawTitle = trim($rawTitle);
                if ($rawTitle === '' || StringNormalizer::normalizeString($rawTitle, true) === $master->getNormalizedTitle()) {
                    continue;
                }

                try {
                    $this->variantGuard->addVariant($master, $rawTitle, 'alternative');
                } catch (JournalVariantConflictException) {
                    // O nome já é título ou variante de outro periódico: continua com o dono dele, não vira variante aqui
                }
            }

            foreach ($sources as $source) {
                $this->em->remove($source);
            }

            $this->em->flush();

            // As fontes já saíram e a família do master está final: refaz o que os artigos guardam do cadastro
            $this->lastMergeRefresh = $this->refreshFamilyArticles($masterId);

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->lastMergeRefresh = null;

            throw $e;
        }

        return $master;
    }

    /**
     * O que a última chamada de mergeJournals() recalculou nos artigos e nas bases da família (ver
     * refreshFamilyArticles()); null se nenhuma mesclagem terminou ainda ou se a última falhou.
     *
     * @return array{family: int, rows: int, articles: int, baseLinks: int}|null
     */
    public function getLastMergeRefresh(): ?array
    {
        return $this->lastMergeRefresh;
    }

    /**
     * Refaz os dados dos artigos que dependem do cadastro de periódicos (mergeJournals() chama isto, e quem alterou o
     * cadastro por fora pode chamar de novo: é idempotente):
     *  1. os vínculos com as bases passam a valer para a família toda (união dos vínculos de todas as linhas, como em
     *     `app:journals:families`), porque a mesclagem só somou os das fontes ao master e os indicadores contam por linha;
     *  2. cada artigo ligado a uma linha da família recebe de novo `production_items.qualis` e `indexed_databases`, como
     *     a indexação (`app:index:journals`) grava: o Qualis da linha ou, se ela não tem, o da família (a canônica; sem
     *     Qualis nela, a de menor id que tem), e as bases da linha, em JSON, ou NULL sem bases. Os artigos de uma
     *     fonte já foram movidos para o master pela mesclagem, então um B1 mesclado num A2 passa a mostrar A2.
     * Não mexe em `qualis_journal_id` nem relê o nome do periódico dos artigos, que continuam como o Lattes trouxe. Tudo
     * numa transação.
     *
     * @param int $journalId qualquer linha da família resultante (normalmente o master devolvido por mergeJournals())
     *
     * @return array{family: int, rows: int, articles: int, baseLinks: int} família, linhas dela, artigos que mudaram e
     *         vínculos com bases criados; tudo zero se o periódico não existe
     */
    public function refreshFamilyArticles(int $journalId): array
    {
        $conn = $this->em->getConnection();

        $familyId = $conn->fetchOne('SELECT COALESCE(canonical_journal_id, id) FROM qualis_journals WHERE id = ?', [$journalId]);
        if ($familyId === false || $familyId === null) {
            return ['family' => 0, 'rows' => 0, 'articles' => 0, 'baseLinks' => 0];
        }
        $familyId = (int) $familyId;

        $rows = $conn->fetchAllAssociative(
            'SELECT id, qualis FROM qualis_journals WHERE id = ? OR canonical_journal_id = ? ORDER BY id',
            [$familyId, $familyId]
        );

        // Mesmo critério do resolver (withFamilyQualis): a linha sem Qualis usa o da canônica e, sem ele, o da primeira
        // linha da família que tem
        $qualisOf = [];
        $familyQualis = null;
        foreach ($rows as $row) {
            $qualis = strtoupper(trim((string) $row['qualis']));
            $qualisOf[(int) $row['id']] = $qualis !== '' ? $qualis : null;
            if ($qualis !== '' && ($familyQualis === null || (int) $row['id'] === $familyId)) {
                $familyQualis = $qualis;
            }
        }

        // O resolver guarda o que já leu: o que ele memorizou antes da mesclagem não vale mais
        $this->journalResolver->clearCache();

        $baseLinks = 0;
        $articles = 0;
        $conn->beginTransaction();
        try {
            $baseLinks = $this->familyBuilder->propagateBaseLinks($familyId);

            foreach ($qualisOf as $rowId => $qualis) {
                $databases = $this->journalResolver->resolveDatabasesForJournalId($rowId);
                $articles += (int) $conn->executeStatement(
                    'UPDATE production_items SET qualis = ?, indexed_databases = ? WHERE qualis_journal_id = ? AND item_type = ?',
                    [
                        $qualis ?? $familyQualis,
                        $databases !== [] ? json_encode($databases, JSON_UNESCAPED_UNICODE) : null,
                        $rowId,
                        ProductionItem::TYPE_ARTIGO,
                    ]
                );
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        // Para o que a próxima leitura (nesta requisição ou no processo) não aproveite os vínculos de antes
        $this->journalResolver->clearCache();

        return ['family' => $familyId, 'rows' => count($rows), 'articles' => $articles, 'baseLinks' => $baseLinks];
    }

    /**
     * Passa para o master o que aponta para as fontes e se perderia com o `DELETE` delas (ver mergeJournals()).
     * Roda dentro da transação do chamador.
     *
     * @param array<int> $sourceIds ids (existentes e diferentes do master) das fontes
     */
    private function moveJournalDependents(\Doctrine\DBAL\Connection $conn, int $masterId, ?int $masterCanonicalId, array $sourceIds): void
    {
        $in = self::idList($sourceIds);

        // 1. Artigos
        $conn->executeStatement("UPDATE production_items SET qualis_journal_id = ? WHERE qualis_journal_id IN ($in)", [$masterId]);

        // 2. Famílias. Se o master era membro da família de uma fonte, ele vira a canônica; senão as linhas das
        // famílias das fontes entram na família do master (a canônica dele, ou ele mesmo), nunca numa cadeia
        // membro -> membro. O master e as fontes ficam de fora: o primeiro apontaria para si, as outras serão removidas.
        if ($masterCanonicalId !== null && in_array($masterCanonicalId, $sourceIds, true)) {
            $conn->executeStatement('UPDATE qualis_journals SET canonical_journal_id = NULL WHERE id = ?', [$masterId]);
            $target = $masterId;
        } else {
            $target = $masterCanonicalId ?? $masterId;
        }
        $conn->executeStatement(
            "UPDATE qualis_journals SET canonical_journal_id = ?, canonical_method = 'manual'
              WHERE canonical_journal_id IN ($in) AND id <> ? AND id NOT IN ($in)",
            [$target, $masterId]
        );

        // 3. Evidências de área: descarta as que o master já tem e as repetidas entre as fontes (a de menor id fica) e
        // move o resto. Comparação binária: o que o scorer deduplica é o texto exato de (fonte, registro, rótulo).
        $conn->executeStatement(
            "DELETE e FROM journal_area_evidence e
               JOIN journal_area_evidence m ON m.journal_id = ? AND m.source = e.source
                AND m.record_key COLLATE utf8mb4_bin = e.record_key COLLATE utf8mb4_bin
                AND m.label COLLATE utf8mb4_bin = e.label COLLATE utf8mb4_bin
              WHERE e.journal_id IN ($in)",
            [$masterId]
        );
        $conn->executeStatement(
            "DELETE e FROM journal_area_evidence e
               JOIN journal_area_evidence d ON d.journal_id IN ($in) AND d.id < e.id AND d.source = e.source
                AND d.record_key COLLATE utf8mb4_bin = e.record_key COLLATE utf8mb4_bin
                AND d.label COLLATE utf8mb4_bin = e.label COLLATE utf8mb4_bin
              WHERE e.journal_id IN ($in)"
        );
        $conn->executeStatement("UPDATE journal_area_evidence SET journal_id = ? WHERE journal_id IN ($in)", [$masterId]);

        // 4. Áreas das fontes: valem as do master
        $conn->executeStatement("DELETE FROM journal_areas WHERE journal_id IN ($in)");

        // 5. Variantes: reaproveitadas. O nome é único no tesauro, então mover nunca duplica
        $conn->executeStatement("UPDATE journal_name_variants SET journal_id = ? WHERE journal_id IN ($in)", [$masterId]);

        // Bases de indexação (a tabela de junção apaga em cascata) e fila de nomes (ON DELETE SET NULL)
        $conn->executeStatement(
            "INSERT IGNORE INTO qualis_journal_academic_database (qualis_journal_id, academic_database_id)
             SELECT ?, academic_database_id FROM qualis_journal_academic_database WHERE qualis_journal_id IN ($in)",
            [$masterId]
        );
        $conn->executeStatement("UPDATE journal_name_candidates SET suggested_journal_id = ? WHERE suggested_journal_id IN ($in)", [$masterId]);
        $conn->executeStatement("UPDATE journal_name_candidates SET resolved_journal_id = ? WHERE resolved_journal_id IN ($in)", [$masterId]);
    }

    /**
     * Lista de ids inteiros para um `IN (...)`: o cast garante que nada além de números entra no SQL.
     *
     * @param array<int> $ids
     */
    private static function idList(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}

