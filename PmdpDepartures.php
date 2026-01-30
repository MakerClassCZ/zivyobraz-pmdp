<?php
/**
 * PMDP Departures - třída pro načítání odjezdů z PMDP API
 *
 * Použití:
 *   // Nastavení konfigurace (jednou na začátku)
 *   PmdpDepartures::$cacheDir = __DIR__ . '/cache';
 *   PmdpDepartures::$stopsDbFile = __DIR__ . '/stops.json';
 *
 *   // Získání odjezdů
 *   $result = PmdpDepartures::get([
 *       'stops' => [40, 124],
 *       'exclude_headsigns' => ['Bory'],
 *       'limit' => 10,
 *   ]);
 */

class PmdpDepartures
{
    private const API_URL = 'https://jizdnirady.pmdp.cz/odjezdy/vyhledat';

    // Konfigurace cachování
    private const CACHE_REALTIME = 30;      // krátká cache když je odjezd brzy (kvůli zpoždění)
    private const CACHE_MAX = 900;          // maximální cache (15 min)
    private const REFRESH_BEFORE = 15;      // začít aktualizovat X minut před odjezdem

    // Globální konfigurace (nastavit před voláním get())
    public static ?string $cacheDir = null;
    public static ?string $stopsDbFile = null;

    // Interní cache pro načtenou databázi zastávek
    private static ?array $stopsDbCache = null;

    /**
     * Hlavní metoda pro získání odjezdů
     *
     * @param array $options [
     *   'stops' => array<int>,           // ID zastávek (povinné, max 3)
     *   'exclude_trips' => array<string>,// ID spojů k vynechání
     *   'exclude_headsigns' => array<string>, // cílové stanice k vynechání
     *   'limit' => int,                  // max počet (výchozí 15)
     *   'min_minutes' => int,            // odjezd nejdříve za X minut
     * ]
     * @return array [
     *   'departures' => array,
     *   'cache_max_age' => int,
     *   'first_departure_minutes' => int|null,
     *   'from_cache' => bool,
     * ]
     */
    public static function get(array $options): array
    {
        $stops = $options['stops'] ?? [];
        $excludeTrips = $options['exclude_trips'] ?? [];
        $excludeHeadsigns = $options['exclude_headsigns'] ?? [];
        // Limit: výchozí 15, min 1, max 50
        $limit = min(50, max(1, $options['limit'] ?? 15));
        $minMinutes = max(0, $options['min_minutes'] ?? 0);
        $stopsDb = self::getStopsDb();
        $cacheDir = self::$cacheDir;

        if (empty($stops)) {
            throw new InvalidArgumentException('Chybí povinný parametr: stops');
        }
        if (count($stops) > 3) {
            throw new InvalidArgumentException('Maximálně 3 zastávky');
        }

        // Cache
        if ($cacheDir) {
            $cached = self::cacheGet($cacheDir, $options);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Získání všech odjezdů z API pro všechny zastávky
        $allDepartures = [];
        $now = time();

        foreach ($stops as $stopId) {
            $apiData = self::fetchApi((int)$stopId, 30);
            if ($apiData === null) {
                continue;
            }

            foreach ($apiData as $dep) {
                // Filtr: exclude_trips
                $tripId = $dep['ConnectionId']['Id'] ?? null;
                if ($tripId && in_array((string)$tripId, $excludeTrips)) {
                    continue;
                }

                // Filtr: exclude_headsigns
                $headsign = $dep['LastStopName'] ?? '';
                $skip = false;
                foreach ($excludeHeadsigns as $exclude) {
                    if (stripos($headsign, $exclude) !== false) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                // Filtr: min_minutes
                if ($minMinutes > 0) {
                    $depTime = strtotime($dep['DepartureTime'] ?? 'now');
                    $delay = ($dep['DelayMin'] ?? 0) * 60;
                    $minutes = ($depTime + $delay - $now) / 60;
                    if ($minutes < $minMinutes) {
                        continue;
                    }
                }

                $stopName = $stopsDb[$stopId] ?? null;
                $allDepartures[] = [
                    'data' => self::transform($dep, (int)$stopId, $stopName),
                    'sort_time' => strtotime($dep['DepartureTime'] ?? 'now'),
                ];
            }
        }

        // Seřazení všech odjezdů podle času a oříznutí na limit
        usort($allDepartures, fn($a, $b) => $a['sort_time'] <=> $b['sort_time']);
        $allDepartures = array_slice($allDepartures, 0, $limit);
        $departures = array_map(fn($item) => $item['data'], $allDepartures);

        // Výpočet cache TTL
        // - Když je odjezd brzy (< REFRESH_BEFORE min), tak krátká cache kvůli aktuálnímu zpoždění
        // - Jinak se cachuje až do REFRESH_BEFORE minut před odjezdem (max CACHE_MAX)
        $firstMin = !empty($departures) ? ($departures[0]['departure']['minutes'] ?? 999) : 999;

        if ($firstMin <= self::REFRESH_BEFORE) {
            $cacheMaxAge = self::CACHE_REALTIME;
        } else {
            $secondsUntilRefresh = ($firstMin - self::REFRESH_BEFORE) * 60;
            $cacheMaxAge = min($secondsUntilRefresh, self::CACHE_MAX);
        }

        $result = [
            'departures' => $departures,
            'cache_max_age' => $cacheMaxAge,
            'first_departure_minutes' => $firstMin,
            'from_cache' => false,
        ];

        // Uložení do cache
        if ($cacheDir) {
            self::cacheSet($cacheDir, $options, $result);
        }

        return $result;
    }

    /**
     * Vrátí databázi zastávek (lazy loading)
     */
    private static function getStopsDb(): ?array
    {
        if (self::$stopsDbCache !== null) {
            return self::$stopsDbCache;
        }

        if (self::$stopsDbFile === null || !file_exists(self::$stopsDbFile)) {
            return null;
        }

        $data = json_decode(file_get_contents(self::$stopsDbFile), true);
        if (!is_array($data)) {
            return null;
        }

        self::$stopsDbCache = [];
        foreach ($data as $stop) {
            if (isset($stop['id'], $stop['name'])) {
                self::$stopsDbCache[$stop['id']] = $stop['name'];
            }
        }
        return self::$stopsDbCache;
    }

    /**
     * Načte odjezdy z PMDP API
     */
    private static function fetchApi(int $stopId, int $maxResults = 20): ?array
    {
        $payload = json_encode([
            'Stop' => [
                'StopId' => $stopId,
                'CISJRNumber' => null,
                'MarkerCode' => null,
                'Latitude' => null,
                'Longitude' => null,
                'MapyCzPoiType' => null,
            ],
            'DateAndTime' => null,
            'MaxResults' => $maxResults,
            'MaxResultsDateAndTime' => null,
            'FullResults' => false,
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Transformuje odjezd do výstupního formátu RFC živák
     */
    private static function transform(array $dep, int $stopId, ?string $stopName): array
    {
        $now = time();
        $depTime = strtotime($dep['DepartureTime'] ?? 'now');
        $delayMin = $dep['DelayMin'] ?? null;
        $delaySec = $delayMin !== null ? $delayMin * 60 : null;
        $predictedTime = $depTime + ($delaySec ?? 0);
        $minutes = max(0, (int)(($predictedTime - $now) / 60));

        $typeMap = [1 => 'tram', 2 => 'trolleybus', 3 => 'bus'];
        $tractionType = $dep['Line']['TractionType'] ?? null;

        $connectionId = null;
        if (isset($dep['ConnectionId']['Id'])) {
            $connectionId = (string)$dep['ConnectionId']['Id'];
        } elseif (isset($dep['ConnectionId']) && is_scalar($dep['ConnectionId'])) {
            $connectionId = (string)$dep['ConnectionId'];
        }

        return [
            'departure' => [
                'timestamp_scheduled' => date('c', $depTime),
                'timestamp_predicted' => date('c', $predictedTime),
                'delay_seconds' => $delaySec,
                'minutes' => $minutes,
            ],
            'stop' => [
                'id' => 'PMDP_' . $stopId,
                'name' => $stopName, // novinka, může se hodit, musí být načtena db zastávek
                'sequence' => null, // nevím co to je, ale asi to nemáme
                'platform_code' => null, // to asi taky nemáme
            ],
            'route' => [
                'type' => $typeMap[$tractionType] ?? 'bus',
                'short_name' => $dep['Line']['Name'] ?? '',
            ],
            'trip' => [
                'id' => $connectionId,
                'headsign' => $dep['LastStopName'] ?? '',
                'is_canceled' => false, // to v api není
            ],
            'vehicle' => [
                'id' => null, // to v api neni
                'is_wheelchair_accessible' => $dep['Line']['IsBarrierFree'] ?? null, // toto je u PMPD v info o lince
                'is_air_conditioned' => $dep['IsAirConditioned'] ?? null,
                'has_charger' => null, // takový vymoženosti v Plzni nemáme :-)
            ],
        ];
    }

    /**
     * Generuje klíč pro cache
     */
    private static function cacheKey(array $options): string
    {
        return 'pmdp_' . md5(json_encode([
            $options['stops'] ?? [],
            $options['exclude_trips'] ?? [],
            $options['exclude_headsigns'] ?? [],
            $options['limit'] ?? 15,
            $options['min_minutes'] ?? 0,
        ])) . '.json';
    }

    /**
     * Vyčistí cache soubory
     *
     * @param bool $all True = smaže vše, false = pouze starší než 1 hodina
     * @return int Počet smazaných souborů
     */
    public static function cleanCache(bool $all = false): int
    {
        if (!self::$cacheDir) {
            return 0;
        }

        $deleted = 0;
        $files = glob(self::$cacheDir . '/pmdp_*.json');
        if (is_array($files)) {
            foreach ($files as $f) {
                if ($all || time() - filemtime($f) > 3600) {
                    if (unlink($f)) {
                        $deleted++;
                    }
                }
            }
        }
        return $deleted;
    }

    /**
     * Načte z cache
     */
    private static function cacheGet(string $cacheDir, array $options): ?array
    {
        // Garbage collection (1% šance)
        if (mt_rand(1, 100) === 1) {
            self::cleanCache();
        }

        $file = $cacheDir . '/' . self::cacheKey($options);
        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || ($data['expires'] ?? 0) <= time()) {
            return null;
        }

        return [
            'departures' => $data['departures'],
            'cache_max_age' => $data['expires'] - time(),
            'first_departure_minutes' => $data['first_min'] ?? null,
            'from_cache' => true,
        ];
    }

    /**
     * Uloží do cache
     */
    private static function cacheSet(string $cacheDir, array $options, array $result): void
    {
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            return; // nelze vytvořit adresář, cache se neuloží
        }

        $file = $cacheDir . '/' . self::cacheKey($options);
        $data = [
            'departures' => $result['departures'],
            'expires' => time() + $result['cache_max_age'],
            'first_min' => $result['first_departure_minutes'],
        ];

        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
