<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateReleasesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->default('')->index('ix_releases_name');
            $table->string('searchname')->default('');
            $table->integer('totalpart')->nullable()->default(0);
            $table->integer('groups_id')->unsigned()->default(0)->comment('FK to groups.id');
            $table->bigInteger('size')->unsigned()->default(0);
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->timestamp('updatetime')->useCurrent();
            $table->string('gid', 32)->nullable();
            $table->string('guid', 40)->index('ix_releases_guid');
            $table->char('leftguid', 1)->comment('The first letter of the release guid');
            $table->string('fromname')->nullable();
            $table->float('completion', 10, 0)->default(0);
            $table->integer('categories_id')->default(10);
            $table->integer('videos_id')->unsigned()->default(0)->index('ix_releases_videos_id')->comment('FK to videos.id of the parent series.');
            $table->integer('tv_episodes_id')->default(0)->index('ix_releases_tv_episodes_id')->comment('FK to tv_episodes.id for the episode.');
            $table->integer('imdbid')->unsigned()->nullable()->index('ix_releases_imdbid');
            $table->integer('xxxinfo_id')->default(0)->index('ix_releases_xxxinfo_id');
            $table->integer('musicinfo_id')->nullable()->comment('FK to musicinfo.id');
            $table->integer('consoleinfo_id')->nullable()->index('ix_releases_consoleinfo_id')->comment('FK to consoleinfo.id');
            $table->integer('gamesinfo_id')->default(0)->index('ix_releases_gamesinfo_id');
            $table->integer('bookinfo_id')->nullable()->index('ix_releases_bookinfo_id')->comment('FK to bookinfo.id');
            $table->integer('anidbid')->nullable()->index('ix_releases_anidbid')->comment('FK to anidb_titles.anidbid');
            $table->integer('predb_id')->unsigned()->default(0)->comment('FK to predb.id');
            $table->integer('grabs')->unsigned()->default(0);
            $table->integer('comments')->default(0);
            $table->boolean('passwordstatus')->default(0)->index('ix_releases_passwordstatus');
            $table->integer('rarinnerfilecount')->default(0);
            $table->boolean('haspreview')->default(0);
            $table->boolean('nfostatus')->default(0);
            $table->boolean('jpgstatus')->default(0);
            $table->boolean('videostatus')->default(0);
            $table->boolean('audiostatus')->default(0);
            $table->boolean('dehashstatus')->default(0);
            $table->boolean('reqidstatus')->default(0);
            $table->boolean('nzbstatus')->default(0);
            $table->boolean('iscategorized')->default(0);
            $table->boolean('isrenamed')->default(0);
            $table->boolean('ishashed')->default(0);
            $table->boolean('proc_pp')->default(0);
            $table->boolean('proc_sorter')->default(0);
            $table->boolean('proc_par2')->default(0);
            $table->boolean('proc_nfo')->default(0);
            $table->boolean('proc_files')->default(0);
            $table->boolean('proc_uid')->default(0);
            $table->boolean('proc_srr')->default(0)->comment('Has the release been srr
processed');
            $table->boolean('proc_hash16k')->default(0)->comment('Has the release been hash16k
processed');
            $table->index(['groups_id','passwordstatus'], 'ix_releases_groupsid');
            $table->index(['postdate','searchname'], 'ix_releases_postdate_searchname');
            $table->index(['leftguid','predb_id'], 'ix_releases_leftguid');
            $table->index(['musicinfo_id','passwordstatus'], 'ix_releases_musicinfo_id');
            $table->index(['predb_id','searchname'], 'ix_releases_predb_id_searchname');
            $table->index(['haspreview','passwordstatus'], 'ix_releases_haspreview_passwordstatus');
            $table->index(['nfostatus','size'], 'ix_releases_nfostatus');
            $table->index(['dehashstatus','ishashed'], 'ix_releases_dehashstatus');
        });

        DB::unprepared('ALTER TABLE releases DROP PRIMARY KEY , ADD PRIMARY KEY (id, categories_id)');
        DB::unprepared('ALTER TABLE releases PARTITION BY RANGE (categories_id) (
    PARTITION misc    VALUES LESS THAN (1000),
    PARTITION console VALUES LESS THAN (2000),
    PARTITION movies  VALUES LESS THAN (3000),
    PARTITION audio   VALUES LESS THAN (4000),
    PARTITION pc      VALUES LESS THAN (5000),
    PARTITION tv      VALUES LESS THAN (6000),
    PARTITION xxx     VALUES LESS THAN (7000),
    PARTITION books   VALUES LESS THAN (8000)
  );');
        DB::unprepared('ALTER TABLE releases ADD COLUMN nzb_guid BINARY(16) NULL');
        DB::unprepared('ALTER TABLE releases ADD INDEX ix_releases_nzb_guid (nzb_guid)');
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('releases');
    }
}
