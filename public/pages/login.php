<?php

use App\Models\User;
use nntmux\Captcha;
use nntmux\Logging;
use App\Models\Settings;
use nntmux\utility\Utility;

$page->smarty->assign(['error' => '', 'username' => '', 'rememberme' => '']);

$captcha = new Captcha($page);

if (! User::isLoggedIn()) {
    if (! isset($_POST['username'], $_POST['password'])) {
        $page->smarty->assign('error', 'Please enter your username and password.');
    } elseif ($captcha->getError() === false) {
        $username = htmlspecialchars($_POST['username']);
        $page->smarty->assign('username', $username);
        if (Utility::checkCsrfToken() === true) {
            $logging = new Logging(['Settings' => $page->settings]);
            $res = User::getByUsername($username);
            if ($res === null) {
                $res = User::getByEmail($username);
            }

            if ($res !== null) {
                $dis = User::isDisabled($username);
                if ($dis) {
                    $page->smarty->assign('error', 'Your account has been disabled.');
                } elseif (User::checkPassword($_POST['password'], $res['password'], $res['id'])) {
                    $rememberMe = (isset($_POST['rememberme']) && $_POST['rememberme'] === 'on');
                    User::login($res['id'], $_SERVER['REMOTE_ADDR'], $rememberMe);

                    if (isset($_POST['redirect']) && $_POST['redirect'] !== '') {
                        header('Location: '.$_POST['redirect']);
                    } else {
                        header('Location: '.WWW_TOP.Settings::settingValue('site.main.home_link'));
                    }
                    die();
                } else {
                    $page->smarty->assign('error', 'Incorrect username/email or password.');
                    $logging->LogBadPasswd($username, $_SERVER['REMOTE_ADDR']);
                }
            } else {
                $page->smarty->assign('error', 'Incorrect username/email or password.');
                $logging->LogBadPasswd($username, $_SERVER['REMOTE_ADDR']);
            }
        } else {
            $page->showTokenError();
        }
    }
} else {
    header('Location: '.WWW_TOP.Settings::settingValue('site.main.home_link'));
}

$page->smarty->assign('redirect', $_GET['redirect'] ?? '');
$page->smarty->assign('csrf_token', $page->token);
$page->meta_title = 'Login';
$page->meta_keywords = 'Login';
$page->meta_description = 'Login';
$page->content = $page->smarty->fetch('login.tpl');
$page->render();
