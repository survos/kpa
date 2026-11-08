<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Survos\EzBundle\Controller\AbstractEzDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin', allowedControllers: [SongCrudController::class, FileAssetCrudController::class])]
final class DashboardController extends AbstractEzDashboardController
{
    public function index(): Response
    {
        return $this->redirect($this->container->get(AdminUrlGenerator::class)
            ->setController(SongCrudController::class)->setAction('index')->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Kid Pan Alley');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToRoute('Back to KPA', 'fa fa-home', 'app_homepage');
        yield MenuItem::linkTo(SongCrudController::class, 'Songs', 'fa fa-music');
        yield MenuItem::linkTo(FileAssetCrudController::class, 'Files', 'fa fa-file');
    }
}
