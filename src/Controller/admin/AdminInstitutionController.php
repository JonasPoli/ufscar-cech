<?php

namespace App\Controller\admin;

use App\Entity\Institution;
use App\Entity\InstitutionVariation;
use App\Repository\InstitutionRepository;
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
#[Route('/admin/institutions')]
class AdminInstitutionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstitutionRepository $institutionRepo,
        private readonly ThesaurusFileService $fileService,
        private readonly EntityMergeService $mergeService
    ) {}

    #[Route('/', name: 'app_admin_institution_index', methods: ['GET'])]
    public function index(): Response
    {
        // Limit to first 500 for initial listing, DataTables handles search
        $institutions = $this->institutionRepo->findBy([], ['officialName' => 'ASC'], 500);

        return $this->render('admin/institution/index.html.twig', [
            'institutions' => $institutions,
        ]);
    }

    #[Route('/new', name: 'app_admin_institution_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $institution = new Institution();

        if ($request->isMethod('POST')) {
            $officialName = trim((string)$request->request->get('officialName'));
            $shortName = trim((string)$request->request->get('shortName')) ?: null;
            $acronym = trim((string)$request->request->get('acronym')) ?: null;
            $institutionType = trim((string)$request->request->get('institutionType')) ?: null;
            $legalNature = trim((string)$request->request->get('legalNature')) ?: null;

            if ($officialName !== '') {
                $institution->setOfficialName($officialName);
                $institution->setShortName($shortName);
                $institution->setAcronym($acronym);
                $institution->setInstitutionType($institutionType);
                $institution->setLegalNature($legalNature);

                $this->em->persist($institution);
                $this->em->flush();

                $this->addFlash('success', "Instituição \"{$officialName}\" cadastrada com sucesso.");
                return $this->redirectToRoute('app_admin_institution_index');
            }
        }

        return $this->render('admin/institution/form.html.twig', [
            'institution' => $institution,
            'isNew' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_institution_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Institution $institution): Response
    {
        if ($request->isMethod('POST')) {
            $officialName = trim((string)$request->request->get('officialName'));
            $shortName = trim((string)$request->request->get('shortName')) ?: null;
            $acronym = trim((string)$request->request->get('acronym')) ?: null;
            $institutionType = trim((string)$request->request->get('institutionType')) ?: null;
            $legalNature = trim((string)$request->request->get('legalNature')) ?: null;

            if ($officialName !== '') {
                $institution->setOfficialName($officialName);
                $institution->setShortName($shortName);
                $institution->setAcronym($acronym);
                $institution->setInstitutionType($institutionType);
                $institution->setLegalNature($legalNature);

                // Add new variation if submitted
                $newVariation = trim((string)$request->request->get('new_variation'));
                if ($newVariation !== '') {
                    $norm = StringNormalizer::normalizeString($newVariation, true);
                    $var = new InstitutionVariation();
                    $var->setInstitution($institution);
                    $var->setVariationName($newVariation);
                    $var->setNormalizedName($norm);
                    $this->em->persist($var);
                }

                $this->em->flush();

                $this->addFlash('success', "Instituição atualizada com sucesso.");
                return $this->redirectToRoute('app_admin_institution_index');
            }
        }

        return $this->render('admin/institution/form.html.twig', [
            'institution' => $institution,
            'isNew' => false,
        ]);
    }

    #[Route('/merge', name: 'app_admin_institution_merge', methods: ['POST'])]
    public function merge(Request $request): Response
    {
        $masterId = (int)$request->request->get('master_id');
        $sourceIds = array_map('intval', (array)$request->request->get('source_ids', []));

        if ($masterId > 0 && !empty($sourceIds)) {
            try {
                $master = $this->mergeService->mergeInstitutions($masterId, $sourceIds);
                $this->addFlash('success', "Fusão de instituições realizada com sucesso em \"{$master->getOfficialName()}\".");
            } catch (\Throwable $e) {
                $this->addFlash('error', "Erro na fusão: " . $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_institution_index');
    }

    #[Route('/export/{format}', name: 'app_admin_institution_export', methods: ['GET'])]
    public function export(string $format): Response
    {
        $institutions = $this->institutionRepo->findAll();
        $records = [];
        foreach ($institutions as $inst) {
            $vars = [];
            foreach ($inst->getVariations() as $v) {
                $vars[] = $v->getVariationName();
            }
            $records[] = [
                'preferred_name' => $inst->getOfficialName(),
                'variants' => $vars,
            ];
        }

        return $this->fileService->exportThesaurusStream($records, $format, 'tesauro_instituicoes');
    }

    #[Route('/import', name: 'app_admin_institution_import', methods: ['POST'])]
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

                $inst = $this->institutionRepo->findOneBy(['officialName' => $pref]);
                if (!$inst) {
                    $inst = new Institution();
                    $inst->setOfficialName($pref);
                    $this->em->persist($inst);
                }

                foreach ($r['variants'] ?? [] as $varName) {
                    $norm = StringNormalizer::normalizeString($varName, true);
                    $varObj = new InstitutionVariation();
                    $varObj->setInstitution($inst);
                    $varObj->setVariationName($varName);
                    $varObj->setNormalizedName($norm);
                    $this->em->persist($varObj);
                }
                $count++;
            }
            $this->em->flush();
            $this->addFlash('success', "{$count} termos de instituições importados com sucesso.");
        }

        return $this->redirectToRoute('app_admin_institution_index');
    }

    #[Route('/{id}/delete', name: 'app_admin_institution_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Institution $institution): Response
    {
        if ($this->isCsrfTokenValid('delete' . $institution->getId(), (string)$request->request->get('_token'))) {
            $name = $institution->getOfficialName();
            $this->em->remove($institution);
            $this->em->flush();
            $this->addFlash('success', "Instituição \"{$name}\" removida com sucesso.");
        }

        return $this->redirectToRoute('app_admin_institution_index');
    }
}
