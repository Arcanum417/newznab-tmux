<?php

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use nntmux\NNTP;
use nntmux\db\DB;
use nntmux\Groups;
use nntmux\Binaries;
use nntmux\ColorCLI;
use App\Models\Settings;

$pdo = new DB();

$maxHeaders = (int) Settings::settingValue('..max_headers_iteration') ?: 1000000;

// Create the connection here and pass
$nntp = new NNTP(['Settings' => $pdo]);
if ($nntp->doConnect() !== true) {
    exit(ColorCLI::error('Unable to connect to usenet.'));
}
$binaries = new Binaries(['NNTP' => $nntp, 'Settings' => $pdo]);

if (isset($argv[1]) && ! is_numeric($argv[1])) {
    $groupName = $argv[1];
    echo ColorCLI::header("Updating group: $groupName");

    $grp = new Groups(['Settings' => $pdo]);
    $group = $grp->getByName($groupName);
    if (is_array($group)) {
        $binaries->updateGroup(
            $group,
            (isset($argv[2]) && is_numeric($argv[2]) && $argv[2] > 0 ? $argv[2] : $maxHeaders)
        );
    }
} else {
    $binaries->updateAllGroups((isset($argv[1]) && is_numeric($argv[1]) && $argv[1] > 0 ? $argv[1] :
        $maxHeaders));
}
