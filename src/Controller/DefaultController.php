<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends Controller
{
    function curlFile($url)
    {
        $proxyName = 'socks5.web-intaro.ru';
        $proxyPort = 4282;
        $logpass = 'socks5:rknFree2018';

        $ch = curl_init();
        $parameter = [
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROXY => $proxyName,
            CURLOPT_PROXYPORT => $proxyPort,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_PROXYUSERPWD => $logpass,
            CURLOPT_URL => $url,
        ];
        curl_setopt_array($ch, $parameter);
        $data = curl_exec($ch);
        curl_close($ch);
        return json_decode($data);
    }

    /**
     * @Route("/", name="index")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index()
    {
        if (isset($_GET["setWebhook"])) {
            $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/setWebhook?url=https://tgbot.skillum.ru/";
            dump($this->curlFile($url));
        }
        if (isset($_GET["getWebhookInfo"])) {
            $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/getWebhookInfo";
            dump($this->curlFile($url));
        }
        if (isset($_GET["sendMessage"])) {
            $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/sendMessage?chat_id=";
            dump($this->curlFile($url));
        }
        if (isset($_GET["getUpdates"])) {
            $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/getUpdates";
            dump($this->curlFile($url));
        }
        if (isset($_GET["Update"])) {
            $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/GetUpdate";
            dump($this->curlFile($url));
        }
        return $this->render('index.html.twig');
    }
}