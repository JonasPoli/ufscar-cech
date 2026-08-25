<?php

namespace App\Controller\admin;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Repository\CountryRepository;
use App\Service\Thesaurus\EntityMergeService;
use App\Service\Thesaurus\StringNormalizer;
use App\Service\Thesaurus\ThesaurusFileService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/countries')]
class AdminCountryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CountryRepository $countryRepo,
        private readonly ThesaurusFileService $fileService,
        private readonly EntityMergeService $mergeService
    ) {}

    #[Route('/', name: 'app_admin_country_index', methods: ['GET'])]
    public function index(): Response
    {
        $countries = $this->countryRepo->findBy([], ['commonName' => 'ASC']);

        return $this->render('admin/country/index.html.twig', [
            'countries' => $countries,
        ]);
    }

    #[Route('/new', name: 'app_admin_country_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $country = new Country();

        if ($request->isMethod('POST')) {
            $commonName = trim((string)$request->request->get('commonName'));
            $officialName = trim((string)$request->request->get('officialName')) ?: null;
            $isoAlpha2 = trim((string)$request->request->get('isoAlpha2')) ?: null;
            $isoAlpha3 = trim((string)$request->request->get('isoAlpha3')) ?: null;
            $foundationYear = $request->request->get('foundationYear') !== null && $request->request->get('foundationYear') !== '' ? (int)$request->request->get('foundationYear') : null;
            $extinctionYear = $request->request->get('extinctionYear') !== null && $request->request->get('extinctionYear') !== '' ? (int)$request->request->get('extinctionYear') : null;
            $status = (bool)$request->request->get('status', true);

            if ($commonName !== '') {
                $country->setCommonName($commonName);
                $country->setOfficialName($officialName);
                $country->setIsoAlpha2($isoAlpha2);
                $country->setIsoAlpha3($isoAlpha3);
                $country->setFoundationYear($foundationYear);
                $country->setExtinctionYear($extinctionYear);
                $country->setStatus($status);

                // Initial variations textarea
                $variationsText = trim((string)$request->request->get('variationsText', ''));
                if ($variationsText !== '') {
                    $lines = array_filter(array_map('trim', explode("\n", $variationsText)));
                    foreach ($lines as $line) {
                        $norm = StringNormalizer::normalizeString($line, true);
                        if ($norm === '') continue;
                        $var = new CountryVariation();
                        $var->setCountry($country);
                        $var->setVariationName($line);
                        $var->setNormalizedName($norm);
                        $country->addVariation($var);
                    }
                }

                $this->em->persist($country);
                $this->em->flush();

                $this->addFlash('success', "País \"{$commonName}\" cadastrado com sucesso.");
                return $this->redirectToRoute('app_admin_country_index');
            }
        }

        return $this->render('admin/country/form.html.twig', [
            'country' => $country,
            'isNew' => true,
            'variationsText' => '',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_country_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Country $country): Response
    {
        if ($request->isMethod('POST')) {
            $commonName = trim((string)$request->request->get('commonName'));
            $officialName = trim((string)$request->request->get('officialName')) ?: null;
            $isoAlpha2 = trim((string)$request->request->get('isoAlpha2')) ?: null;
            $isoAlpha3 = trim((string)$request->request->get('isoAlpha3')) ?: null;
            $foundationYear = $request->request->get('foundationYear') !== null && $request->request->get('foundationYear') !== '' ? (int)$request->request->get('foundationYear') : null;
            $extinctionYear = $request->request->get('extinctionYear') !== null && $request->request->get('extinctionYear') !== '' ? (int)$request->request->get('extinctionYear') : null;
            $status = (bool)$request->request->get('status', false);

            if ($commonName !== '') {
                $country->setCommonName($commonName);
                $country->setOfficialName($officialName);
                $country->setIsoAlpha2($isoAlpha2);
                $country->setIsoAlpha3($isoAlpha3);
                $country->setFoundationYear($foundationYear);
                $country->setExtinctionYear($extinctionYear);
                $country->setStatus($status);

                // Bulk variations textarea sync if submitted
                $variationsText = trim((string)$request->request->get('variationsText', ''));
                if ($request->request->has('variationsText')) {
                    $lines = array_filter(array_map('trim', explode("\n", $variationsText)));
                    $submitted = [];
                    foreach ($lines as $line) {
                        $norm = StringNormalizer::normalizeString($line, true);
                        if ($norm !== '') {
                            $submitted[$norm] = $line;
                        }
                    }

                    // Remove unsubmitted variations
                    foreach ($country->getVariations() as $existingVar) {
                        if (!isset($submitted[$existingVar->getNormalizedName()])) {
                            $country->removeVariation($existingVar);
                            $this->em->remove($existingVar);
                        } else {
                            unset($submitted[$existingVar->getNormalizedName()]);
                        }
                    }

                    // Add newly added variations
                    foreach ($submitted as $norm => $orig) {
                        $var = new CountryVariation();
                        $var->setCountry($country);
                        $var->setVariationName($orig);
                        $var->setNormalizedName($norm);
                        $country->addVariation($var);
                        $this->em->persist($var);
                    }
                }

                // Add single new variation shortcut if provided
                $newVariation = trim((string)$request->request->get('new_variation', ''));
                if ($newVariation !== '') {
                    $norm = StringNormalizer::normalizeString($newVariation, true);
                    $exists = false;
                    foreach ($country->getVariations() as $v) {
                        if ($v->getNormalizedName() === $norm) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $var = new CountryVariation();
                        $var->setCountry($country);
                        $var->setVariationName($newVariation);
                        $var->setNormalizedName($norm);
                        $this->em->persist($var);
                    }
                }

                $this->em->flush();

                $this->addFlash('success', "País atualizado com sucesso.");
                return $this->redirectToRoute('app_admin_country_index');
            }
        }

        $variationsLines = [];
        foreach ($country->getVariations() as $v) {
            $variationsLines[] = $v->getVariationName();
        }

        return $this->render('admin/country/form.html.twig', [
            'country' => $country,
            'isNew' => false,
            'variationsText' => implode("\n", $variationsLines),
        ]);
    }

    #[Route('/variation/{id}/delete', name: 'app_admin_country_variation_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteVariation(Request $request, CountryVariation $variation): Response
    {
        $countryId = $variation->getCountry()?->getId();
        if ($this->isCsrfTokenValid('delete_var_' . $variation->getId(), (string)$request->request->get('_token'))) {
            $name = $variation->getVariationName();
            $this->em->remove($variation);
            $this->em->flush();
            $this->addFlash('success', "Variação \"{$name}\" removida com sucesso.");
        } else {
            $this->addFlash('error', 'Token de segurança inválido.');
        }

        if ($countryId) {
            return $this->redirectToRoute('app_admin_country_edit', ['id' => $countryId]);
        }
        return $this->redirectToRoute('app_admin_country_index');
    }

    #[Route('/variation/{id}/separate', name: 'app_admin_country_variation_separate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function separateVariation(Request $request, CountryVariation $variation): Response
    {
        $countryId = $variation->getCountry()?->getId();
        if ($this->isCsrfTokenValid('separate_var_' . $variation->getId(), (string)$request->request->get('_token'))) {
            $name = $variation->getVariationName();
            
            $newCountry = new Country();
            $newCountry->setCommonName($name);
            $newCountry->setOfficialName($name);
            $this->em->persist($newCountry);

            // Add self variation
            $selfVar = new CountryVariation();
            $selfVar->setCountry($newCountry);
            $selfVar->setVariationName($name);
            $selfVar->setNormalizedName(StringNormalizer::normalizeString($name, true));
            $selfVar->setVariationType('common');
            $this->em->persist($selfVar);

            $this->em->remove($variation);
            $this->em->flush();

            $this->addFlash('success', "Variação \"{$name}\" desmembrada com sucesso como novo país independente.");
            return $this->redirectToRoute('app_admin_country_edit', ['id' => $newCountry->getId()]);
        }

        if ($countryId) {
            return $this->redirectToRoute('app_admin_country_edit', ['id' => $countryId]);
        }
        return $this->redirectToRoute('app_admin_country_index');
    }

    #[Route('/merge', name: 'app_admin_country_merge', methods: ['POST'])]
    public function merge(Request $request): Response
    {
        $masterId = (int)$request->request->get('master_id');
        $sourceIds = array_map('intval', (array)$request->request->get('source_ids', []));

        if ($masterId > 0 && !empty($sourceIds)) {
            try {
                $master = $this->mergeService->mergeCountries($masterId, $sourceIds);
                $this->addFlash('success', "Fusão de países realizada com sucesso em \"{$master->getCommonName()}\".");
            } catch (\Throwable $e) {
                $this->addFlash('error', "Erro na fusão: " . $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_country_index');
    }

    #[Route('/export/{format}', name: 'app_admin_country_export', methods: ['GET'])]
    public function export(string $format): Response
    {
        $countries = $this->countryRepo->findAll();
        $records = [];
        foreach ($countries as $c) {
            $vars = [];
            foreach ($c->getVariations() as $v) {
                $vars[] = $v->getVariationName();
            }
            $records[] = [
                'preferred_name' => $c->getCommonName(),
                'variants' => $vars,
            ];
        }

        return $this->fileService->exportThesaurusStream($records, $format, 'tesauro_paises');
    }

    #[Route('/import', name: 'app_admin_country_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('thesaurus_file');
        if ($file && $file->isValid()) {
            $records = $this->fileService->parseFile($file->getRealPath(), $file->getClientOriginalExtension());
            $count = 0;
            foreach ($records as $r) {
                $pref = trim((string)($r['preferred_name'] ?? ''));
                if ($pref === '') continue;

                $country = $this->countryRepo->findOneBy(['commonName' => $pref]);
                if (!$country) {
                    $country = new Country();
                    $country->setCommonName($pref);
                    $this->em->persist($country);
                }

                foreach ($r['variants'] ?? [] as $varName) {
                    $norm = StringNormalizer::normalizeString($varName, true);
                    $varObj = new CountryVariation();
                    $varObj->setCountry($country);
                    $varObj->setVariationName($varName);
                    $varObj->setNormalizedName($norm);
                    $this->em->persist($varObj);
                }
                $count++;
            }
            $this->em->flush();
            $this->addFlash('success', "{$count} termos de países importados com sucesso.");
        }

        return $this->redirectToRoute('app_admin_country_index');
    }

    #[Route('/{id}/delete', name: 'app_admin_country_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Country $country): Response
    {
        if ($this->isCsrfTokenValid('delete' . $country->getId(), (string)$request->request->get('_token'))) {
            $name = $country->getCommonName();
            $this->em->remove($country);
            $this->em->flush();
            $this->addFlash('success', "País \"{$name}\" removido com sucesso.");
        }

        return $this->redirectToRoute('app_admin_country_index');
    }
}
