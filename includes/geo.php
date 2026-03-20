<?php
class GeoIntelligence {
    private $municipalities = [];
    private $cacheFile = 'municipios_ibge_cache.json';

    public function __construct() {
        if (!file_exists($this->cacheFile)) {
             // Try absolute path assuming we are in includes/ and file is in root
             $this->cacheFile = __DIR__ . '/../municipios_ibge_cache.json';
        }
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
            $data = json_decode($content, true);
            
            // Fallback for JSON errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                $start = strpos($content, '[');
                if ($start !== false) {
                    $content = substr($content, $start);
                    $data = json_decode($content, true);
                }
            }

            if (is_array($data)) {
                foreach ($data as $m) {
                    if (isset($m['codigo_ibge'])) {
                        $ibge = (int)$m['codigo_ibge'];
                        $this->municipalities[$ibge] = [
                            'lat' => (float)$m['latitude'], 
                            'lon' => (float)$m['longitude'], 
                            'nome' => $m['nome'],
                            'uf' => $m['codigo_uf']
                        ];
                    }
                }
            }
        }
    }

    public function getCoordsByIbge($ibgeCode) {
        $code = (int)$ibgeCode;
        if (isset($this->municipalities[$code])) return $this->municipalities[$code];
        $shortCode = (int)substr((string)$code, 0, 6);
        if (isset($this->municipalities[$shortCode])) return $this->municipalities[$shortCode];
        return null;
    }

    public function getDistance($lat1, $lon1, $lat2, $lon2) {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        return $dist * 60 * 1.1515 * 1.609344;
    }

    // New method to fetch aggregated data from DB for the map
    public function getMapDataFromDB($pdo, $whereGlobal = "") {
        // $whereGlobal already has "AND ..."
        
        // Let's modify the query to group by City/UF.
        $sql = "SELECT c.city, c.uf, SUM(i.total_value) as total 
                FROM invoices i 
                JOIN customers c ON i.customer_id = c.id 
                WHERE 1=1 $whereGlobal AND i.status != 'Cancelada' 
                GROUP BY c.city, c.uf";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();

        $mapData = [];
        foreach ($results as $row) {
            $city = $row['city'];
            $uf = $row['uf'];
            $val = $row['total'];
            
            // Try to find coords in cache by Name + UF
            // This is O(N) per city, but N (cities in cache) is ~5500. Not too bad for a few dozen customer cities.
            $coords = $this->findCoordsByName($city, $uf);
            
            if ($coords) {
                $key = "$city - $uf";
                $mapData[$key] = [
                    'val' => $val,
                    'lat' => $coords['lat'],
                    'lon' => $coords['lon'],
                    'city' => $city
                ];
            }
        }
        return $mapData;
    }

    private function normalize($str) {
        $str = mb_strtolower($str, 'UTF-8');
        $str = preg_replace('/[áàãâä]/u', 'a', $str);
        $str = preg_replace('/[éèêë]/u', 'e', $str);
        $str = preg_replace('/[íìîï]/u', 'i', $str);
        $str = preg_replace('/[óòõôö]/u', 'o', $str);
        $str = preg_replace('/[úùûü]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        return trim($str);
    }

    private function findCoordsByName($name, $uf) {
        $normName = $this->normalize($name);
        $normUF = $this->normalize($uf);

        foreach ($this->municipalities as $m) {
            if ($this->normalize($m['nome']) === $normName && $this->normalize($m['uf']) === $normUF) {
                return $m;
            }
        }
        return null;
    }

    public function getTopCustomersLocations($pdo, $whereGlobal = "", $limit = 50) {
        $sql = "SELECT c.name, c.city, c.uf, SUM(i.total_value) as total 
                FROM invoices i 
                JOIN customers c ON i.customer_id = c.id 
                WHERE 1=1 $whereGlobal AND i.status != 'Cancelada' 
                GROUP BY c.id 
                ORDER BY total DESC 
                LIMIT $limit";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();

        $markers = [];
        foreach ($results as $row) {
            $coords = $this->findCoordsByName($row['city'], $row['uf']);
            if ($coords) {
                $markers[] = [
                    'name' => $row['name'],
                    'city' => $row['city'],
                    'val' => $row['total'],
                    'lat' => $coords['lat'],
                    'lon' => $coords['lon']
                ];
            }
        }
        return $markers;
    }
}
