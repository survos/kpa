<?php

namespace App\Menu;

use App\Controller\Admin\MeiliDashboardController;
use App\Entity\FileAsset;
use App\Entity\Song;
use App\Entity\Video;
use Survos\FieldBundle\Enum\Purpose;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Survos\MeiliBundle\Controller\MeiliAdminController;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\TablerBundle\Service\ContextService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

//#[AsEventListener(event: KnpMenuEvent::SIDEBAR_MENU, method: 'appSidebarMenu')]
//#[AsEventListener(event: KnpMenuEvent::FOOTER_MENU, method: 'footerMenu')]
//#[AsEventListener(event: KnpMenuEvent::AUTH_MENU, method: 'ourAuthMenu')]
//#[AsEventListener(event: KnpMenuEvent::PAGE_MENU, method: 'pageMenu')]
//#[AsEventListener(event: KnpMenuEvent::PAGE_MENU_EVENT, method: 'coreMenu')]
final class AppMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private ContextService                               $contextService,
        private MeiliService                                 $meiliService,
        private EntityMetaRegistry                           $entityMetaRegistry,
        private RouteMetaRegistry                            $routeMetaRegistry,
//        private DatatableService                                       $datatableService,
        // why is autowire required?
//        #[Autowire(service: 'api_meili_service')] private MeiliService $meiliService,
        private ?AuthorizationCheckerInterface               $authorizationChecker = null,
        private ?Security                                    $security=null,
    )
    {
//        $this->setAuthorizationChecker($this->authorizationChecker);
    }

    public function supports(MenuEvent $event): bool
    {
        return true;
    }

    public function ourAuthMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, MeiliDashboardController::DASHBOARD_ROUTE, label: "Dashboard");

        if ($this->security?->getUser()) {
            $this->add($menu, 'app_logout', label: 'Logout', icon: 'logout');

            return;
        }

        $this->add($menu, 'app_login', label: 'Login', icon: 'login');
        $this->add($menu, 'app_register', label: 'Register', icon: 'user-plus');
    }

    public function pageMenu(MenuEvent $event): void
    {
        if (!$this->supports($event)) {
            return;
        }
        $menu = $event->getMenu();

        if ($columnsJson = $event->getOption('columns')) {
            $columns = json_decode($columnsJson);
            foreach ($columns as $column) {
                $this->add($menu, 'app_homepage', ['field' => $column->name], label: $column->name);
            }
        }
        $video = $event->getOption('video');
        if ($video) {
            $this->add($menu, 'video_show', $video);
        }
    }

    public function footerMenu(MenuEvent $event): void
    {
        if (!$this->supports($event)) {
            return;
        }
        $menu = $event->getMenu();

//        $subMenu = $this->addSubmenu($menu, 'songs');
//
//        $this->add($subMenu, 'song_index');
//        $this->add($subMenu, 'song_browse');

        $theme = $this->contextService->getOption('theme');
        $this->add($menu, 'app_homepage');
            $this->add($menu, 'app_load_lyrics');
        if ( ($this->env==='dev') && $this->security->isGranted('ROLE_ADMIN')) {
            $this->add($menu, 'survos_commands');
        }
        // it should be possible to do this in twig, not here.
        $this->add($menu, id: 'copyright',

            label: 'Data Copyright &copy; <b>Kid Pan Alley</b> All rights reserved.');
    }


    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
    {
        if (!$this->supports($event)) {
            return;
        }
        $menu = $event->getMenu();
        $this->add($menu, 'app_homepage');
        $this->add($menu, 'admin');
//        $this->add($menu, 'survos_meili_admin');
        $this->add($menu, MeiliDashboardController::MEILI_ROUTE, label: "EZ");

        $contentMenu = $this->addSubmenu($menu, 'Content');
        foreach ($this->entityMetaRegistry->getByGroup('Catalog') as $entityMeta) {
            $route = $this->routeMetaRegistry->forEntityPurpose($entityMeta->class, Purpose::List);
            if ($route === null) {
                continue;
            }

            $this->add($contentMenu, $route->name, label: $entityMeta->label);
        }
//        $this->addMenuItem($menu, ['route' => 'song_index', 'label' => "Songs", 'icon' => 'fas fa-home']);
//        $this->addMenuItem($menu, ['route' => 'song_browse', 'label' => "Song Search", 'icon' => 'fas fa-search']);
//        $subMenu = $this->addSubmenu($menu, 'songs');
//        $subMenu->setExtra('btn', 'btn btn-danger');
//        dump($subMenu);
//        // either a button on a navlink
//        $subMenu->setLinkAttribute('class', 'nav-link');

//        foreach (['Song','Video'] as $shortClass) {
//        }

        //
        if (
//            $this->isEnv('dev') ||
        $this->isGranted('ROLE_ADMIN')) {
//            $this->add($menu, 'survos_meili_admin', external: true);
            $this->add($menu, 'app_publish');
            $subMenu = $this->addSubmenu($menu, '@commands');
            if ($this->env === 'dev') {
                foreach ([Song::class, Video::class, FileAsset::class] as $class) {
                    $this->add($subMenu, 'survos_command', [
                        'commandName' => 'grid:index',
                        'class' => $class
                    ],
                        label: 'grid:index ' . $class
                    );
                }
                $this->add($subMenu, 'survos_commands');
            }

            $subMenu = $this->addSubmenu($menu, '@old');
            $this->add($subMenu, 'song_index', label: 'Songs (html)');
//        $this->add($subMenu, 'song_browse', label: 'Songs-Meili');
//        $this->add($subMenu, 'song_browse_with_doctrine', label: 'Songs-Doctine');
            $this->add($subMenu, 'video_browse', label: 'Youtube Videos');
//        $this->add($menu, 'video_index'); // in-memory
//        $this->add($subMenu, 'song_browse');

//        $this->addMenuItem($menu, ['route' => 'video_index', 'label' => "Videos", 'icon' => 'fas fa-home']);
//        $this->addMenuItem($menu, ['route' => 'video_index', 'label' => "Videos (API)", 'icon' => 'fas fa-sync']);
        }

        $this->add($menu, 'survos_commands', label: "Commands");

        if ($this->env === 'dev' || $this->security->isGranted('ROLE_ADMIN')) {
            $subMenu = $this->addSubmenu($menu, 'admin');
            $this->add($subMenu, 'survos_commands', label: "Commands");
        }

//        $nestedMenu = $this->addMenuItem($menu, ['label' => 'Credits']);
//        foreach (['bundles', 'javascript'] as $type) {
//            // $this->addMenuItem($nestedMenu, ['route' => 'survos_base_credits', 'rp' => ['type' => $type], 'label' => ucfirst($type)]);
//            $this->addMenuItem($nestedMenu, ['uri' => "#type" , 'label' => ucfirst($type)]);
//        }
    }

    private function isGranted(string $role): bool
    {
        return $this->security->isGranted($role);
    }

}
