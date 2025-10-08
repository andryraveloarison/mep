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
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('Utilisateur non connecté.');
        }

        // ===== Fenêtres (LOCAL server TZ) — intervalle semi-ouvert [start, next) =====
        $startOfDay       = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
        $startOfTomorrow  = $startOfDay->modify('+1 day');

        // Semaine : lundi 00:00 -> lundi prochain 00:00
        $startOfWeek      = (new \DateTimeImmutable('monday this week'))->setTime(0, 0, 0);
        $startOfNextWeek  = $startOfWeek->modify('+1 week');

        // Statuts pris en compte
        $paoStatuses = [
            Commande::STATUT_PAO_ATTENTE,
            Commande::STATUT_PAO_EN_COURS,
            Commande::STATUT_PAO_FAIT,
            Commande::STATUT_PAO_MODIFICATION,
        ];

        // ===== Tous les travaux du jour (tous statuts) =====
        $worksPendingCount = (int)  $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.dateCommande >= :start AND c.dateCommande < :next')
            ->setParameter('status', Commande::STATUT_PAO_ATTENTE)
            ->setParameter('pao', $user)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('next',  new \DateTime($startOfTomorrow->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();

            
        // Détail par statut (si tu affiches encore les 3 cartes)
        $workInProgressToday =(int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.updatedAt >= :start AND c.updatedAt < :next')
            ->setParameter('status', Commande::STATUT_PAO_EN_COURS)
            ->setParameter('pao', $user)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('next',  new \DateTime($startOfTomorrow->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();

        $workDoneToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.updatedAt >= :start AND c.updatedAt < :next')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('pao', $user)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('next',  new \DateTime($startOfTomorrow->format('Y-m-d H:i:s')))
            ->getQuery()
            ->getSingleScalarResult();


        $workModificationToday = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.updatedAt >= :start AND c.updatedAt < :next')
            ->setParameter('status', Commande::STATUT_PAO_MODIFICATION)
            ->setParameter('pao', $user)
            ->setParameter('start', new \DateTime($startOfDay->format('Y-m-d H:i:s')))
            ->setParameter('next',  new \DateTime($startOfTomorrow->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();


        // ===== Travaux de la semaine (tous statuts) =====
        $worksThisWeekCount = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao IN (:statuses)')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.dateCommande >= :wstart AND c.dateCommande < :wnext')
            ->setParameter('statuses', $paoStatuses)
            ->setParameter('pao', $user)
            ->setParameter('wstart', new \DateTime($startOfWeek->format('Y-m-d H:i:s')))
            ->setParameter('wnext',  new \DateTime($startOfNextWeek->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();

        $worksDoneThisWeekCount = (int) $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.statutPao = :status')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.dateCommande >= :wstart AND c.dateCommande < :wnext')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('pao', $user)
            ->setParameter('wstart', new \DateTime($startOfWeek->format('Y-m-d H:i:s')))
            ->setParameter('wnext',  new \DateTime($startOfNextWeek->format('Y-m-d H:i:s')))
            ->getQuery()->getSingleScalarResult();


        // ===== Graph “PAO FAIT” par jour sur un mois (?month=YYYY-MM) =====
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

        $startOfTargetMonth = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth)))->setTime(0, 0, 0);
        $startOfNextMonth   = $startOfTargetMonth->modify('+1 month');

        $paoDoneRows = $this->commandeRepository
            ->createQueryBuilder('c')
            ->select('c.id, c.updatedAt')
            ->where('c.statutPao = :status')
            ->andWhere('c.pao = :pao')
            ->andWhere('c.updatedAt >= :start AND c.updatedAt < :next')
            ->setParameter('status', Commande::STATUT_PAO_FAIT)
            ->setParameter('pao', $user)
            ->setParameter('start', new \DateTime($startOfTargetMonth->format('Y-m-d H:i:s')))
            ->setParameter('next',  new \DateTime($startOfNextMonth->format('Y-m-d H:i:s')))
            ->getQuery()->getArrayResult();

        // Agrégation par jour
        $daysInMonth = (int) $startOfTargetMonth->format('t');
        $byDay = array_fill(1, $daysInMonth, 0);

        foreach ($paoDoneRows as $row) {
            $raw = $row['updatedAt'] ?? null;
            if ($raw instanceof \DateTimeInterface) {
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $raw->format('Y-m-d H:i:s'));
            } else {
                $dt = new \DateTime(is_array($raw) && isset($raw['date']) ? $raw['date'] : (string)$raw);
            }
            if (!$dt) continue;
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
            'worksPendingCount'        => $worksPendingCount,
            'workInProgressToday'    => $workInProgressToday,
            'workDoneToday'          => $workDoneToday,
            'workModificationToday'  => $workModificationToday,
            'worksThisWeekCount'     => $worksThisWeekCount,
             'worksDoneThisWeekCount'     => $worksDoneThisWeekCount,
            'selectedMonth'          => $monthParam,
            'chartLabels'            => $chartLabels,
            'chartValues'            => $chartValues,
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
