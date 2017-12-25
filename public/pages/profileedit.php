<?php

use App\Models\User;
use App\Models\UserExcludedCategory;
use nntmux\Users;
use nntmux\NZBGet;
use nntmux\SABnzbd;
use nntmux\Category;
use App\Models\Settings;
use nntmux\utility\Utility;

$category = new Category;
$sab = new SABnzbd($page);
$nzbGet = new NZBGet($page);
$page->users = new Users();

if (! User::isLoggedIn()) {
    $page->show403();
}

$action = $_REQUEST['action'] ?? 'view';

$userid = User::currentUserId();
$data = User::getById($userid);
if (! $data) {
    $page->show404();
}

$errorStr = '';

switch ($action) {
    case 'newapikey':
        User::updateRssKey($userid);
        header('Location: profileedit');
        break;
    case 'clearcookies':
        $sab->unsetCookie();
        header('Location: profileedit');
        break;
    case 'submit':

        $data['email'] = $_POST['email'];
        if (isset($_POST['saburl']) && ! Utility::endsWith($_POST['saburl'], '/') && strlen(trim($_POST['saburl'])) > 0) {
            $_POST['saburl'] .= '/';
        }

        if ($_POST['password'] !== '' && $_POST['password'] !== $_POST['confirmpassword']) {
            $errorStr = 'Password Mismatch';
        } elseif ($_POST['password'] !== '' && ! User::isValidPassword($_POST['password'])) {
            $errorStr = 'Your password must be longer than five characters.';
        } elseif (! empty($_POST['nzbgeturl']) && $nzbGet->verifyURL($_POST['nzbgeturl']) === false) {
            $errorStr = 'The NZBGet URL you entered is invalid!';
        } elseif (! User::isValidEmail($_POST['email'])) {
            $errorStr = 'Your email is not a valid format.';
        } else {
            $res = User::getByEmail($_POST['email']);
            if ($res && (int) $res['id'] !== (int) $userid) {
                $errorStr = 'Sorry, the email is already in use.';
            } elseif ((empty($_POST['saburl']) && ! empty($_POST['sabapikey'])) || (! empty($_POST['saburl']) && empty($_POST['sabapikey']))) {
                $errorStr = 'Insert a SABnzdb URL and API key.';
            } else {
                if (isset($_POST['sabsetting']) && $_POST['sabsetting'] == 2) {
                    $sab->setCookie($_POST['saburl'], $_POST['sabapikey'], $_POST['sabpriority'], $_POST['sabapikeytype']);
                    $_POST['saburl'] = $_POST['sabapikey'] = $_POST['sabpriority'] = $_POST['sabapikeytype'] = false;
                }

                User::updateUser(
                    $userid,
                    $data['username'],
                    $_POST['email'],
                    $data['grabs'],
                    $data['user_roles_id'],
                    $data['notes'],
                    $data['invites'],
                    (isset($_POST['movieview']) ? 1 : 0),
                    (isset($_POST['musicview']) ? 1 : 0),
                    (isset($_POST['gameview']) ? 1 : 0),
                    (isset($_POST['xxxview']) ? 1 : 0),
                    (isset($_POST['consoleview']) ? 1 : 0),
                    (isset($_POST['bookview']) ? 1 : 0),
                    $_POST['queuetypeids'],
                    $_POST['nzbgeturl'] ?? '',
                    $_POST['nzbgetusername'] ?? '',
                    $_POST['nzbgetpassword'] ?? '',
                    (isset($_POST['saburl']) ? Utility::trailingSlash($_POST['saburl']) : ''),
                    $_POST['sabapikey'] ?? '',
                    $_POST['sabpriority'] ?? '',
                    $_POST['sabapikeytype'] ?? '',
                    $_POST['nzbvortex_server_url'] ?? '',
                    $_POST['nzbvortex_api_key'] ?? '',
                    $_POST['cp_url'] ?? '',
                    $_POST['cp_api'] ?? '',
                    (int) Settings::settingValue('site.main.userselstyle') === 1 ? $_POST['style'] : 'None'
                );

                $_POST['exccat'] = (! isset($_POST['exccat']) || ! is_array($_POST['exccat'])) ? [] : $_POST['exccat'];
                UserExcludedCategory::addCategoryExclusions($userid, $_POST['exccat']);

                if ($_POST['password'] !== '') {
                    User::updatePassword($userid, $_POST['password']);
                }

                header('Location:'.WWW_TOP.'/profile');
                die();
            }
        }
        break;

    case 'view':
    default:
        break;
}
if ((int) Settings::settingValue('site.main.userselstyle') === 1) {
    // Get the list of themes.
    $page->smarty->assign('themelist', Utility::getThemesList());
}

$page->smarty->assign('error', $errorStr);
$page->smarty->assign('user', $data);
$page->smarty->assign('userexccat', User::getCategoryExclusion($userid));

$page->smarty->assign('saburl_selected', $sab->url);
$page->smarty->assign('sabapikey_selected', $sab->apikey);

$page->smarty->assign('sabapikeytype_ids', [SABnzbd::API_TYPE_NZB, SABnzbd::API_TYPE_FULL]);
$page->smarty->assign('sabapikeytype_names', ['Nzb Api Key', 'Full Api Key']);
$page->smarty->assign('sabapikeytype_selected', ($sab->apikeytype === '') ? SABnzbd::API_TYPE_NZB : $sab->apikeytype);

$page->smarty->assign('sabpriority_ids', [SABnzbd::PRIORITY_FORCE, SABnzbd::PRIORITY_HIGH, SABnzbd::PRIORITY_NORMAL, SABnzbd::PRIORITY_LOW, SABnzbd::PRIORITY_PAUSED]);
$page->smarty->assign('sabpriority_names', ['Force', 'High', 'Normal', 'Low', 'Paused']);
$page->smarty->assign('sabpriority_selected', ($sab->priority === '') ? SABnzbd::PRIORITY_NORMAL : $sab->priority);

$page->smarty->assign('sabsetting_ids', [1, 2]);
$page->smarty->assign('sabsetting_names', ['Site', 'Cookie']);
$page->smarty->assign('sabsetting_selected', ($sab->checkCookie() === true ? 2 : 1));

switch ($sab->integrated) {
    case SABnzbd::INTEGRATION_TYPE_USER:
        $queueTypes = ['None', 'Sabnzbd', 'NZBGet'];
        $queueTypeIDs = [Users::QUEUE_NONE, Users::QUEUE_SABNZBD, Users::QUEUE_NZBGET];
        break;
    case SABnzbd::INTEGRATION_TYPE_SITEWIDE:
    case SABnzbd::INTEGRATION_TYPE_NONE:
        $queueTypes = ['None', 'NZBGet'];
        $queueTypeIDs = [Users::QUEUE_NONE, Users::QUEUE_NZBGET];
        break;
}

$page->smarty->assign(
    [
        'queuetypes'   => $queueTypes,
        'queuetypeids' => $queueTypeIDs,
    ]
);

$page->meta_title = 'Edit User Profile';
$page->meta_keywords = 'edit,profile,user,details';
$page->meta_description = 'Edit User Profile for '.$data['username'];

$page->smarty->assign('cp_url_selected', $data['cp_url']);
$page->smarty->assign('cp_api_selected', $data['cp_api']);

$page->smarty->assign('catlist', $category->getForSelect(false));

$page->content = $page->smarty->fetch('profileedit.tpl');
$page->render();
