<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorExternalIdentifier;
use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\Researcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serviço central de gerenciamento e sincronização do Tesauro de Autores.
 *
 * Responsável por:
 * 1. Manter a integridade das identidades canônicas de autores (author_identities).
 * 2. Mapear e indexar todas as variações de nomes e citações bibliográficas (author_name_variants).
 * 3. Armazenar identificadores externos (ORCID, Lattes ID) em author_external_identifiers.
 * 4. Reconstruir e limpar o tesauro garantindo a vinculação correta com os pesquisadores do CECH.
 */
class AuthorThesaurusService
{
    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     * @param Connection $conn Conexão DBAL direta para operações de alta performance em lote
     * @param AuthorResolverService|null $authorResolver Serviço de resolução de autores para invalidação de cache
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
        private readonly ?AuthorResolverService $authorResolver = null
    ) {}

    /**
     * Limpa completamente as tabelas do Tesauro de Autores
     * (author_name_variants, author_external_identifiers, author_identities)
     * e desvincula os relacionamentos existentes em production_authors.
     */
    public function truncateAuthorThesaurus(): void
    {
        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->conn->executeStatement('UPDATE production_authors SET author_identity_id = NULL');
        $this->conn->executeStatement('TRUNCATE TABLE author_external_identifiers');
        $this->conn->executeStatement('TRUNCATE TABLE author_name_variants');
        $this->conn->executeStatement('TRUNCATE TABLE author_identities');
        $this->conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        if ($this->authorResolver !== null) {
            $this->authorResolver->clearCache();
        }
    }

    /**
     * Cadastra ou atualiza o pesquisador e cada uma das suas formas/variações de nome
     * no Tesauro de Autores (author_identities, author_name_variants e author_external_identifiers).
     *
     * Regra de negócio:
     * - Verifica se o nome completo já existe como identidade normalizada.
     * - Se não existir, cria a identidade canônica (AuthorIdentity).
     * - Cadastra o nome completo e cada token de citação bibliográfica como variante (AuthorNameVariant).
     * - Registra o identificador ORCID em author_external_identifiers.
     *
     * @param Researcher $researcher Pesquisador a ser sincronizado
     * @return array{identityCreated: bool, variantsAdded: int, variantsChecked: int} Estatísticas da operação
     */
    public function syncResearcher(Researcher $researcher): array
    {
        $fullName = trim((string)$researcher->getFullName());
        if ($fullName === '') {
            return ['identityCreated' => false, 'variantsAdded' => 0, 'variantsChecked' => 0];
        }

        $fullName = mb_substr($fullName, 0, 250, 'UTF-8');
        $normName = StringNormalizer::normalizeString($fullName, true);
        if ($normName === '') {
            return ['identityCreated' => false, 'variantsAdded' => 0, 'variantsChecked' => 0];
        }
        $normName = mb_substr($normName, 0, 250, 'UTF-8');

        $identityRepo = $this->em->getRepository(AuthorIdentity::class);
        $identity = $identityRepo->findOneBy(['normalizedName' => $normName])
            ?: $identityRepo->findOneBy(['preferredName' => $fullName]);

        $identityCreated = false;
        if (!$identity) {
            $identity = new AuthorIdentity();
            $identity->setPreferredName($fullName);
            $identity->setNormalizedName($normName);
            $identity->setStatus(true);
            $this->em->persist($identity);
            $this->em->flush();
            $identityCreated = true;
        }

        // Mapear variações existentes para esta identidade
        $existingVariants = [];
        if ($identity->getId()) {
            $variantRows = $this->conn->fetchAllAssociative(
                'SELECT normalized_name, original_name FROM author_name_variants WHERE author_identity_id = ?',
                [$identity->getId()]
            );
            foreach ($variantRows as $row) {
                $existingVariants[$row['normalized_name']] = true;
                $existingVariants[StringNormalizer::normalizeString($row['original_name'], true)] = true;
            }
        }
        foreach ($identity->getVariations() as $v) {
            $existingVariants[$v->getNormalizedName()] = true;
            $existingVariants[StringNormalizer::normalizeString($v->getOriginalName(), true)] = true;
        }

        $variantsChecked = 0;
        $variantsAdded = 0;

        // 1. Verificar e cadastrar o nome completo como variação
        $variantsChecked++;
        if (!isset($existingVariants[$normName])) {
            $var = new AuthorNameVariant();
            $var->setAuthorIdentity($identity);
            $var->setOriginalName($fullName);
            $var->setDisplayName($fullName);
            $var->setNormalizedName($normName);
            $var->setSource('lattes');
            $var->setStatus(true);
            $identity->addVariation($var);
            $this->em->persist($var);
            $existingVariants[$normName] = true;
            $variantsAdded++;
        }

        // 2. Verificar e cadastrar cada uma das variações de citação presentes em citation_names
        $citationNames = (string)$researcher->getCitationNames();
        if ($citationNames !== '') {
            $tokens = array_filter(array_map('trim', explode(';', $citationNames)));
            foreach ($tokens as $token) {
                if ($token === '') continue;
                $token = mb_substr($token, 0, 250, 'UTF-8');
                $normToken = mb_substr(StringNormalizer::normalizeString($token, true), 0, 250, 'UTF-8');
                if ($normToken === '') continue;

                $variantsChecked++;
                if (!isset($existingVariants[$normToken])) {
                    $var = new AuthorNameVariant();
                    $var->setAuthorIdentity($identity);
                    $var->setOriginalName($token);
                    $var->setDisplayName($token);
                    $var->setNormalizedName($normToken);
                    $var->setSource('citation');
                    $var->setStatus(true);
                    $identity->addVariation($var);
                    $this->em->persist($var);
                    $existingVariants[$normToken] = true;
                    $variantsAdded++;
                }
            }
        }

        // 3. Verificar e cadastrar ORCID se presente
        $orcid = trim((string)$researcher->getOrcid());
        if ($orcid !== '') {
            $orcid = mb_substr(preg_replace('#^https?://orcid\.org/#i', '', $orcid), 0, 50, 'UTF-8');
            $hasOrcid = false;
            foreach ($identity->getIdentifiers() as $ident) {
                if ($ident->getProvider() === 'orcid') {
                    $hasOrcid = true;
                    break;
                }
            }
            if (!$hasOrcid && $identity->getId()) {
                $count = (int)$this->conn->fetchOne(
                    'SELECT COUNT(*) FROM author_external_identifiers WHERE author_identity_id = ? AND provider = ?',
                    [$identity->getId(), 'orcid']
                );
                if ($count > 0) {
                    $hasOrcid = true;
                }
            }
            if (!$hasOrcid) {
                $extIdent = new AuthorExternalIdentifier();
                $extIdent->setAuthorIdentity($identity);
                $extIdent->setProvider('orcid');
                $extIdent->setIdentifier($orcid);
                $identity->addIdentifier($extIdent);
                $this->em->persist($extIdent);
            }
        }

        $this->em->flush();

        if ($variantsAdded > 0 && $this->authorResolver !== null) {
            $this->authorResolver->clearCache();
        }

        return [
            'identityCreated' => $identityCreated,
            'variantsAdded' => $variantsAdded,
            'variantsChecked' => $variantsChecked,
        ];
    }

    /**
     * Sincroniza todos os pesquisadores existentes em lote.
     *
     * @return array{researchersProcessed: int, identitiesCreated: int, variantsAdded: int, variantsChecked: int}
     */
    public function syncAllResearchers(): array
    {
        $researchers = $this->em->getRepository(Researcher::class)->findBy([], ['id' => 'ASC']);
        $identitiesCreated = 0;
        $variantsAdded = 0;
        $variantsChecked = 0;

        foreach ($researchers as $r) {
            $res = $this->syncResearcher($r);
            if ($res['identityCreated']) {
                $identitiesCreated++;
            }
            $variantsAdded += $res['variantsAdded'];
            $variantsChecked += $res['variantsChecked'];
        }

        if ($this->authorResolver !== null) {
            $this->authorResolver->clearCache();
        }

        return [
            'researchersProcessed' => count($researchers),
            'identitiesCreated' => $identitiesCreated,
            'variantsAdded' => $variantsAdded,
            'variantsChecked' => $variantsChecked,
        ];
    }

    /**
     * Cleans and completely rebuilds the author thesaurus from existing CECH Researchers
     * and their Production Authors.
     *
     * @return array{researchersProcessed: int, identitiesCreated: int, variantsCreated: int, coauthorsProcessed: int}
     */
    public function rebuildAuthorThesaurus(): array
    {
        // 1. Wipe old polluted author tables
        $this->truncateAuthorThesaurus();

        $researcherRows = $this->conn->fetchAllAssociative('
            SELECT id, full_name, slug, id_lattes, orcid, citation_names 
            FROM researchers 
            ORDER BY id ASC
        ');

        $identityMap = []; // normalized_name => identity_id
        $variantsMap = []; // normalized_variant => identity_id
        $identitiesCount = 0;
        $variantsCount = 0;

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // 2. Register all CECH Researchers as Master AuthorIdentities
        foreach ($researcherRows as $r) {
            $fullName = trim((string)($r['full_name'] ?? ''));
            if ($fullName === '') continue;

            $fullName = mb_substr($fullName, 0, 250, 'UTF-8');
            $normName = StringNormalizer::normalizeString($fullName, true);
            if ($normName === '') continue;
            $normName = mb_substr($normName, 0, 250, 'UTF-8');

            // Check if identity already exists
            if (!isset($identityMap[$normName])) {
                $this->conn->insert('author_identities', [
                    'preferred_name' => $fullName,
                    'normalized_name' => $normName,
                    'status' => 1,
                    'review_reasons' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $identityId = (int)$this->conn->lastInsertId();
                $identityMap[$normName] = $identityId;
                $identitiesCount++;
            } else {
                $identityId = $identityMap[$normName];
            }

            // Register researcher's full name as a variant
            if (!isset($variantsMap[$normName])) {
                $this->conn->insert('author_name_variants', [
                    'author_identity_id' => $identityId,
                    'original_name' => $fullName,
                    'normalized_name' => $normName,
                    'display_name' => $fullName,
                    'source' => 'lattes',
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $variantsMap[$normName] = $identityId;
                $variantsCount++;
            }

            // Register Citation Names from Lattes
            $rawCitations = (string)($r['citation_names'] ?? '');
            if ($rawCitations !== '') {
                $citationTokens = array_filter(array_map('trim', explode(';', $rawCitations)));
                foreach ($citationTokens as $citToken) {
                    if ($citToken === '') continue;
                    $citToken = mb_substr($citToken, 0, 250, 'UTF-8');
                    $normCit = StringNormalizer::normalizeString($citToken, true);
                    if ($normCit === '') continue;
                    $normCit = mb_substr($normCit, 0, 250, 'UTF-8');

                    if (!isset($variantsMap[$normCit])) {
                        $this->conn->insert('author_name_variants', [
                            'author_identity_id' => $identityId,
                            'original_name' => $citToken,
                            'normalized_name' => $normCit,
                            'display_name' => $citToken,
                            'source' => 'citation',
                            'status' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $variantsMap[$normCit] = $identityId;
                        $variantsCount++;
                    }
                }
            }

            // Register ORCID if present
            $orcid = trim((string)($r['orcid'] ?? ''));
            if ($orcid !== '') {
                $this->conn->insert('author_external_identifiers', [
                    'author_identity_id' => $identityId,
                    'provider' => 'orcid',
                    'identifier' => mb_substr($orcid, 0, 50, 'UTF-8'),
                    'created_at' => $now,
                ]);
            }
        }

        // 3. Process Production Authors
        $prodAuthors = $this->conn->fetchAllAssociative('
            SELECT DISTINCT author_name, citation_name, id_lattes 
            FROM production_authors 
            WHERE (author_name IS NOT NULL AND author_name != "") OR (citation_name IS NOT NULL AND citation_name != "")
        ');

        $coauthorsCount = 0;
        foreach ($prodAuthors as $pa) {
            $authorName = trim((string)($pa['author_name'] ?? ''));
            $citationName = trim((string)($pa['citation_name'] ?? ''));
            $coauthorsCount++;

            $preferred = $authorName !== '' ? $authorName : $citationName;
            if ($preferred === '') continue;

            $preferred = mb_substr($preferred, 0, 250, 'UTF-8');
            $normPref = mb_substr(StringNormalizer::normalizeString($preferred, true), 0, 250, 'UTF-8');
            $normCit = $citationName !== '' ? mb_substr(StringNormalizer::normalizeString($citationName, true), 0, 250, 'UTF-8') : '';

            $invPref = AuthorResolverService::invertName($preferred);
            $normInvPref = $invPref ? mb_substr(StringNormalizer::normalizeString($invPref, true), 0, 250, 'UTF-8') : '';

            $invCit = $citationName !== '' ? AuthorResolverService::invertName($citationName) : null;
            $normInvCit = $invCit ? mb_substr(StringNormalizer::normalizeString($invCit, true), 0, 250, 'UTF-8') : '';

            // Check if matches an existing identity or variant
            $matchedIdentityId = null;
            if (isset($variantsMap[$normPref])) {
                $matchedIdentityId = $variantsMap[$normPref];
            } elseif ($normCit !== '' && isset($variantsMap[$normCit])) {
                $matchedIdentityId = $variantsMap[$normCit];
            } elseif ($normInvPref !== '' && isset($variantsMap[$normInvPref])) {
                $matchedIdentityId = $variantsMap[$normInvPref];
            } elseif ($normInvCit !== '' && isset($variantsMap[$normInvCit])) {
                $matchedIdentityId = $variantsMap[$normInvCit];
            } elseif (isset($identityMap[$normPref])) {
                $matchedIdentityId = $identityMap[$normPref];
            } elseif ($normInvPref !== '' && isset($identityMap[$normInvPref])) {
                $matchedIdentityId = $identityMap[$normInvPref];
            }

            if (!$matchedIdentityId) {
                // If preferred is in ABNT format (e.g. "GREGOLIN, José Angelo Rodrigues"), use the natural inverted order for preferred_name
                $canonicalPreferred = ($invPref && str_contains($preferred, ',')) ? $invPref : $preferred;
                $normCanonical = mb_substr(StringNormalizer::normalizeString($canonicalPreferred, true), 0, 250, 'UTF-8');

                // Create new identity for co-author
                $this->conn->insert('author_identities', [
                    'preferred_name' => $canonicalPreferred,
                    'normalized_name' => $normCanonical,
                    'status' => 1,
                    'review_reasons' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $matchedIdentityId = (int)$this->conn->lastInsertId();
                $identityMap[$normCanonical] = $matchedIdentityId;
                $identityMap[$normPref] = $matchedIdentityId;
                $identitiesCount++;

                // Add canonical and preferred names as variants
                $this->conn->insert('author_name_variants', [
                    'author_identity_id' => $matchedIdentityId,
                    'original_name' => $canonicalPreferred,
                    'normalized_name' => $normCanonical,
                    'display_name' => $canonicalPreferred,
                    'source' => 'production',
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $variantsMap[$normCanonical] = $matchedIdentityId;
                $variantsMap[$normPref] = $matchedIdentityId;
                if ($normInvPref !== '') {
                    $variantsMap[$normInvPref] = $matchedIdentityId;
                }
                $variantsCount++;
            }

            // If citation name exists and not yet registered as variant, add it
            if ($citationName !== '' && $normCit !== '' && !isset($variantsMap[$normCit])) {
                $citTrunc = mb_substr($citationName, 0, 250, 'UTF-8');
                $this->conn->insert('author_name_variants', [
                    'author_identity_id' => $matchedIdentityId,
                    'original_name' => $citTrunc,
                    'normalized_name' => $normCit,
                    'display_name' => $citTrunc,
                    'source' => 'citation',
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $variantsMap[$normCit] = $matchedIdentityId;
                if ($normInvCit !== '') {
                    $variantsMap[$normInvCit] = $matchedIdentityId;
                }
                $variantsCount++;
            }
        }

        if ($this->authorResolver !== null) {
            $this->authorResolver->clearCache();
        }

        return [
            'researchersProcessed' => count($researcherRows),
            'identitiesCreated' => $identitiesCount,
            'variantsCreated' => $variantsCount,
            'coauthorsProcessed' => $coauthorsCount,
        ];
    }

    /**
     * Alias de syncResearcher para compatibilidade.
     */
    public function syncResearcherCitationNames(Researcher $researcher): void
    {
        $this->syncResearcher($researcher);
    }
}

