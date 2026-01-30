# Odjezdy PMPD pro Živý Obraz

PHP knihovna a API pro načítání odjezdů z PMDP (Plzeňské městské dopravní podniky) ve formátu kompatibilním s RFC Živák https://zivyobraz.eu/.

## Funkce

- Načítání odjezdů z PMDP API pro až 3 zastávky současně
- Filtrování podle cílových stanic a ID spojů
- Parametr pro minimální čas do odjezdu
- Cachování podle času do odjezdu

## Instalace

Soubory:
- `PmdpDepartures.php` - hlavní knihovna
- `departures.php` - ukázkový HTTP API endpoint
- `stops.json` - volitelná databáze názvů zastávek
- `cache/` - adresář pro cache (musí být zapisovatelný)

## Použití jako HTTP API

```
GET departures.php?stops=124&limit=10
```

### Parametry

| Parametr | Typ | Povinný | Popis |
|----------|-----|---------|-------|
| `stops` | string | ano | ID zastávek oddělené čárkou (max 3) |
| `exclude_trips` | string | ne | ID spojů k vynechání, oddělené čárkou |
| `exclude_headsigns` | string | ne | Cílové stanice k vynechání, oddělené čárkou |
| `limit` | int | ne | Max počet odjezdů (výchozí 15, max 50) |
| `min_minutes` | int | ne | Odjezd nejdříve za X minut |

### Příklady

```bash
# Základní dotaz
curl "http://example.com/departures.php?stops=40"

# Více zastávek s filtrem
curl "http://example.com/departures.php?stops=123,124&exclude_headsigns=Bory&limit=5"

# Odjezdy nejdříve za 5 minut
curl "http://example.com/departures.php?stops=40&min_minutes=5"
```

## Použití jako PHP knihovna

```php
<?php
require_once 'PmdpDepartures.php';

// Konfigurace (jednou na začátku)
PmdpDepartures::$cacheDir = __DIR__ . '/cache';
PmdpDepartures::$stopsDbFile = __DIR__ . '/stops.json';  // volitelné

// Získání odjezdů
$result = PmdpDepartures::get([
    'stops' => [123, 124],
    'exclude_headsigns' => ['Bory'],
    'limit' => 10,
    'min_minutes' => 5,
]);

// Výsledek
print_r($result['departures']);
echo "Cache max age: " . $result['cache_max_age'] . "s\n";
echo "First departure in: " . $result['first_departure_minutes'] . " min\n";
```

### Čištění cache

```php
// Smazat staré soubory (starší než 1 hodina)
$deleted = PmdpDepartures::cleanCache();

// Smazat celou cache
$deleted = PmdpDepartures::cleanCache(true);
```

## Formát odpovědi

Odpověď je kompatibilní s RFC Živák (PID):

```json
[[
    {
        "departure": {
            "timestamp_scheduled": "2024-01-15T14:30:00+01:00",
            "timestamp_predicted": "2024-01-15T14:32:00+01:00",
            "delay_seconds": 120,
            "minutes": 5
        },
        "stop": {
            "id": "PMDP_40",
            "name": "Hlavní nádraží",
            "sequence": null,
            "platform_code": null
        },
        "route": {
            "type": "tram",
            "short_name": "4"
        },
        "trip": {
            "id": "12345",
            "headsign": "Košutka",
            "is_canceled": false
        },
        "vehicle": {
            "id": null,
            "is_wheelchair_accessible": true,
            "is_air_conditioned": true,
            "has_charger": null
        }
    }
]]
```

### Typy vozidel (`route.type`)

| TractionType | type |
|--------------|------|
| 1 | tram |
| 2 | trolleybus |
| 3 | bus |

## Cachování

Knihovna používá cachování podle času do nejbližšího odjezdu:

- **< 15 minut do odjezdu**: cache 30 sekund (kvůli stavu aktuálníého zpoždění)
- **>= 15 minut do odjezdu**: cache až do 15 minut před odjezdem (max 15 minut)

Garbage collector automaticky maže soubory starší než 1 hodina (spouští se s 1% pravděpodobností při každém requestu).

## Databáze zastávek

Soubor `stops.json` je volitelný a slouží k získání názvů zastávek. Pokud není k dispozici, pole `stop.name` bude `null`.

Formát:
```json
[
    {"id": 40, "name": "Hlavní nádraží"},
    {"id": 124, "name": "Náměstí Republiky"}
]
```

Může být rozšířen o další parametry, které lze využít například ve vyhledávači zastávek.

## Požadavky

- PHP 8.0+
- cURL extension
- Zapisovatelný adresář pro cache

## Licence

MIT
