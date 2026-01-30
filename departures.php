<?php
/**
 * PMDP Departures API
 *
 * Parametry (GET):
 * - stops: ID zastávek oddělené čárkou (max 3)
 * - exclude_trips: čísla spojů k vynechání
 * - exclude_headsigns: cílové stanice k vynechání
 * - limit: max počet odjezdů (výchozí 15, max 50)
 * - min_minutes: odjezd nejdříve za X minut
 *
 * Příklad: departures.php?stops=40,124&exclude_headsigns=Bory&limit=10
 */

require_once __DIR__ . '/PmdpDepartures.php';

// Konfigurace
PmdpDepartures::$cacheDir = __DIR__ . '/cache';
PmdpDepartures::$stopsDbFile = __DIR__ . '/stops.json';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Parsování parametrů
$stopsParam = $_GET['stops'] ?? '';
if (empty($stopsParam)) {
    http_response_code(400);
    echo json_encode(['error' => 'Chybí povinný parametr: stops']);
    exit;
}

$stopIds = array_filter(array_map('intval', explode(',', $stopsParam)));
if (empty($stopIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatný parametr stops']);
    exit;
}

// Volání knihovny
try {
    $result = PmdpDepartures::get([
        'stops' => $stopIds,
        'exclude_trips' => isset($_GET['exclude_trips'])
            ? array_filter(array_map('trim', explode(',', $_GET['exclude_trips'])))
            : [],
        'exclude_headsigns' => isset($_GET['exclude_headsigns'])
            ? array_filter(array_map('trim', explode(',', $_GET['exclude_headsigns'])))
            : [],
        'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 15,
        'min_minutes' => isset($_GET['min_minutes']) ? (int)$_GET['min_minutes'] : 0,
    ]);

    // HTTP hlavičky
    header('Cache-Control: public, max-age=' . $result['cache_max_age']);
    header('X-Cache: ' . ($result['from_cache'] ? 'HIT' : 'MISS'));
    if ($result['first_departure_minutes'] !== null) {
        header('X-First-Departure-In: ' . $result['first_departure_minutes'] . ' min');
    }

    // Výstup (formát podle RFC živák)
    echo json_encode([$result['departures']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interní chyba serveru']);
}
