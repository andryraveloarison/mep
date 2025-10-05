<?php
namespace App\Controller\Pao;

use App\Entity\Commande;
use App\Repository\CommandeProduitRepository;
use App\Repository\CommandeRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PaoDashboardController extends AbstractDashboardController
{
    private CommandeRepository $commandeRepository;
    private CommandeProduitRepository $commandeProduitRepository;
    private RequestStack $requestStack;

    public function __construct(
        CommandeRepository $commandeRepository,
        CommandeProduitRepository $commandeProduitRepository,
        RequestStack $requestStack
    ) {
        $this->commandeRepository = $commandeRepository;
        $this->commandeProduitRepository = $commandeProduitRepository;
        $this->requestStack = $requestStack;
    }

    #[Route('/pao', name: 'pao_dashboard')]
    public function index(): Response
    {
        // ===== Fenêtres temporelles "aujourd'hui" =====
        $today      = new \DateTimeImmutable('today');
        $startOfDay = $today->setTime(0, 0, 0);
        $endOfDay   = $today->setTime(23, 59, 59);

        // ===== Fenêtre "semaine" (lundi -> dimanche) =====
        $startOfWeek = (new \DateTimeImmutable('monday this week'))->setTime(0, 0, 0);
        $endOfWeek   = (new \DateTimeImmutable('sunday this week'))->setTime(23, 59, 59);

        // ===== Travaux du jour : EN COURS =====
        $workInProgressToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_EN_COURS)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();

        // ===== Travaux du jour : FAIT =====
        $workDoneToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();

        // ===== Travaux du jour : MODIFICATION =====
        $workModificationToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_MODIFICATION)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfDay->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();

        // ===== Travaux de la semaine = EN_COURS + FAIT + MODIFICATION =====
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
            ->getQuery()->getSingleScalarResult();

        // ===== Graph “PAO FAIT” par jour sur un mois (filtre ?month=YYYY-MM) =====
        $request    = $this->requestStack->getCurrentRequest();
        $monthParam = $request?->query->get('month');

        if (is_string($monthParam) && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            [$y, $m] = explode('-', $monthParam);
            $targetYear  = (int) $y;
            $targetMonth = (int) $m;
        } else {
            $now         = new \DateTimeImmutable('now');
            $targetYear  = (int) $now->format('Y');
            $targetMonth = (int) $now->format('m');
            $monthParam  = sprintf('%04d-%02d', $targetYear, $targetMonth);
        }

        $startOfTargetMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth)))->setTime(0,0,0);
        $endOfTargetMonth   = $startOfTargetMonth->modify('last day of this month')->setTime(23,59,59);

        // Récup commandes PAO FAIT sur le mois
        $paoDoneRows = $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('c.id, c.dateCommande')
            ->where('c.statutPao = :status')
            ->andWhere('c.dateCommande BETWEEN :start AND :end')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('start', new \DateTime($startOfTargetMonth->format('Y-m-d H:i:s')))
            ->setParameter('end',   new \DateTime($endOfTargetMonth->format('Y-m-d H:i:s')))
            ->getQuery()->getArrayResult();

        // Agrégation par jour en PHP
        $daysInMonth = (int) $startOfTargetMonth->format('t');
        $byDay = array_fill(1, $daysInMonth, 0);

        foreach ($paoDoneRows as $row) {
            $raw = $row['dateCommande'] ?? null;
            if ($raw instanceof \DateTimeInterface) {
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $raw->format('Y-m-d H:i:s'));
            } else {
                $dt = new \DateTime(is_array($raw) && isset($raw['date']) ? $raw['date'] : (string)$raw);
            }
            if (!$dt || is_nan($dt->getTimestamp())) continue;

            $day = (int) $dt->format('j');
            if ($day >= 1 && $day <= $daysInMonth) $byDay[$day]++;
        }

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
            'selectedMonth'         => $monthParam,     // 'YYYY-MM'
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
        yield MenuItem::linktoDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkToCrud('PAO à traiter', 'fa fa-pencil-ruler', Commande::class)
            ->setController(PaoCommandeCrudController::class);
    }
}
