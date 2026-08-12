<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Projection\CatOptionsPageContext;
use Piwigo\Admin\Request\CatOptionsRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/cat_options.php (page slug "cat_options") -- a flat
 * page, pure delegate. The page's own bulk comments/visible/status/
 * representative toggling already calls
 * Piwigo\Admin\Category\CategoryAdminService::setCategoryOption()
 * (consolidating 8 switch-case branches into one parameterized method).
 */
final readonly class CatOptionsPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private CurrentTemplate $currentTemplate,
        private CategoryAdminService $categoryAdminService,
        private ActivityService $activityService,
        private CategoryService $categoryService,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
    ) {}

    public function render(): void
    {
        $conn = DbConnection::build();
        $template = $this->currentTemplate->get();

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $catOptionsRequest = CatOptionsRequest::fromGlobals($this->inputValidator);

        if ($catOptionsRequest->isSubmitted) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        if ($catOptionsRequest->isFalsify and $catOptionsRequest->catTrue !== []) {
            $this->categoryAdminService
                ->setCategoryOption($catOptionsRequest->catTrue, $catOptionsRequest->sectionRaw, false, $this->activityService);
        } elseif ($catOptionsRequest->isTrueify and $catOptionsRequest->catFalse !== []) {
            $this->categoryAdminService
                ->setCategoryOption($catOptionsRequest->catFalse, $catOptionsRequest->sectionRaw, true, $this->activityService);
        }

        $section = $catOptionsRequest->section;
        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=cat_options&amp;section=';

        // CoreTabs::setContext() must be called with linkStart before
        // Tabsheet renders this page's tab strip, or the tabs render with
        // broken relative hrefs.
        $this->coreTabs->setContext(new CoreTabsContext(linkStart: $this->urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('cat_options');
        $tabsheet->select($section, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        // for each section, categories in the multiselect field can be :
        //
        // - true : commentable for comment section
        // - false : un-commentable for comment section
        // - NA : (not applicable) for virtual categories
        //
        // for true and false status, we associates an array of category ids,
        // function display_select_categories will use the given CSS class for each
        // option
        [$l_section, $l_true, $l_false] = match ($section) {
            'comments' => [
                $this->lang->t('Authorize users to add comments on selected albums'),
                $this->lang->t('Authorized'),
                $this->lang->t('Forbidden'),
            ],
            'visible' => [
                $this->lang->t('Lock albums'),
                $this->lang->t('Unlocked'),
                $this->lang->t('Locked'),
            ],
            'status' => [
                $this->lang->t('Manage authorizations for selected albums'),
                $this->lang->t('Public'),
                $this->lang->t('Private'),
            ],
            // 'representative' is the only value that can still reach here: $section
            // is already restricted to comments/visible/status/representative by
            // the in_array() guard above.
            default => [
                $this->lang->t('Representative'),
                $this->lang->t('singly represented'),
                $this->lang->t('randomly represented'),
            ],
        };
        $categoryService = $this->categoryService;
        $htmlService = $this->htmlRenderer;
        if ($section === 'comments') {
            $categoryOptionTrue = $categoryService->displaySelectByCommentable(true, $htmlService);
            $categoryOptionFalse = $categoryService->displaySelectByCommentable(false, $htmlService);
        } elseif ($section === 'visible') {
            $categoryOptionTrue = $categoryService->displaySelectByVisible(true, $htmlService);
            $categoryOptionFalse = $categoryService->displaySelectByVisible(false, $htmlService);
        } elseif ($section === 'status') {
            $categoryOptionTrue = $categoryService->displaySelectByStatus('public', $htmlService);
            $categoryOptionFalse = $categoryService->displaySelectByStatus('private', $htmlService);
        } else {
            // 'representative' is the only value that can still reach here --
            // same guard as the label match() above.
            $categoryOptionTrue = $categoryService->displaySelectByRepresentativePresence(true, $htmlService);
            $categoryOptionFalse = $categoryService->displaySelectByRepresentativePresence(false, $htmlService);
        }
        $template->assignContext(new CatOptionsPageContext(
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=cat_options',
            formAction: $base_url . $section,
            section: $l_section,
            catOptionsTrueLabel: $l_true,
            catOptionsFalseLabel: $l_false,
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            adminPageTitle: $this->lang->t('Properties of abums'),
            categoryOptionTrue: $categoryOptionTrue,
            categoryOptionFalse: $categoryOptionFalse,
        ));

        $template->assignVarFromTemplate('DOUBLE_SELECT', 'double_select.latte');
        $template->assignVarFromTemplate('ADMIN_CONTENT', 'cat_options.latte');
    }
}
