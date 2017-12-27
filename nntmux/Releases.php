<?php

namespace nntmux;

use nntmux\db\DB;
use Carbon\Carbon;
use App\Models\Release;
use App\Models\Settings;
use App\Models\ReleaseNfo;
use nntmux\utility\Utility;
use Illuminate\Support\Facades\Cache;
use App\Models\Category as CategoryModel;

/**
 * Class Releases.
 */
class Releases
{
    // RAR/ZIP Passworded indicator.
    public const PASSWD_NONE = 0; // No password.
    public const PASSWD_POTENTIAL = 1; // Might have a password.
    public const BAD_FILE = 2; // Possibly broken RAR/ZIP.
    public const PASSWD_RAR = 10; // Definitely passworded.

    /**
     * @var \nntmux\db\DB
     */
    public $pdo;

    /**
     * @var \nntmux\Groups
     */
    public $groups;

    /**
     * @var \nntmux\ReleaseSearch
     */
    public $releaseSearch;

    /**
     * @var \nntmux\SphinxSearch
     */
    public $sphinxSearch;

    /**
     * @var string
     */
    public $showPasswords;

    /**
     * @var int
     */
    public $passwordStatus;

    /**
     * @var \nntmux\Category
     */
    public $category;

    /**
     * @var array Class instances.
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        $defaults = [
            'Settings' => null,
            'Groups'   => null,
        ];
        $options += $defaults;

        $this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
        $this->groups = ($options['Groups'] instanceof Groups ? $options['Groups'] : new Groups(['Settings' => $this->pdo]));
        $this->sphinxSearch = new SphinxSearch();
        $this->releaseSearch = new ReleaseSearch($this->pdo);
        $this->category = new Category(['Settings' => $this->pdo]);
        $this->showPasswords = self::showPasswords();
    }

    /**
     * @return array
     */
    public function get(): array
    {
        $result = Cache::get('releaseget');
        if ($result !== null) {
            return $result;
        }

        $result = Release::query()
            ->where('nzbstatus', '=', NZB::NZB_ADDED)
            ->select(['releases.*', 'g.name as group_name', 'c.title as category_name'])
            ->leftJoin('categories as c', 'c.id', '=', 'releases.categories_id')
            ->leftJoin('groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put('releaseget', $result, $expiresAt);

        return $result;
    }

    /**
     * Used for admin page release-list.
     *
     *
     * @param $start
     * @param $num
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection|static[]
     */
    public function getRange($start, $num)
    {
        $range = Cache::get('releasesrange');
        if ($range !== null) {
            return $range;
        }
        $query = Release::query()
            ->where('nzbstatus', '=', NZB::NZB_ADDED)
            ->select(
            [
                'releases.id',
                'releases.name',
                'releases.searchname',
                'releases.size',
                'releases.guid',
                'releases.totalpart',
                'releases.postdate',
                'releases.adddate',
                'releases.grabs',
            ]
            )
            ->selectRaw('CONCAT(cp.title, ' > ', c.title) AS category_name')
            ->leftJoin('categories as c', 'c.id', '=', 'releases.categories_id')
            ->leftJoin('categories as cp', 'cp.id', '=', 'c.parentid')
            ->orderBy('releases.postdate', 'desc');
        if ($start !== false) {
            $query->limit($num)->offset($start);
        }

        $range = $query->get();

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put('releasesrange', $range, $expiresAt);

        return $range;
    }

    /**
     * Used for pager on browse page.
     *
     * @param array  $cat
     * @param int    $maxAge
     * @param array  $excludedCats
     * @param string|int $groupName
     *
     * @return int
     */
    public function getBrowseCount($cat, $maxAge = -1, array $excludedCats = [], $groupName = ''): int
    {
        $sql = sprintf(
                'SELECT COUNT(r.id) AS count
				FROM releases r
				%s
				WHERE r.nzbstatus = %d
				AND r.passwordstatus %s
				%s %s %s %s',
                ($groupName !== -1 ? 'LEFT JOIN groups g ON g.id = r.groups_id' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($groupName !== -1 ? sprintf(' AND g.name = %s', $this->pdo->escapeString($groupName)) : ''),
                $this->category->getCategorySearch($cat),
                ($maxAge > 0 ? (' AND r.postdate > NOW() - INTERVAL '.$maxAge.' DAY ') : ''),
                (\count($excludedCats) ? (' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')') : '')
        );
        $count = Cache::get(md5($sql));
        if ($count !== null) {
            return $count;
        }
        $count = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_SHORT);
        Cache::put(md5($sql), $count[0]['count'], $expiresAt);

        return $count[0]['count'] ?? 0;
    }

    /**
     * Used for browse results.
     *
     * @param array  $cat
     * @param        $start
     * @param        $num
     * @param string|array $orderBy
     * @param int    $maxAge
     * @param array  $excludedCats
     * @param string|int $groupName
     * @param int    $minSize
     *
     * @return array
     */
    public function getBrowseRange($cat, $start, $num, $orderBy, $maxAge = -1, array $excludedCats = [], $groupName = -1, $minSize = 0): array
    {
        $orderBy = $this->getBrowseOrder($orderBy);

        $qry = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				CONCAT(cp.id, ',', c.id) AS category_ids,
				df.failed AS failed,
				rn.releases_id AS nfoid,
				re.releases_id AS reid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.title, tve.firstaired
			FROM
			(
				SELECT r.*, g.name AS group_name
				FROM releases r
				LEFT JOIN groups g ON g.id = r.groups_id
				WHERE r.nzbstatus = %d
				AND r.passwordstatus %s
				%s %s %s %s %s
				ORDER BY %s %s %s
			) r
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			GROUP BY r.id
			ORDER BY %8\$s %9\$s",
            NZB::NZB_ADDED,
            $this->showPasswords,
            $this->category->getCategorySearch($cat),
            ($maxAge > 0 ? (' AND postdate > NOW() - INTERVAL '.$maxAge.' DAY ') : ''),
            (\count($excludedCats) ? (' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')') : ''),
            ((int) $groupName !== -1 ? sprintf(' AND g.name = %s ', $this->pdo->escapeString($groupName)) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : ''),
            $orderBy[0],
            $orderBy[1],
            ($start === false ? '' : ' LIMIT '.$num.' OFFSET '.$start)
        );

        $releases = Cache::get(md5($qry));
        if ($releases !== null) {
            return $releases;
        }
        $sql = $this->pdo->query($qry);
        if (\count($sql) > 0) {
            $possibleRows = $this->getBrowseCount($cat, $maxAge, $excludedCats, $groupName);
            $sql[0]['_totalcount'] = $sql[0]['_totalrows'] = $possibleRows;
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($qry), $sql, $expiresAt);

        return $sql;
    }

    /**
     * Return site setting for hiding/showing passworded releases.
     *
     * @return string
     * @throws \Exception
     */
    public static function showPasswords(): ?string
    {
        $setting = Settings::settingValue('..showpasswordedrelease', true);
        $setting = ($setting !== null && is_numeric($setting)) ? $setting : 10;

        switch ($setting) {
            case 0: // Hide releases with a password or a potential password (Hide unprocessed releases).
                return '='.self::PASSWD_NONE;
            case 1: // Show releases with no password or a potential password (Show unprocessed releases).
                return '<= '.self::PASSWD_POTENTIAL;
            case 2: // Hide releases with a password or a potential password (Show unprocessed releases).
                return '<= '.self::PASSWD_NONE;
            case 10: // Shows everything.
            default:
                return '<= '.self::PASSWD_RAR;
        }
    }

    /**
     * Use to order releases on site.
     *
     * @param string|array $orderBy
     *
     * @return array
     */
    public function getBrowseOrder($orderBy): array
    {
        $orderArr = explode('_', ($orderBy === '' ? 'posted_desc' : $orderBy));
        switch ($orderArr[0]) {
            case 'cat':
                $orderField = 'categories_id';
                break;
            case 'name':
                $orderField = 'searchname';
                break;
            case 'size':
                $orderField = 'size';
                break;
            case 'files':
                $orderField = 'totalpart';
                break;
            case 'stats':
                $orderField = 'grabs';
                break;
            case 'posted':
            default:
                $orderField = 'postdate';
                break;
        }

        return [$orderField, isset($orderArr[1]) && preg_match('/^(asc|desc)$/i', $orderArr[1]) ? $orderArr[1] : 'desc'];
    }

    /**
     * Return ordering types usable on site.
     *
     * @return string[]
     */
    public function getBrowseOrdering(): array
    {
        return [
            'name_asc',
            'name_desc',
            'cat_asc',
            'cat_desc',
            'posted_asc',
            'posted_desc',
            'size_asc',
            'size_desc',
            'files_asc',
            'files_desc',
            'stats_asc',
            'stats_desc',
        ];
    }

    /**
     * Get list of releases available for export.
     *
     * @param string $postFrom (optional) Date in this format : 01/01/2014
     * @param string $postTo   (optional) Date in this format : 01/01/2014
     * @param string|int $groupID  (optional) Group ID.
     *
     * @return array
     */
    public function getForExport($postFrom = '', $postTo = '', $groupID = ''): array
    {
        return $this->pdo->query(
            sprintf(
                "SELECT searchname, guid, groups.name AS gname, CONCAT(cp.title,'_',categories.title) AS catName
				FROM releases r
				LEFT JOIN categories c ON r.categories_id = c.id
				LEFT JOIN groups g ON r.groups_id = g.id
				LEFT JOIN categories cp ON cp.id = c.parentid
				WHERE r.nzbstatus = %d
				%s %s %s",
                NZB::NZB_ADDED,
                $this->exportDateString($postFrom),
                $this->exportDateString($postTo, false),
                $groupID !== '' && $groupID !== -1 ? sprintf(' AND r.groups_id = %d ', $groupID) : ''
            )
        );
    }

    /**
     * Create a date query string for exporting.
     *
     * @param string $date
     * @param bool   $from
     *
     * @return string
     */
    private function exportDateString($date = '', $from = true): string
    {
        if ($date !== '') {
            $dateParts = explode('/', $date);
            if (\count($dateParts) === 3) {
                $date = sprintf(
                    ' AND postdate %s %s ',
                    ($from ? '>' : '<'),
                    $this->pdo->escapeString(
                        $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].
                        ($from ? ' 00:00:00' : ' 23:59:59')
                    )
                );
            }
        }

        return $date;
    }

    /**
     * Get date in this format : 01/01/2014 of the oldest release.
     *
     * @note Used for exporting NZB's.
     * @return mixed
     */
    public function getEarliestUsenetPostDate()
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(min(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * Get date in this format : 01/01/2014 of the newest release.
     *
     * @note Used for exporting NZB's.
     * @return mixed
     */
    public function getLatestUsenetPostDate()
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(max(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * Gets all groups for drop down selection on NZB-Export web page.
     *
     * @param bool $blnIncludeAll
     *
     * @note Used for exporting NZB's.
     * @return array
     */
    public function getReleasedGroupsForSelect($blnIncludeAll = true): array
    {
        $groups = Release::query()
            ->selectRaw('DISTINCT g.id, g.name')
            ->leftJoin('groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();
        $temp_array = [];

        if ($blnIncludeAll) {
            $temp_array[-1] = '--All Groups--';
        }

        foreach ($groups as $group) {
            $temp_array[$group['id']] = $group['name'];
        }

        return $temp_array;
    }

    /**
     * Cache of concatenated category ID's used in queries.
     * @var null|array
     */
    private $concatenatedCategoryIDsCache = null;

    /**
     * Gets / sets a string of concatenated category ID's used in queries.
     *
     * @return array|null|string
     */
    public function getConcatenatedCategoryIDs()
    {
        if ($this->concatenatedCategoryIDsCache === null) {
            $this->concatenatedCategoryIDsCache = Cache::get('concatenatedcats');
            if ($this->concatenatedCategoryIDsCache !== null) {
                return $this->concatenatedCategoryIDsCache;
            }

            $result = CategoryModel::query()
                ->whereNotNull('categories.parentid')
                ->whereNotNull('cp.id')
                ->selectRaw('CONCAT(cp.id, ", ", categories.id) AS category_ids')
                ->leftJoin('categories as cp', 'cp.id', '=', 'categories.parentid')
                ->get();
            if (isset($result[0]['category_ids'])) {
                $this->concatenatedCategoryIDsCache = $result[0]['category_ids'];
            }
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put('concatenatedcats', $this->concatenatedCategoryIDsCache, $expiresAt);

        return $this->concatenatedCategoryIDsCache;
    }

    /**
     * Get TV for my shows page.
     *
     * @param          $userShows
     * @param int|bool $offset
     * @param int      $limit
     * @param string|array   $orderBy
     * @param int      $maxAge
     * @param array    $excludedCats
     *
     * @return array
     */
    public function getShowsRange($userShows, $offset, $limit, $orderBy, $maxAge = -1, array $excludedCats = []): array
    {
        $orderBy = $this->getBrowseOrder($orderBy);

        $sql = sprintf(
                "SELECT r.*,
					CONCAT(cp.title, '-', c.title) AS category_name,
					%s AS category_ids,
					g.name AS group_name,
					rn.releases_id AS nfoid, re.releases_id AS reid,
					tve.firstaired,
					(SELECT df.failed) AS failed
				FROM releases PARTITION (tv) r
				LEFT OUTER JOIN video_data re ON re.releases_id = r.id
				LEFT JOIN groups g ON g.id = r.groups_id
				LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
				LEFT OUTER JOIN tv_episodes tve ON tve.videos_id = r.videos_id
				LEFT JOIN categories c ON c.id = r.categories_id
				LEFT JOIN categories cp ON cp.id = c.parentid
				LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
				WHERE %s %s
				AND r.nzbstatus = %d
				AND r.passwordstatus %s
				%s
				GROUP BY r.id
				ORDER BY %s %s %s",
                $this->getConcatenatedCategoryIDs(),
                $this->uSQL($userShows, 'videos_id'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : ''),
                $orderBy[0],
                $orderBy[1],
                ($offset === false ? '' : (' LIMIT '.$limit.' OFFSET '.$offset))
            );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get count for my shows page pagination.
     *
     * @param       $userShows
     * @param int   $maxAge
     * @param array $excludedCats
     *
     * @return int
     */
    public function getShowsCount($userShows, $maxAge = -1, array $excludedCats = []): int
    {
        return $this->getPagerCount(
            sprintf(
                'SELECT r.id
				FROM releases PARTITION (tv) r
				WHERE %s %s
				AND r.nzbstatus = %d
				AND r.passwordstatus %s
				%s',
                $this->uSQL($userShows, 'videos_id'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
            )
        );
    }

    /**
     * Get count for my shows page pagination.
     *
     * @param       $userMovies
     * @param int   $maxAge
     * @param array $excludedCats
     *
     * @return int
     */
    public function getMovieCount($userMovies, $maxAge = -1, array $excludedCats = []): int
    {
        return $this->getPagerCount(
            sprintf(
                'SELECT r.id
				FROM releases PARTITION (movies) r
				WHERE %s %s
				AND r.nzbstatus = %d
				AND r.passwordstatus %s
				%s',
                $this->uSQL($userMovies, 'imdbid'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
            )
        );
    }

    /**
     * Get count for admin release list page.
     *
     * @return int
     */
    public function getCount(): int
    {
        $res = Cache::get('count');
        if ($res !== null) {
            return $res;
        }
        $res = Release::query()->count(['id']);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put('count', $res, $expiresAt);

        return $res ?? 0;
    }

    /**
     * Delete multiple releases, or a single by ID.
     *
     * @param array|int|string $list   Array of GUID or ID of releases to delete.
     * @throws \Exception
     */
    public function deleteMultiple($list): void
    {
        $list = (array) $list;

        $nzb = new NZB($this->pdo);
        $releaseImage = new ReleaseImage();

        foreach ($list as $identifier) {
            $this->deleteSingle(['g' => $identifier, 'i' => false], $nzb, $releaseImage);
        }
    }

    /**
     * Deletes a single release by GUID, and all the corresponding files.
     *
     * @param array        $identifiers ['g' => Release GUID(mandatory), 'id => ReleaseID(optional, pass false)]
     * @param NZB          $nzb
     * @param ReleaseImage $releaseImage
     */
    public function deleteSingle($identifiers, $nzb, $releaseImage)
    {
        // Delete NZB from disk.
        $nzbPath = $nzb->NZBPath($identifiers['g']);
        if ($nzbPath) {
            @unlink($nzbPath);
        }

        // Delete images.
        $releaseImage->delete($identifiers['g']);

        // Delete from sphinx.
        $this->sphinxSearch->deleteRelease($identifiers, $this->pdo);

        $param1 = false;
        $param2 = $identifiers['g'];

        // Delete from DB.
        $query = $this->pdo->Prepare('CALL delete_release(:is_numeric, :identifier)');
        $query->bindParam(':is_numeric', $param1, \PDO::PARAM_BOOL);
        $query->bindParam(':identifier', $param2);

        $query->execute();
    }

    /**
     * @param $guids
     * @param $category
     * @param $grabs
     * @param $videoId
     * @param $episodeId
     * @param $anidbId
     * @param $imdbId
     * @return bool|int
     */
    public function updateMulti($guids, $category, $grabs, $videoId, $episodeId, $anidbId, $imdbId)
    {
        if (! \is_array($guids) || \count($guids) < 1) {
            return false;
        }

        $update = [
            'categories_id'     => $category === -1 ? 'categories_id' : $category,
            'grabs'          => $grabs,
            'videos_id'      => $videoId,
            'tv_episodes_id' => $episodeId,
            'anidbid'        => $anidbId,
            'imdbid'         => $imdbId,
        ];

        return Release::query()->whereIn('guid', $guids)->update($update);
    }

    /**
     * Creates part of a query for some functions.
     *
     * @param array  $userQuery
     * @param string $type
     *
     * @return string
     */
    public function uSQL($userQuery, $type): string
    {
        $sql = '(1=2 ';
        foreach ($userQuery as $query) {
            $sql .= sprintf('OR (r.%s = %d', $type, $query[$type]);
            if ($query['categories'] !== '') {
                $catsArr = explode('|', $query['categories']);
                if (\count($catsArr) > 1) {
                    $sql .= sprintf(' AND r.categories_id IN (%s)', implode(',', $catsArr));
                } else {
                    $sql .= sprintf(' AND r.categories_id = %d', $catsArr[0]);
                }
            }
            $sql .= ') ';
        }
        $sql .= ') ';

        return $sql;
    }

    /**
     * Function for searching on the site (by subject, searchname or advanced).
     *
     * @param string $searchName
     * @param string $usenetName
     * @param string $posterName
     * @param string $fileName
     * @param string|int $groupName
     * @param int $sizeFrom
     * @param int $sizeTo
     * @param int $hasNfo
     * @param int $hasComments
     * @param int $daysNew
     * @param int $daysOld
     * @param int $offset
     * @param int $limit
     * @param string|array $orderBy
     * @param int $maxAge
     * @param int|array $excludedCats
     * @param string $type
     * @param array $cat
     *
     * @param int $minSize
     * @return array
     */
    public function search($searchName, $usenetName, $posterName, $fileName, $groupName, $sizeFrom, $sizeTo, $hasNfo, $hasComments, $daysNew, $daysOld, $offset = 0, $limit = 1000, $orderBy = '', $maxAge = -1, array $excludedCats = [], $type = 'basic', array $cat = [-1], $minSize = 0): array
    {
        $sizeRange = [
            1 => 1,
            2 => 2.5,
            3 => 5,
            4 => 10,
            5 => 20,
            6 => 30,
            7 => 40,
            8 => 80,
            9 => 160,
            10 => 320,
            11 => 640,
        ];

        if ($orderBy === '') {
            $orderBy = [];
            $orderBy[0] = 'postdate ';
            $orderBy[1] = 'desc ';
        } else {
            $orderBy = $this->getBrowseOrder($orderBy);
        }

        $searchOptions = [];
        if ($searchName !== -1) {
            $searchOptions['searchname'] = $searchName;
        }
        if ($usenetName !== -1) {
            $searchOptions['name'] = $usenetName;
        }
        if ($posterName !== -1) {
            $searchOptions['fromname'] = $posterName;
        }
        if ($fileName !== -1) {
            $searchOptions['filename'] = $fileName;
        }

        $catQuery = '';
        if ($type === 'basic') {
            $catQuery = $this->category->getCategorySearch($cat);
        } elseif ($type === 'advanced' && (int) $cat[0] !== -1) {
            $catQuery = sprintf('AND r.categories_id = %d', $cat[0]);
        }

        $whereSql = sprintf(
            '%s WHERE r.passwordstatus %s AND r.nzbstatus = %d %s %s %s %s %s %s %s %s %s %s %s %s',
            $this->releaseSearch->getFullTextJoinString(),
            $this->showPasswords,
            NZB::NZB_ADDED,
            ($maxAge > 0 ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $maxAge) : ''),
            ((int) $groupName !== -1 ? sprintf(' AND r.groups_id = %d ', $this->groups->getIDByName($groupName)) : ''),
            (array_key_exists($sizeFrom, $sizeRange) ? ' AND r.size > '.(string) (104857600 * (int) $sizeRange[$sizeFrom]).' ' : ''),
            (array_key_exists($sizeTo, $sizeRange) ? ' AND r.size < '.(string) (104857600 * (int) $sizeRange[$sizeTo]).' ' : ''),
            ((int) $hasNfo !== 0 ? ' AND r.nfostatus = 1 ' : ''),
            ((int) $hasComments !== 0 ? ' AND r.comments > 0 ' : ''),
            $catQuery,
            ((int) $daysNew !== -1 ? sprintf(' AND r.postdate < (NOW() - INTERVAL %d DAY) ', $daysNew) : ''),
            ((int) $daysOld !== -1 ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $daysOld) : ''),
            (\count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
            (\count($searchOptions) > 0 ? $this->releaseSearch->getSearchSQL($searchOptions) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				df.failed AS failed,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid,
				cp.id AS categoryparentid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.firstaired
			FROM releases r
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            'SELECT * FROM (
				%s
			) r
			ORDER BY r.%s %s
			LIMIT %d OFFSET %d',
            $baseSql,
            $orderBy[0],
            $orderBy[1],
            $limit,
            $offset
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount($baseSql);
        }

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Search TV Shows via the API.
     *
     * @param array  $siteIdArr Array containing all possible TV Processing site IDs desired
     * @param string $series The series or season number requested
     * @param string $episode The episode number requested
     * @param string $airdate The airdate of the episode requested
     * @param int    $offset Skip this many releases
     * @param int    $limit Return this many releases
     * @param string $name The show name to search
     * @param array  $cat The category to search
     * @param int    $maxAge The maximum age of releases to be returned
     * @param int    $minSize The minimum size of releases to be returned
     *
     * @return array
     */
    public function searchShows(
        array $siteIdArr = [],
        $series = '',
        $episode = '',
        $airdate = '',
        $offset = 0,
        $limit = 100,
        $name = '',
        array $cat = [-1],
        $maxAge = -1,
        $minSize = 0
    ): array {
        $siteSQL = [];
        $showSql = '';

        if (\is_array($siteIdArr)) {
            foreach ($siteIdArr as $column => $Id) {
                if ($Id > 0) {
                    $siteSQL[] = sprintf('v.%s = %d', $column, $Id);
                }
            }
        }

        if (\count($siteSQL) > 0) {
            // If we have show info, find the Episode ID/Video ID first to avoid table scans
            $showQry = sprintf(
                "
				SELECT
					v.id AS video,
					GROUP_CONCAT(tve.id SEPARATOR ',') AS episodes
				FROM videos v
				LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
				WHERE (%s) %s %s %s
				GROUP BY v.id",
                implode(' OR ', $siteSQL),
                ($series !== '' ? sprintf('AND tve.series = %d', (int) preg_replace('/^s0*/i', '', $series)) : ''),
                ($episode !== '' ? sprintf('AND tve.episode = %d', (int) preg_replace('/^e0*/i', '', $episode)) : ''),
                ($airdate !== '' ? sprintf('AND DATE(tve.firstaired) = %s', $this->pdo->escapeString($airdate)) : '')
            );
            $show = $this->pdo->queryOneRow($showQry);
            if ($show !== false) {
                if ((! empty($series) || ! empty($episode) || ! empty($airdate)) && strlen((string) $show['episodes']) > 0) {
                    $showSql = sprintf('AND r.tv_episodes_id IN (%s)', $show['episodes']);
                } elseif ((int) $show['video'] > 0) {
                    $showSql = 'AND r.videos_id = '.$show['video'];
                    // If $series is set but episode is not, return Season Packs only
                    if (! empty($series) && empty($episode)) {
                        $showSql .= ' AND r.tv_episodes_id = 0';
                    }
                } else {
                    // If we were passed Episode Info and no match was found, do not run the query
                    return [];
                }
            } else {
                // If we were passed Site ID Info and no match was found, do not run the query
                return [];
            }
        }

        // If $name is set it is a fallback search, add available SxxExx/airdate info to the query
        if (! empty($name) && $showSql === '') {
            if (! empty($series) && (int) $series < 1900) {
                $name .= sprintf(' S%s', str_pad($series, 2, '0', STR_PAD_LEFT));
                if (! empty($episode) && strpos($episode, '/') === false) {
                    $name .= sprintf('E%s', str_pad($episode, 2, '0', STR_PAD_LEFT));
                }
            } elseif (! empty($airdate)) {
                $name .= sprintf(' %s', str_replace(['/', '-', '.', '_'], ' ', $airdate));
            }
        }

        $whereSql = sprintf(
            '%s
			WHERE r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s',
            ($name !== '' ? $this->releaseSearch->getFullTextJoinString() : ''),
            NZB::NZB_ADDED,
            $this->showPasswords,
            $showSql,
            ($name !== '' ? $this->releaseSearch->getSearchSQL(['searchname' => $name]) : ''),
            $this->category->getCategorySearch($cat),
            ($maxAge > 0 ? sprintf('AND r.postdate > NOW() - INTERVAL %d DAY', $maxAge) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				v.title, v.countries_id, v.started, v.tvdb, v.trakt,
					v.imdb, v.tmdb, v.tvmaze, v.tvrage, v.source,
				tvi.summary, tvi.publisher, tvi.image,
				tve.series, tve.episode, tve.se_complete, tve.title, tve.firstaired, tve.summary,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid
			FROM releases PARTITION (tv) r
			LEFT OUTER JOIN videos v ON r.videos_id = v.id AND v.type = 0
			LEFT OUTER JOIN tv_info tvi ON v.id = tvi.videos_id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount(
                preg_replace('#LEFT(\s+OUTER)?\s+JOIN\s+(?!tv_episodes)\s+.*ON.*=.*\n#i', ' ', $baseSql)
            );
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * @param        $aniDbID
     * @param int    $offset
     * @param int    $limit
     * @param string $name
     * @param array  $cat
     * @param int    $maxAge
     *
     * @return array
     */
    public function searchbyAnidbId($aniDbID, $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1): array
    {
        $whereSql = sprintf(
            '%s
			WHERE r.passwordstatus %s
			AND r.nzbstatus = %d
			%s %s %s %s',
            ($name !== '' ? $this->releaseSearch->getFullTextJoinString() : ''),
            $this->showPasswords,
            NZB::NZB_ADDED,
            ($aniDbID > -1 ? sprintf(' AND r.anidbid = %d ', $aniDbID) : ''),
            ($name !== '' ? $this->releaseSearch->getSearchSQL(['searchname' => $name]) : ''),
            $this->category->getCategorySearch($cat),
            ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid
			FROM releases r
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN releaseextrafull re ON re.releases_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);

        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount($baseSql);
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * @param int $imDbId
     * @param int $offset
     * @param int $limit
     * @param string $name
     * @param array $cat
     * @param int $maxAge
     * @param int $minSize
     *
     * @return array
     */
    public function searchbyImdbId($imDbId, $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1, $minSize = 0): array
    {
        $whereSql = sprintf(
            '%s
			WHERE r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s',
            ($name !== '' ? $this->releaseSearch->getFullTextJoinString() : ''),
            NZB::NZB_ADDED,
            $this->showPasswords,
            ($name !== '' ? $this->releaseSearch->getSearchSQL(['searchname' => $name]) : ''),
            (($imDbId !== -1 && is_numeric($imDbId)) ? sprintf(' AND imdbid = %d ', str_pad($imDbId, 7, '0', STR_PAD_LEFT)) : ''),
            $this->category->getCategorySearch($cat),
            ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				concat(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid
			FROM releases PARTITION (movies) r
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);

        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount($baseSql);
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get count of releases for pager.
     *
     * @param string $query The query to get the count from.
     *
     * @return int
     */
    private function getPagerCount($query): int
    {
        $sql = sprintf(
                        'SELECT COUNT(z.id) AS count FROM (%s LIMIT %s) z',
                        preg_replace('/SELECT.+?FROM\s+releases/is', 'SELECT r.id FROM releases', $query),
                        NN_MAX_PAGER_RESULTS
        );

        $count = Cache::get(md5($sql));
        if ($count !== null) {
            return $count;
        }

        $count = $this->pdo->query($sql);

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_SHORT);
        Cache::put(md5($sql), $count[0]['count'], $expiresAt);

        return $count[0]['count'] ?? 0;
    }

    /**
     * @param       $currentID
     * @param       $name
     * @param int $limit
     * @param array $excludedCats
     *
     * @return array
     * @throws \Exception
     */
    public function searchSimilar($currentID, $name, $limit = 6, array $excludedCats = []): array
    {
        // Get the category for the parent of this release.
        $currRow = Release::getCatByRelId($currentID);
        $catRow = (new Category(['Settings' => $this->pdo]))->getById($currRow['categories_id']);
        $parentCat = $catRow['parentid'];

        $results = $this->search(
            $this->getSimilarName($name),
            -1,
            -1,
            -1,
            -1,
            -1,
            -1,
            0,
            0,
            -1,
            -1,
            0,
            $limit,
            '',
            -1,
            $excludedCats,
            null,
            [$parentCat]
        );
        if (! $results) {
            return $results;
        }

        $ret = [];
        foreach ($results as $res) {
            if ($res['id'] !== $currentID && $res['categoryparentid'] === $parentCat) {
                $ret[] = $res;
            }
        }

        return $ret;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    public function getSimilarName($name): string
    {
        return implode(' ', \array_slice(str_word_count(str_replace(['.', '_'], ' ', $name), 2), 0, 2));
    }

    /**
     * @param $guid
     * @return array|bool
     */
    public function getByGuid($guid)
    {
        if (\is_array($guid)) {
            $tempGuids = [];
            foreach ($guid as $identifier) {
                $tempGuids[] = $this->pdo->escapeString($identifier);
            }
            $gSql = sprintf('r.guid IN (%s)', implode(',', $tempGuids));
        } else {
            $gSql = sprintf('r.guid = %s', $this->pdo->escapeString($guid));
        }
        $sql = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				CONCAT(cp.id, ',', c.id) AS category_ids,
				GROUP_CONCAT(g2.name ORDER BY g2.name ASC SEPARATOR ',') AS group_names,
				g.name AS group_name,
				v.title AS showtitle, v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.source,
				tvi.summary, tvi.image,
				tve.title, tve.firstaired, tve.se_complete
				FROM releases r
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_info tvi ON r.videos_id = tvi.videos_id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN releases_groups rg ON r.id = rg.releases_id
			LEFT OUTER JOIN groups g2 ON rg.groups_id = g2.id
			WHERE %s
			GROUP BY r.id",
            $gSql
        );

        return \is_array($guid) ? $this->pdo->query($sql) : $this->pdo->queryOneRow($sql);
    }

    /**
     * @param array $guids
     * @return string
     * @throws \Exception
     */
    public function getZipped($guids): string
    {
        $nzb = new NZB($this->pdo);
        $zipFile = new \ZipFile();

        foreach ($guids as $guid) {
            $nzbPath = $nzb->NZBPath($guid);

            if ($nzbPath) {
                $nzbContents = Utility::unzipGzipFile($nzbPath);

                if ($nzbContents) {
                    $filename = $guid;
                    $r = $this->getByGuid($guid);
                    if ($r) {
                        $filename = $r['searchname'];
                    }
                    $zipFile->addFile($nzbContents, $filename.'.nzb');
                }
            }
        }

        return $zipFile->file();
    }

    /**
     * @param $videoId
     * @return int
     */
    public function removeVideoIdFromReleases($videoId): int
    {
        return Release::query()->where('videos_id', $videoId)->update(['videos_id' => 0, 'tv_episodes_id' => 0]);
    }

    /**
     * @param $anidbID
     * @return int
     */
    public function removeAnidbIdFromReleases($anidbID): int
    {
        return Release::query()->where('anidbid', $anidbID)->update(['anidbid' => -1]);
    }

    /**
     * @param $id
     * @param bool $getNfoString
     * @return \Illuminate\Database\Eloquent\Model|null|static
     */
    public function getReleaseNfo($id, $getNfoString = true)
    {
        $nfo = ReleaseNfo::query()->where('releases_id', $id)->whereNotNull('nfo')->select(['releases_id']);
        if ($getNfoString === true) {
            $nfo->selectRaw('UNCOMPRESS(nfo) AS nfo');
        }

        return $nfo->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection|static[]
     */
    public function getTopDownloads()
    {
        $releases = Cache::get('topdownloads');
        if ($releases !== null) {
            return $releases;
        }

        $releases = Release::query()
            ->where('grabs', '>', 0)
            ->select(['id', 'searchname', 'guid', 'adddate'])
            ->selectRaw('SUM(grabs) as grabs')
            ->groupBy('id', 'searchname', 'adddate')
            ->havingRaw('SUM(grabs) > 0')
            ->orderBy('grabs', 'desc')
            ->limit(10)
            ->get();

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put('topdownloads', $releases, $expiresAt);

        return $releases;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection|static[]
     */
    public function getTopComments()
    {
        $comments = Cache::get('topcomments');
        if ($comments !== null) {
            return $comments;
        }

        $comments = Release::query()
            ->where('comments', '>', 0)
            ->select(['id', 'guid', 'searchname'])
            ->selectRaw('SUM(comments) AS comments')
            ->groupBy('id', 'searchname', 'adddate')
            ->havingRaw('SUM(comments) > 0')
            ->orderBy('comments', 'desc')
            ->limit(10)
            ->get();
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put('topcomments', $comments, $expiresAt);

        return $comments;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection|static[]
     */
    public function getRecentlyAdded()
    {
        $recent = Cache::get('recentlyadded');
        if ($recent !== null) {
            return $recent;
        }

        $recent = CategoryModel::query()
            ->where('r.adddate', '>', Carbon::now()->subWeek())
            ->selectRaw('CONCAT(cp.title, " > ", categories.title) as title')
            ->selectRaw('COUNT(r.id) as count')
            ->join('categories as cp', 'cp.id', '=', 'categories.parentid')
            ->join('releases as r', 'r.categories_id', '=', 'categories.id')
            ->groupBy('title')
            ->orderBy('count', 'desc')
            ->get();

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put('recentlyadded', $recent, $expiresAt);

        return $recent;
    }

    /**
     * Get all newest movies with coves for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestMovies()
    {
        $sql = sprintf(
            'SELECT r.imdbid, r.guid, r.name, r.searchname, r.size, r.completion,
				postdate, categories_id, comments, grabs,
				m.cover
			FROM releases PARTITION (movies) r
			INNER JOIN movieinfo m USING (imdbid)
			WHERE m.imdbid > 0
			AND m.cover = 1
			AND r.id in (select max(id) from releases where imdbid > 0 group by imdbid)
			ORDER BY r.postdate DESC
			LIMIT 24'
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest xxx with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestXXX()
    {
        $sql = sprintf(
            'SELECT r.xxxinfo_id, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs,
				xxx.cover, xxx.title
			FROM releases PARTITION (xxx) r
			INNER JOIN xxxinfo xxx ON r.xxxinfo_id = xxx.id
			WHERE xxx.id > 0
			AND xxx.cover = 1
			AND r.id in (select max(id) from releases where xxxinfo_id > 0 group by xxxinfo_id)
			ORDER BY r.postdate DESC
			LIMIT 20'
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest console games with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestConsole()
    {
        $sql = sprintf(
            'SELECT r.consoleinfo_id, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs,
				con.cover
			FROM releases PARTITION (console) r
			INNER JOIN consoleinfo con ON r.consoleinfo_id = con.id
			WHERE con.id > 0
			AND con.cover > 0
			AND r.id IN (SELECT max(id) FROM releases WHERE consoleinfo_id > 0 GROUP BY consoleinfo_id)
			ORDER BY r.postdate DESC
			LIMIT 35'
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest PC games with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestGames()
    {
        $sql = sprintf(
            'SELECT r.gamesinfo_id, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs,
				gi.cover
			FROM releases r
			INNER JOIN gamesinfo gi ON r.gamesinfo_id = gi.id
			WHERE r.categories_id = 4050
			AND gi.id > 0
			AND gi.cover > 0
			AND r.id in (select max(id) from releases where gamesinfo_id > 0 group by gamesinfo_id)
			ORDER BY r.postdate DESC
			LIMIT 24'
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest music with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestMP3s()
    {
        $sql = sprintf(
            sprintf('SELECT r.musicinfo_id, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs,
				m.cover
			FROM releases PARTITION (audio) r
			INNER JOIN musicinfo m ON r.musicinfo_id = m.id
			WHERE m.id > 0
			AND m.cover > 0
			OR r.categories_id != %d
			AND r.id in (select max(id) from releases where musicinfo_id > 0 group by musicinfo_id)
			ORDER BY r.postdate DESC
			LIMIT 24', Category::MUSIC_AUDIOBOOK)
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest books with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestBooks()
    {
        $sql = sprintf(
            sprintf('SELECT r.bookinfo_id, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs,
				b.url,	b.cover, b.title as booktitle, b.author
			FROM releases PARTITION (books) r
			INNER JOIN bookinfo b ON r.bookinfo_id = b.id
			WHERE b.id > 0
			OR r.categories_id = %d
			AND b.cover > 0
			AND r.id in (select max(id) from releases where bookinfo_id > 0 group by bookinfo_id)
			ORDER BY r.postdate DESC
			LIMIT 24', Category::MUSIC_AUDIOBOOK)
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest TV with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestTV()
    {
        $sql = sprintf(
            'SELECT r.videos_id, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs,
				v.id AS tvid, v.title AS tvtitle, v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tvi.image
			FROM releases PARTITION (tv) r
			INNER JOIN videos v ON r.videos_id = v.id
			INNER JOIN tv_info tvi ON r.videos_id = tvi.videos_id
			WHERE v.id > 0
			AND v.type = 0
			AND tvi.image = 1
			AND r.id in (select max(id) from releases where videos_id > 0 group by videos_id)
			ORDER BY r.postdate DESC
			LIMIT 24'
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get all newest anime with covers for poster wall.
     *
     *
     * @return array|bool
     */
    public function getNewestAnime()
    {
        $sql = sprintf(
            "SELECT r.anidbid, r.guid, r.name, r.searchname, r.size, r.completion,
				r.postdate, r.categories_id, r.comments, r.grabs, at.title
			FROM releases r
			INNER JOIN anidb_titles at USING (anidbid)
			INNER JOIN anidb_info ai USING (anidbid)
			WHERE r.categories_id = 5070
			AND at.anidbid > 0
			AND at.lang = 'en'
			AND ai.picture != ''
			AND r.id IN (SELECT MAX(id) FROM releases WHERE anidbid > 0 GROUP BY anidbid)
			GROUP BY r.id
			ORDER BY r.postdate DESC
			LIMIT 24"
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }
}
