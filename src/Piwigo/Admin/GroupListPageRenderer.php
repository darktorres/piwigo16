<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Latte\Runtime\Html;
use Piwigo\Admin\Projection\GroupListView;
use Piwigo\Admin\Request\GroupListActionRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\Projection\GroupListRow;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/group_list.php (page slug "group_list").
 */
final readonly class GroupListPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private Translator $translator,
        private CurrentTemplate $currentTemplate,
        private HtmlRenderingInterface $htmlRenderer,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    public function render(): AdminPageResult
    {
        $template = $this->currentTemplate->get();

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('groups');
        $tabsheet->select('group_list', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        if (GroupListActionRequest::fromGlobals()->requiresCsrfCheck) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $group_repo = TypedRepository::narrow($this->entityManager->getRepository(GroupEntity::class), GroupRepository::class);
        $groups = $group_repo->findAllBasic();

        $group_counter = 0;
        $tpl_groups = [];

        foreach ($groups as $row) {
            $members = $group_repo->findMemberUsernames($row->id);

            $tpl_groups[] = new GroupListRow(
                id: $row->id,
                name: $row->name,
                isDefault: $row->isDefault,
                members: $this->translator->plural('%d member', '%d members', count($members)),
            );

            $group_counter++;
        }

        $adminContent = $this->renderer->render(new GroupListView(
            pwgToken: $this->csrfService
                ->getToken(),
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['groups', 'users']),
            groups: array_map(static fn (GroupListRow $group): array => $group->toArray(), $tpl_groups),
            rootUrl: $this->urlService->getRootUrl(),
            colorscheme: $template->themeConf('colorscheme'),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: new Html($this->lang->t('Groups') . ' <span class="badge-number">' . $group_counter . '</span>'),
        );
    }
}
