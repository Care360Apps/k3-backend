<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CareerPasswordReset extends Mailable
{
    public function __construct(public readonly string $code) {}

    public function build()
    {
        return $this->subject('Care360 career account recovery')->text('emails.career_password_reset', ['code' => $this->code]);
    }
}
