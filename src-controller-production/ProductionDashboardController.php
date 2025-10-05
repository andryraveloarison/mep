<?php
namespace App\Controller\Production;

use App\Entity\Commande;
use App\Repository\CommandeProduitRepository;
use App\Repository\CommandeRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProductionDashboardController extends AbstractDashboardController
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

    #[Route('/production', name: 'production_dashboard')]
    public function index(): Response
    {
        // ===== Fenêtre "aujourd'hui" (sur la base de dateCommande) =====
        $today      = new \DateTimeImmutable('today');
        $startOfDay = $today->setTime(0, 0, 0);
        $endOfDay   = $today->setTime(23, 59, 59);

        // === 1) Travaux du jour — statut production EN COURS
        $workInProgressToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutProduction = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PRODUCTION_EN_COURS)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

        // === 2) Travaux "prêts pour livraison" du jour — statut production POUR_LIVRAISON
        $workReadyForDeliveryToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutProduction = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PRODUCTION_POUR_LIVRAISON)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

        // ===== 3) Graph mensuel : "PRÊT POUR LIVRAISON" par jour =====
        // ?month=YYYY-MM, ex: 2025-10 ; défaut = mois courant
        $monthParam = $request->query->get('month');
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            [$y, $m]   = explode('-', $monthParam);
            $year      = (int) $y;
            $month     = (int) $m;
        } else {
            $now       = new \DateTimeImmutable('now');
            $year      = (int) $now->format('Y');
            $month     = (int) $now->format('m');
            $monthParam = sprintf('%04d-%02d', $year, $month);
        }

        $startOfTargetMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->setTime(0, 0, 0);
        $endOfTargetMonth   = $startOfTargetMonth->modify('last day of this month')->setTime(23, 59, 59);

        // Récup toutes les commandes en statut POUR_LIVRAISON sur le mois
        $readyRows = $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('c.id, c.dateCommande')
            ->where('c.statutProduction = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PRODUCTION_POUR_LIVRAISON)
            ->setParameter('start', new \DateTime($startOfTargetMonth->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfTargetMonth->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getArrayResult();

        // Agréger par jour (en PHP)
        $daysInMonth = (int) $startOfTargetMonth->format('t');
        $perDay = array_fill(1, $daysInMonth, 0);

        foreach ($readyRows as $row) {
            // dateCommande peut être un array (Doctrine DateTime) selon mapping, on normalise
            $raw = $row['dateCommande'];
            $dt  = $raw instanceof \DateTimeInterface ? \DateTime::createFromFormat('Y-m-d H:i:s', $raw->format('Y-m-d H:i:s')) : new \DateTime(is_array($raw) && isset($raw['date']) ? $raw['date'] : (string)$raw);
            if ($dt && !is_nan($dt->getTimestamp())) {
                $d = (int) $dt->format('j');
                if ($d >= 1 && $d <= $daysInMonth) $perDay[$d]++;
            }
        }

        $chartLabels = [];
        $chartValues = [];
        for ($i = 1; $i <= $daysInMonth; $i++) {
            $chartLabels[] = sprintf('%02d', $i);
            $chartValues[] = $perDay[$i];
        }

        return $this->render('production/dashboard.html.twig', [
            'workInProgressToday'       => $workInProgressToday,
            'workReadyForDeliveryToday' => $workReadyForDeliveryToday,

            // Graph
            'selectedMonth'             => $monthParam,   // 'YYYY-MM'
            'chartLabels'               => $chartLabels,  // ['01', '02', ...]
            'chartValues'               => $chartValues,  // [0, 1, 0, 2, ...]
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
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkToCrud('Commandes à traiter', 'fas fa-industry', Commande::class)
            ->setController(ProductionCommandeCrudController::class);
    }
}
