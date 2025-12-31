<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once('../config.php');

// Initialize the variable to store global data
$GLOBALS['queryData'] = [
    'vermittler_id' => 11833,
    'berufsgruppe' => 1,
    'aktuelleVersicherung' => 1,
    'aktuelleKasse' => 839,
    'krankengeldAnspruch' => 0,
    'bundesland' => 9,
    'jahresEinkommen' => 40000,
    'topleistungen' => "306,524,531,709,401,604,554",
];

$GLOBALS['grunddaten'] = [];
$GLOBALS['topleistungen'] = [];

try {
    $aktuelleKasse = $GLOBALS['queryData']['aktuelleKasse'];
    $vermittlerID = $GLOBALS['queryData']['vermittler_id'];
    $topleistungenArray = explode(',', $GLOBALS['queryData']['topleistungen']); // Convert to array

    // Create a new PDO instance
    $pdo = new PDO("mysql:host=$targetDBHost;dbname=$targetDBName;charset=utf8", $targetDBUser, $targetDBPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load and parse 'grunddaten' from the options table once
    $stmt = $pdo->prepare("SELECT value FROM options WHERE `key` = 'grunddaten'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && isset($result['value'])) {
        // Decode the JSON value into a PHP array
        $GLOBALS['grunddaten'] = json_decode($result['value'], true);
    }

    // Prepare the SQL query to fetch all kassen
    $stmt = $pdo->prepare('SELECT id, name, zusatzbeitrag, bundesland, ad, prio FROM kassen');
    $stmt->execute();
    $kassen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Modify data to include the required structure
    $kassenData = [];

    foreach ($kassen as $entry) {

        //$empfehlung = ($GLOBALS['topleistungen']['empfehlung'] ?? 0) == $entry['id'] ? 1 : 0; //todo
        $empfehlung = ((int)$topleistungenArray[6] ?? 0) == $entry['id'] ? 1 : 0;

        // Fetch topleistungen for the current kasse
        $stmt = $pdo->prepare("
    SELECT 
        f.leistung AS id, 
        cs.label AS label, 
        f.status, 
        f.description AS `desc`, 
        f.additiv
    FROM filter f
    LEFT JOIN categories_subcategories cs ON f.leistung = cs.id
    WHERE f.id = :kasse_id AND f.leistung IN (" . implode(',', array_map('intval', $topleistungenArray)) . ")
    LIMIT 6
    ");

        $stmt->bindValue(':kasse_id', $entry['id'], PDO::PARAM_INT);
        $stmt->execute();
        $topleistungenResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process topleistungen in the new format
        $topleistungen = [];
        foreach ($topleistungenResults as $index => $leistung) {
            // Use an integer index starting from 1 for topleistungen
            $topleistungen[$index + 1] = $leistung;
        }

        // Decode the ad column
        $adData = json_decode($entry['ad'], true);
        $siegelData = $adData['siegel'] ?? []; // Retrieve the siegel object or default to an empty array

        // Dynamically build the siegel array with non-empty values
        $siegel = [];
        foreach ($siegelData as $key => $value) {
            if (!empty($value)) {
                $siegel[(int)$key] = $value; // Cast the key to an integer and keep non-empty values
            }
        }

        // Extract audio and video data from the ad column
        $audio = !empty($adData['audio']) ? $adData['audio'] : ''; // Use the audio path if available
        $video = !empty($adData['video']) ? "https://youtu.be/{$adData['video']}" : ''; // Convert video ID to YouTube URL

        // Final structure for each kasse
        $kassenData[] = [
            'id' => $entry['id'],
            'name' => $entry['name'],
            'topleistungen' => $topleistungen, // Only non-null and properly indexed
            'status' => [
                'vorkasse' => (int)($aktuelleKasse === $entry['id']) ? 1 : 0,
                'anzeige' => 0,
                'empfehlung' => $empfehlung,
                'bundesland' => $entry['bundesland'],
                'prio' => $entry['prio'],
            ],
            'kosten' => [
                'beitragssatz' => get_beitragssatz($entry['zusatzbeitrag']),
                'beitrag' => (float)calc_costs($entry),
                'ersparnis' => 1147.50,
            ],
            'siegel' => $siegel, // Use the dynamically built siegel array

            'media' => [
                'audio' => $audio, // Dynamically extracted from the ad column
                'video' => $video, //
            ],
        ];
    }

    // Place the "vorkasse beitrag" and "ersparnis" calculation here
    // Step 1: Find the [kosten][beitrag] where [status][vorkasse] == 1
    $vorkasseBeitrag = null; // To store the "vorkasse beitrag"
    foreach ($kassenData as $kasse) {
        if ($kasse['status']['vorkasse'] === 1) {
            $vorkasseBeitrag = $kasse['kosten']['beitrag'];
            break; // Exit loop once found
        }
    }

// Step 2: If a vorkasse beitrag is found, calculate the ersparnis for all kassen
    if ($vorkasseBeitrag !== null) {
        foreach ($kassenData as &$kasse) { // Use reference to modify $kassenData directly
            $kassenBeitrag = $kasse['kosten']['beitrag'];
            // Calculate ersparnis: (vorkasse beitrag - kassen beitrag) * 12
            $ersparnis = ($vorkasseBeitrag - $kassenBeitrag) * 12;
            // Round to 2 decimal places
            $kasse['kosten']['ersparnis'] = round($ersparnis, 2);
        }
    }



    // Step 1: Sort the data by `beitrag` from low to high BEFORE filtering
    uasort($kassenData, function ($a, $b) {
        return (round($a['kosten']['beitrag'], 2)) <=> (round($b['kosten']['beitrag'], 2));
    });

    error_log("Data sorted BEFORE filtering: " . json_encode($kassenData));

    // Step 2: Apply the filter while preserving keys
    $filteredKassenData = filterBundeslaender($kassenData);

    error_log("Filtered Data Before Sorting By Beitrag: " . json_encode($filteredKassenData));

    // Step 3: Deduplicate and Sort filtered data
    $filteredKassenData = array_values(array_reduce($filteredKassenData, function ($carry, $item) {
        // Use the kasse ID as the key for deduplication
        $id = $item['id'];

        // If a kasse already exists in the list, prioritize the one with `vorkasse = 1`
        if (isset($carry[$id])) {
            if ($carry[$id]['status']['vorkasse'] !== 1 && $item['status']['vorkasse'] === 1) {
                // Replace with the `vorkasse = 1` version if existing is not `vorkasse`
                $carry[$id] = $item;
            }
        } else {
            // Add the kasse if it doesn't exist in the list
            $carry[$id] = $item;
        }

        return $carry;
    }, []));

// Sort the deduplicated data based on priorities
    usort($filteredKassenData, function ($a, $b) {
        // 1. Prioritize `vorkasse`
        if ($a['status']['vorkasse'] === 1 && $b['status']['vorkasse'] !== 1) {
            return -1;
        } elseif ($a['status']['vorkasse'] !== 1 && $b['status']['vorkasse'] === 1) {
            return 1;
        }

        // 2. Prioritize `empfehlung`
        if ($a['status']['empfehlung'] === 1 && $b['status']['empfehlung'] !== 1) {
            return -1;
        } elseif ($a['status']['empfehlung'] !== 1 && $b['status']['empfehlung'] === 1) {
            return 1;
        }

        // 3. Sort by `beitrag` in ascending order
        return $a['kosten']['beitrag'] <=> $b['kosten']['beitrag'];
    });



//    $filteredKassenData = array_values($filteredKassenData);

// Step 4: Output the data as JSON
    header('Content-Type: application/json');
    echo json_encode($filteredKassenData);




} catch (PDOException $e) {
    // If an error occurs, return an error response
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

// Filter the data to allow only kassen with `bundesland` values matching `0` (global) or the selected value
function filterBundeslaender($kassenData)
{
    $allowedBundeslaender = [99, $GLOBALS['queryData']['bundesland']]; // Allowed values: global (99) and the selected bundesland
    error_log("Allowed Bundeslaender: " . json_encode($allowedBundeslaender)); // Debugging log

    return array_filter($kassenData, function ($kasse) use ($allowedBundeslaender) {
        if (isset($kasse['status']['bundesland'])) {
            $bundesland = is_string($kasse['status']['bundesland']) ? json_decode($kasse['status']['bundesland'], true) : $kasse['status']['bundesland'];
            // Ensure the decoded value is an array
            if (is_array($bundesland)) {
                if (!empty(array_intersect($bundesland, $allowedBundeslaender))) {
                    return true; // Include the kasse
                }
            }
        }
        return false; // Exclude kasse
    });
}

function get_beitragssatz($zusatzBeitragsSatz)
{
    return $GLOBALS['grunddaten']['beitragssatz'] + $zusatzBeitragsSatz;
}

function calc_costs($kasse)
{
    $beitrag = 0;
    $grunddaten = $GLOBALS['grunddaten'];
    $berufsgruppe = $GLOBALS['queryData']['berufsgruppe'];

    $queryData = $GLOBALS['queryData'];

    $monthlyIncome = (float)$GLOBALS['queryData']['jahresEinkommen'] / 12;
    $minimumBeitrag = $grunddaten['minimumbeitrag'] ?? 556; //todo noch ergaenzen in Grundeinstellungen
    $maximumBetrag = $grunddaten['hoechstbetrag'];
    $beitragsSatz = $grunddaten['beitragssatz'] ?? 0;
    $ermasigterBeitragsSatz = $grunddaten['ermassigter_beitragssatz'] ?? 0;
    $zusatzBeitrag = $kasse['zusatzbeitrag'] ?? 0;
    $student_beitrag = $grunddaten['studenten_grundeinkommen'] ?? 0;
    $student_satz = $grunddaten['studenten_beitragssatz'] ?? 0;
    $azubiGrenzBetrag = $grunddaten['azubi_grenzbetrag'] ?? 0;
    $arbeitslosen_zusatzbeitrag = $grunddaten['arbeitslosen_zusatzbeitrag'] ?? 0;

    switch ($berufsgruppe) {
        case 1: //Arbeitnehmer
            $monthlyIncome = ($monthlyIncome < $minimumBeitrag) ? $minimumBeitrag : $monthlyIncome;
            $monthlyIncome = ($monthlyIncome > $maximumBetrag) ? $maximumBetrag : $monthlyIncome;
            $calculationsSatz = $beitragsSatz / 2;
            $zusatzBeitrag = $zusatzBeitrag / 2;
            $beitrag = ($monthlyIncome * ($calculationsSatz + $zusatzBeitrag)) / 100;
            break;

        case 2: //Auszubildender
            $monthlyIncome = ($monthlyIncome <= $azubiGrenzBetrag) ? 0 : $monthlyIncome;
            $monthlyIncome = min($monthlyIncome, $maximumBetrag);
            $calculationsSatz = $beitragsSatz / 2;
            $zusatzBeitrag = $zusatzBeitrag / 2;
            $beitrag = ($monthlyIncome * ($calculationsSatz + $zusatzBeitrag)) / 100;
            break;

        case 3: //Student
            $monthlyIncome = $student_beitrag;
            $calculationsSatz = $student_satz;
            $beitrag = ($monthlyIncome * ($calculationsSatz + $zusatzBeitrag)) / 100;
            break;
    }
//
//        case 4"Selbstständiger":
//            if ($kg == "mit Krankengeldanspruch")
//                $cSatz = $bSatz;
//            if ($kg == "ohne Krankengeldanspruch")
//                $cSatz = $eSatz;
//            if ($monthly < 1248.33) {
//                $monthly = 1248.33;
//            }
//            if ($monthly > $hBetrag) {
//                $monthly = $hBetrag;
//            }
//            $Beitrag = ($monthly * ($cSatz + $zSatz)) / 100;
//            break;
//
//        case 5"Rentner":
//            if ($monthly < 1) {
//                $monthly = 1;
//            }
//            if ($monthly > $hBetrag) {
//                $monthly = $hBetrag;
//            }
//            $cSatz = $bSatz / 2;
//            $zSatz = $zSatz / 2;
//            $Beitrag = ($monthly * ($cSatz + $zSatz)) / 100;
//            break;
//
//        case 6"Arbeitslos":
//            //if ( $monthly < 1) { $monthly = 1; }
//            //if ( $monthly > 4350) { $monthly = 4350; }
//            $monthly = 0;
//            $cSatz = $bSatz / 2;
//            $Beitrag = ($monthly * ($cSatz + $arbeitslosen_zusatzbeitrag)) / 100;
//            break;
//
//        case 7 "Sonstige":
//            if ($monthly < 1248.33) {
//                $monthly = 1248.33;
//            }
//            if ($monthly > $hBetrag) {
//                $monthly = $hBetrag;
//            }
//            $cSatz = $eSatz;
//            $Beitrag = ($monthly * ($cSatz + $zSatz)) / 100;
//            break;
//    }

//    setlocale(LC_MONETARY, 'de_DE', 'de_DE.utf8', 'de_DE@euro', 'de', 'ge');
//    return number_format($Beitrag, 2, ",", ".");
    $beitragRounded = round($beitrag, 2);
    return number_format($beitragRounded, 2, '.', '');

}
