<?php
class NCMRegistry {
    private static $descriptions = [];
    private static $loaded = false;

    public static function load($file) {
        if (self::$loaded) return;
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
            $json = json_decode($content, true);
            if (isset($json['Nomenclaturas'])) {
                foreach ($json['Nomenclaturas'] as $item) {
                    $cleanCode = str_replace('.', '', $item['Codigo']);
                    self::$descriptions[$cleanCode] = $item['Descricao'];
                }
            }
        }
        self::$loaded = true;
    }

    public static function getInfo($fullCode) {
        $candidates = [substr($fullCode, 0, 8), substr($fullCode, 0, 6), substr($fullCode, 0, 4), substr($fullCode, 0, 2)];
        foreach ($candidates as $code) {
            if (isset(self::$descriptions[$code])) return ['code' => $code, 'desc' => self::$descriptions[$code]];
        }
        return ['code' => substr($fullCode, 0, 4), 'desc' => 'Categoria Desconhecida'];
    }
}
