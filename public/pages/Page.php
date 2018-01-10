<?php

use nntmux\Menu;
use App\Models\User;
use nntmux\Category;
use nntmux\Contents;
use App\Models\Settings;
use App\Models\Forumpost;

/**
 * This class represents every normal user page in the site.
 */
class Page extends BasePage
{
    /**
     * Default constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();

        // Tell Smarty which directories to use for templates
        $this->smarty->setTemplateDir(
            [
                'user'        => NN_THEMES.$this->theme,
                'shared'    => NN_THEMES.'shared',
                'default'    => NN_THEMES.'Gentele',
            ]
        );

        $role = User::ROLE_GUEST;
        if (! empty($this->userdata)) {
            $role = $this->userdata['user_roles_id'];
        }

        $content = new Contents(['Settings' => $this->settings]);
        $menu = new Menu($this->settings);
        $this->smarty->assign('menulist', $menu->get($role, $this->serverurl));
        $this->smarty->assign('usefulcontentlist', $content->getForMenuByTypeAndRole(Contents::TYPEUSEFUL, $role));
        $this->smarty->assign('articlecontentlist', $content->getForMenuByTypeAndRole(Contents::TYPEARTICLE, $role));
        if ($this->userdata !== null) {
            $this->smarty->assign('recentforumpostslist', Forumpost::getPosts(Settings::settingValue('..showrecentforumposts')));
        }

        $this->smarty->assign('main_menu', $this->smarty->fetch('mainmenu.tpl'));
        $this->smarty->assign('useful_menu', $this->smarty->fetch('usefullinksmenu.tpl'));
        $this->smarty->assign('article_menu', $this->smarty->fetch('articlesmenu.tpl'));

        $category = new Category(['Settings' => $content->pdo]);
        if (! empty($this->userdata)) {
            $parentcatlist = $category->getForMenu($this->userdata['categoryexclusions'], $this->userdata['rolecategoryexclusions']);
        } else {
            $parentcatlist = $category->getForMenu();
        }

        $this->smarty->assign('parentcatlist', $parentcatlist);
        $this->smarty->assign('catClass', $category);
        $searchStr = '';
        if ($this->page == 'search' && isset($_REQUEST['id'])) {
            $searchStr = (string) $_REQUEST['id'];
        }
        $this->smarty->assign('header_menu_search', $searchStr);

        if (isset($_REQUEST['t'])) {
            $this->smarty->assign('header_menu_cat', $_REQUEST['t']);
        } else {
            $this->smarty->assign('header_menu_cat', '');
        }
        $header_menu = $this->smarty->fetch('headermenu.tpl');
        $this->smarty->assign('header_menu', $header_menu);
    }

    /**
     * Output the page.
     */
    public function render()
    {
        $this->smarty->assign('page', $this);
        $this->page_template = 'basepage.tpl';

        parent::render();
    }
}
