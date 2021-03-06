<?php

namespace App\Controller;

use App\Entity\Event;

use App\Entity\Candidats;
use App\Entity\Elector;
use App\Form\CandidatsType;
use App\Repository\EventRepository;
use App\Repository\CandidatsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;

/**
 * @Route("/candidats")
 */
class CandidatsController extends Controller
{
    /**
     * @Route("/", name="candidats", methods={"GET"})
     */
    public function index(CandidatsRepository $candidatsRepository, EventRepository $eventRepository, Request $request): Response
    {

        if ($this->getUser() && $this->getUser()->hasRole('ROLE_ADMIN')) {
            $currentRoute = $request->attributes->get('_route');
            return $this->render('admins/baseAdmin.html.twig', [
                'candidats' => $candidatsRepository->findAll(),
                'events' => $eventRepository->findAll(),
                'currentRoute' => $currentRoute
            ]);
        } elseif ($this->getUser() && $this->getUser()->hasRole('ROLE_ELECTOR')) {
            return $this->render('users/vote/404.html.twig', [
                'userPhoto' => $this->getUser()->getElector()->getPhoto(),
                'userId' => $this->getUser()->getElector()->getId(),
            ]);
        } else {
            return $this->redirectToRoute('fos_user_security_login');
        }

    }

    /**
     * @Route("/filterByEvent1/{id}", name="filterByEvent1", methods={"GET","POST"})
     */
    public function filterByEvent($id, EventRepository $eventRepository, CandidatsRepository $candidatsRepository, Request $request)
    {
        $event = intval($id);
        if ($event != 0) {
            $event = $eventRepository->findOneBy(['id' => $event]);
            $candidats = $event->getCandidats();
        } else {
            $candidats = $candidatsRepository->findAll();
        }
        $result = array();
        foreach ($candidats as $candidat) {
            $url = $this->generateUrl(
                'candidats_show',
                ['id' => $candidat->getId()]
            );
            $url2 = $this->generateUrl(
                'candidats_edit',
                ['id' => $candidat->getId()]
            );
            $result[] = array('<img style="width: 50px" src="/uploads/profile.jpg" alt="">', $candidat->getCin(), $candidat->getFirstName() . ' ' . $candidat->getLastName(), $candidat->getDateOfBirth()->format("Y-m-d"), $candidat->getEmail(), $candidat->getPhone(), $candidat->getGender() == 0 ? 'Homme' : 'Femme', "<a class='label danger' href='" . $url . "'>Montrer</a><a class='label success' href='" . $url2 . "'>Modifier</a>");
        }
        return new JsonResponse($result);
    }

    /**
     * @Route("/new", name="candidats_new", methods={"GET","POST"})
     */
    public function new(Request $request, MailerInterface $mailer): Response
    {

        if ($this->getUser() && $this->getUser()->hasRole('ROLE_ADMIN')) {
            $userManager = $this->get('fos_user.user_manager');
            $candidat = new Candidats();
            $form = $this->createForm(CandidatsType::class, $candidat);
            $form->handleRequest($request);

            $currentRoute = $request->attributes->get('_route');
            $form1 = $this->createFormBuilder()
                ->add('input', FileType::class, ['required' => true, 'attr' => [
                    'accept' => '.csv',
                    'name' => "file1",
                    'id' => "input-file-now",
                    'name' => "file1",
                    'class' => "dropify uploadlogo"
                ]])
                ->add('event', EntityType::class, ['required' => true, 'class' => Event::class, 'attr' => [
                    'class' => 'form-control'
                ]])
                ->getForm();
            $form1->handleRequest($request);
            if ($form1->isSubmitted() && $form1->isValid()) {
                if ($form1->get('input')->getData()) {


                    $csvFile = fopen($form1->get('input')->getData(), 'r');
                    $event = $form1->get('event')->getData();
                    $row = 0;
                    while (($line = fgetcsv($csvFile, 1000, ";")) !== FALSE) {
                        if ($row != 0) {
                            $cin = (isset($line[0]) && $line[0] != '') ? $line[0] : NULL;
                            $firstname = (isset($line[1]) && $line[1] != '') ? $line[1] : NULL;
                            $lastname = (isset($line[2]) && $line[2] != '') ? $line[2] : NULL;
                            $phone = (isset($line[3]) && $line[3] != '') ? $line[3] : NULL;
                            $birth = (isset($line[4]) && $line[4] != '') ? $line[4] : NULL;
                            $gender = (isset($line[5]) && $line[5] != '') ? $line[5] : NULL;
                            $email = (isset($line[6]) && $line[6] != '') ? $line[6] : NULL;
                            $email_exist = $userManager->findUserByEmail($email);
                            if ($email_exist) {
                                return $this->render('admins/baseAdmin.html.twig', [
                                    'error' => 1,
                                    'form' => $form->createView(),
                                    'form1' => $form1->createView(),
                                    'candidat' => $candidat,
                                    'currentRoute' => $currentRoute
                                ]);
                            }
                            $cin_Exist = $this->getDoctrine()
                                ->getRepository(Candidats::class)
                                ->findOneBy(['cin' => $cin]);

                            if ($cin_Exist) {
                                return $this->render('admins/baseAdmin.html.twig', [
                                    'error' => 1,
                                    'form' => $form->createView(),
                                    'form1' => $form1->createView(),
                                    'candidat' => $candidat,
                                    'currentRoute' => $currentRoute
                                ]);
                            }
                            $password = $firstname . uniqid();
                            $user = $userManager->createUser();
                            $user->setUsername($firstname . ' ' . $lastname);
                            $user->setEmail($email);
                            $user->setEmailCanonical($email);
                            $user->setEnabled(1);
                            $user->setRoles(['ROLE_ELECTOR']);
                            $user->setPlainPassword($password);
                            $userManager->updateUser($user);

                            $candidat = new Candidats();
                            $candidat->setPhone(intval($phone));
                            $candidat->setFirstName($firstname);
                            $candidat->setLastName($lastname);
                            $candidat->setCin(intval($cin));
                            $candidat->setGender($gender);
                            $candidat->setEmail($email);
                            $candidat->setEvent($event);
                            $candidat->setDateOfBirth(new \DateTime($birth));
                            $candidat->setPhoto('profile.jpg');

                            $Elector = new Elector();
                            $Elector->setPhone(intval($phone));
                            $Elector->setFirstName($firstname);
                            $Elector->setLastName($lastname);
                            $Elector->setCin(intval($cin));
                            $Elector->setGender($gender);
                            $Elector->setEmail($email);
                            $Elector->AddEvent($event);
                            $Elector->setBirth(new \DateTime($birth));
                            $Elector->setPhoto('profile.jpg');
                            $entityManager = $this->getDoctrine()->getManager();
                            $entityManager->persist($candidat);
                            $entityManager->flush();
                            $entityManager->persist($Elector);
                            $entityManager->flush();
                        }
                        $row++;
                    }
                }
            }

            if ($form->isSubmitted() && $form->isValid()) {

                $file = $form->get('photo')->getData();
                $fileName = '' . md5(uniqid()) . '.' . $file->guessExtension();
                // Move the file to the directory where images are stored
                try {
                    $file->move(
                        $this->getParameter('upload_directory'),
                        $fileName
                    );
                } catch (FileException $e) {
                    // ... handle exception if something happens during file upload
                }
                // updates the 'image' property to store the PDF file name
                // instead of its contents

                $candidat->setphoto($fileName);
                $email_exist = $userManager->findUserByEmail($form->get('email')->getData());

                if ($email_exist) {

                    return $this->render('admins/baseAdmin.html.twig', [
                        'error' => 1,
                        'form' => $form->createView(),
                        'form1' => $form1->createView(),
                        'candidat' => $candidat,
                        'currentRoute' => $currentRoute
                    ]);
                }
                $cin_Exist = $this->getDoctrine()
                    ->getRepository(Candidats::class)
                    ->findOneBy(['cin' => $form->get('cin')->getData()]);

                if ($cin_Exist) {

                    return $this->render('admins/baseAdmin.html.twig', [
                        'error' => 1,
                        'form' => $form->createView(),
                        'form1' => $form1->createView(),
                        'candidat' => $candidat,
                        'currentRoute' => $currentRoute
                    ]);
                }

                $Elector = new Elector();
                $Elector->setPhone(intval($form->get('phone')->getData()));
                $Elector->setFirstName($form->get('first_name')->getData());
                $Elector->setLastName($form->get('last_name')->getData());
                $Elector->setCin(intval($form->get('cin')->getData()));
                $Elector->setGender($form->get('gender')->getData());
                $Elector->setEmail($form->get('email')->getData());
                if ($form->get('event')->getData() != null) {
                    $Elector->AddEvent($form->get('event')->getData());
                }
                $Elector->setBirth($form->get('date_of_birth')->getData());
                $Elector->setPhoto('profile.jpg');
                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->persist($candidat);
                $entityManager->flush();
                $entityManager->persist($Elector);
                $entityManager->flush();

                $password = $form->get('first_name')->getData() . uniqid();
                $user = $userManager->createUser();
                $user->setUsername($form->get('first_name')->getData() . ' ' . $form->get('last_name')->getData());
                $user->setEmail($form->get('email')->getData());
                $user->setEmailCanonical($form->get('photo')->getData());
                $user->setEnabled(1);
                $user->setRoles(['ROLE_ELECTOR']);
                $user->setPlainPassword($password);
                $user->setElector($Elector);
                $userManager->updateUser($user);


                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->persist($candidat);
                $entityManager->flush();
                $email = (new Email())
                    ->from('EvotePro@gmail.com')
                    ->to($form->get('email')->getData())
                    ->subject('Bienvenue a E-Vote!')
                    ->html('<div style="text-align:center"><div style="margin-bottom:30px">Bonjour MR/MRS <strong>' . $form->get('last_name')->getData() . ' ' . $form->get('first_name')->getData() . '</strong></div><div style="margin-bottom:10px">login : ' . $form->get('first_name')->getData() . ' ' . $form->get('last_name')->getData() . '</div><div style="margin-bottom:10px"> mot de passe : ' . $password . ' </div><div><button><a href="#">acced??s a votre espace</a></button></div></div>');

                $mailer->send($email);


                return $this->redirectToRoute('candidats');
            }

            return $this->render('admins/baseAdmin.html.twig', [
                'candidat' => $candidat,
                'form' => $form->createView(),
                'form1' => $form1->createView(),
                'currentRoute' => $currentRoute,
                'error' => 0
            ]);

        } elseif ($this->getUser() && $this->getUser()->hasRole('ROLE_ELECTOR')) {
            return $this->render('users/vote/404.html.twig', [
                'userPhoto' => $this->getUser()->getElector()->getPhoto(),
                'userId' => $this->getUser()->getElector()->getId(),
            ]);
        } else {
            return $this->redirectToRoute('fos_user_security_login');
        }

    }

    /**
     * @Route("/{id}", name="candidats_show", methods={"GET"})
     */
    public function show(Candidats $candidat, Request $request): Response
    {

        if ($this->getUser() && $this->getUser()->hasRole('ROLE_ADMIN')) {
            $currentRoute = $request->attributes->get('_route');

            return $this->render('admins/baseAdmin.html.twig', [
                'candidat' => $candidat,
                'currentRoute' => $currentRoute
            ]);

        } elseif ($this->getUser() && $this->getUser()->hasRole('ROLE_ELECTOR')) {
            return $this->render('users/vote/404.html.twig', [
                'userPhoto' => $this->getUser()->getElector()->getPhoto(),
                'userId' => $this->getUser()->getElector()->getId(),
            ]);
        } else {
            return $this->redirectToRoute('fos_user_security_login');
        }
    }

    /**
     * @Route("/{id}/edit", name="candidats_edit", methods={"GET","POST"})
     */
    public function edit(Request $request, Candidats $candidat): Response
    {
        if ($this->getUser() && $this->getUser()->hasRole('ROLE_ADMIN')) {
            $form = $this->createForm(CandidatsType::class, $candidat);
            $form->handleRequest($request);
            $currentRoute = $request->attributes->get('_route');
            if ($form->isSubmitted() && $form->isValid()) {

                $file = $form->get('photo')->getData();
                $fileName = '' . md5(uniqid()) . '.' . $file->guessExtension();
                // Move the file to the directory where images are stored
                try {
                    $file->move(
                        $this->getParameter('upload_directory'),
                        $fileName
                    );
                } catch (FileException $e) {
                    // ... handle exception if something happens during file upload
                }
                // updates the 'image' property to store the PDF file name
                // instead of its contents

                $candidat->setphoto($fileName);
                $this->getDoctrine()->getManager()->flush();

                return $this->redirectToRoute('candidats');
            }

            return $this->render('admins/baseAdmin.html.twig', [
                'candidat' => $candidat,
                'form' => $form->createView(),
                'currentRoute' => $currentRoute
            ]);

        } elseif ($this->getUser() && $this->getUser()->hasRole('ROLE_ELECTOR')) {
            return $this->render('users/vote/404.html.twig', [
                'userPhoto' => $this->getUser()->getElector()->getPhoto(),
                'userId' => $this->getUser()->getElector()->getId(),
            ]);
        } else {
            return $this->redirectToRoute('fos_user_security_login');
        }
    }

    /**
     * @Route("/{id}", name="candidats_delete", methods={"DELETE"})
     */
    public function delete(Request $request, Candidats $candidat): Response
    {
        if ($this->getUser() && $this->getUser()->hasRole('ROLE_ADMIN')) {
            if ($this->isCsrfTokenValid('delete' . $candidat->getId(), $request->request->get('_token'))) {
                $entityManager = $this->getDoctrine()->getManager();
                $entityManager->remove($candidat);
                $entityManager->flush();
            }

            return $this->redirectToRoute('candidats');
        } elseif ($this->getUser() && $this->getUser()->hasRole('ROLE_ELECTOR')) {
            return $this->render('users/vote/404.html.twig', [
                'userPhoto' => $this->getUser()->getElector()->getPhoto(),
                'userId' => $this->getUser()->getElector()->getId(),
            ]);
        } else {
            return $this->redirectToRoute('fos_user_security_login');
        }
    }

}
