<?php

namespace App\Service\Thesaurus;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Entity\AuthorExternalIdentifier;
use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Entity\Institution;
use App\Entity\InstitutionVariation;
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
    /**
     * @param EntityManagerInterface $em Gerenciador de entidades do Doctrine
     */
    public function __construct(
        private readonly EntityManagerInterface $em
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
     * Mescla múltiplos periódicos Qualis em um periódico Master e preserva todas as variantes textuais.
     *
     * @param int $masterId ID do periódico Qualis Master
     * @param array<int> $sourceIds IDs dos periódicos que serão mesclados
     * @param array<string, mixed> $selectedFields Campos para atualizar no periódico Master
     * @return \App\Entity\QualisJournal
     * @throws \InvalidArgumentException Se o periódico Master não for encontrado
     */
    public function mergeJournals(int $masterId, array $sourceIds, array $selectedFields = []): \App\Entity\QualisJournal
    {
        $allIds = array_values(array_unique(array_merge([$masterId], $sourceIds)));
        $journals = $this->em->getRepository(\App\Entity\QualisJournal::class)->findBy(['id' => $allIds]);

        $journalMap = [];
        foreach ($journals as $j) {
            $journalMap[$j->getId()] = $j;
        }

        if (!isset($journalMap[$masterId])) {
            throw new \InvalidArgumentException("Master journal #{$masterId} not found.");
        }

        $master = $journalMap[$masterId];
        $sources = array_filter($journalMap, fn($j) => $j->getId() !== $masterId);

        $allVariationStrings = [];
        foreach ($journalMap as $j) {
            if ($j->getTitle()) $allVariationStrings[] = $j->getTitle();
            foreach ($j->getVariations() as $var) {
                if ($var->getVariationName()) $allVariationStrings[] = $var->getVariationName();
            }
        }

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

        $existingVariations = [];
        foreach ($master->getVariations() as $var) {
            $existingVariations[$var->getNormalizedName()] = true;
        }

        foreach ($allVariationStrings as $rawVar) {
            $rawVar = trim($rawVar);
            if ($rawVar === '' || $rawVar === $master->getTitle()) continue;

            $norm = StringNormalizer::normalizeString($rawVar, true);
            if ($norm === '' || isset($existingVariations[$norm])) continue;

            $varObj = new \App\Entity\JournalVariation();
            $varObj->setJournal($master);
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
}

