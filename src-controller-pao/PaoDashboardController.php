<?php
namespace App\Controller\Pao;

use App\Entity\Commande;
use App\Repository\CommandeProduitRepository;
use App\Repository\CommandeRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PaoDashboardController extends AbstractDashboardController
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

    #[Route('/pao', name: 'pao_dashboard')]
    public function index(Request $request): Response
    {
        // ===== Fenêtres temporelles "aujourd'hui" =====
        $today      = new \DateTimeImmutable('today');
        $startOfDay = $today->setTime(0, 0, 0);
        $endOfDay   = $today->setTime(23, 59, 59);

        // ===== Fenêtre "semaine" (lundi -> dimanche) =====
        $startOfWeek = (new \DateTimeImmutable('monday this week'))->setTime(0, 0, 0);
        $endOfWeek   = (new \DateTimeImmutable('sunday this week'))->setTime(23, 59, 59);

        // ===== 1) Travaux du jour — PAO EN COURS =====
        $workInProgressToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_EN_COURS)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

        // ===== 2) Travaux du jour — PAO FAIT =====
        $workDoneToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

        // ===== 3) Travaux du jour — PAO MODIFICATION =====
        $workModificationToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_MODIFICATION)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

        // ===== 4) Travaux de la semaine = EN_COURS + FAIT + MODIFICATION =====
        $worksThisWeekCount = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao IN (:statuses)')
            ->andWhere('c.dateCommande BETWEEN :weekStart AND :weekEnd')
            ->setParameter('statuses', [
                Commande::STATUT_PAO_EN_COURS,
                Commande::STATUT_PAO_FAIT,
                Commande::STATUT_PAO_MODIFICATION,
            ])
            ->setParameter('weekStart', new \DateTime($startOfWeek->format('Y-m-d H:i:s')))
            ->setParameter('weekEnd',   new \DateTime($endOfWeek->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

        // ===== 5) Graph "PAO FAIT" par jour d’un mois — filtre mois =====
        // Query param month = 'YYYY-MM' (ex: 2025-10). Par défaut: mois courant.
        $monthParam = $request->query->get('month');
        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            [$y, $m] = explode('-', $monthParam);
            $targetYear  = (int) $y;
            $targetMonth = (int) $m;
        } else {
            $targetYear  = (int) (new \DateTime())->format('Y');
            $targetMonth = (int) (new \DateTime())->format('m');
            $monthParam  = sprintf('%04d-%02d', $targetYear, $targetMonth);
        }

        $startOfTargetMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth)))->setTime(0,0,0);
        $endOfTargetMonth   = $startOfTargetMonth->modify('last day of this month')->setTime(23,59,59);

        // Récup toutes les commandes PAO FAIT sur le mois
        $paoDoneRows = $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('c.id, c.dateCommande')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('start', new \DateTime($startOfTargetMonth->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfTargetMonth->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getArrayResult();

        // Agrégation par jour (en PHP pour portabilité)
        $daysInMonth = (int) $startOfTargetMonth->format('t');
        $byDay = array_fill(1, $daysInMonth, 0);

        foreach ($paoDoneRows as $row) {
            $d = \DateTime::createFromFormat('Y-m-d H:i:s', (new \DateTime($row['dateCommande']['date'] ?? $row['dateCommande']))->format('Y-m-d H:i:s'));
            if (!$d) { $d = new \DateTime($row['dateCommande']); }
            $day = (int) $d->format('j');
            if ($day >= 1 && $day <= $daysInMonth) {
                $byDay[$day]++;
            }
        }

        // Labels & data pour Chart.js
        $chartLabels = [];
        $chartValues = [];
        for ($i = 1; $i <= $daysInMonth; $i++) {
            $chartLabels[] = sprintf('%02d', $i);
            $chartValues[] = $byDay[$i];
        }

        return $this->render('pao/dashboard.html.twig', [
            // cartes "du jour"
            'workInProgressToday'   => $workInProgressToday,
            'workDoneToday'         => $workDoneToday,
            'workModificationToday' => $workModificationToday,

            // carte "semaine"
            'worksThisWeekCount'    => $worksThisWeekCount,

            // graph mois
            'selectedMonth'         => $monthParam,     // 'YYYY-MM' pour le <input type="month">
            'chartLabels'           => $chartLabels,    // ['01','02',...]
            'chartValues'           => $chartValues,    // [0,1,2,...]
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
        yield MenuItem::linkToCrud('PAO à traiter', 'fa fa-pencil-ruler', Commande::class)
            ->setController(PaoCommandeCrudController::class);
    }
}
