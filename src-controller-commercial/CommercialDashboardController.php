<?php

namespace App\Controller\Commercial;

use App\Entity\Client;
use App\Entity\Commande;
use App\Entity\Devis as DevisEntity;
use App\Entity\Facture;
use App\Entity\Fournisseur;
use App\Entity\Produit;
use App\Entity\Devis;
use App\Repository\DevisRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Controller\Admin\CommandeCrudController;
use App\Controller\Admin\DevisCrudController;
use App\Controller\Admin\FactureCrudController;

#[IsGranted('ROLE_COMMERCIAL')]
final class CommercialDashboardController extends AbstractDashboardController
{
    private DevisRepository $devisRepository;
    private RequestStack $requestStack;

    public function __construct(
        DevisRepository $devisRepository,
        RequestStack $requestStack
    ) {
        $this->devisRepository = $devisRepository;
        $this->requestStack    = $requestStack;
    }

    #[Route('/commercial/gestion', name: 'commercial_dashboard')]
    public function index(): Response
    {
        // ===== 1) Lire le paramètre de filtre mois via RequestStack =====
        $request    = $this->requestStack->getCurrentRequest();
        $monthParam = $request?->query->get('month');

        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            [$y, $m] = explode('-', $monthParam);
            $year  = (int) $y;
            $month = (int) $m;
        } else {
            $now   = new \DateTimeImmutable('now');
            $year  = (int) $now->format('Y');
            $month = (int) $now->format('m');
            $monthParam = sprintf('%04d-%02d', $year, $month);
        }

        $startOfMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->setTime(0, 0, 0);
        $endOfMonth   = $startOfMonth->modify('last day of this month')->setTime(23, 59, 59);

        // ===== 2) Compteurs par statut =====
        $trackedStatuses = [
            Devis::STATUT_ENVOYE,
            Devis::STATUT_BAT_PRODUCTION,
            Devis::STATUT_RELANCE,
            Devis::STATUT_PERDU,
        ];

        $devisCounts = [];
        foreach ($trackedStatuses as $status) {
            $count = (int) $this->devisRepository
                ->createQueryBuilder('d')
                ->select('COUNT(d.id)')
                ->where('d.statut = :status')
                ->andWhere('d.dateCreation BETWEEN :start AND :end')
                ->setParameter('status', $status)
                ->setParameter('start', new \DateTime($startOfMonth->format('Y-m-d H:i:s')))
                ->setParameter('end',   new \DateTime($endOfMonth->format('Y-m-d H:i:s')))
                ->getQuery()
                ->getSingleScalarResult();

            $devisCounts[$status] = $count;
        }

        // ===== 3) Séries journalières par statut pour le graphe =====
        $rows = $this->devisRepository
            ->createQueryBuilder('d')
            ->select('d.id, d.dateCreation, d.statut')
            ->where('d.statut IN (:statuses)')
            ->andWhere('d.dateCreation BETWEEN :start AND :end')
            ->setParameter('statuses', $trackedStatuses)
            ->setParameter('start', new \DateTime($startOfMonth->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfMonth->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getArrayResult();

        $daysInMonth = (int) $startOfMonth->format('t');
        $chartLabels = [];
        for ($i = 1; $i <= $daysInMonth; $i++) {
            $chartLabels[] = sprintf('%02d', $i);
        }

        $chartDataByStatus = [];
        foreach ($trackedStatuses as $status) {
            $chartDataByStatus[$status] = array_fill(1, $daysInMonth, 0);
        }

        foreach ($rows as $r) {
            $rawDate = $r['dateCreation'] ?? null;

            if ($rawDate instanceof \DateTimeInterface) {
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $rawDate->format('Y-m-d H:i:s'));
            } else {
                $dt = new \DateTime(is_array($rawDate) && isset($rawDate['date']) ? $rawDate['date'] : (string)$rawDate);
            }
            if (!$dt || is_nan($dt->getTimestamp())) {
                continue;
            }

            $day    = (int) $dt->format('j');
            $status = (string) $r['statut'];
            if (isset($chartDataByStatus[$status][$day])) {
                $chartDataByStatus[$status][$day]++;
            }
        }

        foreach ($chartDataByStatus as $status => $series) {
            $chartDataByStatus[$status] = array_values($series);
        }

        return $this->render('commercial/dashboard.html.twig', [
            'selectedMonth'      => $monthParam,
            'devisCounts'        => $devisCounts,
            'chartLabels'        => $chartLabels,
            'chartDataByStatus'  => $chartDataByStatus,
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

        yield MenuItem::linkToCrud('Devis', 'fas fa-file-pdf', DevisEntity::class)
            ->setController(DevisCrudController::class);

        yield MenuItem::linkToCrud('Factures', 'fas fa-file-invoice', Facture::class)
            ->setController(FactureCrudController::class);
    }
}
