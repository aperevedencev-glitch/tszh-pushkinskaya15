<?php
// Тестовый config.php из config.example.php — так же, как его заполняет человек на хостинге:
// правятся только нужные строки, app_secret и webhook_secret остаются пустыми (их впишет мастер установки).
// php tests/make_config.php ПУТЬ_К_PRIVATE SITE_URL API_BASE INSTALL_KEY
[, $priv, $site, $api, $key] = $argv;
$src = file_get_contents("$priv/config.example.php");
$rep = [
    "'site_url' => 'https://ВАШ-ДОМЕН.ru'" => "'site_url' => '$site'",
    "'driver'  => 'mysql'," => "'driver'  => 'sqlite', 'path' => __DIR__ . '/data/crm.sqlite',",
    "'install_key' => ''," => "'install_key' => '$key',",
    "'bot_token'      => ''," => "'bot_token'      => '123:TEST',",
    "'api_base'       => 'https://api.telegram.org'," => "'api_base'       => '$api',",
];
foreach ($rep as $from => $to) {
    if (substr_count($src, $from) !== 1) {
        fwrite(STDERR, "Не найдено в config.example.php: $from\n");
        exit(1);
    }
    $src = str_replace($from, $to, $src);
}
file_put_contents("$priv/config.php", $src);
