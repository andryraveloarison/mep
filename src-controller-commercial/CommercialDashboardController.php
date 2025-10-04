<?php

namespace App\Controller\Commercial;

use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Controller\Admin\CommandeCrudController;
use App\Controller\Admin\FactureCrudController;
use App\Controller\Admin\DevisCrudController;
use App\Entity\Client;
use App\Entity\Produit;
use App\Entity\Facture;
use App\Entity\Devis;
use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Repository\CommandeRepository;
use App\Repository\CommandeProduitRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\User\UserInterface;

#[IsGranted('ROLE_COMMERCIAL')]
final class CommercialDashboardController extends AbstractDashboardController
{
    private CommandeRepository $commandeRepository;
    private CommandeProduitRepository $commandeProduitRepository;

    public function __construct(
        CommandeRepository $commandeRepository,
        CommandeProduitRepository $commandeProduitRepository
    ) {
        $this->commandeRepository = $commandeRepository;
        $this->commandeProduitRepository = $commandeProduitRepository;
    }

    #[Route('/commercial/gestion', name: 'commercial_dashboard')]
    public function index(): Response
    {
        /** @var UserInterface|null $user */
        $user = $this->getUser();
        if (!$user || !method_exists($user, 'getId')) {
            throw $this->createAccessDeniedException('Utilisateur non connecté.');
        }

        // ⚠️ Les Repository attendent ?int $userId, pas l'objet utilisateur
        $userId = (int) $user->getId();

        // Fenêtres temporelles
        $today = new \DateTimeImmutable('now');
        $startOfDay = $today->setTime(0, 0, 0);
        $endOfDay   = $today->setTime(23, 59, 59);

        $startOfMonth = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
        $endOfMonth   = (new \DateTimeImmutable('last day of this month'))->setTime(23, 59, 59);

        // Appels repository (les méthodes attendent \DateTime, on convertit depuis Immutable)
        $salesToday = $this->commandeRepository->findTotalSalesBetweenDates(
            new \DateTime($startOfDay->format('Y-m-d H:i:s')),
            new \DateTime($endOfDay->format('Y-m-d H:i:s')),
            $userId
        );

        $salesThisMonth = $this->commandeRepository->findTotalSalesBetweenDates(
            new \DateTime($startOfMonth->format('Y-m-d H:i:s')),
            new \DateTime($endOfMonth->format('Y-m-d H:i:s')),
            $userId
        );

        // Top produits du mois (assure-toi que la signature du repo est (DateTime $start, DateTime $end, ?int $userId = null))
        $bestProductsThisMonth = $this->commandeProduitRepository->findBestSellingProducts(
            new \DateTime($startOfMonth->format('Y-m-d H:i:s')),
            new \DateTime($endOfMonth->format('Y-m-d H:i:s')),
            $userId
        );

        // Stats mensuelles pour l'utilisateur
        $monthlyData = $this->commandeRepository->getMonthlyStatistics($userId);

        return $this->render('commercial/dashboard.html.twig', [
            'salesToday'             => $salesToday,
            'salesThisMonth'         => $salesThisMonth,
            'bestProductsThisMonth'  => $bestProductsThisMonth,
            'monthlyData'            => $monthlyData,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<div style="text-align:center;">
                            <img src="/utils/logo/forever-removebg-preview.png" alt="Forever Logo" width="130" height="100">
                        </div>');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linktoDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkToCrud('Clients', 'fas fa-users', Client::class);
        yield MenuItem::linkToCrud('Fournisseurs', 'fas fa-thumbs-up', Fournisseur::class);
        yield MenuItem::linkToCrud('Produits', 'fas fa-box', Produit::class);
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-cart', Commande::class)
            ->setController(CommandeCrudController::class);
        yield MenuItem::linkToCrud('Devis', 'fas fa-file-pdf', Devis::class)
            ->setController(DevisCrudController::class);
        yield MenuItem::linkToCrud('Factures', 'fas fa-file-invoice', Facture::class)
            ->setController(FactureCrudController::class);
    }
}
