<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreatePredbHashesTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('predb_hashes', function(Blueprint $table)
		{
			$table->integer('predb_id')->unsigned()->default(0)->comment('id, of the predb entry, this hash belongs to');
		});

		DB::unprepared('ALTER TABLE predb_hashes ADD COLUMN hash VARBINARY(40) DEFAULT ""');
        DB::unprepared('ALTER TABLE predb_hashes ADD PRIMARY KEY (hash)');
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('predb_hashes');
	}

}
