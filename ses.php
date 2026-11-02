<?php
// BirdHub — TEK PHP DOSYASI. Ses ağ geçidi + düzenli yayın kontrolü + log + yönetim sayfası + Excel.
//   ses.php?src=tr|jp   -> o an geçerli olan sesi sunar (canlı yayın veya kayıt)
//   ses.php?durum=1     -> {"tr":"canli|kayit","jp":"canli|kayit"}  (süresi dolmuşsa kontrolü de yapar)
//   ses.php?yonetim     -> şifreli yönetim sayfası (zorlama, anlık kontrol, Excel raporu)
//   ses.php?kontrol=KEY -> (isteğe bağlı) cron ile zorunlu kontrol
// Durum ve log, aynı klasördeki gizli ".ses-veri.json" dosyasında tutulur (.htaccess dışarıya kapatır).

$AYAR = [
    'kontrol_aralik' => 300,                  // saniye; 300 = 5 dakikada bir (hafif: yayından yalnızca 16 KB çekilir)
    'sifre'          => '3c12b54d5acbfc9c',          // yönetim sayfası şifresi — canlıya almadan değiştirin
    'cron_anahtar'   => '464cc81e5404059ab758ced3e656b40e',
    'kaynaklar' => [
        'tr' => [
            'canli' => 'https://birdstream.yildiz.edu.tr/ta1afo.mp3',
            'proxy' => false,                 // false: tarayıcı doğrudan bağlanır (302 yönlendirme)
            'ssl'   => true,                  // sertifika doğrulaması
            'kayit' => __DIR__ . '/assets/data/yedek/yedek.mp3',      // sabah kaydı: bu dosyanın üzerine kopyalayın
        ],
        'jp' => [
            'canli' => 'https://mp3s.nc.u-tokyo.ac.jp/Fuji_CyberForest.mp3',
            'proxy' => true,                  // true: ses.php araya girip akıtır
            'ssl'   => true,                  // Tokyo sertifikası yine bozulursa geçici olarak false yapın
            'kayit' => __DIR__ . '/assets/data/yedek/jp-kayit.mp3',
        ],
    ],
];

date_default_timezone_set('Europe/Istanbul');
set_time_limit(0);
const VERI = __DIR__ . '/.ses-veri.json';
const ETIKET = ['canli' => 'Canlı', 'kayit' => 'Kayıt', '-' => '-'];

function bitir($kod, $metin) { http_response_code($kod); header('Content-Type: text/plain; charset=utf-8'); echo $metin; exit; }

// ================= Veri (durum + log tek dosyada) =================
function varsayilan($v) {
    foreach (['tr', 'jp'] as $t) $v[$t] = array_merge(['otomatik' => 'canli', 'mod' => 'auto', 'sebep' => '', 'son_kontrol' => 0], $v[$t] ?? []);
    $v['log'] = $v['log'] ?? [];
    return $v;
}
function oku() {
    $h = @fopen(VERI, 'r');
    if (!$h) return varsayilan([]);
    flock($h, LOCK_SH);
    $v = json_decode((string)stream_get_contents($h), true);
    flock($h, LOCK_UN); fclose($h);
    return varsayilan(is_array($v) ? $v : []);
}
function yazilabilir() { return file_exists(VERI) ? is_writable(VERI) : is_writable(__DIR__); }
// Kilit altında oku-değiştir-yaz. $fn(&$veri) çağrılır.
function degistir($fn) {
    $h = @fopen(VERI, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    $v = json_decode((string)stream_get_contents($h), true);
    $v = varsayilan(is_array($v) ? $v : []);
    $fn($v);
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($v, JSON_UNESCAPED_UNICODE));
    fflush($h); flock($h, LOCK_UN); fclose($h);
}
function etkin($v, $t) { return in_array($v[$t]['mod'], ['canli', 'kayit'], true) ? $v[$t]['mod'] : $v[$t]['otomatik']; }
function logla(&$v, $t, $onceki, $yeni, $sebep, $kaynak) {
    $v['log'][] = [date('Y-m-d H:i:s'), strtoupper($t), $onceki, $yeni, $sebep, $kaynak];
}

// ================= Yayın kontrolü: HTTP 2xx + kısa sürede en az 16 KB ses verisi =================
function probe($url, $ssl) {
    $bayt = 0; $kod = 0; $esik = 16384;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'BirdHub-Kontrol/1.0',
        CURLOPT_SSL_VERIFYPEER => $ssl, CURLOPT_SSL_VERIFYHOST => $ssl ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$kod) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $kod = (int)$m[1];
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$bayt, $esik) {   // yeterli veri gelince bağlantıyı kes
            $bayt += strlen($d);
            return $bayt >= $esik ? 0 : strlen($d);
        },
    ]);
    curl_exec($ch);
    $hata = curl_error($ch);
    curl_close($ch);
    if ($kod >= 200 && $kod < 300 && $bayt >= $esik) return [true, ''];
    if ($kod && ($kod < 200 || $kod >= 300)) return [false, 'HTTP ' . $kod];
    if ($bayt > 0) return [false, 'Yetersiz veri (' . $bayt . ' bayt)'];
    return [false, $hata !== '' ? $hata : 'Yanıt yok'];
}

// Süresi dolan tarafları kontrol eder, değişimi loglar. Kontrol kilit dışında yapılır (oku/yaz bekletilmesin).
function kontrol($kaynak = 'otomatik', $zorla = false) {
    global $AYAR;
    $v = oku(); $simdi = time(); $sonuc = [];
    // Durum kaydedilemiyorsa her istekte iki yayına bağlanıp boşa kontrol yapmayalım.
    if (!yazilabilir()) { error_log('ses.php: ' . VERI . ' yazılamıyor, kontrol atlandı'); return $v; }
    foreach (['tr', 'jp'] as $t) {
        if ($zorla || $simdi - (int)$v[$t]['son_kontrol'] >= $AYAR['kontrol_aralik']) {
            $k = $AYAR['kaynaklar'][$t];
            $sonuc[$t] = probe($k['canli'], !empty($k['ssl']));
        }
    }
    if (!$sonuc) return $v;
    degistir(function (&$v) use ($sonuc, $simdi, $kaynak, $zorla, $AYAR) {
        foreach ($sonuc as $t => $s) {
            list($ok, $sebep) = $s;
            if (!$zorla && $simdi - (int)$v[$t]['son_kontrol'] < $AYAR['kontrol_aralik']) continue;   // başkası az önce yaptı
            $ilk = ((int)$v[$t]['son_kontrol'] === 0);
            $onceki = $ilk ? '-' : etkin($v, $t);
            $v[$t]['otomatik'] = $ok ? 'canli' : 'kayit';
            $v[$t]['sebep'] = $ok ? '' : $sebep;
            $v[$t]['son_kontrol'] = $simdi;
            $yeni = etkin($v, $t);
            if ($ilk || $yeni !== $onceki) {
                $neden = $ilk ? ($ok ? 'İlk kontrol: yayın var' : 'İlk kontrol: yayın yok (' . $sebep . ')')
                              : ($ok ? 'Yayın geri geldi' : 'Yayın alınamadı (' . $sebep . ')');
                logla($v, $t, $onceki, $yeni, $neden, $kaynak);
            }
        }
    });
    return oku();
}

// ================= Excel (.xlsx) — ek kütüphane gerektirmeyen saf PHP =================
function xe($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function kol($i) { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function sayfa_xml($satirlar, $gen) {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($gen as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $x .= '</cols><sheetData>';
    foreach ($satirlar as $ri => $r) {
        $x .= '<row r="' . ($ri + 1) . '">';
        foreach ($r as $ci => $c) {
            $ref = kol($ci) . ($ri + 1); $s = $ri === 0 ? ' s="1"' : '';
            $x .= is_int($c) ? "<c r=\"$ref\"$s><v>$c</v></c>" : "<c r=\"$ref\"$s t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xe($c) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}
function zip_sakla($dosyalar) {   // sıkıştırmasız zip
    $o = ''; $m = ''; $n = 0; $g = getdate();
    $z = ($g['hours'] << 11) | ($g['minutes'] << 5) | intdiv($g['seconds'], 2);
    $d = (($g['year'] - 1980) << 9) | ($g['mon'] << 5) | $g['mday'];
    foreach ($dosyalar as $ad => $ic) {
        $crc = crc32($ic); $b = strlen($ic); $k = strlen($o);
        $o .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $z, $d, $crc, $b, $b, strlen($ad), 0) . $ad . $ic;
        $m .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $z, $d, $crc, $b, $b, strlen($ad), 0, 0, 0, 0, 0, $k) . $ad;
        $n++;
    }
    return $o . $m . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($m), strlen($o), 0);
}
function gunluk_ozet($log) {   // her gün her taraf için kaç dakika canlı / kayıt
    $olay = ['TR' => [], 'JP' => []]; $gun = [];
    foreach ($log as $r) { $ts = strtotime($r[0]); if ($ts && isset($olay[$r[1]])) $olay[$r[1]][] = [$ts, $r[3]]; }
    foreach ($olay as $taraf => $l) {
        usort($l, function ($a, $b) { return $a[0] <=> $b[0]; });
        foreach ($l as $i => $o) {
            $g = date('Y-m-d', $o[0]);
            $gun[$g][$taraf]['d'] = ($gun[$g][$taraf]['d'] ?? 0) + 1;
            $bas = $o[0]; $son = isset($l[$i + 1]) ? $l[$i + 1][0] : time();
            while ($bas < $son && ($o[1] === 'canli' || $o[1] === 'kayit')) {
                $kes = min($son, strtotime(date('Y-m-d', $bas) . ' +1 day')); $key = date('Y-m-d', $bas);
                $gun[$key][$taraf][$o[1]] = ($gun[$key][$taraf][$o[1]] ?? 0) + ($kes - $bas); $bas = $kes;
            }
        }
    }
    ksort($gun); $s = [];
    foreach ($gun as $g => $tl) foreach (['TR', 'JP'] as $t) if (isset($tl[$t])) $s[] = [$g, $t, (int)round(($tl[$t]['canli'] ?? 0) / 60), (int)round(($tl[$t]['kayit'] ?? 0) / 60), (int)($tl[$t]['d'] ?? 0)];
    return $s;
}
function excel($log) {
    $deg = [['Zaman', 'Taraf', 'Önceki', 'Yeni', 'Sebep', 'Kaynak']];
    foreach ($log as $r) $deg[] = [$r[0], $r[1], ETIKET[$r[2]] ?? $r[2], ETIKET[$r[3]] ?? $r[3], $r[4], $r[5]];
    $oz = array_merge([['Tarih', 'Taraf', 'Canlı (dk)', 'Kayıt (dk)', 'Değişiklik sayısı']], gunluk_ozet($log));
    $sayfalar = ['Günlük Özet' => [$oz, [14, 8, 14, 14, 20]], 'Değişiklikler' => [$deg, [20, 8, 10, 10, 48, 14]]];
    $ns = 'http://schemas.openxmlformats.org/';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="' . $ns . 'package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . $ns . 'spreadsheetml/2006/main" xmlns:r="' . $ns . 'officeDocument/2006/relationships"><sheets>';
    $rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . $ns . 'package/2006/relationships">';
    $f = []; $i = 1;
    foreach ($sayfalar as $ad => $s) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . xe($ad) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rel .= '<Relationship Id="rId' . $i . '" Type="' . $ns . 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $f['xl/worksheets/sheet' . $i . '.xml'] = sayfa_xml($s[0], $s[1]); $i++;
    }
    $rel .= '<Relationship Id="rId' . $i . '" Type="' . $ns . 'officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $st = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . $ns . 'spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    return zip_sakla([
        '[Content_Types].xml' => $ct . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . $ns . 'package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $wb . '</sheets></workbook>', 'xl/_rels/workbook.xml.rels' => $rel, 'xl/styles.xml' => $st,
    ] + $f);
}

// ================= Yönetim sayfası =================
function yonetim() {
    global $AYAR;
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
    header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('X-Robots-Tag: noindex');
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['csrf']; $hata = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) bitir(400, 'Geçersiz istek.');
        $i = $_POST['islem'] ?? '';
        if ($i === 'giris') {
            if (hash_equals($AYAR['sifre'], (string)($_POST['sifre'] ?? ''))) { session_regenerate_id(true); $_SESSION['giris'] = true; }
            else { sleep(2); $hata = 'Şifre yanlış.'; }
        } elseif (!empty($_SESSION['giris'])) {
            if ($i === 'cikis') { $_SESSION = []; session_destroy(); }
            if ($i === 'kontrol') kontrol('elle', true);
            if ($i === 'mod' && in_array($_POST['taraf'] ?? '', ['tr', 'jp'], true) && in_array($_POST['mod'] ?? '', ['auto', 'canli', 'kayit'], true)) {
                $t = $_POST['taraf']; $m = $_POST['mod'];
                degistir(function (&$v) use ($t, $m) {
                    if ($v[$t]['mod'] === $m) return;
                    $onceki = etkin($v, $t); $v[$t]['mod'] = $m;
                    logla($v, $t, $onceki, etkin($v, $t), ['auto' => 'Otomatiğe dönüldü', 'canli' => 'Elle: zorla canlı', 'kayit' => 'Elle: zorla kayıt'][$m], 'elle');
                });
            }
            header('Location: ses.php?yonetim'); exit;
        }
    }
    $giris = !empty($_SESSION['giris']);
    if ($giris && isset($_GET['excel'])) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="birdhub-rapor-' . date('Y-m-d') . '.xlsx"');
        echo excel(oku()['log']); exit;
    }
    $post = function ($islem, $ek = '') use ($h, $csrf) { return '<input type="hidden" name="csrf" value="' . $h($csrf) . '"><input type="hidden" name="islem" value="' . $islem . '">' . $ek; };
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>BirdHub Yönetim</title><style>
:root{--bg:#f6f7f8;--kt:#fff;--tx:#1d2328;--mt:#68737d;--bd:#dde2e6;--ac:#0e8f94;--kr:#c0392b}
@media (prefers-color-scheme:dark){:root{--bg:#14181b;--kt:#1d2328;--tx:#e8ecef;--mt:#93a0aa;--bd:#2f383f;--ac:#27b6bc;--kr:#e5695a}}
body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.5 system-ui,sans-serif}main{max-width:760px;margin:0 auto;padding:24px 16px}h1{font-size:1.3rem;margin:0 0 16px}
.k{background:var(--kt);border:1px solid var(--bd);border-radius:10px;padding:16px;margin-bottom:14px}.k h2{font-size:1rem;margin:0 0 8px}
.r{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.85rem;font-weight:600;background:var(--ac);color:#fff}.r.kayit{background:var(--kr)}.m{color:var(--mt);font-size:.9rem}
form.s{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}select,input,button{font:inherit;padding:7px 10px;border-radius:7px;border:1px solid var(--bd);background:var(--kt);color:var(--tx)}
button{cursor:pointer;background:var(--ac);color:#fff;border-color:var(--ac)}button.i{background:transparent;color:var(--tx);border-color:var(--bd)}
a.b{display:inline-block;padding:7px 12px;border-radius:7px;background:var(--ac);color:#fff;text-decoration:none}table{width:100%;border-collapse:collapse;font-size:.88rem}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--bd)}.e{color:var(--kr)}
</style></head><body><main>';
    if (!$giris) {
        echo '<h1>BirdHub Yönetim</h1><form method="post" class="k">' . $post('giris') . '<label>Şifre<br><input type="password" name="sifre" autofocus autocomplete="current-password"></label> <button>Giriş</button>'
           . ($hata ? '<p class="e">' . $h($hata) . '</p>' : '') . '</form></main></body></html>';
        return;
    }
    $v = oku();
    echo '<h1>BirdHub Yönetim</h1>';
    if (!yazilabilir()) echo '<div class="k e"><b>Durum dosyası yazılamıyor.</b> Otomatik kontrol, log ve zorlama çalışmaz; ses.php dosyasının bulunduğu klasöre yazma izni verin.</div>';
    foreach (['tr' => 'Türkiye (YTÜ)', 'jp' => 'Japonya (Tokyo)'] as $t => $ad) {
        $e = etkin($v, $t); $x = $v[$t];
        echo '<div class="k"><h2>' . $h($ad) . ' — şu an <span class="r ' . $e . '">' . ETIKET[$e] . '</span></h2><div class="m">Otomatik kontrol sonucu: ' . ETIKET[$x['otomatik']]
           . ($x['sebep'] ? ' (' . $h($x['sebep']) . ')' : '') . ' · Son kontrol: ' . ($x['son_kontrol'] ? date('d.m.Y H:i:s', $x['son_kontrol']) : 'henüz yok') . '</div>'
           . '<form method="post" class="s">' . $post('mod', '<input type="hidden" name="taraf" value="' . $t . '">') . '<select name="mod">';
        foreach (['auto' => 'Otomatik (her 5 dakikada kontrol)', 'canli' => 'Zorla canlı', 'kayit' => 'Zorla kayıt'] as $k => $l) echo '<option value="' . $k . '"' . ($x['mod'] === $k ? ' selected' : '') . '>' . $l . '</option>';
        echo '</select><button>Kaydet</button></form></div>';
    }
    echo '<div class="k"><form method="post" class="s" style="margin:0">' . $post('kontrol') . '<button class="i">Şimdi kontrol et</button> <a class="b" href="ses.php?yonetim&excel=1">Excel raporunu indir</a></form></div>'
       . '<div class="k"><h2>Son değişiklikler</h2><table><tr><th>Zaman</th><th>Taraf</th><th>Geçiş</th><th>Sebep</th></tr>';
    foreach (array_slice(array_reverse($v['log']), 0, 20) as $r) echo '<tr><td>' . $h($r[0]) . '</td><td>' . $h($r[1]) . '</td><td>' . $h((ETIKET[$r[2]] ?? $r[2]) . ' → ' . (ETIKET[$r[3]] ?? $r[3])) . '</td><td>' . $h($r[4]) . '</td></tr>';
    echo '</table><form method="post" class="s">' . $post('cikis') . '<button class="i">Çıkış</button></form></div></main></body></html>';
}

// ================= Yönlendirme =================
if (isset($_GET['yonetim'])) { yonetim(); exit; }

if (isset($_GET['durum'])) {
    $v = kontrol();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['tr' => etkin($v, 'tr'), 'jp' => etkin($v, 'jp')]);
    exit;
}

if (isset($_GET['kontrol'])) {
    if (!hash_equals($AYAR['cron_anahtar'], (string)$_GET['kontrol'])) bitir(403, 'Yetkisiz.');
    kontrol('cron', true);
    bitir(200, 'tamam');
}

$t = $_GET['src'] ?? '';
if (!isset($AYAR['kaynaklar'][$t])) bitir(400, 'Gecersiz kaynak.');
$k = $AYAR['kaynaklar'][$t];
// ?mod=canli|kayit yalnızca test içindir (A/B/C sayfalarını elle denemek için)
$mod = in_array($_GET['mod'] ?? '', ['canli', 'kayit'], true) ? $_GET['mod'] : etkin(oku(), $t);
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($mod === 'kayit') {            // kayıt: Range destekli (tarayıcı rastgele noktaya atlayabilsin)
    if (!is_file($k['kayit'])) bitir(503, 'Kayit dosyasi yok.');
    $boyut = filesize($k['kayit']); $bas = 0; $son = $boyut - 1;
    header('Content-Type: audio/mpeg'); header('Accept-Ranges: bytes');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') { $bas = (int)$m[1]; if ($m[2] !== '') $son = min((int)$m[2], $boyut - 1); }
        elseif ($m[2] !== '') $bas = max(0, $boyut - (int)$m[2]);
        if ($bas > $son || $bas >= $boyut) { header('Content-Range: bytes */' . $boyut); bitir(416, ''); }
        http_response_code(206); header("Content-Range: bytes $bas-$son/$boyut");
    }
    header('Content-Length: ' . ($son - $bas + 1));
    $f = fopen($k['kayit'], 'rb'); fseek($f, $bas); $kalan = $son - $bas + 1;
    while ($kalan > 0 && !feof($f) && !connection_aborted()) {
        $p = fread($f, min(65536, $kalan));
        if ($p === false || $p === '') break;
        echo $p; $kalan -= strlen($p); @ob_flush(); flush();
    }
    fclose($f); exit;
}

if (empty($k['proxy'])) { header('Location: ' . $k['canli'], true, 302); exit; }   // canlı: tarayıcı doğrudan bağlanır

$ch = curl_init($k['canli']);       // canlı + proxy (sertifika sorunu olan yayınlar için)
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 0, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => !empty($k['ssl']), CURLOPT_SSL_VERIFYHOST => !empty($k['ssl']) ? 2 : 0,
    CURLOPT_USERAGENT => 'BirdHub-Proxy/2.0',
    CURLOPT_WRITEFUNCTION => function ($c, $d) { echo $d; @ob_flush(); flush(); return strlen($d); },
]);
header('Content-Type: audio/mpeg');
curl_exec($ch);
if (curl_errno($ch)) { if (!headers_sent()) http_response_code(502); error_log('ses.php proxy hata (' . $t . '): ' . curl_error($ch)); }
curl_close($ch);
