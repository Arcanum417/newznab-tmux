<?php

use Illuminate\Database\Seeder;

class ContentTableSeeder extends Seeder
{

    /**
     * Auto generated seed file
     *
     * @return void
     */
    public function run()
    {


        \DB::table('content')->delete();

        \DB::table('content')->insert(array (
            0 =>
            array (
                'id' => 1,
                'title' => 'Welcome to NNTmux.',
                'url' => 'NULL',
                'body' => '<p>Since NNTmux is a fork of newznab, the API is compatible with sonarr, sickbeard, couchpotato, etc...</p>',
                'metadescription' => '""',
                'metakeywords' => '""',
                'contenttype' => 3,
                'showinmenu' => 0,
                'status' => 1,
                'ordinal' => 0,
                'role' => 0,
            ),
            1 =>
            array (
                'id' => 2,
                'title' => 'example content',
                'url' => '/great/seo/content/page/',
                'body' => '<p>this is an example content page</p>',
                'metadescription' => '""',
                'metakeywords' => '""',
                'contenttype' => 1,
                'showinmenu' => 2,
                'status' => 1,
                'ordinal' => 1,
                'role' => 0,
            ),
            2 =>
            array (
                'id' => 3,
                'title' => 'another example',
                'url' => '/another/great/seo/content/page/',
                'body' => '<p>this is another example content page</p>',
                'metadescription' => '""',
                'metakeywords' => '""',
                'contenttype' => 1,
                'showinmenu' => 2,
                'status' => 1,
                'ordinal' => 0,
                'role' => 0,
            ),
        ));


    }
}