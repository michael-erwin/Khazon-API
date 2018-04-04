<?php

namespace App\Listeners;

use App\Events\PwResetReqEvent;

class PwResetReqListener
{
    private $providers, $send_from;

    public function __construct()
    {
        $this->send_from = env('SMTP_SEND_FROM');
        $this->providers = [
            'yandex' => [
                'host' => env('SMTP_HOST_0'),
                'user' => env('SMTP_USER_0'),
                'pass' => env('SMTP_PASS_0'),
                'port' => env('SMTP_PORT_0'),
                'encr' => env('SMTP_ENCR_0'),
            ],
            'sendinblue' => [
                'host' => env('SMTP_HOST_1'),
                'user' => env('SMTP_USER_1'),
                'pass' => env('SMTP_PASS_1'),
                'port' => env('SMTP_PORT_1'),
                'encr' => env('SMTP_ENCR_1'),
            ],
            'sendpulse' => [
                'host' => env('SMTP_HOST_2'),
                'user' => env('SMTP_USER_2'),
                'pass' => env('SMTP_PASS_2'),
                'port' => env('SMTP_PORT_2'),
                'encr' => env('SMTP_ENCR_2'),
            ],
        ];
    }

    public function handle(PwResetReqEvent $event)
    {
        $email = $event->email;
        $token = $event->token;

        $provider = $this->getProvider($email);
        $content = $this->getMessage($token);

        // Send 1st attempt.
        $sent = $this->send($email, $content, $provider);
        // Send 2nd attemmpt.
        $next_provider = $provider == 'yandex' ? 'sendpulse' : 'sendinblue';
        if(!$sent) $this->send($email, $content, $next_provider);
    }

    private function send($send_to, $content, $provider)
    {
        $host = $this->providers[$provider]['host'];
        $user = $this->providers[$provider]['user'];
        $pass = $this->providers[$provider]['pass'];
        $port = $this->providers[$provider]['port'];
        $encr = $this->providers[$provider]['encr'];
        $transport = (new \Swift_SmtpTransport($host, $port, $encr))->setUsername($user)->setPassword($pass);
        $mailer = new \Swift_Mailer($transport);
        $message = (new \Swift_Message('Reset Your Password'))
                ->setFrom([$this->send_from => 'Khazon Online'])
                ->setTo([$send_to])
                ->setBody($content, 'text/html');
        return $mailer->send($message);
    }

    private function getProvider($email)
    {
        $provider = 'sendpulse';
        $microsoft_only = '/.+@(hotmail|outlook|live)\.com(\.[a-z]{2,})?/';
        $is_microsoft = preg_match($microsoft_only, $email);
        if($is_microsoft) $provider = 'yandex';
        return $provider;
    }

    private function getMessage($token)
    {
        $expiry_minute = round(env('JWT_LINK_EXPIRY') / 60);
        $reset_link = 'https://'.env('APP_DOMAIN').'/reset-password/'.preg_replace('/\./', '~', $token);
        return <<<MESSAGE
        <p>Reset your password by clicking this button:</p>
        <p>
            <a href="{$reset_link}" title="Click to reset your password"
             style="display:inline-block;padding:5px 18px;font-size:1.2em;color:white;background-color:#3273dc;border-radius:5px;text-decoration:none">
                Reset Now
            </a>
        </p>
        <p> Or manually paste the following link in address bar of browser:</p>
        <p><div style="padding:8px"><a href="{$reset_link}" style="text-decoration:none">{$reset_link}</a></div></p>
        <p>Note: the code is valid for {$expiry_minute} minutes only.</p>
MESSAGE;
    }
}
