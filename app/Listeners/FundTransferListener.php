<?php

namespace App\Listeners;

use Log;
use App\Events\FundTransferEvent;

class FundTransferListener
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

    public function handle(FundTransferEvent $event)
    {
        $alert_kta_transfer = config('general.alert_kta_transfer');
        

        // Notify email if amount is large sum.
        if ($event->amount > $alert_kta_transfer)
        {
            $email_list = explode(',', config('general.alert_to_emails'));
            $valid_emails = [];
            
            // Validate emails from config.
            foreach ($email_list as $email)
            {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) $valid_emails [] = $email;
            }

            // Send if valid email is found.
            if (count($valid_emails) > 0)
            {
                $content = $this->getMessage($event);
                $this->send($valid_emails, $content, 'sendpulse');
            }
        }
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
        $message = (new \Swift_Message('Fund Transfer Alert'))
                ->setFrom([$this->send_from => 'Khazon Online'])
                ->setTo($send_to)
                ->setBody($content, 'text/html');
        return $mailer->send($message);
    }

    private function getMessage($event)
    {
        $sender = $event->sender;
        $receiver = $event->receiver;
        $amount = $event->amount; 

        return <<<MESSAGE
        <p>Suspicious transaction has been detected.</p>
        <table>
          <tr><td><b>Amount</b></td><td>{$amount} KTA</td></tr>
          <tr><td colspan="2">&nbsp;</td></tr>
          <tr><td colspan="2"><b>Sender</b></td></tr>
          <tr><td>Email</td><td>{$sender->email}</td></tr>
          <tr><td>Address</td>{$sender->address}</tr>
          <tr><td>Balance</td>{$sender->balance}</tr>
          <tr><td colspan="2">&nbsp;</td></tr>
          <tr><td colspan="2"><b>Receiver</b></td></tr>
          <tr><td>Email</td><td>{$receiver->email}</td></tr>
          <tr><td>Address</td>{$receiver->address}</tr>
          <tr><td>Balance</td>{$receiver->balance}</tr>
        </table>
MESSAGE;
    }
}
