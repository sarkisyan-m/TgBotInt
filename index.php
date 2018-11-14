<?php

function dump($val)
{
    print '<div style="font-size:14px; font-weight:bold;">';
    highlight_string("\n<?php\n" . var_export($val, true) . "\n?>\n");
    print '</div>';
}

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

//https://habr.com/post/404921/

$post = print_r($_POST, true) . "\n"  . print_r($_GET, true) . "test";
$n = "\n";
$date = date("H:i:s");

$filename = "postgetbot.txt";
$text =  $n . $date . $n . $post . $n;
file_put_contents($filename, $text, FILE_APPEND);

$token = '685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0';

if (isset($_GET["getUpdates"])) {
    $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/getUpdates";

    dump(curlFile($url));
}

if (isset($_GET["getUserProfilePhotos"])) {
    $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/getUserProfilePhotos?user_id=338308809";

    dump(curlFile($url));
}

if (isset($_GET["setWebhook"])) {
    $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/setWebhook?url=http://tgbot.skillum.ru/";

    dump(curlFile($url));

    print 123;
}

if (isset($_GET["getWebhookInfo"])) {
    $url = "https://api.telegram.org/bot685643586:AAGpJOliZNJ-7mC_tf5avSgHOsp7gpOpXI0/getWebhookInfo";

    dump(curlFile($url));

    print 123;
}

