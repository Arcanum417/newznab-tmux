<?php

namespace App\Mail;

use App\Models\Settings;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $newPass;

    /**
     * Create a new message instance.
     *
     * @param $userId
     * @param $newPass
     */
    public function __construct($userId, $newPass)
    {
        $this->user = User::query()->where('id', $userId)->first();
        $this->newPass = $newPass;
    }

    /**
     * Build the message.
     *
     * @return $this
     * @throws \Exception
     */
    public function build()
    {
        return $this->from(Settings::settingValue('site.main.email'))->subject('Password reset')->view('emails.forgottenPassword')->with(['newPass' => $this->newPass, 'userName' => $this->user->username, 'site' => Settings::settingValue('site.main.title'),]);
    }
}