<?php

namespace App\Controller\admin;

use App\Entity\AuthorIdentity;
use App\Entity\AuthorNameVariant;
use App\Repository\AuthorIdentityRepository;
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
#[Route('/admin/authors')]
class AdminAuthorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorIdentityRepository $authorRepo,
        private readonly ThesaurusFileService $fileService,
        private readonly EntityMergeService $mergeService
    ) {}

    #[Route('/', name: 'app_admin_author_index', methods: ['GET'])]
    public function index(): Response
    {
        // Limit initial display, DataTables provides instant client-side filtering
        $authors = $this->authorRepo->findBy([], ['preferredName' => 'ASC'], 500);

        return $this->render('admin/author/index.html.twig', [
            'authors' => $authors,
        ]);
    }

    #[Route('/new', name: 'app_admin_author_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $author = new AuthorIdentity();

        if ($request->isMethod('POST')) {
            $preferredName = trim((string)$request->request->get('preferredName'));

            if ($preferredName !== '') {
                $author->setPreferredName($preferredName);
                $author->setNormalizedName(StringNormalizer::normalizeString($preferredName, true));

                $this->em->persist($author);
                $this->em->flush();

                $this->addFlash('success', "Autor \"{$preferredName}\" cadastrado com sucesso.");
                return $this->redirectToRoute('app_admin_author_index');
            }
        }

        return $this->render('admin/author/form.html.twig', [
            'author' => $author,
            'isNew' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_author_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, AuthorIdentity $author): Response
    {
        if ($request->isMethod('POST')) {
            $preferredName = trim((string)$request->request->get('preferredName'));

            if ($preferredName !== '') {
                $author->setPreferredName($preferredName);
                $author->setNormalizedName(StringNormalizer::normalizeString($preferredName, true));

                // Add new variation if submitted
                $newVariation = trim((string)$request->request->get('new_variation'));
                if ($newVariation !== '') {
                    $norm = StringNormalizer::normalizeString($newVariation, true);
                    $var = new AuthorNameVariant();
                    $var->setAuthorIdentity($author);
                    $var->setOriginalName($newVariation);
                    $var->setDisplayName($newVariation);
                    $var->setNormalizedName($norm);
                    $var->setSource('manual');
                    $this->em->persist($var);
                }

                $this->em->flush();

                $this->addFlash('success', "Autor atualizado com sucesso.");
                return $this->redirectToRoute('app_admin_author_index');
            }
        }

        return $this->render('admin/author/form.html.twig', [
            'author' => $author,
            'isNew' => false,
        ]);
    }

    #[Route('/merge', name: 'app_admin_author_merge', methods: ['POST'])]
    public function merge(Request $request): Response
    {
        $masterId = (int)$request->request->get('master_id');
        $sourceIds = array_map('intval', (array)$request->request->get('source_ids', []));

        if ($masterId > 0 && !empty($sourceIds)) {
            try {
                $master = $this->mergeService->mergeAuthors($masterId, $sourceIds);
                $this->addFlash('success', "Fusão de autores realizada com sucesso em \"{$master->getPreferredName()}\".");
            } catch (\Throwable $e) {
                $this->addFlash('error', "Erro na fusão: " . $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_author_index');
    }

    #[Route('/export/{format}', name: 'app_admin_author_export', methods: ['GET'])]
    public function export(string $format): Response
    {
        $authors = $this->authorRepo->findBy([], ['id' => 'ASC'], 50000);
        $records = [];
        foreach ($authors as $auth) {
            $vars = [];
            foreach ($auth->getVariations() as $v) {
                $vars[] = $v->getOriginalName();
            }
            $records[] = [
                'preferred_name' => $auth->getPreferredName(),
                'variants' => $vars,
            ];
        }

        return $this->fileService->exportThesaurusStream($records, $format, 'tesauro_autores');
    }

    #[Route('/import', name: 'app_admin_author_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('thesaurus_file');
        if ($file && $file->isValid()) {
            @\set_time_limit(600);
            @\ini_set('memory_limit', '512M');
            $records = $this->fileService->parseFile($file->getRealPath(), $file->getClientOriginalExtension());
            $count = 0;
            $batchSize = 200;
            foreach ($records as $r) {
                $pref = trim((string)($r['preferred_name'] ?? ''));
                if ($pref === '') continue;

                $author = $this->authorRepo->findOneBy(['preferredName' => $pref]);
                if (!$author) {
                    $author = new AuthorIdentity();
                    $author->setPreferredName($pref);
                    $author->setNormalizedName(StringNormalizer::normalizeString($pref, true));
                    $this->em->persist($author);
                }

                $existingNorms = [];
                foreach ($author->getVariations() as $existingVar) {
                    $existingNorms[$existingVar->getNormalizedName()] = true;
                }

                foreach ($r['variants'] ?? [] as $varName) {
                    $norm = StringNormalizer::normalizeString($varName, true);
                    if ($norm === '' || isset($existingNorms[$norm])) {
                        continue;
                    }
                    $varObj = new AuthorNameVariant();
                    $varObj->setAuthorIdentity($author);
                    $varObj->setOriginalName($varName);
                    $varObj->setDisplayName($varName);
                    $varObj->setNormalizedName($norm);
                    $varObj->setSource('import');
                    $this->em->persist($varObj);
                    $existingNorms[$norm] = true;
                }
                $count++;
                if ($count % $batchSize === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $this->addFlash('success', "{$count} autores importados com sucesso.");
        }

        return $this->redirectToRoute('app_admin_author_index');
    }

    #[Route('/{id}/delete', name: 'app_admin_author_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, AuthorIdentity $author): Response
    {
        if ($this->isCsrfTokenValid('delete' . $author->getId(), (string)$request->request->get('_token'))) {
            $name = $author->getPreferredName();
            $this->em->remove($author);
            $this->em->flush();
            $this->addFlash('success', "Autor \"{$name}\" removido com sucesso.");
        }

        return $this->redirectToRoute('app_admin_author_index');
    }
}
