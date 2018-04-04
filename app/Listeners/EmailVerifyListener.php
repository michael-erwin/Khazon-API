<?php

namespace App\Listeners;

use App\Events\EmailVerifyEvent;

class EmailVerifyListener
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
    
    public function handle(EmailVerifyEvent $event)
    {
        $email = $event->email;
        $otp = $event->otp;
        $otp_exp = $event->otp_exp;

        $provider = $this->getProvider($email);
        $content = $this->getMessage($otp, $otp_exp);

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
        $message = (new \Swift_Message('Verify Your Email'))
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

    private function getMessage($otp, $otp_exp)
    {
        $expiry_minute = round($otp_exp / 60);
        return <<<MESSAGE
        <p>Input the following to complete your email verification:</p>
        <p><div style="display:inline-block;font-size:1.8em;font-family:monospace;font-weight:bold;padding: 5px"><code>{$otp}</code></div></p>
        <p>Note: the code is valid for {$expiry_minute} minutes only.</p>
MESSAGE;
    }
}
