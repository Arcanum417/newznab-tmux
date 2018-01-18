<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateContentTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('content', function(Blueprint $table)
		{
			$table->integer('id', true);
			$table->string('title', 255);
			$table->string('url', 2000)->nullable();
			$table->text('body', 65535)->nullable();
			$table->string('metadescription', 1000);
			$table->string('metakeywords', 1000);
			$table->integer('contenttype');
			$table->integer('showinmenu');
			$table->integer('status');
			$table->integer('ordinal')->nullable();
			$table->integer('role')->default(0);
			$table->index(['showinmenu','status','contenttype','role'], 'ix_showinmenu_status_contenttype_role');
		});

        DB::unprepared('ALTER TABLE content AUTO_INCREMENT 1000001');
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('content');
	}

}
