<?php

namespace App\Controller\admin;

use App\Entity\AcademicDatabase;
use App\Repository\AcademicDatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/academic-databases')]
class AdminAcademicDatabaseController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AcademicDatabaseRepository $databaseRepo
    ) {}

    #[Route('/', name: 'app_admin_academic_database_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = trim((string)$request->query->get('search', ''));

        $qb = $this->em->createQueryBuilder()
            ->select('ad', 'COUNT(j.id) as journalCount')
            ->from(AcademicDatabase::class, 'ad')
            ->leftJoin('ad.journals', 'j')
            ->groupBy('ad.id')
            ->orderBy('ad.name', 'ASC');

        if ($search !== '') {
            $qb->andWhere('ad.name LIKE :s OR ad.acronym LIKE :s OR ad.description LIKE :s')
               ->setParameter('s', '%' . $search . '%');
        }

        $results = $qb->getQuery()->getResult();

        return $this->render('admin/academic_database/index.html.twig', [
            'results' => $results,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_admin_academic_database_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $database = new AcademicDatabase();

        if ($request->isMethod('POST')) {
            $name = trim((string)$request->request->get('name'));
            $acronym = strtolower(trim((string)$request->request->get('acronym')));
            $url = trim((string)$request->request->get('url')) ?: null;
            $listDownloadUrl = trim((string)$request->request->get('list_download_url')) ?: null;
            $description = trim((string)$request->request->get('description')) ?: null;
            $fileFormatsRaw = trim((string)$request->request->get('file_formats', ''));
            $signatureColsRaw = trim((string)$request->request->get('signature_columns', ''));

            if ($name !== '' && $acronym !== '') {
                $existing = $this->databaseRepo->findOneBy(['acronym' => $acronym]);
                if ($existing) {
                    $this->addFlash('error', "Já existe uma base de indexação com a sigla \"{$acronym}\".");
                } else {
                    $database->setName($name);
                    $database->setAcronym($acronym);
                    $database->setUrl($url);
                    $database->setListDownloadUrl($listDownloadUrl);
                    $database->setDescription($description);

                    if ($fileFormatsRaw !== '') {
                        $formats = array_filter(array_map('trim', explode(',', $fileFormatsRaw)));
                        $database->setFileFormats(array_values($formats));
                    }
                    if ($signatureColsRaw !== '') {
                        $cols = array_filter(array_map('trim', explode(',', $signatureColsRaw)));
                        $database->setSignatureColumns(array_values($cols));
                    }

                    // Handle Logo upload
                    /** @var UploadedFile|null $logoFile */
                    $logoFile = $request->files->get('logo_file');
                    if ($logoFile) {
                        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/images/databases';
                        @mkdir($uploadDir, 0777, true);
                        $filename = $acronym . '_' . uniqid() . '.' . $logoFile->guessExtension();
                        $logoFile->move($uploadDir, $filename);
                        $database->setLogo('/images/databases/' . $filename);
                    }

                    $this->em->persist($database);
                    $this->em->flush();

                    $this->addFlash('success', "Base de indexação \"{$name}\" criada com sucesso.");
                    return $this->redirectToRoute('app_admin_academic_database_index');
                }
            }
        }

        return $this->render('admin/academic_database/form.html.twig', [
            'database' => $database,
            'isNew' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_academic_database_edit', methods: ['GET', 'POST'])]
    public function edit(AcademicDatabase $database, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string)$request->request->get('name'));
            $acronym = strtolower(trim((string)$request->request->get('acronym')));
            $url = trim((string)$request->request->get('url')) ?: null;
            $listDownloadUrl = trim((string)$request->request->get('list_download_url')) ?: null;
            $description = trim((string)$request->request->get('description')) ?: null;
            $fileFormatsRaw = trim((string)$request->request->get('file_formats', ''));
            $signatureColsRaw = trim((string)$request->request->get('signature_columns', ''));

            if ($name !== '' && $acronym !== '') {
                $existing = $this->databaseRepo->findOneBy(['acronym' => $acronym]);
                if ($existing && $existing->getId() !== $database->getId()) {
                    $this->addFlash('error', "Já existe outra base de indexação com a sigla \"{$acronym}\".");
                } else {
                    $database->setName($name);
                    $database->setAcronym($acronym);
                    $database->setUrl($url);
                    $database->setListDownloadUrl($listDownloadUrl);
                    $database->setDescription($description);

                    if ($fileFormatsRaw !== '') {
                        $formats = array_filter(array_map('trim', explode(',', $fileFormatsRaw)));
                        $database->setFileFormats(array_values($formats));
                    } else {
                        $database->setFileFormats([]);
                    }

                    if ($signatureColsRaw !== '') {
                        $cols = array_filter(array_map('trim', explode(',', $signatureColsRaw)));
                        $database->setSignatureColumns(array_values($cols));
                    } else {
                        $database->setSignatureColumns([]);
                    }

                    // Handle Logo upload
                    /** @var UploadedFile|null $logoFile */
                    $logoFile = $request->files->get('logo_file');
                    if ($logoFile) {
                        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/images/databases';
                        @mkdir($uploadDir, 0777, true);
                        $filename = $acronym . '_' . uniqid() . '.' . $logoFile->guessExtension();
                        $logoFile->move($uploadDir, $filename);
                        $database->setLogo('/images/databases/' . $filename);
                    }

                    $this->em->flush();

                    $this->addFlash('success', "Base de indexação \"{$name}\" atualizada com sucesso.");
                    return $this->redirectToRoute('app_admin_academic_database_index');
                }
            }
        }

        return $this->render('admin/academic_database/form.html.twig', [
            'database' => $database,
            'isNew' => false,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_academic_database_delete', methods: ['POST'])]
    public function delete(AcademicDatabase $database, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_database_' . $database->getId(), (string)$request->request->get('_token'))) {
            $name = $database->getName();
            $this->em->remove($database);
            $this->em->flush();
            $this->addFlash('success', "Base de indexação \"{$name}\" removida com sucesso.");
        }

        return $this->redirectToRoute('app_admin_academic_database_index');
    }
}
