<?php

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

use App\Models\Group;

$page = new AdminPage();

$gname = '';
if (! empty($_REQUEST['groupname'])) {
    $gname = $_REQUEST['groupname'];
}

$groupcount = Group::getGroupsCount($gname, 0);

$offset = $_REQUEST['offset'] ?? 0;
$groupname = ! empty($_REQUEST['groupname']) ? $_REQUEST['groupname'] : '';

$page->smarty->assign('groupname', $groupname);
$page->smarty->assign('pagertotalitems', $groupcount);
$page->smarty->assign('pageroffset', $offset);
$page->smarty->assign('pageritemsperpage', ITEMS_PER_PAGE);
$page->smarty->assign('pagerquerysuffix', '#results');

$groupsearch = $gname != '' ? 'groupname='.$gname.'&amp;' : '';
$page->smarty->assign('pagerquerybase', WWW_TOP.'/group-list-inactive.php?'.$groupsearch.'offset=');
$pager = $page->smarty->fetch('pager.tpl');
$page->smarty->assign('pager', $pager);

$grouplist = Group::getGroupsRange($offset, ITEMS_PER_PAGE, $gname);

$page->smarty->assign('grouplist', $grouplist);

$page->title = 'Group List';

$page->content = $page->smarty->fetch('group-list.tpl');
$page->render();
