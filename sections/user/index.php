<?php
//TODO
/*****************************************************************
Finish removing the take[action] pages and utilize the index correctly
Should the advanced search really only show if they match 3 perms?
Make sure all constants are defined in config.php and not in random files
*****************************************************************/

enforce_login();
$Val = new Validate;

if (empty($_REQUEST['action'])) {
    $_REQUEST['action'] = '';
}

switch ($_REQUEST['action']) {
    case 'notify':
        require(__DIR__ . '/notify_edit.php');
        break;
    case 'notify_handle':
        require(__DIR__ . '/notify_handle.php');
        break;
    case 'notify_delete':
        authorize();
        $notifId = (int)$_GET['id'];
        if ($notifId) {
            $user = new Gazelle\User($LoggedUser['ID']);
            $user->removeNotificationFilter($notifId);
        }
        header('Location: user.php?action=notify');
        break;
    case 'search':// User search
        if (check_perms('admin_advanced_user_search') && check_perms('users_view_ips') && check_perms('users_view_email')) {
            require(__DIR__ . '/advancedsearch.php');
        }
        else {
            require(__DIR__ . '/search.php');
        }
        break;
    case 'edit':
        if (isset($_REQUEST['userid'])) {
            require(__DIR__ . '/edit.php');
        }
        else {
            header("Location: user.php?action=edit&userid={$LoggedUser['ID']}");
        }
        break;
    case '2fa':
        require(__DIR__ . '/../../classes/google_authenticator.class.php');

        if (empty($_GET['do'])) {
            // we didn't get a required "do", we'll just let the 404 handler deal with the request.
            error(404);
        }

        $UserID = (int)$_REQUEST['userid'];
        if (!$UserID) {
            error(404);
        }
        [$PassHash, $TFAKey, $Level] = $DB->row("
            SELECT m.PassHash, m.2FA_Key, p.Level
            FROM users_main AS m
            LEFT JOIN permissions AS p ON (p.ID = PermissionID)
            WHERE m.ID = ?
            ", $UserID
        );
        if ($UserID != $LoggedUser['ID'] && !check_perms('users_mod')) {
            error(403);
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start(['read_and_close' => true]);
        }

        switch($_GET['do']) {
            case 'enable':
                if ($TFAKey) {
                    // 2fa is already enabled...
                    error(404);
                }
                if (empty($_SESSION['private_key'])) {
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $_SESSION['private_key'] = (new PHPGangsta_GoogleAuthenticator())->createSecret();
                    session_write_close();
                }

                require(__DIR__ . '/2fa/step1.php');
                break;

            case 'enable2':
                if ($TFAKey) {
                    // 2fa is already enabled...
                    error(404);
                }

                if (empty($_SESSION['private_key'])) {
                    header('Location: user.php?action=2fa&do=enable&userid=' . $UserID);
                    exit;
                }

                if (empty($_POST['2fa'])) {
                    require(__DIR__ . '/2fa/step2.php');
                } else {
                    $works = (new PHPGangsta_GoogleAuthenticator())->verifyCode($_SESSION['private_key'], $_POST['2fa'], 2);

                    if (!$works) {
                        // user got their token wrong...
                        header('Location: user.php?action=2fa&do=enable&invalid&userid=' . $UserID);
                    } else {
                        $recovery = [];
                        for ($i = 0; $i < 6; $i++) {
                            $recovery[] = randomString(16);
                        }

                        $DB->prepared_query('
                            UPDATE users_main SET
                                2FA_Key = ?,
                                Recovery = ?
                            WHERE ID = ?
                            ', $_SESSION['private_key'], serialize($recovery), $UserID
                        );
                        header('Location: user.php?action=2fa&do=complete&userid=' . $UserID);
                    }
                }
                break;

            case 'complete':
                // user should only ever see this page once.
                if (empty($_SESSION['private_key'])) {
                    error(404);
                }

                require(__DIR__ . '/2fa/complete.php');
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                unset($_SESSION['private_key']);
                session_write_close();
                break;

            case 'disable':
                if (!$TFAKey) {
                    // 2fa isn't enabled...
                    error(404);
                }

                if ($UserID === $LoggedUser['ID']) {
                    if (empty($_POST['password'])) {
                        require(__DIR__ . '/2fa/password_confirm.php');
                        break;
                    }
                    if (!Users::check_password($_POST['password'], $PassHash)) {
                        header('Location: user.php?action=2fa&do=disable&invalid&userid=' . $UserID);
                        exit;
                    }
                } elseif (!check_perms('users_edit_password')) {
                    error(403);
                }

                (new Gazelle\User($UserID))->remove2FA();

                $action = '';
                if (!isset($_GET['page']) || $_GET['page'] !== 'user') {
                    $action = 'action=edit&';
                }
                header('Location: user.php?' . $action . 'userid=' . $UserID);
                break;
        }
        break;
    case 'take_edit':
        require(__DIR__ . '/take_edit.php');
        break;
    case 'invitetree':
        require(__DIR__ . '/invitetree.php');
        break;
    case 'invite':
        require(__DIR__ . '/invite.php');
        break;
    case 'take_invite':
        require(__DIR__ . '/take_invite.php');
        break;
    case 'delete_invite':
        require(__DIR__ . '/delete_invite.php');
        break;
    case 'stats':
        require(__DIR__ . '/user_stats.php');
        break;
    case 'sessions':
        require(__DIR__ . '/sessions.php');
        break;
    case 'connchecker':
        require(__DIR__ . '/connchecker.php');
        break;
    case 'permissions':
        require(__DIR__ . '/permissions.php');
        break;
    case 'similar':
        require(__DIR__ . '/similar.php');
        break;
    case 'moderate':
        require(__DIR__ . '/takemoderate.php');
        break;
    case 'clearcache':
        if (!check_perms('admin_clear_cache') || !check_perms('users_override_paranoia')) {
            error(403);
        }
        $UserID = $_REQUEST['id'];
        $Cache->deleteMulti([
            'collage_subs_user_new_'  . $UserID,
            'donor_info_'             . $UserID,
            'inbox_new_'              . $UserID,
            'notifications_new_'      . $UserID,
            'staff_pm_new_'           . $UserID,
            'subscriptions_user_new_' . $UserID,
            'user_info_'              . $UserID,
            'user_info_heavy_'        . $UserID,
        ]);
        require(__DIR__ . '/user.php');
        break;

    // Provide public methods for Last.fm data gets.
    case 'lastfm_compare':
        if (isset($_GET['username'])) {
            echo LastFM::compare_user_with($_GET['username']);
        }
        break;
    case 'lastfm_last_played_track':
        if (isset($_GET['username'])) {
            echo LastFM::get_last_played_track($_GET['username']);
        }
        break;
    case 'lastfm_top_artists':
        if (isset($_GET['username'])) {
            echo LastFM::get_top_artists($_GET['username']);
        }
        break;
    case 'lastfm_top_albums':
        if (isset($_GET['username'])) {
            echo LastFM::get_top_albums($_GET['username']);
        }
        break;
    case 'lastfm_top_tracks':
        if (isset($_GET['username'])) {
            echo LastFM::get_top_tracks($_GET['username']);
        }
        break;
    case 'lastfm_clear_cache':
        if (isset($_GET['username']) && isset($_GET['uid'])) {
            echo LastFM::clear_cache($_GET['username'],$_GET['uid']);
        }
        break;
    default:
        if (isset($_REQUEST['id'])) {
            require(__DIR__ . '/user.php');
        } else {
            header("Location: user.php?id={$LoggedUser['ID']}");
        }
}
