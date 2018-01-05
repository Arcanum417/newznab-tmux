<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Yadakhov\InsertOnDuplicateKey;

class AnidbTitle extends Model
{
    use InsertOnDuplicateKey;

    /**
     * @var string
     */
    protected $primaryKey = 'anidbid';

    /**
     * @var bool
     */
    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $guarded = [];

    public function episode()
    {
        return $this->hasMany(AnidbEpisode::class, 'anidbid');
    }

    public function info()
    {
        return $this->hasMany(AnidbInfo::class, 'anidbid');
    }
}
