<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use nntmux\SphinxSearch;

class NntmuxResetDb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:resetdb';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will reset your database to blank state (will retain settings)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function handle()
    {

        if ($this->confirm('This script removes all releases, nzb files, samples, previews , nfos, truncates all article tables and resets all groups. Are you sure you want reset the DB?')) {
            $timestart = Carbon::now();

            Group::query()->update([
                'first_record' => 0,
                'first_record_postdate' => null,
                'last_record' => 0,
                'last_record_postdate' => null,
                'last_updated' => null
            ]);
            $this->info('Reseting all groups completed.');

            $arr = [
                'videos',
                'tv_episodes',
                'tv_info',
                'release_nfos',
                'release_comments',
                'sharing',
                'sharing_sites',
                'users_releases',
                'user_movies',
                'user_series',
                'movieinfo',
                'musicinfo',
                'release_files',
                'audio_data',
                'release_subtitles',
                'video_data',
                'releaseextrafull',
                'releases',
                'anidb_titles',
                'anidb_info',
                'anidb_episodes',
                'releases_groups',
            ];
            foreach ($arr as &$value) {
                $rel = DB::unprepared("TRUNCATE TABLE $value");
                DB::commit();
                if ($rel === true) {
                    $this->info('Truncating '.$value.' completed.');
                }
            }
            unset($value);
            DB::unprepared('SET FOREIGN_KEY_CHECKS = 0;');
            DB::commit();
            $this->info('Truncating binaries, collections, missed_parts and parts tables...');
            DB::unprepared("CALL loop_cbpm('truncate')");
            DB::commit();
            $this->info('Truncating completed.');

            (new SphinxSearch())->truncateRTIndex('releases_rt');

            $this->info('Deleting nzbfiles subfolders.');
            $files = File::allfiles(Settings::settingValue('..nzbpath'));
            File::delete($files);

            $this->info('Deleting all images, previews and samples that still remain.');

            $files = File::allfiles(NN_COVERS);
            foreach ($files as $file) {
                if (basename($file) !== '.gitignore' && basename($file) !== 'no-cover.jpg' && basename($file) !== 'no-backdrop.jpg') {
                    File::delete($file);
                }
            }

            $this->info('Deleted all releases, images, previews and samples. This script finished '.Carbon::now()->diffForHumans($timestart).' start');
            DB::unprepared('SET FOREIGN_KEY_CHECKS = 1;');
            DB::commit();
        } else {
            $this->info('Script execution stopped');
        }
    }
}