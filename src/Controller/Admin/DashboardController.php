<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AnalysisSnapshot;
use App\Entity\Company;
use App\Entity\CompetitorSnapshot;
use App\Entity\DemandSignalSubscription;
use App\Entity\DiscoveryProfile;
use App\Entity\EmailBlacklist;
use App\Entity\EmailLog;
use App\Entity\EmailTemplate;
use App\Entity\Lead;
use App\Entity\MarketWatchFilter;
use App\Entity\MonitoredDomain;
use App\Entity\MonitoredDomainSubscription;
use App\Entity\Offer;
use App\Entity\Proposal;
use App\Entity\User;
use App\Entity\UserCompanyNote;
use App\Entity\UserEmailTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

#[AdminDashboard(routes: ['index' => ['routePath' => '/admin', 'routeName' => 'admin']])]
class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Web Analyzer')
            ->setFaviconPath('favicon.ico')
            ->setTranslationDomain('admin')
            ->setLocales(['cs', 'en'])
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Lead Pipeline');
        yield MenuItem::linkToCrud('Discovery Profiles', 'fa fa-search-plus', DiscoveryProfile::class);
        yield MenuItem::linkToCrud('Leads', 'fa fa-user-plus', Lead::class);
        yield MenuItem::linkToCrud('Proposals', 'fa fa-file-lines', Proposal::class);
        yield MenuItem::linkToCrud('Offers', 'fa fa-envelope', Offer::class);
        yield MenuItem::linkToCrud('Snapshots', 'fa fa-camera', AnalysisSnapshot::class);

        yield MenuItem::section('Firmy');
        yield MenuItem::linkToCrud('Companies', 'fa fa-building', Company::class);
        yield MenuItem::linkToCrud('Poznámky', 'fa fa-sticky-note', UserCompanyNote::class);

        yield MenuItem::section('Email');
        yield MenuItem::linkToCrud('Moje šablony', 'fa fa-file-alt', UserEmailTemplate::class);
        yield MenuItem::linkToCrud('Systémové šablony', 'fa fa-file-code', EmailTemplate::class);
        yield MenuItem::linkToCrud('Email Log', 'fa fa-paper-plane', EmailLog::class);
        yield MenuItem::linkToCrud('Blacklist', 'fa fa-ban', EmailBlacklist::class);

        yield MenuItem::section('Sledování konkurence');
        yield MenuItem::linkToCrud('Monitorované domény', 'fa fa-eye', MonitoredDomain::class)
            ->setPermission(User::PERMISSION_COMPETITORS_MANAGE);
        yield MenuItem::linkToCrud('Moje odběry', 'fa fa-bell', MonitoredDomainSubscription::class);
        yield MenuItem::linkToCrud('Snapshoty konkurence', 'fa fa-binoculars', CompetitorSnapshot::class);

        yield MenuItem::section('Sledování poptávek');
        yield MenuItem::linkToCrud('Filtry sledování', 'fa fa-filter', MarketWatchFilter::class);
        yield MenuItem::linkToCrud('Moje odběry', 'fa fa-rss', DemandSignalSubscription::class);

        yield MenuItem::section('Nastavení');
        yield MenuItem::linkToCrud('Uživatelé', 'fa fa-users', User::class)
            ->setPermission(User::PERMISSION_USERS_READ);

        yield MenuItem::section('');
        yield MenuItem::linkToLogout('Logout', 'fa fa-sign-out');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        /** @var User $user */
        $userMenu = parent::configureUserMenu($user)
            ->setName($user->getName())
            ->setGravatarEmail($user->getEmail() ?? '');

        if ($user->isAdmin()) {
            $userMenu->addMenuItems([
                MenuItem::linkToCrud('My Profile', 'fa fa-user', User::class)
                    ->setAction(Crud::PAGE_EDIT)
                    ->setEntityId($user->getId()?->toRfc4122()),
            ]);
        }

        return $userMenu;
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateFormat('d.m.Y')
            ->setDateTimeFormat('d.m.Y H:i:s')
            ->setTimezone('Europe/Prague')
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureActions(): Actions
    {
        return Actions::new()
            ->add(Crud::PAGE_INDEX, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DELETE)
            ->add(Crud::PAGE_DETAIL, Action::EDIT)
            ->add(Crud::PAGE_DETAIL, Action::DELETE)
            ->add(Crud::PAGE_DETAIL, Action::INDEX)
            ->add(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
            ->add(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_RETURN)
            ->add(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('css/admin.css');
    }
}
