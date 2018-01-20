<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateRoleExcludedCategoriesTable extends Migration {

	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up()
	{
		Schema::create('role_excluded_categories', function(Blueprint $table)
		{
			$table->increments('id');
			$table->integer('user_roles_id');
			$table->integer('categories_id')->nullable();
			$table->timestamps();
			$table->unique(['user_roles_id','categories_id'], 'ix_roleexcat_rolecat');
		});
	}


	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::drop('role_excluded_categories');
	}

}
