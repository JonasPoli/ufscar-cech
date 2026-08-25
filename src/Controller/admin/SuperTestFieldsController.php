<?php

namespace App\Controller\admin;

use App\Entity\SuperTestFields;
use App\Form\SuperTestFieldsType;
use App\Repository\SuperTestFieldsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/super/test/fields')]
final class SuperTestFieldsController extends AbstractController
{
    #[Route(name: 'app_admin_super_test_fields_index', methods: ['GET'])]
    public function index(SuperTestFieldsRepository $superTestFieldsRepository): Response
    {
        return $this->render('admin/super_test_fields/index.html.twig', [
            'superTestFields' => $superTestFieldsRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_super_test_fields_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $superTestField = new SuperTestFields();
        $form = $this->createForm(SuperTestFieldsType::class, $superTestField);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($superTestField);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_super_test_fields_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/super_test_fields/new.html.twig', [
            'super_test_field' => $superTestField,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_super_test_fields_show', methods: ['GET'])]
    public function show(SuperTestFields $superTestField): Response
    {
        return $this->render('admin/super_test_fields/show.html.twig', [
            'super_test_field' => $superTestField,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_super_test_fields_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SuperTestFields $superTestField, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SuperTestFieldsType::class, $superTestField);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_super_test_fields_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/super_test_fields/edit.html.twig', [
            'super_test_field' => $superTestField,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_super_test_fields_delete', methods: ['POST'])]
    public function delete(Request $request, SuperTestFields $superTestField, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$superTestField->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($superTestField);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_super_test_fields_index', [], Response::HTTP_SEE_OTHER);
    }
}
