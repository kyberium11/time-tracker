<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewUserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $email;
    public string $password;
    public string $loginUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(string $userName, string $email, string $password, ?string $loginUrl = null)
    {
        $this->userName = $userName;
        $this->email = $email;
        $this->password = $password;
        $this->loginUrl = $loginUrl ?: rtrim(config('app.url') ?? url('/'), '/') . '/login';
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('Your Time Tracker account credentials')
            ->view('emails.new-user-credentials')
            ->with([
                'userName' => $this->userName,
                'email' => $this->email,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}


