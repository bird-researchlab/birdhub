<?php

// İzin verilen kaynakların beyaz listesi — keyfi URL proxy'lenmesin diye
$SOURCES = [
    'tokyo' => 'https://mp3s.nc.u-tokyo.ac.jp/Fuji_CyberForest.mp3',
    'ytu'   => 'https://birdstream.yildiz.edu.tr/ta1afo.mp3',
];

$key = isset($_GET['src']) ? $_GET['src'] : '';

if (!isset($SOURCES[$key])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Gecersiz kaynak.';
    exit;
}

$url = $SOURCES[$key];

if (isset($_GET['t'])) {
    $url .= (strpos($url, '?') === false ? '?' : '&') . 't=' . preg_replace('/[^0-9]/', '', $_GET['t']);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,  // direkt stream et, belleğe alma
    CURLOPT_SSL_VERIFYPEER => false,  // <- asil sorunu cozen satir (gecici bypass)
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 0,      // canli stream, suresiz
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'BirdHub-Proxy/1.0',
    CURLOPT_HEADERFUNCTION => function($curl, $header) {
        if (stripos($header, 'Content-Type:') === 0) {
            header(trim($header));
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION => function($curl, $data) {
        echo $data;
        @ob_flush();
        @flush();
        return strlen($data);
    },
]);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

if (!headers_sent()) {
    header('Content-Type: audio/mpeg');
}

curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(502);
    error_log('audio-proxy.php hata (' . $key . '): ' . curl_error($ch));
}

curl_close($ch);
