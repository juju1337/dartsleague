<?php
// import_stats.php - Import Statistics from SQLite Scoring App
session_start();

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Redirect non-admins
if (!$is_admin) {
    header('Location: index.php');
    exit;
}

$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';
$sets_file = 'tables/sets.csv';

// Initialize session variables for import process
if (!isset($_SESSION['import_step'])) {
    $_SESSION['import_step'] = 'upload';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_file'])) {
        handleFileUpload();
    } elseif (isset($_POST['confirm_players'])) {
        handlePlayerMapping();
    } elseif (isset($_POST['select_matchday'])) {
        handleMatchdaySelection();
    } elseif (isset($_POST['confirm_matches'])) {
        handleConfirmMatches();
    } elseif (isset($_POST['import_stats'])) {
        handleStatsImport();
    } elseif (isset($_POST['reset_import'])) {
        resetImport();
    }
}

// Load CSV data
$csv_players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// Functions
function loadPlayers() {
    global $players_file;
    $players = [];
    if (file_exists($players_file) && ($fp = fopen($players_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $players[$row[0]] = ['id' => $row[0], 'name' => $row[1], 'nickname' => $row[2]];
        }
        fclose($fp);
    }
    return $players;
}

function loadMatchdays() {
    global $matchdays_file;
    $matchdays = [];
    if (file_exists($matchdays_file) && ($fp = fopen($matchdays_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matchdays[] = ['id' => $row[0], 'date' => $row[1], 'location' => $row[2]];
        }
        fclose($fp);
    }
    return $matchdays;
}

function loadMatches() {
    global $matches_file;
    $matches = [];
    if (file_exists($matches_file) && ($fp = fopen($matches_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matches[] = [
                'id' => $row[0],
                'matchday_id' => $row[1],
                'phase' => $row[2],
                'firsttosets' => $row[3],
                'firsttolegs' => $row[4],
                'player1_id' => $row[5],
                'player2_id' => $row[6],
                'sets1' => $row[7],
                'sets2' => $row[8]
            ];
        }
        fclose($fp);
    }
    return $matches;
}

function loadSets() {
    global $sets_file;
    $sets = [];
    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $sets[] = [
                'id' => $row[0],
                'match_id' => $row[1],
                'player1_id' => $row[2],
                'player2_id' => $row[3],
                'legs1' => $row[4],
                'legs2' => $row[5],
                'darts1' => $row[6],
                'darts2' => $row[7],
                '3da1' => $row[8],
                '3da2' => $row[9],
                'dblattempts1' => $row[10],
                'dblattempts2' => $row[11],
                'highscore1' => $row[12],
                'highscore2' => $row[13],
                'highco1' => $row[14],
                'highco2' => $row[15]
            ];
        }
        fclose($fp);
    }
    return $sets;
}

function handleFileUpload() {
    if (isset($_FILES['sqlite_file']) && $_FILES['sqlite_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_file = $_FILES['sqlite_file']['tmp_name'];
        
        // Read the entire file into memory
        $file_content = file_get_contents($tmp_file);
        
        if ($file_content === false) {
            $_SESSION['error'] = 'Failed to read uploaded file.';
            return;
        }
        
        // Store in session as base64
        $_SESSION['sqlite_data'] = base64_encode($file_content);
        $_SESSION['import_step'] = 'map_players';
        
        header('Location: import_stats.php');
        exit;
    } else {
        $_SESSION['error'] = 'Please select a file to upload.';
    }
}

function handlePlayerMapping() {
    if (!isset($_POST['player_mapping']) || empty($_POST['player_mapping'])) {
        $_SESSION['error'] = 'Please map all players before continuing.';
        return;
    }
    
    // Check for duplicate mappings
    $mapped_sqlite_ids = array_values($_POST['player_mapping']);
    if (count($mapped_sqlite_ids) !== count(array_unique($mapped_sqlite_ids))) {
        $_SESSION['error'] = 'Each SQLite player can only be mapped once. Please check your selections.';
        return;
    }
    
    $_SESSION['player_mapping'] = $_POST['player_mapping'];
    $_SESSION['import_step'] = 'select_matchday';
    header('Location: import_stats.php');
    exit;
}

function handleMatchdaySelection() {
    $_SESSION['selected_matchday'] = intval($_POST['matchday_id']);
    $_SESSION['import_step'] = 'match_sets';
    header('Location: import_stats.php');
    exit;
}

function handleConfirmMatches() {
    if (!isset($_POST['selected_sets']) || empty($_POST['selected_sets'])) {
        $_SESSION['error'] = 'Please select at least one set to import.';
        $_SESSION['import_step'] = 'match_sets';
        header('Location: import_stats.php');
        exit;
    }
    
    $_SESSION['selected_sets'] = $_POST['selected_sets'];
    $_SESSION['import_step'] = 'confirm_import';
    header('Location: import_stats.php');
    exit;
}

function handleStatsImport() {
    if (!isset($_SESSION['selected_sets']) || empty($_SESSION['selected_sets'])) {
        $_SESSION['error'] = 'No sets selected for import.';
        return;
    }
    
    if (!isset($_SESSION['sqlite_data'])) {
        $_SESSION['error'] = 'SQLite data not found. Please start over.';
        return;
    }
    
    $player_mapping = $_SESSION['player_mapping'];
    $selected_sets = $_SESSION['selected_sets'];
    
    try {
        // Create temporary file for PDO
        $tmp_file = tempnam(sys_get_temp_dir(), 'sqlite_import_');
        file_put_contents($tmp_file, base64_decode($_SESSION['sqlite_data']));
        
        $db = new PDO('sqlite:' . $tmp_file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Load current sets
        $csv_sets = loadSets();
        
        $imported_count = 0;
        
        foreach ($selected_sets as $set_id) {
            // Find the set in CSV
            $csv_set = null;
            foreach ($csv_sets as &$s) {
                if ($s['id'] == $set_id) {
                    $csv_set = &$s;
                    break;
                }
            }
            
            if (!$csv_set) {
                continue;
            }
            
            // Get match details
            $match_id = $csv_set['match_id'];
            $player1_csv_id = $csv_set['player1_id'];
            $player2_csv_id = $csv_set['player2_id'];
            
            // Get SQLite player IDs from mapping
            $player1_sqlite_id = isset($player_mapping[$player1_csv_id]) ? $player_mapping[$player1_csv_id] : null;
            $player2_sqlite_id = isset($player_mapping[$player2_csv_id]) ? $player_mapping[$player2_csv_id] : null;
            
            if ($player1_sqlite_id === null || $player2_sqlite_id === null) {
                continue;
            }
            
            // Get matchday date for filtering
            $matchday_date = null;
            foreach ($GLOBALS['matchdays'] as $md) {
                if ($md['id'] == $_SESSION['selected_matchday']) {
                    $matchday_date = $md['date'];
                    break;
                }
            }
            
            // Find matching set in SQLite by analyzing legs
            $stats = findSetStats($db, $player1_sqlite_id, $player2_sqlite_id, $csv_set['legs1'], $csv_set['legs2'], $matchday_date);
            
            if ($stats) {
                // Update the CSV set with the stats
                $csv_set['darts1'] = $stats['darts1'];
                $csv_set['darts2'] = $stats['darts2'];
                $csv_set['3da1'] = $stats['3da1'];
                $csv_set['3da2'] = $stats['3da2'];
                $csv_set['dblattempts1'] = $stats['dblattempts1'];
                $csv_set['dblattempts2'] = $stats['dblattempts2'];
                $csv_set['highscore1'] = $stats['highscore1'];
                $csv_set['highscore2'] = $stats['highscore2'];
                $csv_set['highco1'] = $stats['highco1'];
                $csv_set['highco2'] = $stats['highco2'];
                
                $imported_count++;
            }
        }
        
        // Write back to CSV
        saveSets($csv_sets);
        
        // Clean up temporary file
        unlink($tmp_file);
        
        $_SESSION['success'] = "Successfully imported statistics for {$imported_count} set(s).";
        $_SESSION['import_step'] = 'complete';
        
    } catch (Exception $e) {
        // Clean up temporary file on error
        if (isset($tmp_file) && file_exists($tmp_file)) {
            unlink($tmp_file);
        }
        $_SESSION['error'] = 'Import failed: ' . $e->getMessage();
    }
    
    header('Location: import_stats.php');
    exit;
}

function findSetStats($db, $player1_id, $player2_id, $expected_legs1, $expected_legs2, $matchday_date = null) {
    // Build query to find all sets where these two players played
    // If we have a matchday date, filter by date proximity (within 7 days)
    $date_filter = '';
    $params = [$player1_id, $player2_id];
    
    if ($matchday_date && !empty($matchday_date)) {
        $date_filter = " AND s.created_at >= date(?, '-7 days') AND s.created_at <= date(?, '+7 days')";
        $params[] = $matchday_date;
        $params[] = $matchday_date;
    }
    
    $stmt = $db->prepare("
        SELECT DISTINCT s.id as set_id, s.setNummer, s.created_at
        FROM xGameMpSet s
        JOIN xGameMpLeg l ON l.setId = s.id
        JOIN xGameSpieler gs ON gs.legId = l.id
        WHERE gs.spielerId IN (?, ?)
        $date_filter
        GROUP BY s.id
        HAVING COUNT(DISTINCT gs.spielerId) = 2
        ORDER BY s.created_at DESC
    ");
    $stmt->execute($params);
    $potential_sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each potential set, check if the leg counts match
    foreach ($potential_sets as $set_info) {
        $set_id = $set_info['set_id'];
        
        // Count legs won by each player
        $stmt = $db->prepare("
            SELECT l.siegerId, COUNT(*) as legs_won
            FROM xGameMpLeg l
            WHERE l.setId = ?
            GROUP BY l.siegerId
        ");
        $stmt->execute([$set_id]);
        $leg_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $legs1 = 0;
        $legs2 = 0;
        
        foreach ($leg_counts as $lc) {
            if ($lc['siegerId'] == $player1_id) {
                $legs1 = $lc['legs_won'];
            } elseif ($lc['siegerId'] == $player2_id) {
                $legs2 = $lc['legs_won'];
            }
        }
        
        // Check if this matches our expected leg counts (in either order)
        $match_forward = ($legs1 == $expected_legs1 && $legs2 == $expected_legs2);
        $match_reverse = ($legs1 == $expected_legs2 && $legs2 == $expected_legs1);
        
        if ($match_forward || $match_reverse) {
            // This is our set! Extract stats
            // If reversed, swap the player IDs when extracting stats
            if ($match_reverse) {
                return extractSetStats($db, $set_id, $player2_id, $player1_id);
            } else {
                return extractSetStats($db, $set_id, $player1_id, $player2_id);
            }
        }
    }
    
    return null;
}

function extractSetStats($db, $set_id, $player1_id, $player2_id) {
    $stats = [
        'darts1' => 0,
        'darts2' => 0,
        '3da1' => 0,
        '3da2' => 0,
        'dblattempts1' => 0,
        'dblattempts2' => 0,
        'highscore1' => 0,
        'highscore2' => 0,
        'highco1' => 0,
        'highco2' => 0
    ];
    
    $total_score1 = 0;
    $total_score2 = 0;
    
    // Get all legs in this set
    $stmt = $db->prepare("
        SELECT id FROM xGameMpLeg WHERE setId = ? ORDER BY legNummer
    ");
    $stmt->execute([$set_id]);
    $legs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($legs as $leg_id) {
        // Get player stats for this leg
        $stmt = $db->prepare("
            SELECT spielerId, gesamtScore, gesamtDarts
            FROM xGameSpieler
            WHERE legId = ? AND spielerId IN (?, ?)
        ");
        $stmt->execute([$leg_id, $player1_id, $player2_id]);
        $player_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($player_stats as $ps) {
            $is_player1 = ($ps['spielerId'] == $player1_id);
            
            if ($is_player1) {
                $stats['darts1'] += $ps['gesamtDarts'];
                $total_score1 += $ps['gesamtScore'];
            } else {
                $stats['darts2'] += $ps['gesamtDarts'];
                $total_score2 += $ps['gesamtScore'];
            }
        }
        
        // Get throw statistics for each player in this leg
        // For each player in this leg, get their xGameSpieler ID to query throws
        $stmt = $db->prepare("
            SELECT gs.id, gs.spielerId
            FROM xGameSpieler gs
            WHERE gs.legId = ? AND gs.spielerId IN (?, ?)
        ");
        $stmt->execute([$leg_id, $player1_id, $player2_id]);
        $player_entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($player_entities as $entity) {
            $entity_id = $entity['id'];
            $is_player1 = ($entity['spielerId'] == $player1_id);
            
            // Get highest score (any throw)
            $stmt = $db->prepare("
                SELECT MAX(score) as max_score
                FROM aufnahmeMp
                WHERE entityId = ? AND entityName = 'XGame'
            ");
            $stmt->execute([$entity_id]);
            $result = $stmt->fetchColumn();
            
            if ($result !== null && $result !== false) {
                if ($is_player1) {
                    if ($result > $stats['highscore1']) {
                        $stats['highscore1'] = $result;
                    }
                } else {
                    if ($result > $stats['highscore2']) {
                        $stats['highscore2'] = $result;
                    }
                }
            }
            
            // Get highest checkout (only throws where checkout = 1)
            $stmt = $db->prepare("
                SELECT MAX(beginnScore) as max_checkout
                FROM aufnahmeMp
                WHERE entityId = ? AND entityName = 'XGame' AND checkout = 1
            ");
            $stmt->execute([$entity_id]);
            $result = $stmt->fetchColumn();
            
            if ($result !== null && $result !== false) {
                if ($is_player1) {
                    if ($result > $stats['highco1']) {
                        $stats['highco1'] = $result;
                    }
                } else {
                    if ($result > $stats['highco2']) {
                        $stats['highco2'] = $result;
                    }
                }
            }
            
            // Get total double attempts (sum of dartsOnDouble from all throws)
            $stmt = $db->prepare("
                SELECT SUM(dartsOnDouble) as total_dbl_attempts
                FROM aufnahmeMp
                WHERE entityId = ? AND entityName = 'XGame'
            ");
            $stmt->execute([$entity_id]);
            $result = $stmt->fetchColumn();
            
            if ($result !== null && $result !== false) {
                if ($is_player1) {
                    $stats['dblattempts1'] += $result;
                } else {
                    $stats['dblattempts2'] += $result;
                }
            }
        }
    }
    
    // Calculate 3-dart averages
    $stats['3da1'] = ($stats['darts1'] > 0) ? round(($total_score1 / $stats['darts1']) * 3, 2) : 0;
    $stats['3da2'] = ($stats['darts2'] > 0) ? round(($total_score2 / $stats['darts2']) * 3, 2) : 0;
    
    return $stats;
}

function saveSets($sets) {
    global $sets_file;
    
    $fp = fopen($sets_file, 'w');
    fputcsv($fp, ['id', 'match_id', 'player1_id', 'player2_id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2']);
    
    foreach ($sets as $set) {
        fputcsv($fp, [
            $set['id'],
            $set['match_id'],
            $set['player1_id'],
            $set['player2_id'],
            $set['legs1'],
            $set['legs2'],
            $set['darts1'],
            $set['darts2'],
            $set['3da1'],
            $set['3da2'],
            $set['dblattempts1'],
            $set['dblattempts2'],
            $set['highscore1'],
            $set['highscore2'],
            $set['highco1'],
            $set['highco2']
        ]);
    }
    
    fclose($fp);
}

function getSQLitePlayers() {
    if (!isset($_SESSION['sqlite_data'])) {
        return [];
    }
    
    try {
        // Create temporary file for PDO
        $tmp_file = tempnam(sys_get_temp_dir(), 'sqlite_import_');
        file_put_contents($tmp_file, base64_decode($_SESSION['sqlite_data']));
        
        $db = new PDO('sqlite:' . $tmp_file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $db->query("SELECT id, name FROM Spieler ORDER BY name");
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Clean up temporary file
        unlink($tmp_file);
        
        return $result;
        
    } catch (Exception $e) {
        // Clean up temporary file on error
        if (isset($tmp_file) && file_exists($tmp_file)) {
            unlink($tmp_file);
        }
        $_SESSION['error'] = 'Failed to read SQLite file: ' . $e->getMessage();
        return [];
    }
}

function findBestMatch($csv_player, $sqlite_players) {
    $best_match_id = null;
    $best_score = 0;
    
    // We'll compare using the nickname if it exists, otherwise use the name
    $csv_compare = !empty($csv_player['nickname']) ? $csv_player['nickname'] : $csv_player['name'];
    
    foreach ($sqlite_players as $sp) {
        $score = similarityScore($csv_compare, $sp['name']);
        if ($score > $best_score) {
            $best_score = $score;
            $best_match_id = $sp['id'];
        }
    }
    
    return $best_match_id;
}

function similarityScore($str1, $str2) {
    $str1 = strtolower(trim($str1));
    $str2 = strtolower(trim($str2));
    
    // Exact match
    if ($str1 === $str2) {
        return 100;
    }
    
    // Contains match (substring)
    if (strpos($str2, $str1) !== false || strpos($str1, $str2) !== false) {
        return 90;
    }
    
    // Check for similarity using levenshtein distance
    $lev = levenshtein($str1, $str2);
    $max_len = max(strlen($str1), strlen($str2));
    
    if ($max_len == 0) {
        return 0;
    }
    
    $similarity = (1 - $lev / $max_len) * 100;
    
    // Also check similar_text for better matching
    similar_text($str1, $str2, $percent);
    
    // Return the higher of the two scores
    return max($similarity, $percent);
}

function isMatchdayComplete($matchday_id) {
    global $all_matches;
    
    $matchday_matches = array_filter($all_matches, function($m) use ($matchday_id) {
        return $m['matchday_id'] == $matchday_id;
    });
    
    foreach ($matchday_matches as $match) {
        // Check if the match has been played (at least one set score entered)
        if ($match['sets1'] == 0 && $match['sets2'] == 0) {
            return false;
        }
    }
    
    return count($matchday_matches) > 0;
}

function getMatchdaySets($matchday_id) {
    global $all_matches;
    
    $matchday_matches = array_filter($all_matches, function($m) use ($matchday_id) {
        return $m['matchday_id'] == $matchday_id;
    });
    
    $csv_sets = loadSets();
    
    $result = [];
    foreach ($matchday_matches as $match) {
        $match_sets = array_filter($csv_sets, function($s) use ($match) {
            return $s['match_id'] == $match['id'];
        });
        
        foreach ($match_sets as $set) {
            $set['match'] = $match;
            $result[] = $set;
        }
    }
    
    return $result;
}

function hasStats($set) {
    return $set['darts1'] > 0 || $set['darts2'] > 0 || $set['3da1'] > 0 || $set['3da2'] > 0;
}

function matchAllSets($matchday_id) {
    global $all_matches;
    
    if (!isset($_SESSION['sqlite_data']) || !isset($_SESSION['player_mapping'])) {
        return [];
    }
    
    $player_mapping = $_SESSION['player_mapping'];
    $matchday_date = null;
    
    // Get matchday date
    foreach ($GLOBALS['matchdays'] as $md) {
        if ($md['id'] == $matchday_id) {
            $matchday_date = $md['date'];
            break;
        }
    }
    
    try {
        // Create temporary file for PDO
        $tmp_file = tempnam(sys_get_temp_dir(), 'sqlite_import_');
        file_put_contents($tmp_file, base64_decode($_SESSION['sqlite_data']));
        
        $db = new PDO('sqlite:' . $tmp_file);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get all sets for this matchday
        $matchday_sets = getMatchdaySets($matchday_id);
        
        $matches_info = [];
        
        foreach ($matchday_sets as $set) {
            $player1_csv_id = $set['player1_id'];
            $player2_csv_id = $set['player2_id'];
            
            // Get SQLite player IDs from mapping
            // The mapping is: player_mapping[csv_player_id] = sqlite_player_id
            $player1_sqlite_id = isset($player_mapping[$player1_csv_id]) ? $player_mapping[$player1_csv_id] : null;
            $player2_sqlite_id = isset($player_mapping[$player2_csv_id]) ? $player_mapping[$player2_csv_id] : null;
            
            $match_info = [
                'set' => $set,
                'matched' => false,
                'stats' => null,
                'sqlite_set_id' => null,
                'sqlite_created_at' => null,
                // 'debug' => []
            ];
            
            if ($player1_sqlite_id !== null && $player2_sqlite_id !== null) {
                // Try to find matching set
                $result = findSetStatsWithInfo($db, $player1_sqlite_id, $player2_sqlite_id, $set['legs1'], $set['legs2'], $matchday_date);
                
                if ($result && isset($result['stats']) && $result['stats'] !== null) {
                    $match_info['matched'] = true;
                    $match_info['stats'] = $result['stats'];
                    $match_info['sqlite_set_id'] = $result['set_id'];
                    $match_info['sqlite_created_at'] = $result['created_at'];
                }
                
                // Always store debug info
                // if (isset($result['debug'])) {
                //     $match_info['debug'] = $result['debug'];
                // }
            } else {
                // $match_info['debug'][] = "Player mapping failed: Player1=$player1_csv_id -> SQLite=" . ($player1_sqlite_id !== null ? $player1_sqlite_id : 'NOT FOUND') . ", Player2=$player2_csv_id -> SQLite=" . ($player2_sqlite_id !== null ? $player2_sqlite_id : 'NOT FOUND');
            }
            
            $matches_info[] = $match_info;
        }
        
        // Clean up temporary file
        unlink($tmp_file);
        
        return $matches_info;
        
    } catch (Exception $e) {
        // Clean up temporary file on error
        if (isset($tmp_file) && file_exists($tmp_file)) {
            unlink($tmp_file);
        }
        $_SESSION['error'] = 'Matching failed: ' . $e->getMessage();
        return [];
    }
}

function findSetStatsWithInfo($db, $player1_id, $player2_id, $expected_legs1, $expected_legs2, $matchday_date = null) {
    // Build query to find all sets where these two players played
    $date_filter = '';
    $params = [$player1_id, $player2_id];
    
    // $debug_info = [];
    // $debug_info[] = "Searching for: Player1 ID=$player1_id, Player2 ID=$player2_id, Legs: $expected_legs1-$expected_legs2";
    // $debug_info[] = "Matchday date: " . ($matchday_date ?: 'Not set');
    
    if ($matchday_date && !empty($matchday_date)) {
        $date_filter = " AND s.created_at >= date(?, '-7 days') AND s.created_at <= date(?, '+7 days')";
        $params[] = $matchday_date;
        $params[] = $matchday_date;
        // $debug_info[] = "Date filter: ±7 days from $matchday_date";
    }
    
    $stmt = $db->prepare("
        SELECT DISTINCT s.id as set_id, s.setNummer, s.created_at
        FROM xGameMpSet s
        JOIN xGameMpLeg l ON l.setId = s.id
        JOIN xGameSpieler gs ON gs.legId = l.id
        WHERE gs.spielerId IN (?, ?)
        $date_filter
        GROUP BY s.id
        HAVING COUNT(DISTINCT gs.spielerId) = 2
        ORDER BY s.created_at DESC
    ");
    $stmt->execute($params);
    $potential_sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // $debug_info[] = "Found " . count($potential_sets) . " potential sets with these players";
    
    // For each potential set, check if the leg counts match
    foreach ($potential_sets as $set_info) {
        $set_id = $set_info['set_id'];
        
        // Count legs won by each player
        $stmt = $db->prepare("
            SELECT l.siegerId, COUNT(*) as legs_won
            FROM xGameMpLeg l
            WHERE l.setId = ?
            GROUP BY l.siegerId
        ");
        $stmt->execute([$set_id]);
        $leg_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $legs1 = 0;
        $legs2 = 0;
        
        foreach ($leg_counts as $lc) {
            if ($lc['siegerId'] == $player1_id) {
                $legs1 = $lc['legs_won'];
            } elseif ($lc['siegerId'] == $player2_id) {
                $legs2 = $lc['legs_won'];
            }
        }
        
        // $debug_info[] = "Set ID $set_id (created: {$set_info['created_at']}): Legs $legs1-$legs2";
        
        // Check if this matches our expected leg counts (in either order)
        $match_forward = ($legs1 == $expected_legs1 && $legs2 == $expected_legs2);
        $match_reverse = ($legs1 == $expected_legs2 && $legs2 == $expected_legs1);
        
        if ($match_forward || $match_reverse) {
            // This is our set! Extract stats and return with info
            // If reversed, we need to swap the player IDs when extracting stats
            if ($match_reverse) {
                // $debug_info[] = "✓ MATCH FOUND (reversed player order)!";
                $stats = extractSetStats($db, $set_id, $player2_id, $player1_id);
            } else {
                // $debug_info[] = "✓ MATCH FOUND!";
                $stats = extractSetStats($db, $set_id, $player1_id, $player2_id);
            }
            
            return [
                'set_id' => $set_id,
                'created_at' => $set_info['created_at'],
                'stats' => $stats,
                // 'debug' => $debug_info
            ];
        }
    }
    
    // $debug_info[] = "✗ No matching set found";
    
    return [
        'set_id' => null,
        'created_at' => null,
        'stats' => null,
        // 'debug' => $debug_info,
        'matched' => false
    ];
}

function getPlayerName($player_id) {
    global $csv_players;
    if ($player_id == 0) return 'TBD';
    if (isset($csv_players[$player_id])) {
        $p = $csv_players[$player_id];
        return $p['nickname'] ? $p['name'] . ' (' . $p['nickname'] . ')' : $p['name'];
    }
    return 'Unknown';
}

function resetImport() {
    // Clear session data
    unset($_SESSION['sqlite_data']);
    unset($_SESSION['player_mapping']);
    unset($_SESSION['selected_matchday']);
    unset($_SESSION['import_step']);
    
    header('Location: import_stats.php');
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Statistics</title>
    <link rel="stylesheet" href="styles.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            border: 1px solid #28a745;
            color: #155724;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .error {
            background-color: #f8d7da;
            border: 1px solid #dc3545;
            color: #721c24;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .step-indicator {
            margin: 20px 0;
            padding: 15px;
            background: #f0f0f0;
            border-radius: 4px;
        }
        .step-indicator .step {
            display: inline-block;
            padding: 5px 15px;
            margin: 0 5px;
            background: #ddd;
            border-radius: 3px;
        }
        .step-indicator .step.active {
            background: #007bff;
            color: white;
            font-weight: bold;
        }
        .step-indicator .step.completed {
            background: #28a745;
            color: white;
        }
        .set-item {
            padding: 10px;
            margin: 5px 0;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .set-item.has-stats {
            background: #fff3cd;
            border-color: #ffc107;
        }
        .set-item.unmatched {
            background: #f8d7da;
            border-color: #dc3545;
        }
        select.player-mapping {
            width: 100%;
            padding: 5px;
            margin: 5px 0;
        }
        .selection-buttons {
            margin: 15px 0;
            padding: 10px;
            background: #f0f0f0;
            border-radius: 4px;
        }
        .selection-buttons button {
            margin-right: 10px;
        }
    </style>
    <script>
        function selectAllSets() {
            var checkboxes = document.querySelectorAll('input[name="selected_sets[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = true;
            });
        }
        
        function selectNoneSets() {
            var checkboxes = document.querySelectorAll('input[name="selected_sets[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });
        }
        
        function selectWithoutStats() {
            var checkboxes = document.querySelectorAll('input[name="selected_sets[]"]');
            checkboxes.forEach(function(checkbox) {
                var setItem = checkbox.closest('.set-item');
                if (setItem && !setItem.classList.contains('has-stats')) {
                    checkbox.checked = true;
                } else {
                    checkbox.checked = false;
                }
            });
        }
    </script>
</head>
<body>
    
    <nav>
        <a href="index.php">Tournament Overview</a>
        <?php if (!empty($matchdays)): ?>
            <?php foreach ($matchdays as $md): ?>
                | <a href="matchdays.php?view=<?php echo $md['id']; ?>">Matchday <?php echo $md['id']; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    
    <h1>Import Statistics from Scoring App</h1>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <div class="step-indicator">
        <span class="step <?php echo $_SESSION['import_step'] == 'upload' ? 'active' : 'completed'; ?>">1. Upload File</span>
        <span class="step <?php echo $_SESSION['import_step'] == 'map_players' ? 'active' : ($_SESSION['import_step'] != 'upload' ? 'completed' : ''); ?>">2. Map Players</span>
        <span class="step <?php echo $_SESSION['import_step'] == 'select_matchday' ? 'active' : (in_array($_SESSION['import_step'], ['match_sets', 'confirm_import', 'complete']) ? 'completed' : ''); ?>">3. Select Matchday</span>
        <span class="step <?php echo $_SESSION['import_step'] == 'match_sets' ? 'active' : (in_array($_SESSION['import_step'], ['confirm_import', 'complete']) ? 'completed' : ''); ?>">4. Match Sets</span>
        <span class="step <?php echo $_SESSION['import_step'] == 'confirm_import' ? 'active' : ($_SESSION['import_step'] == 'complete' ? 'completed' : ''); ?>">5. Confirm & Import</span>
    </div>
    
    <?php if ($_SESSION['import_step'] == 'upload'): ?>
        <!-- Step 1: Upload SQLite File -->
        <div class="form-section">
            <h2>Step 1: Upload Scoring App Database</h2>
            <p>Please upload the SQLite database file (.db or .mdt) from your scoring application.</p>
            
            <form method="POST" enctype="multipart/form-data">
                <label>Select SQLite File:</label><br>
                <input type="file" name="sqlite_file" accept=".db,.mdt,.sqlite,.sqlite3" required><br><br>
                <input type="submit" name="upload_file" value="Upload and Continue">
            </form>
        </div>
        
    <?php elseif ($_SESSION['import_step'] == 'map_players'): ?>
        <!-- Step 2: Map Players -->
        <?php 
        $sqlite_players = getSQLitePlayers();
        if (empty($sqlite_players)) {
            echo '<div class="error">No players found in the SQLite database.</div>';
            $_SESSION['import_step'] = 'upload';
        } else {
        ?>
        <div class="form-section">
            <h2>Step 2: Map Players</h2>
            <p>Match each player from your tournament to their corresponding entry in the scoring app database.</p>
            <p class="warning"><strong>Note:</strong> Each scoring app player can only be mapped once. Make sure no duplicates exist.</p>
            
            <form method="POST">
                <table>
                    <tr>
                        <th>Tournament Player</th>
                        <th>Scoring App Player</th>
                    </tr>
                    <?php foreach ($csv_players as $player): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(getPlayerName($player['id'])); ?></td>
                        <td>
                            <select name="player_mapping[<?php echo $player['id']; ?>]" class="player-mapping" required>
                                <option value="">-- Select Player --</option>
                                <?php 
                                $best_match = findBestMatch($player, $sqlite_players);
                                foreach ($sqlite_players as $sp): 
                                ?>
                                <option value="<?php echo $sp['id']; ?>" <?php echo ($sp['id'] == $best_match) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sp['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <br>
                <input type="submit" name="confirm_players" value="Confirm Player Mapping">
                <input type="submit" name="reset_import" value="Cancel" onclick="return confirm('This will cancel the import. Continue?');">
            </form>
        </div>
        <?php } ?>
        
    <?php elseif ($_SESSION['import_step'] == 'select_matchday'): ?>
        <!-- Step 3: Select Matchday -->
        <div class="form-section">
            <h2>Step 3: Select Matchday</h2>
            <p>Choose which matchday you want to import statistics for. Only complete matchdays are shown.</p>
            
            <form method="POST">
                <table>
                    <tr>
                        <th>Select</th>
                        <th>Matchday</th>
                        <th>Date</th>
                        <th>Location</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach ($matchdays as $md): ?>
                    <?php if (isMatchdayComplete($md['id'])): ?>
                    <tr>
                        <td>
                            <input type="radio" name="matchday_id" value="<?php echo $md['id']; ?>" required>
                        </td>
                        <td>Matchday <?php echo $md['id']; ?></td>
                        <td><?php echo $md['date'] ? htmlspecialchars($md['date']) : '<em>Not set</em>'; ?></td>
                        <td><?php echo $md['location'] ? htmlspecialchars($md['location']) : '<em>Not set</em>'; ?></td>
                        <td><span style="color: green;">✓ Complete</span></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </table>
                <br>
                <input type="submit" name="select_matchday" value="Continue to Set Selection">
                <input type="submit" name="reset_import" value="Cancel" onclick="return confirm('This will cancel the import. Continue?');">
            </form>
        </div>
        
    <?php elseif ($_SESSION['import_step'] == 'match_sets'): ?>
        <!-- Step 4: Match Sets and Show Preview -->
        <?php 
        $selected_matchday = $_SESSION['selected_matchday'];
        $matches_info = matchAllSets($selected_matchday);
        
        // Count matched and unmatched
        $matched_count = 0;
        $unmatched_count = 0;
        foreach ($matches_info as $info) {
            if ($info['matched']) {
                $matched_count++;
            } else {
                $unmatched_count++;
            }
        }
        ?>
        <div class="form-section">
            <h2>Step 4: Match Sets</h2>
            <p>Review the automatic matching between your tournament sets and the SQLite database.</p>
            
            <?php if ($matched_count > 0): ?>
                <div class="success">
                    <strong>✓ <?php echo $matched_count; ?> set(s) successfully matched</strong>
                </div>
            <?php endif; ?>
            
            <?php if ($unmatched_count > 0): ?>
                <div class="warning">
                    <strong>⚠ <?php echo $unmatched_count; ?> set(s) could not be matched</strong>
                    <br>These sets will be skipped during import.
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <?php if (empty($matches_info)): ?>
                    <p>No sets found for this matchday.</p>
                <?php else: ?>
                    <div class="selection-buttons">
                        <strong>Quick Select:</strong>
                        <button type="button" onclick="selectAllSets()">Select All Matched</button>
                        <button type="button" onclick="selectNoneSets()">Select None</button>
                    </div>
                    
                    <?php 
                    $current_match = null;
                    foreach ($matches_info as $info): 
                        $set = $info['set'];
                        
                        if ($current_match != $set['match_id']):
                            if ($current_match !== null) echo '</div>';
                            $current_match = $set['match_id'];
                            echo '<h3>Match: ' . htmlspecialchars(getPlayerName($set['match']['player1_id'])) . ' vs ' . htmlspecialchars(getPlayerName($set['match']['player2_id'])) . '</h3>';
                            echo '<div style="margin-left: 20px;">';
                        endif;
                        
                        $has_stats = hasStats($set);
                        $is_matched = $info['matched'];
                    ?>
                    <div class="set-item <?php echo $has_stats ? 'has-stats' : ''; ?> <?php echo !$is_matched ? 'unmatched' : ''; ?>">
                        <?php if ($is_matched): ?>
                            <label>
                                <input type="checkbox" name="selected_sets[]" value="<?php echo $set['id']; ?>" checked>
                                <strong>Set <?php echo $set['id']; ?>:</strong>
                                <?php echo htmlspecialchars(getPlayerName($set['player1_id'])); ?> 
                                (<?php echo $set['legs1']; ?>) 
                                vs 
                                <?php echo htmlspecialchars(getPlayerName($set['player2_id'])); ?> 
                                (<?php echo $set['legs2']; ?>)
                                <?php if ($has_stats): ?>
                                    <span style="color: #856404;"> ⚠ Has existing stats - will be overwritten</span>
                                <?php endif; ?>
                            </label>
                            <div style="margin-top: 8px; padding: 8px; background: #e8f5e9; border-left: 3px solid #4caf50; font-size: 0.9em;">
                                <strong>✓ Matched SQLite Set:</strong> Created at <?php echo $info['sqlite_created_at']; ?>
                                <br>
                                <strong>Stats to import:</strong>
                                3DA: <?php echo $info['stats']['3da1']; ?> / <?php echo $info['stats']['3da2']; ?> |
                                Darts: <?php echo $info['stats']['darts1']; ?> / <?php echo $info['stats']['darts2']; ?> |
                                Dbl Attempts: <?php echo $info['stats']['dblattempts1']; ?> / <?php echo $info['stats']['dblattempts2']; ?> |
                                High Score: <?php echo $info['stats']['highscore1']; ?> / <?php echo $info['stats']['highscore2']; ?> |
                                High CO: <?php echo $info['stats']['highco1']; ?> / <?php echo $info['stats']['highco2']; ?>
                            </div>
                        <?php else: ?>
                            <div style="opacity: 0.6;">
                                <strong>Set <?php echo $set['id']; ?>:</strong>
                                <?php echo htmlspecialchars(getPlayerName($set['player1_id'])); ?> 
                                (<?php echo $set['legs1']; ?>) 
                                vs 
                                <?php echo htmlspecialchars(getPlayerName($set['player2_id'])); ?> 
                                (<?php echo $set['legs2']; ?>)
                                <br>
                                <span style="color: #dc3545;">✗ No matching set found in database</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php 
                    endforeach;
                    if ($current_match !== null) echo '</div>';
                    ?>
                <?php endif; ?>
                <br>
                <?php if ($matched_count > 0): ?>
                    <input type="submit" name="confirm_matches" value="Continue to Final Confirmation">
                <?php endif; ?>
                <input type="submit" name="reset_import" value="Cancel" onclick="return confirm('This will cancel the import. Continue?');">
            </form>
        </div>
        
    <?php elseif ($_SESSION['import_step'] == 'confirm_import'): ?>
        <!-- Step 5: Final Confirmation Before Import -->
        <?php 
        $selected_matchday = $_SESSION['selected_matchday'];
        $selected_sets = $_SESSION['selected_sets'];
        
        // Re-match to show final preview
        $matches_info = matchAllSets($selected_matchday);
        
        // Filter to only show selected sets
        $selected_matches = [];
        foreach ($matches_info as $info) {
            if (in_array($info['set']['id'], $selected_sets) && $info['matched']) {
                $selected_matches[] = $info;
            }
        }
        ?>
        <div class="form-section">
            <h2>Step 5: Final Confirmation</h2>
            <p>Review the statistics that will be imported. Click "Import Now" to proceed.</p>
            
            <div class="warning">
                <strong>⚠ Warning:</strong> This will overwrite existing statistics for the selected sets. This action cannot be undone.
            </div>
            
            <h3>Import Summary</h3>
            <p><strong>Matchday:</strong> <?php echo $selected_matchday; ?></p>
            <p><strong>Sets to import:</strong> <?php echo count($selected_matches); ?></p>
            
            <?php if (empty($selected_matches)): ?>
                <p>No sets selected for import.</p>
                <form method="POST">
                    <input type="submit" name="reset_import" value="Start Over">
                </form>
            <?php else: ?>
                <?php 
                $current_match = null;
                foreach ($selected_matches as $info): 
                    $set = $info['set'];
                    
                    if ($current_match != $set['match_id']):
                        if ($current_match !== null) echo '</div>';
                        $current_match = $set['match_id'];
                        echo '<h3>Match: ' . htmlspecialchars(getPlayerName($set['match']['player1_id'])) . ' vs ' . htmlspecialchars(getPlayerName($set['match']['player2_id'])) . '</h3>';
                        echo '<div style="margin-left: 20px;">';
                    endif;
                ?>
                <div class="set-item">
                    <strong>Set <?php echo $set['id']; ?>:</strong>
                    <?php echo htmlspecialchars(getPlayerName($set['player1_id'])); ?> 
                    (<?php echo $set['legs1']; ?>) 
                    vs 
                    <?php echo htmlspecialchars(getPlayerName($set['player2_id'])); ?> 
                    (<?php echo $set['legs2']; ?>)
                    
                    <table style="margin-top: 10px; width: 100%; font-size: 0.9em;">
                        <tr>
                            <th>Player</th>
                            <th>3DA</th>
                            <th>Darts</th>
                            <th>Dbl Att.</th>
                            <th>High Score</th>
                            <th>High CO</th>
                        </tr>
                        <tr>
                            <td><?php echo htmlspecialchars(getPlayerName($set['player1_id'])); ?></td>
                            <td><?php echo $info['stats']['3da1']; ?></td>
                            <td><?php echo $info['stats']['darts1']; ?></td>
                            <td><?php echo $info['stats']['dblattempts1']; ?></td>
                            <td><?php echo $info['stats']['highscore1']; ?></td>
                            <td><?php echo $info['stats']['highco1']; ?></td>
                        </tr>
                        <tr>
                            <td><?php echo htmlspecialchars(getPlayerName($set['player2_id'])); ?></td>
                            <td><?php echo $info['stats']['3da2']; ?></td>
                            <td><?php echo $info['stats']['darts2']; ?></td>
                            <td><?php echo $info['stats']['dblattempts2']; ?></td>
                            <td><?php echo $info['stats']['highscore2']; ?></td>
                            <td><?php echo $info['stats']['highco2']; ?></td>
                        </tr>
                    </table>
                </div>
                <?php 
                endforeach;
                if ($current_match !== null) echo '</div>';
                ?>
                
                <form method="POST">
                    <br>
                    <input type="submit" name="import_stats" value="Import Now" onclick="return confirm('This will import the statistics shown above. Any existing data will be overwritten. Continue?');">
                    <input type="submit" name="reset_import" value="Cancel" onclick="return confirm('This will cancel the import. Continue?');">
                </form>
            <?php endif; ?>
        </div>
        
    <?php elseif ($_SESSION['import_step'] == 'complete'): ?>
        <!-- Import Complete -->
        <div class="form-section">
            <h2>Import Complete!</h2>
            <div class="success">
                Statistics have been successfully imported.
            </div>
            
            <p>
                <a href="matchdays.php?view=<?php echo $_SESSION['selected_matchday']; ?>"><button>View Matchday <?php echo $_SESSION['selected_matchday']; ?></button></a>
                <a href="index.php"><button>Back to Tournament Overview</button></a>
            </p>
            
            <form method="POST">
                <input type="submit" name="reset_import" value="Import Another File">
            </form>
        </div>
    <?php endif; ?>
    
    <hr style="margin-top: 40px;">
    <p>
        <a href="index.php">Home</a> | 
        <a href="matchdays.php">Matches Overview</a>
        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']): ?>
            | <a href="players.php">Player Management</a>
            | <a href="matchday_setup.php">Tournament Setup</a>
            | <a href="index.php?logout=1">Logout</a>
        <?php else: ?>
            | <a href="index.php#login">Login</a>
        <?php endif; ?>
    </p>
</body>
</html>
