<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;

class AdminPageController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index()
    {
        $this->adminBasePage();
    }
}
