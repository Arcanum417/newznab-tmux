<?php

require_once dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use nntmux\db\DB;
use nntmux\Category;
use nntmux\ReleaseImage;

$pdo = new DB();

$path2cover = NN_COVERS.'samples'.DS;

if (isset($argv[1]) && ($argv[1] === 'true' || $argv[1] === 'check')) {
    $releaseImage = new ReleaseImage();
    $couldbe = $argv[1] === 'true' ? $couldbe = 'had ' : 'could have ';
    $limit = $counterfixed = 0;
    if (isset($argv[2]) && is_numeric($argv[2])) {
        $limit = $argv[2];
    }

    echo $pdo->log->header('Scanning for XXX UHD/HD/SD releases missing sample images');
    $res = $pdo->query(sprintf('SELECT r.id, r.guid AS guid, r.searchname AS searchname
								FROM releases r
								WHERE r.nzbstatus = 1 AND r.jpgstatus = 0 AND r.categories_id IN (%s, %s, %s) ORDER BY r.adddate DESC', Category::XXX_CLIPHD, Category::XXX_CLIPSD, Category::XXX_UHD));
    foreach ($res as $row) {
        $nzbpath = $path2cover.$row['guid'].'_thumb.jpg';
        if (! file_exists($nzbpath)) {
            $counterfixed++;
            if ($argv[1] === 'true') {
                $imgpath = 'http://pic4all.eu/images/'.$row['searchname'].'_1.jpg';
                //scan pic4all.eu for sample image
                if (preg_match('/SDCLiP/i', $row['searchname'])) {
                    $row['searchname'] = strtolower(preg_replace('/.XXX(.720p|.1080p)?.MP4-SDCLiP/i', '', $row['searchname']));
                    $imgpath = 'http://pic4all.eu/images/'.$row['searchname'].'.jpg';
                }
                $sample = $releaseImage->saveImage($row['guid'].'_thumb', $imgpath, $releaseImage->jpgSavePath, 650, 650);
                if ($sample !== 0) {
                    echo $pdo->log->info('Downloaded sample for '.$row['searchname']);
                    $pdo->queryExec(sprintf('UPDATE releases SET jpgstatus = 1 WHERE id = %d', $row['id']));
                } else {
                    echo $pdo->log->notice('Sample download failed!');
                    $pdo->queryExec(sprintf('UPDATE releases SET jpgstatus = -2 WHERE id = %d', $row['id']));
                }
            }
        }

        if (($limit > 0) && ($counterfixed >= $limit)) {
            break;
        }
    }
    echo $pdo->log->header('Total releases missing samples that '.$couldbe.'their samples updated = '.number_format($counterfixed));
} else {
    exit($pdo->log->header("\nThis script checks if XXX release samples actually exist on disk.\n\n"
        ."php $argv[0] check   ...: Dry run, displays missing samples.\n"
        ."php $argv[0] true    ...: Update XXX releases missing samples.\n"));
}
