<?php
// matchdays.php - Matchday Management
session_start();

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';
$sets_file = 'tables/sets.csv';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_matchday'])) {
        updateMatchday($_POST['id'], $_POST['date'], $_POST['location']);
        header('Location: matchdays.php');
        exit;
    }
    
    if (isset($_POST['assign_playoffs'])) {
        assignPlayoffPlayers($_POST['matchday_id'], $_POST);
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '#semis');
        exit;
    }
    
    if (isset($_POST['auto_assign_finals'])) {
        autoAssignFinals($_POST['matchday_id']);
        // Force reload
        //header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '&t=' . time());
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '#finals');
        exit;
    }
    
    if (isset($_POST['add_set'])) {
        $result = addSet($_POST);
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '&edit_match=' . $_POST['match_id'] . '#match' . $_POST['match_id']);
        exit;
    }
    
    if (isset($_POST['delete_set'])) {
        $set_id = $_POST['set_id'];
        $match_id = $_POST['match_id'];
        $matchday_id = $_POST['matchday_id'];
        
        // Check if confirmed cascade delete
        if (isset($_POST['confirm_cascade'])) {
            // User confirmed - delete set and reset cascading phases
            $cascade_info = checkCascadeEffects($set_id);
            if ($cascade_info['has_cascade']) {
                resetCascadingPhases($cascade_info);
            }
            deleteSet($set_id);
            $_SESSION['success'] = 'Set deleted successfully. ' . ($cascade_info['has_cascade'] ? 'Affected phases have been reset.' : '');
            header('Location: matchdays.php?view=' . $matchday_id . '&edit_match=' . $match_id . '#match' . $match_id);
            exit;
        }
        
        // Check if this deletion will cause cascade effects
        $cascade_info = checkCascadeEffects($set_id);
        
        if ($cascade_info['has_cascade']) {
            // Show warning page
            $_SESSION['cascade_warning'] = $cascade_info;
            $_SESSION['delete_set_data'] = [
                'set_id' => $set_id,
                'match_id' => $match_id,
                'matchday_id' => $matchday_id
            ];
            header('Location: matchdays.php?view=' . $matchday_id . '&edit_match=' . $match_id . '&show_cascade_warning=1#match' . $match_id);
            exit;
        } else {
            // No cascade - delete directly
            deleteSet($set_id);
            $_SESSION['success'] = 'Set deleted successfully.';
            header('Location: matchdays.php?view=' . $matchday_id . '&edit_match=' . $match_id . '#match' . $match_id);
            exit;
        }
    }
    
    if (isset($_POST['save_extra_points'])) {
        saveExtraPoints($_POST['matchday_id'], $_POST['extra_points']);
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '#extrapoints');
        exit;
    }
    
    if (isset($_POST['update_set_stats'])) {
        $result = updateSetStats($_POST);
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '&edit_match=' . $_POST['match_id'] . '#match' . $_POST['match_id']);
        exit;
}

}

// Load data
$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// View specific matchday
$view_matchday = isset($_GET['view']) ? intval($_GET['view']) : null;
$edit_matchday = isset($_GET['edit']) ? intval($_GET['edit']) : null;
$edit_match = isset($_GET['edit_match']) ? intval($_GET['edit_match']) : null;
$edit_set = isset($_GET['edit_set']) ? intval($_GET['edit_set']) : null;

// Functions
function loadPlayers() {
    global $players_file;
    $players = [];
    if (!empty($players_file) && file_exists($players_file) && ($fp = fopen($players_file, 'r')) !== false) {
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
    if (!empty($matchdays_file) && file_exists($matchdays_file) && ($fp = fopen($matchdays_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matchdays[] = [
                'id' => $row[0],
                'date' => $row[1],
                'location' => $row[2],
                'standingsmethod' => isset($row[3]) ? $row[3] : 'points',
                'winpoints' => isset($row[4]) ? intval($row[4]) : 2
            ];
        }
        fclose($fp);
    }
    return $matchdays;
}

function loadMatches() {
    global $matches_file;
    $matches = [];
    if (!empty($matches_file) && file_exists($matches_file) && ($fp = fopen($matches_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matches[] = [
                'id' => $row[0],
                'matchdayid' => $row[1],
                'phase' => $row[2],
                'firsttosets' => $row[3],
                'firsttolegs' => $row[4],
                'player1id' => $row[5],
                'player2id' => $row[6],
                'sets1' => $row[7],
                'sets2' => $row[8]
            ];
        }
        fclose($fp);
    }
    return $matches;
}

function getMatchdayById($id) {
    global $matchdays;
    foreach ($matchdays as $md) {
        if ($md['id'] == $id) return $md;
    }
    return null;
}

function getMatchesByMatchday($matchday_id) {
    global $all_matches;
    return array_filter($all_matches, function($m) use ($matchday_id) {
        return $m['matchdayid'] == $matchday_id;
    });
}

function updateMatchday($id, $date, $location) {
    global $matchdays_file;
    
    // Load current matchdays
    $matchdays = loadMatchdays();
    
    $fp = fopen($matchdays_file, 'w');
    fputcsv($fp, ['id', 'date', 'location', 'standingsmethod', 'winpoints']);
    foreach ($matchdays as $md) {
        if ($md['id'] == $id) {
            fputcsv($fp, [
                $id,
                $date,
                $location,
                isset($md['standingsmethod']) ? $md['standingsmethod'] : 'points',
                isset($md['winpoints']) ? $md['winpoints'] : 2
            ]);
        } else {
            fputcsv($fp, [
                $md['id'],
                $md['date'],
                $md['location'],
                isset($md['standingsmethod']) ? $md['standingsmethod'] : 'points',
                isset($md['winpoints']) ? $md['winpoints'] : 2
            ]);
        }
    }
    fclose($fp);
}

function assignPlayoffPlayers($matchday_id, $data) {
    global $matches_file;
    
    // Load current matches
    $all_matches = loadMatches();
    
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    foreach ($all_matches as $match) {
        if ($match['matchdayid'] == $matchday_id && ($match['phase'] == 'semi1' || $match['phase'] == 'semi2')) {
            // Update semi-final matches with assigned players
            $phase = $match['phase'];
            $p1_key = 'playoff_' . $phase . '_p1';
            $p2_key = 'playoff_' . $phase . '_p2';
            
            $player1id = isset($data[$p1_key]) ? $data[$p1_key] : $match['player1id'];
            $player2id = isset($data[$p2_key]) ? $data[$p2_key] : $match['player2id'];
            
            fputcsv($fp, [
                $match['id'],
                $match['matchdayid'],
                $match['phase'],
                $match['firsttosets'],
                $match['firsttolegs'],
                $player1id,
                $player2id,
                $match['sets1'],
                $match['sets2']
            ]);
        } else {
            // Keep other matches unchanged
            fputcsv($fp, [
                $match['id'],
                $match['matchdayid'],
                $match['phase'],
                $match['firsttosets'],
                $match['firsttolegs'],
                $match['player1id'],
                $match['player2id'],
                $match['sets1'],
                $match['sets2']
            ]);
        }
    }
    fclose($fp);
}

function autoAssignFinals($matchday_id) {
    global $matches_file;
    
    // Load current matches
    $all_matches = loadMatches();
    
    if (!$all_matches) {
        return; // Safety check
    }
    
    // Find semi-final results
    $semi1_winner = 0;
    $semi1_loser = 0;
    $semi2_winner = 0;
    $semi2_loser = 0;
    
    foreach ($all_matches as $match) {
        if ($match['matchdayid'] == $matchday_id) {
            if ($match['phase'] == 'semi1' && ($match['sets1'] > 0 || $match['sets2'] > 0)) {
                if ($match['sets1'] > $match['sets2']) {
                    $semi1_winner = $match['player1id'];
                    $semi1_loser = $match['player2id'];
                } else {
                    $semi1_winner = $match['player2id'];
                    $semi1_loser = $match['player1id'];
                }
            }
            if ($match['phase'] == 'semi2' && ($match['sets1'] > 0 || $match['sets2'] > 0)) {
                if ($match['sets1'] > $match['sets2']) {
                    $semi2_winner = $match['player1id'];
                    $semi2_loser = $match['player2id'];
                } else {
                    $semi2_winner = $match['player2id'];
                    $semi2_loser = $match['player1id'];
                }
            }
        }
    }
    
    // Update matches.csv with final assignments
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    foreach ($all_matches as $m) {
        if ($m['matchdayid'] == $matchday_id) {
            if ($m['phase'] == 'third' && $semi1_loser > 0 && $semi2_loser > 0) {
                // Assign losers to 3rd place match
                fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $semi1_loser, $semi2_loser, $m['sets1'], $m['sets2']]);
            } elseif ($m['phase'] == 'final' && $semi1_winner > 0 && $semi2_winner > 0) {
                // Assign winners to final
                fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $semi1_winner, $semi2_winner, $m['sets1'], $m['sets2']]);
            } else {
                fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $m['sets1'], $m['sets2']]);
            }
        } else {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $m['sets1'], $m['sets2']]);
        }
    }
    fclose($fp);
}

function loadSets($match_id) {
    global $sets_file;
    $sets = [];
    if (!empty($sets_file) && file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[1] == $match_id) {
                $sets[] = [
                    'id' => $row[0],
                    'matchid' => $row[1],
                    'player1id' => $row[2],
                    'player2id' => $row[3],
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
                    'highco2' => $row[15],
                    'bestleg1' => isset($row[16]) ? $row[16] : '',
                    'bestleg2' => isset($row[17]) ? $row[17] : '',
                    '180s1' => isset($row[18]) ? $row[18] : '',
                    '180s2' => isset($row[19]) ? $row[19] : '',
                    '140s1' => isset($row[20]) ? $row[20] : '',
                    '140s2' => isset($row[21]) ? $row[21] : '',
                    '100s1' => isset($row[22]) ? $row[22] : '',
                    '100s2' => isset($row[23]) ? $row[23] : ''
                ];
            }
        }
        fclose($fp);
    }
    return $sets;
}

function getNextSetId() {
    global $sets_file;
    $max_id = 0;
    if (!empty($sets_file) && file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] > $max_id) $max_id = $row[0];
        }
        fclose($fp);
    }
    return $max_id + 1;
}

function addSet($data) {
    global $sets_file, $matches_file;
    
    $all_matches = loadMatches();
    $match = null;
    foreach ($all_matches as $m) {
        if ($m['id'] == $data['match_id']) {
            $match = $m;
            break;
        }
    }
    
    if (!$match) {
        return ['success' => false, 'message' => 'Match not found.'];
    }
    
    // Validate leg counts
    $legs1 = intval($data['legs1']);
    $legs2 = intval($data['legs2']);
    $firsttolegs = intval($match['firsttolegs']);
    
    if ($legs1 > $firsttolegs || $legs2 > $firsttolegs) {
        return ['success' => false, 'message' => "Legs won cannot exceed first-to-" . $firsttolegs . " format."];
    }
    
    if ($legs1 != $firsttolegs && $legs2 != $firsttolegs) {
        return ['success' => false, 'message' => "One player must reach " . $firsttolegs . " legs to win the set."];
    }
    
    // Check if match is already won
    $existing_sets = loadSets($data['match_id']);
    $sets1 = 0;
    $sets2 = 0;
    
    foreach ($existing_sets as $set) {
        if (intval($set['legs1']) > intval($set['legs2'])) {
            $sets1++;
        } elseif (intval($set['legs2']) > intval($set['legs1'])) {
            $sets2++;
        }
    }
    
    $firsttosets = intval($match['firsttosets']);
    if ($sets1 >= $firsttosets || $sets2 >= $firsttosets) {
        return ['success' => false, 'message' => "Match is already won (first-to-" . $firsttosets . " sets)."];
    }
    
    $set_id = getNextSetId();
    
    // Get 3DA from entered values
    $da1 = floatval($data['3da1']);
    $da2 = floatval($data['3da2']);
    
    $darts1 = floatval($data['darts1']);
    $darts2 = floatval($data['darts2']);
    
    
    $fp = fopen($sets_file, 'a');
    fputcsv($fp, [
        $set_id,
        $data['match_id'],
        $match['player1id'],
        $match['player2id'],
        $legs1,
        $legs2,
        $darts1,
        $darts2,
        $da1,
        $da2,
        $data['dblattempts1'],
        $data['dblattempts2'],
        $data['highscore1'],
        $data['highscore2'],
        $data['highco1'],
        $data['highco2'],
        isset($data['bestleg1']) ? $data['bestleg1'] : 0,
        isset($data['bestleg2']) ? $data['bestleg2'] : 0,
        isset($data['180s1']) ? $data['180s1'] : 0,
        isset($data['180s2']) ? $data['180s2'] : 0,
        isset($data['140s1']) ? $data['140s1'] : 0,
        isset($data['140s2']) ? $data['140s2'] : 0,
        isset($data['100s1']) ? $data['100s1'] : 0,
        isset($data['100s2']) ? $data['100s2'] : 0
    ]);
    fclose($fp);
    
    // Update match score
    if ($legs1 > $legs2) {
        $sets1++;
    } else {
        $sets2++;
    }
    
    // Update matches.csv
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    foreach ($all_matches as $m) {
        if ($m['id'] == $data['match_id']) {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $sets1, $sets2]);
        } else {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $m['sets1'], $m['sets2']]);
        }
    }
    fclose($fp);
    
    return ['success' => true, 'message' => 'Set added successfully.'];
}

function deleteSet($set_id) {
    global $sets_file, $matches_file;
    
    // First, find the match_id of the set being deleted
    $match_id = null;
    if (($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] == $set_id) {
                $match_id = $row[1]; // matchid column
                break;
            }
        }
        fclose($fp);
    }
    
    // Delete the set from sets.csv
    $all_sets = [];
    if (($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] != $set_id) {
                $all_sets[] = $row;
            }
        }
        fclose($fp);
    }
    
    $fp = fopen($sets_file, 'w');
    fputcsv($fp, ['id', 'matchid', 'player1id', 'player2id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2', 'bestleg1', 'bestleg2', '180s1', '180s2', '140s1', '140s2', '100s1', '100s2']);
    foreach ($all_sets as $set) {
        fputcsv($fp, $set);
    }
    fclose($fp);
    
    // Recalculate match totals if we found a match_id
    if ($match_id !== null) {
        // Count remaining sets for this match
        $sets_won_p1 = 0;
        $sets_won_p2 = 0;
        
        foreach ($all_sets as $set) {
            if ($set[1] == $match_id) { // matchid column
                $legs1 = intval($set[4]);
                $legs2 = intval($set[5]);
                
                if ($legs1 > $legs2) {
                    $sets_won_p1++;
                } elseif ($legs2 > $legs1) {
                    $sets_won_p2++;
                }
            }
        }
        
        // Update matches.csv with new totals
        $all_matches = [];
        if (($fp = fopen($matches_file, 'r')) !== false) {
            $header = fgetcsv($fp);
            while (($row = fgetcsv($fp)) !== false) {
                if ($row[0] == $match_id) {
                    // Update sets1 and sets2
                    $row[7] = $sets_won_p1;
                    $row[8] = $sets_won_p2;
                }
                $all_matches[] = $row;
            }
            fclose($fp);
        }
        
        // Write back to matches.csv
        $fp = fopen($matches_file, 'w');
        fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
        foreach ($all_matches as $match) {
            fputcsv($fp, $match);
        }
        fclose($fp);
    }
}

function checkCascadeEffects($set_id) {
    global $sets_file, $matches_file;
    
    $cascade_info = [
        'has_cascade' => false,
        'affected_phases' => [],
        'set_phase' => null,
        'matchday_id' => null
    ];
    
    // Find the set and its match
    $match_id = null;
    if (($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] == $set_id) {
                $match_id = $row[1];
                break;
            }
        }
        fclose($fp);
    }
    
    if (!$match_id) {
        return $cascade_info;
    }
    
    // Find the match phase and matchday
    $match_phase = null;
    $matchday_id = null;
    if (($fp = fopen($matches_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] == $match_id) {
                $matchday_id = $row[1];
                $match_phase = $row[2];
                break;
            }
        }
        fclose($fp);
    }
    
    if (!$match_phase || !$matchday_id) {
        return $cascade_info;
    }
    
    $cascade_info['set_phase'] = $match_phase;
    $cascade_info['matchday_id'] = $matchday_id;
    
    // Check for cascading effects based on phase
    if ($match_phase == 'group') {
        // Check if any playoff matches are assigned for this matchday
        if (($fp = fopen($matches_file, 'r')) !== false) {
            $header = fgetcsv($fp);
            while (($row = fgetcsv($fp)) !== false) {
                if ($row[1] == $matchday_id && in_array($row[2], ['semi1', 'semi2', 'final', 'third'])) {
                    // Check if assigned (player IDs are not 0)
                    if (intval($row[5]) != 0 || intval($row[6]) != 0) {
                        $cascade_info['has_cascade'] = true;
                        if (!in_array('playoffs', $cascade_info['affected_phases'])) {
                            $cascade_info['affected_phases'][] = 'playoffs';
                        }
                    }
                }
            }
            fclose($fp);
        }
    } elseif (in_array($match_phase, ['semi1', 'semi2'])) {
        // Check if finals are assigned for this matchday
        if (($fp = fopen($matches_file, 'r')) !== false) {
            $header = fgetcsv($fp);
            while (($row = fgetcsv($fp)) !== false) {
                if ($row[1] == $matchday_id && in_array($row[2], ['final', 'third'])) {
                    // Check if assigned (player IDs are not 0)
                    if (intval($row[5]) != 0 || intval($row[6]) != 0) {
                        $cascade_info['has_cascade'] = true;
                        if (!in_array('finals', $cascade_info['affected_phases'])) {
                            $cascade_info['affected_phases'][] = 'finals';
                        }
                    }
                }
            }
            fclose($fp);
        }
    }
    
    return $cascade_info;
}

function resetCascadingPhases($cascade_info) {
    global $matches_file, $sets_file;
    
    $matchday_id = $cascade_info['matchday_id'];
    $set_phase = $cascade_info['set_phase'];
    
    $phases_to_reset = [];
    
    if ($set_phase == 'group') {
        // Reset all playoff phases
        $phases_to_reset = ['semi1', 'semi2', 'final', 'third'];
    } elseif (in_array($set_phase, ['semi1', 'semi2'])) {
        // Reset finals only
        $phases_to_reset = ['final', 'third'];
    }
    
    if (empty($phases_to_reset)) {
        return;
    }
    
    // Step 1: Delete all sets from affected matches
    $matches_to_reset = [];
    $all_matches = [];
    
    if (($fp = fopen($matches_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[1] == $matchday_id && in_array($row[2], $phases_to_reset)) {
                $matches_to_reset[] = $row[0]; // Store match ID
                // Reset match: clear players and scores
                $row[5] = 0; // player1id
                $row[6] = 0; // player2id
                $row[7] = 0; // sets1
                $row[8] = 0; // sets2
            }
            $all_matches[] = $row;
        }
        fclose($fp);
    }
    
    // Step 2: Delete sets from affected matches
    $all_sets = [];
    if (($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            // Keep set only if its match is NOT in the reset list
            if (!in_array($row[1], $matches_to_reset)) {
                $all_sets[] = $row;
            }
        }
        fclose($fp);
    }
    
    // Step 3: Write back updated matches.csv
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    foreach ($all_matches as $match) {
        fputcsv($fp, $match);
    }
    fclose($fp);
    
    // Step 4: Write back updated sets.csv
    $fp = fopen($sets_file, 'w');
    fputcsv($fp, ['id', 'matchid', 'player1id', 'player2id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2', 'bestleg1', 'bestleg2', '180s1', '180s2', '140s1', '140s2', '100s1', '100s2']);
    foreach ($all_sets as $set) {
        fputcsv($fp, $set);
    }
    fclose($fp);
}

function updateSetStats($data) {
    global $sets_file;
    
    $set_id = intval($data['set_id']);
    
    // Read all sets
    $all_sets = [];
    $updated = false;
    
    if (($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] == $set_id) {
                // Update only the stats, keep legs the same
                $row[6] = intval($data['darts1']); // darts1
                $row[7] = intval($data['darts2']); // darts2
                $row[8] = floatval($data['3da1']); // 3da1
                $row[9] = floatval($data['3da2']); // 3da2
                $row[10] = intval($data['dblattempts1']); // dblattempts1
                $row[11] = intval($data['dblattempts2']); // dblattempts2
                $row[12] = intval($data['highscore1']); // highscore1
                $row[13] = intval($data['highscore2']); // highscore2
                $row[14] = intval($data['highco1']); // highco1
                $row[15] = intval($data['highco2']); // highco2
                $row[16] = intval($data['bestleg1']); // bestleg1
                $row[17] = intval($data['bestleg2']); // bestleg2
                $row[18] = intval($data['180s1']); // 180s1
                $row[19] = intval($data['180s2']); // 180s2
                $row[20] = intval($data['140s1']); // 140s1
                $row[21] = intval($data['140s2']); // 140s2
                $row[22] = intval($data['100s1']); // 100s1
                $row[23] = intval($data['100s2']); // 100s2
                $updated = true;
            }
            $all_sets[] = $row;
        }
        fclose($fp);
    }
    
    if (!$updated) {
        return ['success' => false, 'message' => 'Set not found.'];
    }
    
    // Write back to CSV
    $fp = fopen($sets_file, 'w');
    fputcsv($fp, ['id', 'matchid', 'player1id', 'player2id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2', 'bestleg1', 'bestleg2', '180s1', '180s2', '140s1', '140s2', '100s1', '100s2']);
    foreach ($all_sets as $set) {
        fputcsv($fp, $set);
    }
    fclose($fp);
    
    return ['success' => true, 'message' => 'Set statistics updated successfully.'];
}

function getPlayerName($player_id) {
    global $players;
    if ($player_id == 0) return 'TBD';
    if (isset($players[$player_id])) {
        $p = $players[$player_id];
        return $p['nickname'] ? $p['name'] . ' (' . $p['nickname'] . ')' : $p['name'];
    }
    return 'Unknown';
}

function getPhaseLabel($phase) {
    $labels = [
        'group' => 'Group Phase',
        'semi1' => 'Semi-Final 1',
        'semi2' => 'Semi-Final 2',
        'third' => '3rd Place',
        'final' => 'Final'
    ];
    return isset($labels[$phase]) ? $labels[$phase] : $phase;
}

function saveExtraPoints($matchday_id, $extra_points) {
    $extrapoints_file = 'tables/extrapoints.csv';
    
    // Load all existing extra points
    $all_extra = [];
    if (file_exists($extrapoints_file) && ($fp = fopen($extrapoints_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            // Keep points from other matchdays
            if ($row[1] != $matchday_id) {
                $all_extra[] = $row;
            }
        }
        fclose($fp);
    }
    
    // Add new extra points for this matchday
    foreach ($extra_points as $player_id => $points) {
        $points = intval($points);
        if ($points > 0) {
            $all_extra[] = [$player_id, $matchday_id, $points];
        }
    }
    
    // Write back to file
    $fp = fopen($extrapoints_file, 'w');
    fputcsv($fp, ['player_id', 'matchday_id', 'points']);
    foreach ($all_extra as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
}

function getHeadToHeadResult($player1_id, $player2_id, $matches) {
    // Returns: positive if player1 won more, negative if player2 won more, 0 if tied
    $p1_wins = 0;
    $p2_wins = 0;
    
    foreach ($matches as $match) {
        $p1id = $match['player1id'];
        $p2id = $match['player2id'];
        $sets1 = intval($match['sets1']);
        $sets2 = intval($match['sets2']);
        
        // Skip unplayed matches
        if ($sets1 == 0 && $sets2 == 0) continue;
        
        // Check if this match is between the two players we're comparing
        if (($p1id == $player1_id && $p2id == $player2_id) || 
            ($p1id == $player2_id && $p2id == $player1_id)) {
            
            // Determine winner
            if ($sets1 > $sets2) {
                if ($p1id == $player1_id) {
                    $p1_wins++;
                } else {
                    $p2_wins++;
                }
            } elseif ($sets2 > $sets1) {
                if ($p2id == $player1_id) {
                    $p1_wins++;
                } else {
                    $p2_wins++;
                }
            }
        }
    }
    
    // Return comparison result
    return $p1_wins - $p2_wins;
}

function calculateStandings($matches, $matchday_config) {
    global $players, $sets_file;
    
    $standingsmethod = isset($matchday_config['standingsmethod']) ? $matchday_config['standingsmethod'] : 'points';
    $winpoints = isset($matchday_config['winpoints']) ? intval($matchday_config['winpoints']) : 2;
    
    // Initialize standings
    $standings = [];
    foreach ($players as $player) {
        $standings[$player['id']] = [
            'id' => $player['id'],
            'name' => getPlayerName($player['id']),
            'played' => 0,
            'won' => 0,
            'lost' => 0,
            'legs_for' => 0,
            'legs_against' => 0,
            'points' => 0,
            'total_darts' => 0,
            'total_legs' => 0,
            'dbl_attempts' => 0,
            'dbl_hit' => 0,
            'bestleg' => PHP_INT_MAX,
            '180s' => 0,
            '140s' => 0,
            '100s' => 0,
            'highscore' => 0,
            'highco' => 0
        ];
    }
    
    // Process matches
    foreach ($matches as $match) {
        $p1id = $match['player1id'];
        $p2id = $match['player2id'];
        $sets1 = intval($match['sets1']);
        $sets2 = intval($match['sets2']);
        
        // Only count completed matches
        if ($sets1 > 0 || $sets2 > 0) {
            $standings[$p1id]['played']++;
            $standings[$p2id]['played']++;
            
            // Determine winner and award points
            if ($sets1 > $sets2) {
                $standings[$p1id]['won']++;
                $standings[$p1id]['points'] += $winpoints;
                $standings[$p2id]['lost']++;
            } elseif ($sets2 > $sets1) {
                $standings[$p2id]['won']++;
                $standings[$p2id]['points'] += $winpoints;
                $standings[$p1id]['lost']++;
            }
            
            // Get legs from sets.csv for this match
            if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                $header = fgetcsv($fp);
                while (($row = fgetcsv($fp)) !== false) {
                    if ($row[1] == $match['id']) { // matchid column
                        $legs1 = intval($row[4]);
                        $legs2 = intval($row[5]);
                        
                        $standings[$p1id]['legs_for'] += $legs1;
                        $standings[$p1id]['legs_against'] += $legs2;
                        $standings[$p2id]['legs_for'] += $legs2;
                        $standings[$p2id]['legs_against'] += $legs1;

                        // Weighted 3DA calculation
                        $da1 = floatval($row[8]);
                        $da2 = floatval($row[9]);
                        $total_legs_in_set = $legs1 + $legs2;
                        
                        if ($da1 > 0) {
                            $standings[$p1id]['total_darts'] += $da1 * $total_legs_in_set;
                            $standings[$p1id]['total_legs'] += $total_legs_in_set;
                        }
                        if ($da2 > 0) {
                            $standings[$p2id]['total_darts'] += $da2 * $total_legs_in_set;
                            $standings[$p2id]['total_legs'] += $total_legs_in_set;
                        }
                        
                        // Double attempts and hits
                        $standings[$p1id]['dbl_attempts'] += intval($row[10]);
                        $standings[$p2id]['dbl_attempts'] += intval($row[11]);
                        
                        if (intval($row[10]) > 0) {
                            $standings[$p1id]['dbl_hit'] += $legs1;
                        }
                        if (intval($row[11]) > 0) {
                            $standings[$p2id]['dbl_hit'] += $legs2;
                        }
                        
                        // Detailed stats
                        $bestleg1 = isset($row[16]) ? intval($row[16]) : 0;
                        $bestleg2 = isset($row[17]) ? intval($row[17]) : 0;
                        
                        if ($bestleg1 > 0 && $bestleg1 < $standings[$p1id]['bestleg']) {
                            $standings[$p1id]['bestleg'] = $bestleg1;
                        }
                        if ($bestleg2 > 0 && $bestleg2 < $standings[$p2id]['bestleg']) {
                            $standings[$p2id]['bestleg'] = $bestleg2;
                        }
                        
                        $standings[$p1id]['180s'] += isset($row[18]) ? intval($row[18]) : 0;
                        $standings[$p2id]['180s'] += isset($row[19]) ? intval($row[19]) : 0;
                        $standings[$p1id]['140s'] += isset($row[20]) ? intval($row[20]) : 0;
                        $standings[$p2id]['140s'] += isset($row[21]) ? intval($row[21]) : 0;
                        $standings[$p1id]['100s'] += isset($row[22]) ? intval($row[22]) : 0;
                        $standings[$p2id]['100s'] += isset($row[23]) ? intval($row[23]) : 0;
                        
                        // High scores
                        $hs1 = intval($row[12]);
                        $hs2 = intval($row[13]);
                        $hco1 = intval($row[14]);
                        $hco2 = intval($row[15]);
                        
                        if ($hs1 > $standings[$p1id]['highscore']) $standings[$p1id]['highscore'] = $hs1;
                        if ($hs2 > $standings[$p2id]['highscore']) $standings[$p2id]['highscore'] = $hs2;
                        if ($hco1 > $standings[$p1id]['highco']) $standings[$p1id]['highco'] = $hco1;
                        if ($hco2 > $standings[$p2id]['highco']) $standings[$p2id]['highco'] = $hco2;
                    }
                }
                fclose($fp);
            }
        }
    }
    
    // Remove players who didn't play
    $standings = array_filter($standings, function($s) {
        return $s['played'] > 0;
    });
    
    // Sort based on method
    usort($standings, function($a, $b) use ($standingsmethod, $matches) {
        if ($standingsmethod == 'leg_diff') {
            // Pure leg difference sorting
            $diff_a = $a['legs_for'] - $a['legs_against'];
            $diff_b = $b['legs_for'] - $b['legs_against'];
            if ($diff_a != $diff_b) return $diff_b - $diff_a;
            
            // Tiebreaker 1: Direct comparison (head-to-head)
            $h2h = getHeadToHeadResult($a['id'], $b['id'], $matches);
            if ($h2h != 0) return $h2h;
            
            // Tiebreaker 2: Total legs won
            return $b['legs_for'] - $a['legs_for'];
        } else {
            // Points-based sorting (default)
            if ($b['points'] != $a['points']) {
                return $b['points'] - $a['points'];
            }
            
            // Tiebreaker 1: Leg difference
            $diff_a = $a['legs_for'] - $a['legs_against'];
            $diff_b = $b['legs_for'] - $b['legs_against'];
            if ($diff_b != $diff_a) {
                return $diff_b - $diff_a;
            }
            
            // Tiebreaker 2: Direct comparison (head-to-head)
            $h2h = getHeadToHeadResult($a['id'], $b['id'], $matches);
            if ($h2h != 0) return $h2h;
            
            // Tiebreaker 3: Total legs won
            return $b['legs_for'] - $a['legs_for'];
        }
    });
    
    return $standings;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Matchday Management</title>
    <link rel="stylesheet" href="styles.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    
    <h1>Matchday Management</h1>
    
    <?php if (empty($matchdays)): ?>
        <div class="info">
            No matchdays created yet. Please run the tournament setup first.<br>
            <a href="setup.php"><button type="button">Go to Tournament Setup</button></a>
        </div>
    <?php elseif ($edit_matchday): ?>
        <!-- Edit Matchday Form -->
        <?php $md = getMatchdayById($edit_matchday); ?>
        <div>
            <h2>Edit Matchday <?php echo $md['id']; ?></h2>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $md['id']; ?>">
                
                <label>Date:</label><br>
                <input type="date" name="date" value="<?php echo htmlspecialchars($md['date']); ?>"><br>
                
                <label>Location:</label><br>
                <input type="text" name="location" value="<?php echo htmlspecialchars($md['location']); ?>" size="50"><br>
                
                <input type="submit" name="update_matchday" value="Save">
                <a href="matchdays.php"><button type="button">Cancel</button></a>
            </form>
        </div>
    <?php elseif ($view_matchday): ?>
        <!-- View Matchday Details -->
        <?php 
        $md = getMatchdayById($view_matchday);
        $matches = getMatchesByMatchday($view_matchday);
        $group_matches = array_filter($matches, function($m) { return $m['phase'] == 'group'; });
        $playoff_matches = array_filter($matches, function($m) { return $m['phase'] != 'group'; });
        ?>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h2>Matchday <?php echo $md['id']; ?></h2>
            <button type="button" id="toggleDetailedStats" onclick="toggleDetailedStats()" style="padding: 8px 15px;">Show Detailed Stats</button>
        </div>
        
        <script>
            function toggleDetailedStats() {
                const btn = document.getElementById('toggleDetailedStats');
                const groupDetailedCols = document.querySelectorAll('.detailed-stats-col');
                
                if (groupDetailedCols.length === 0) return;
                
                const isCurrentlyHidden = groupDetailedCols[0].style.display === 'none';
                
                groupDetailedCols.forEach(col => {
                    col.style.display = isCurrentlyHidden ? 'table-cell' : 'none';
                });
                
                btn.textContent = isCurrentlyHidden ? 'Hide Detailed Stats' : 'Show Detailed Stats';
            }
        </script>
        
        <p>
            <strong>Date:</strong> <?php echo $md['date'] ? $md['date'] : 'Not set'; ?> | 
            <strong>Location:</strong> <?php echo $md['location'] ? $md['location'] : 'Not set'; ?>
            <?php if ($is_admin): ?>
                | <a href="matchdays.php?edit=<?php echo $md['id']; ?>"><button>Edit Date/Location</button></a>
            <?php endif; ?>
        </p>
        
        <!-- Group Phase Matches
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 style="margin: 0;">Group Phase Matches</h3>
            <button type="button" id="toggleGroupStats" onclick="toggleGroupDetailedStats()" style="padding: 8px 15px;">Show All Stats</button>
        </div>
        
        <script>
            function toggleGroupDetailedStats() {
                const btn = document.getElementById('toggleGroupStats');
                const groupDetailedCols = document.querySelectorAll('.detailed-stats-col.group-stats');
                
                if (groupDetailedCols.length === 0) return;
                
                const isCurrentlyHidden = groupDetailedCols[0].style.display === 'none';
                
                groupDetailedCols.forEach(col => {
                    col.style.display = isCurrentlyHidden ? 'table-cell' : 'none';
                });
                
                btn.textContent = isCurrentlyHidden ? 'Hide All Stats' : 'Show All Stats';
            }
        </script>-->
        <h3 style="margin: 0;">Group Phase Matches</h3>
        
        <?php if (empty($group_matches)): ?>
            <p>No group matches for this matchday.</p>
        <?php else: ?>
            <?php 
            $first_match = array_values($group_matches)[0];
            ?>
            <p class="match-format">Format: First to <?php echo $first_match['firsttosets']; ?> sets (each set first to <?php echo $first_match['firsttolegs']; ?> legs)</p>
            
            <?php foreach ($group_matches as $match): 
                // Check if this match is being edited
                $is_editing = ($edit_match == $match['id']);
                
                if ($is_editing):
                    // Show score entry form
                    $match_sets = loadSets($match['id']);
                    $sets_won_p1 = 0;
                    $sets_won_p2 = 0;
                    foreach ($match_sets as $set) {
                        if (intval($set['legs1']) > intval($set['legs2'])) $sets_won_p1++;
                        elseif (intval($set['legs2']) > intval($set['legs1'])) $sets_won_p2++;
                    }
                    $match_won = ($sets_won_p1 >= intval($match['firsttosets']) || $sets_won_p2 >= intval($match['firsttosets']));
                ?>
                
                <div class="section" id="match<?php echo $match['id']; ?>">
                    <h4>Match #<?php echo $match['id']; ?> - Score Entry</h4>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="warning"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="info"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['show_cascade_warning']) && isset($_SESSION['cascade_warning'])): ?>
                        <?php 
                        $cascade = $_SESSION['cascade_warning'];
                        $delete_data = $_SESSION['delete_set_data'];
                        ?>
                        <div class="warning" style="border: 3px solid #ff0000; padding: 20px; margin: 20px 0; background-color: #fff3cd;">
                            <h3 style="color: #ff0000; margin-top: 0;">⚠️ CASCADE DELETE WARNING</h3>
                            <p><strong>Deleting this set will change the <?php echo $cascade['set_phase'] == 'group' ? 'group standings' : 'semi-final results'; ?>!</strong></p>
                            
                            <p>This will <strong>RESET and INVALIDATE</strong>:</p>
                            <ul style="color: #d00;">
                                <?php if ($cascade['set_phase'] == 'group'): ?>
                                    <li>✗ All Playoff assignments (players will be cleared)</li>
                                    <li>✗ All Semi-Final results (if entered)</li>
                                    <li>✗ Final and 3rd Place Match assignments (if assigned)</li>
                                    <li>✗ All Final results (if entered)</li>
                                <?php elseif (in_array($cascade['set_phase'], ['semi1', 'semi2'])): ?>
                                    <li>✗ Final and 3rd Place Match assignments (players will be cleared)</li>
                                    <li>✗ All Final results (if entered)</li>
                                    <li>✗ All 3rd Place Match results (if entered)</li>
                                <?php endif; ?>
                            </ul>
                            
                            <p>After deletion, you will need to:</p>
                            <ul style="color: #000;">
                                <?php if ($cascade['set_phase'] == 'group'): ?>
                                    <li>→ Re-assign all Playoff matches based on new group standings</li>
                                    <li>→ Re-enter all Playoff results</li>
                                <?php elseif (in_array($cascade['set_phase'], ['semi1', 'semi2'])): ?>
                                    <li>→ Re-assign Finals based on new semi-final results</li>
                                    <li>→ Re-enter all Final results</li>
                                <?php endif; ?>
                            </ul>
                            
                            <p style="margin-top: 20px;"><strong>Are you absolutely sure you want to continue?</strong></p>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="set_id" value="<?php echo $delete_data['set_id']; ?>">
                                <input type="hidden" name="match_id" value="<?php echo $delete_data['match_id']; ?>">
                                <input type="hidden" name="matchday_id" value="<?php echo $delete_data['matchday_id']; ?>">
                                <input type="hidden" name="confirm_cascade" value="1">
                                <input type="submit" name="delete_set" value="Yes, Delete and Reset Everything" style="background-color: #dc3545; color: white; padding: 10px 20px; font-size: 16px; font-weight: bold;">
                            </form>
                            
                            <a href="matchdays.php?view=<?php echo $delete_data['matchday_id']; ?>&edit_match=<?php echo $delete_data['match_id']; ?>#match<?php echo $delete_data['match_id']; ?>">
                                <button type="button" style="background-color: #28a745; color: white; padding: 10px 20px; font-size: 16px; font-weight: bold;">Cancel - Keep Everything</button>
                            </a>
                        </div>
                        <?php 
                        unset($_SESSION['cascade_warning']);
                        unset($_SESSION['delete_set_data']);
                        ?>
                    <?php endif; ?>
                    
                    <p><strong><?php echo getPlayerName($match['player1id']); ?></strong> vs <strong><?php echo getPlayerName($match['player2id']); ?></strong></p>
                    <p>Current Score: <?php echo $sets_won_p1; ?> : <?php echo $sets_won_p2; ?> sets</p>
                    
                    <?php if (!empty($match_sets)): ?>
                        <h5>Entered Sets</h5>
                        <table style="margin-bottom: 20px;">
                            <tr>
                                <th>Set</th>
                                <th>Player</th>
                                <th>Legs</th>
                                <!--Added manually-->
                                <th>Total Darts</th>
                                <!--end-->
                                <th>Avg</th>
                                <th>Double Attempts</th>
                                <th>Highscore</th>
                                <th>Highest Checkout</th>
                                <th>Best Leg</th>
                                <th>180s</th>
                                <th>140+</th>
                                <th>100+</th>
                                <th>Action</th>
                            </tr>
                            <?php 
                            $set_num = 1;
                            foreach ($match_sets as $set): 
                                $is_editing_set = ($edit_set == $set['id']);  // ← NEW: Check if this set is being edited
                            ?>
                            <?php if ($is_editing_set): ?>
                            <!-- EDIT MODE -->
                            <form method="POST">
                                <input type="hidden" name="set_id" value="<?php echo $set['id']; ?>">
                                <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                <tr style="background-color: #ffffcc;">
                                    <td rowspan="2"><strong><?php echo $set_num; ?></strong></td>
                                    <td><?php echo getPlayerName($match['player1id']); ?></td>
                                    <td><strong><?php echo $set['legs1']; ?></strong></td>
                                    <td><input type="number" name="darts1" value="<?php echo $set['darts1']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="3da1" value="<?php echo $set['3da1']; ?>" min="0" max="180" step="0.01" required style="width: 60px;"></td>
                                    <td><input type="number" name="dblattempts1" value="<?php echo $set['dblattempts1']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highscore1" value="<?php echo $set['highscore1']; ?>" min="0" max="180" required style="width: 60px;"></td>
                                    <td><input type="number" name="highco1" value="<?php echo $set['highco1']; ?>" min="0" max="170" required style="width: 60px;"></td>
                                    <td><input type="number" name="bestleg1" value="<?php echo $set['bestleg1']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="180s1" value="<?php echo $set['180s1']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="140s1" value="<?php echo $set['140s1']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="100s1" value="<?php echo $set['100s1']; ?>" min="0" required style="width: 60px;"></td>
                                    <td rowspan="2">
                                        <input type="submit" name="update_set_stats" value="Save Stats" style="background-color: #4CAF50; color: white;">
                                        <a href="matchdays.php?view=<?php echo $md['id']; ?>&edit_match=<?php echo $match['id']; ?>#match<?php echo $match['id']; ?>"><button type="button">Cancel</button></a>
                                    </td>
                                </tr>
                                <tr style="background-color: #ffffcc;">
                                    <td><?php echo getPlayerName($match['player2id']); ?></td>
                                    <td><strong><?php echo $set['legs2']; ?></strong></td>
                                    <td><input type="number" name="darts2" value="<?php echo $set['darts2']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="3da2" value="<?php echo $set['3da2']; ?>" min="0" max="180" step="0.01" required style="width: 60px;"></td>
                                    <td><input type="number" name="dblattempts2" value="<?php echo $set['dblattempts2']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highscore2" value="<?php echo $set['highscore2']; ?>" min="0" max="180" required style="width: 60px;"></td>
                                    <td><input type="number" name="highco2" value="<?php echo $set['highco2']; ?>" min="0" max="170" required style="width: 60px;"></td>
                                    <td><input type="number" name="bestleg2" value="<?php echo $set['bestleg2']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="180s2" value="<?php echo $set['180s2']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="140s2" value="<?php echo $set['140s2']; ?>" min="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="100s2" value="<?php echo $set['100s2']; ?>" min="0" required style="width: 60px;"></td>
                                </tr>
                            </form>
                            <?php else: ?>
                            <!-- DISPLAY MODE -->
                            <tr>
                                <td rowspan="2"><?php echo $set_num; ?></td>
                                <td><?php echo getPlayerName($match['player1id']); ?></td>
                                <td><?php echo $set['legs1']; ?></td>
                                <td><?php echo $set['darts1']; ?></td>
                                <td><?php echo $set['3da1']; ?></td>
                                <td><?php echo $set['dblattempts1']; ?></td>
                                <td><?php echo $set['highscore1']; ?></td>
                                <td><?php echo $set['highco1']; ?></td>
                                <td><?php echo $set['bestleg1']; ?></td>
                                <td><?php echo $set['180s1']; ?></td>
                                <td><?php echo $set['140s1']; ?></td>
                                <td><?php echo $set['100s1']; ?></td>
                                <td rowspan="2">
                                    <a href="matchdays.php?view=<?php echo $md['id']; ?>&edit_match=<?php echo $match['id']; ?>&edit_set=<?php echo $set['id']; ?>#match<?php echo $match['id']; ?>"><button type="button">Edit Stats</button></a>
                                    <br><br>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this set?');">
                                        <input type="hidden" name="set_id" value="<?php echo $set['id']; ?>">
                                        <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                        <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                        <input type="submit" name="delete_set" value="Delete Set">
                                    </form>
                                </td>
                            </tr>
                            <tr>
                                <td><?php echo getPlayerName($match['player2id']); ?></td>
                                <td><?php echo $set['legs2']; ?></td>
                                <td><?php echo $set['darts2']; ?></td>
                                <td><?php echo $set['3da2']; ?></td>
                                <td><?php echo $set['dblattempts2']; ?></td>
                                <td><?php echo $set['highscore2']; ?></td>
                                <td><?php echo $set['highco2']; ?></td>
                                <td><?php echo $set['bestleg2']; ?></td>
                                <td><?php echo $set['180s2']; ?></td>
                                <td><?php echo $set['140s2']; ?></td>
                                <td><?php echo $set['100s2']; ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php 
                            $set_num++;
                            endforeach; 
                            ?>
                        </table>
                    <?php endif; ?>
                    
                    <?php if (!$match_won): ?>
                        <h5>Add Set <?php echo count($match_sets) + 1; ?></h5>
                        <form method="POST">
                            <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                            <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                            
                            <table>
                                <tr>
                                    <th>Player</th>
                                    <th>Legs Won</th>
                                    <!--Added manually-->
                                    <th>Total Darts</th>
                                    <!--end-->
                                    <th>Avg</th>
                                    <th>Double Attempts</th>
                                    <th>Highscore</th>
                                    <th>Highest Checkout</th>
                                    <th>Best Leg</th>
                                    <th>180s</th>
                                    <th>140+</th>
                                    <th>100+</th>
                                </tr>
                                <tr>
                                    <td><?php echo getPlayerName($match['player1id']); ?></td>
                                    <td><input type="number" name="legs1" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                    <!--Added manually-->
                                    <td><input type="number" name="darts1" min="0" value="0" required style="width: 60px;"></td>
                                    <!--end-->
                                    <td><input type="number" name="3da1" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                    <td><input type="number" name="dblattempts1" min="0" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highscore1" min="0" max="180" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highco1" min="0" max="170" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="bestleg1" min="0" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="180s1" min="0" value="0" required style="width: 50px;"></td>
                                    <td><input type="number" name="140s1" min="0" value="0" required style="width: 50px;"></td>
                                    <td><input type="number" name="100s1" min="0" value="0" required style="width: 50px;"></td>
                                </tr>
                                <tr>
                                    <td><?php echo getPlayerName($match['player2id']); ?></td>
                                    <td><input type="number" name="legs2" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                    <!--Added manually-->
                                    <td><input type="number" name="darts2" min="0" value="0" required style="width: 60px;"></td>
                                    <!--end-->
                                    <td><input type="number" name="3da2" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                    <td><input type="number" name="dblattempts2" min="0" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highscore2" min="0" max="180" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highco2" min="0" max="170" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="bestleg2" min="0" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="180s2" min="0" value="0" required style="width: 50px;"></td>
                                    <td><input type="number" name="140s2" min="0" value="0" required style="width: 50px;"></td>
                                    <td><input type="number" name="100s2" min="0" value="0" required style="width: 50px;"></td>
                                </tr>
                            </table>
                            
                            <input type="submit" name="add_set" value="Add Set">
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Cancel</button></a>
                        </form>
                    <?php else: ?>
                        <div class="info">Match is complete!</div>
                        <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Back to Overview</button></a>
                    <?php endif; ?>
                </div>
                
                <?php else:
                    // Show match summary
                    // Get detailed stats from sets.csv
                    $sets_data = [];
                    $p1_stats = ['total_legs' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0, '3da_weighted' => 0, 'bestleg' => PHP_INT_MAX, '180s' => 0, '140s' => 0, '100s' => 0, 'highscore' => 0, 'highco' => 0];
                    $p2_stats = ['total_legs' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0, '3da_weighted' => 0, 'bestleg' => PHP_INT_MAX, '180s' => 0, '140s' => 0, '100s' => 0, 'highscore' => 0, 'highco' => 0];
                    
                    $sets_file = 'tables/sets.csv';
                    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                        $header = fgetcsv($fp);
                        while (($row = fgetcsv($fp)) !== false) {
                            if ($row[1] == $match['id']) { // matchid
                                $legs1 = intval($row[4]);
                                $legs2 = intval($row[5]);
                                $total_legs_in_set = $legs1 + $legs2;
                                
                                $sets_data[] = [
                                    'legs1' => $legs1,
                                    'legs2' => $legs2,
                                    'darts1' => intval($row[6]),
                                    'darts2' => intval($row[7]),
                                    'dblattempts1' => intval($row[10]),
                                    'dblattempts2' => intval($row[11])
                                ];
                                
                                // Both players played all legs in the set
                                $p1_stats['total_legs'] += $total_legs_in_set;
                                $p2_stats['total_legs'] += $total_legs_in_set;
                                
                                $p1_stats['dbl_attempts'] += intval($row[10]);
                                $p2_stats['dbl_attempts'] += intval($row[11]);
                                
                                // Weighted 3DA calculation - each player's 3DA weighted by total legs in set
                                $p1_stats['3da_weighted'] += floatval($row[8]) * $total_legs_in_set; // 3da1 * total legs
                                $p2_stats['3da_weighted'] += floatval($row[9]) * $total_legs_in_set; // 3da2 * total legs
                                
                                // Count successful doubles (= legs won)
                                $p1_stats['dbl_hit'] += $legs1;
                                $p2_stats['dbl_hit'] += $legs2;
                                
                                // New stats
                                $bestleg1 = isset($row[16]) ? intval($row[16]) : 0;
                                $bestleg2 = isset($row[17]) ? intval($row[17]) : 0;
                                
                                if ($bestleg1 > 0 && $bestleg1 < $p1_stats['bestleg']) {
                                    $p1_stats['bestleg'] = $bestleg1;
                                }
                                if ($bestleg2 > 0 && $bestleg2 < $p2_stats['bestleg']) {
                                    $p2_stats['bestleg'] = $bestleg2;
                                }
                                
                                $p1_stats['180s'] += isset($row[18]) ? intval($row[18]) : 0;
                                $p2_stats['180s'] += isset($row[19]) ? intval($row[19]) : 0;
                                $p1_stats['140s'] += isset($row[20]) ? intval($row[20]) : 0;
                                $p2_stats['140s'] += isset($row[21]) ? intval($row[21]) : 0;
                                $p1_stats['100s'] += isset($row[22]) ? intval($row[22]) : 0;
                                $p2_stats['100s'] += isset($row[23]) ? intval($row[23]) : 0;
                                
                                // Track max highscore and highco
                                $hs1 = isset($row[12]) ? intval($row[12]) : 0;
                                $hs2 = isset($row[13]) ? intval($row[13]) : 0;
                                $hco1 = isset($row[14]) ? intval($row[14]) : 0;
                                $hco2 = isset($row[15]) ? intval($row[15]) : 0;
                                
                                if ($hs1 > $p1_stats['highscore']) $p1_stats['highscore'] = $hs1;
                                if ($hs2 > $p2_stats['highscore']) $p2_stats['highscore'] = $hs2;
                                if ($hco1 > $p1_stats['highco']) $p1_stats['highco'] = $hco1;
                                if ($hco2 > $p2_stats['highco']) $p2_stats['highco'] = $hco2;
                            }
                        }
                        fclose($fp);
                    }
                    
                    $p1_3da = ($p1_stats['total_legs'] > 0) ? round($p1_stats['3da_weighted'] / $p1_stats['total_legs'], 2) : '-';
                    $p2_3da = ($p2_stats['total_legs'] > 0) ? round($p2_stats['3da_weighted'] / $p2_stats['total_legs'], 2) : '-';
                    $p1_dbl = ($p1_stats['dbl_attempts'] > 0) ? round(($p1_stats['dbl_hit'] / $p1_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                    $p2_dbl = ($p2_stats['dbl_attempts'] > 0) ? round(($p2_stats['dbl_hit'] / $p2_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                    
                    $show_sets = intval($first_match['firsttosets']) > 1;
                ?>
                
                <table style="margin-bottom: 20px;">
                    <tr>
                        <th colspan="<?php echo $show_sets ? (10 + count($sets_data)) : 10; ?>">
                            Match #<?php echo $match['id']; ?>
                            <?php if ($is_admin): ?>
                                <a href="matchdays.php?view=<?php echo $md['id']; ?>&edit_match=<?php echo $match['id']; ?>#match<?php echo $match['id']; ?>" style="float: right;"><button type="button">Enter/Edit Scores</button></a>

                            <?php endif; ?>
                        </th>
                    </tr>
                    <tr>
                        <th class="player-name">Player</th>
                        <?php if ($show_sets): ?>
                            <th>Sets</th>
                            <?php for ($i = 1; $i <= count($sets_data); $i++): ?>
                                <th>Set <?php echo $i; ?></th>
                            <?php endfor; ?>
                        <?php else: ?>
                            <th>Legs</th>
                        <?php endif; ?>
                        <th>Avg</th>
                        <th>Doubles %</th>
                        <th class="detailed-stats-col group-stats" style="display: none;">Highscore</th>
                        <th class="detailed-stats-col group-stats" style="display: none;">Highest Checkout</th>
                        <th class="detailed-stats-col group-stats" style="display: none;">Best Leg</th>
                        <th class="detailed-stats-col group-stats" style="display: none;">180s</th>
                        <th class="detailed-stats-col group-stats" style="display: none;">140+</th>
                        <th class="detailed-stats-col group-stats" style="display: none;">100+</th>
                    </tr>
                    <tr>
                        <td class="player-name">
                            <?php 
                            $p1_won = (intval($match['sets1']) > intval($match['sets2']));
                            if ($p1_won) echo '<strong>';
                            echo getPlayerName($match['player1id']);
                            if ($p1_won) echo '</strong>';
                            ?>
                        </td>
                        <?php if ($show_sets): ?>
                            <td>
                                <?php 
                                $p1_won = (intval($match['sets1']) > intval($match['sets2']));
                                if ($p1_won) echo '<strong>';
                                echo $match['sets1'];
                                if ($p1_won) echo '</strong>';
                                ?>
                            </td>
                            <?php foreach ($sets_data as $set): ?>
                                <td>
                                    <?php 
                                    $legs1_won = (intval($set['legs1']) > intval($set['legs2']));
                                    if ($legs1_won) echo '<strong>';
                                    echo $set['legs1'];
                                    if ($legs1_won) echo '</strong>';
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td>
                                <?php 
                                $p1_won = (intval($match['sets1']) > intval($match['sets2']));
                                if ($p1_won) echo '<strong>';
                                echo $p1_stats['dbl_hit'];
                                if ($p1_won) echo '</strong>';
                                ?>
                            </td>
                        <?php endif; ?>
                        <td><?php echo $p1_3da; ?></td>
                        <td><?php echo $p1_dbl; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p1_stats['highscore'] > 0 ? $p1_stats['highscore'] : '-'; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p1_stats['highco'] > 0 ? $p1_stats['highco'] : '-'; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo ($p1_stats['bestleg'] < PHP_INT_MAX) ? $p1_stats['bestleg'] : '-'; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p1_stats['180s']; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p1_stats['140s']; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p1_stats['100s']; ?></td>
                    </tr>
                    <tr>
                        <td class="player-name">
                            <?php 
                            $p2_won = (intval($match['sets2']) > intval($match['sets1']));
                            if ($p2_won) echo '<strong>';
                            echo getPlayerName($match['player2id']);
                            if ($p2_won) echo '</strong>';
                            ?>
                        </td>
                        <?php if ($show_sets): ?>
                            <td>
                                <?php 
                                $p2_won = (intval($match['sets2']) > intval($match['sets1']));
                                if ($p2_won) echo '<strong>';
                                echo $match['sets2'];
                                if ($p2_won) echo '</strong>';
                                ?>
                            </td>
                            <?php foreach ($sets_data as $set): ?>
                                <td>
                                    <?php 
                                    $legs2_won = (intval($set['legs2']) > intval($set['legs1']));
                                    if ($legs2_won) echo '<strong>';
                                    echo $set['legs2'];
                                    if ($legs2_won) echo '</strong>';
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td>
                                <?php 
                                $p2_won = (intval($match['sets2']) > intval($match['sets1']));
                                if ($p2_won) echo '<strong>';
                                echo $p2_stats['dbl_hit'];
                                if ($p2_won) echo '</strong>';
                                ?>
                            </td>
                        <?php endif; ?>
                        <td><?php echo $p2_3da; ?></td>
                        <td><?php echo $p2_dbl; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p2_stats['highscore'] > 0 ? $p2_stats['highscore'] : '-'; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p2_stats['highco'] > 0 ? $p2_stats['highco'] : '-'; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo ($p2_stats['bestleg'] < PHP_INT_MAX) ? $p2_stats['bestleg'] : '-'; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p2_stats['180s']; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p2_stats['140s']; ?></td>
                        <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $p2_stats['100s']; ?></td>
                    </tr>
                </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Group Phase Standings -->
        <?php if (!empty($group_matches)): ?>
            <h3>Group Phase Standings
                <?php if ($md['standingsmethod'] == 'leg_diff'): ?>
                    <small style="font-weight: normal;">(Sorted by Leg Difference)</small>
                <?php else: ?>
                    <small style="font-weight: normal;">(<?php echo $md['winpoints']; ?> points per win)</small>
                <?php endif; ?>
            </h3>
            <?php
            // Calculate standings using configured method
            $standings = calculateStandings($group_matches, $md);
            ?>
            
            <table>
                <tr>
                    <th>Pos</th>
                    <th>Player</th>
                    <th>Played</th>
                    <th>Wins</th>
                    <th>Losses</th>
                    <th>Legs+</th>
                    <th>Legs-</th>
                    <th>Leg Diff</th>
                    <?php if ($md['standingsmethod'] == 'points'): ?>
                        <th>Points</th>
                    <?php else: ?>
                        <th>Points</th>
                    <?php endif; ?>
                    <th>Avg</th>
                    <th>Doubles %</th>
                    <th class="detailed-stats-col group-stats" style="display: none;">Highscore</th>
                    <th class="detailed-stats-col group-stats" style="display: none;">Highest Checkout</th>
                    <th class="detailed-stats-col group-stats" style="display: none;">Best Leg</th>
                    <th class="detailed-stats-col group-stats" style="display: none;">180s</th>
                    <th class="detailed-stats-col group-stats" style="display: none;">140+</th>
                    <th class="detailed-stats-col group-stats" style="display: none;">100+</th>
                </tr>
                <?php 
                $pos = 1;
                foreach ($standings as $s): 
                    $three_da = ($s['total_legs'] > 0) ? round($s['total_darts'] / $s['total_legs'], 2) : '-';
                    //$three_da = ($s['total_legs'] > 0) ? round(($s['total_darts'] / $s['total_legs']) * 3, 2) : '-';
                    $dbl_pct = ($s['dbl_attempts'] > 0) ? round(($s['dbl_hit'] / $s['dbl_attempts']) * 100, 1) . '%' : '-';
                ?>
                <tr>
                    <td><?php echo $pos++; ?></td>
                    <td><?php echo $s['name']; ?></td>
                    <td><?php echo $s['played']; ?></td>
                    <td><?php echo $s['won']; ?></td>
                    <td><?php echo $s['lost']; ?></td>
                    <td><?php echo $s['legs_for']; ?></td>
                    <td><?php echo $s['legs_against']; ?></td>
                    <td><?php echo $s['legs_for'] - $s['legs_against']; ?></td>
                    <td><strong><?php echo $s['points']; ?></strong></td>
                    <td><?php echo $three_da; ?></td>
                    <td><?php echo $dbl_pct; ?></td>
                    <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $s['highscore'] > 0 ? $s['highscore'] : '-'; ?></td>
                    <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $s['highco'] > 0 ? $s['highco'] : '-'; ?></td>
                    <td class="detailed-stats-col group-stats" style="display: none;"><?php echo ($s['bestleg'] < PHP_INT_MAX) ? $s['bestleg'] : '-'; ?></td>
                    <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $s['180s']; ?></td>
                    <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $s['140s']; ?></td>
                    <td class="detailed-stats-col group-stats" style="display: none;"><?php echo $s['100s']; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <!-- Playoff Matches -->
        <?php if (!empty($playoff_matches)): ?>
            <!--<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3 style="margin: 0;">Playoff Matches</h3>
                <button type="button" id="togglePlayoffStats" onclick="togglePlayoffDetailedStats()" style="padding: 8px 15px;">Show All Stats</button>
            </div>
            
            <script>
                function togglePlayoffDetailedStats() {
                    const btn = document.getElementById('togglePlayoffStats');
                    const playoffDetailedCols = document.querySelectorAll('.detailed-stats-col.playoff-stats');
                    
                    if (playoffDetailedCols.length === 0) return;
                    
                    const isCurrentlyHidden = playoffDetailedCols[0].style.display === 'none';
                    
                    playoffDetailedCols.forEach(col => {
                        col.style.display = isCurrentlyHidden ? 'table-cell' : 'none';
                    });
                    
                    btn.textContent = isCurrentlyHidden ? 'Hide All Stats' : 'Show All Stats';
                }
            </script>-->
            <h3 style="margin: 0;">Playoff Matches</h3>
            
            <?php
            $has_unassigned = false;
            foreach ($playoff_matches as $match) {
                if ($match['player1id'] == 0 || $match['player2id'] == 0) {
                    $has_unassigned = true;
                    break;
                }
            }
            ?>
            
        <?php 
            // Check which playoffs need player assignment
            $semis_unassigned = false;
            $finals_unassigned = false;
            
            foreach ($playoff_matches as $match) {
                if ($match['phase'] == 'semi1' || $match['phase'] == 'semi2') {
                    if ($match['player1id'] == 0 || $match['player2id'] == 0) {
                        $semis_unassigned = true;
                    }
                }
                if ($match['phase'] == 'third' || $match['phase'] == 'final') {
                    if ($match['player1id'] == 0 || $match['player2id'] == 0) {
                        $finals_unassigned = true;
                    }
                }
            }
            
            // Check if all group matches are complete
            $all_group_complete = true;
            foreach ($group_matches as $gm) {
                if ($gm['sets1'] == 0 && $gm['sets2'] == 0) {
                    $all_group_complete = false;
                    break;
                }
            }
            
            // Check if semis are complete
            $semis_complete = true;
            $semis_started = false;
            foreach ($playoff_matches as $pm) {
                if ($pm['phase'] == 'semi1' || $pm['phase'] == 'semi2') {
                    if ($pm['sets1'] == 0 && $pm['sets2'] == 0) {
                        $semis_complete = false;
                    } else {
                        $semis_started = true;
                    }
                }
            }
            
            // Get standings for auto-assignment
            $standings_sorted = [];
            if ($all_group_complete && !empty($standings)) {
                $standings_sorted = $standings;
                // Already sorted by points, then leg difference
            }
            
            // Auto-assign semis if needed
            if ($semis_unassigned && $all_group_complete && count($standings_sorted) >= 4) {
                $auto_assignments = [
                    'semi1' => ['p1' => $standings_sorted[0]['id'] ?? 0, 'p2' => $standings_sorted[3]['id'] ?? 0], // 1st vs 4th
                    'semi2' => ['p1' => $standings_sorted[1]['id'] ?? 0, 'p2' => $standings_sorted[2]['id'] ?? 0]  // 2nd vs 3rd
                ];
            } else {
                $auto_assignments = [];
            }
            ?>
            
            <!-- SEMI-FINALS ASSIGNMENT -->
            <?php if ($semis_unassigned && $is_admin): ?>
                <h3 id="semis">Semi-Finals Assignment</h3>
                <div class="info">
                    <?php if (!$all_group_complete): ?>
                        <strong>Note:</strong> Complete all group matches first. Players will be auto-assigned to semi-finals based on standings.
                    <?php else: ?>
                        <strong>Ready to assign:</strong> Players will be assigned based on group standings: 1st vs 4th, 2nd vs 3rd.
                    <?php endif; ?>
                </div>
                
                <?php if ($all_group_complete): ?>
                    <table>
                        <tr>
                            <th>Match</th>
                            <th>Player 1</th>
                            <th>Player 2</th>
                        </tr>
                        <?php foreach ($playoff_matches as $match): ?>
                        <?php if ($match['phase'] == 'semi1' || $match['phase'] == 'semi2'): ?>
                        <?php
                        // Get auto-assignment
                        $p1_id = 0;
                        $p2_id = 0;
                        
                        if (isset($auto_assignments[$match['phase']])) {
                            $p1_id = $auto_assignments[$match['phase']]['p1'];
                            $p2_id = $auto_assignments[$match['phase']]['p2'];
                        }
                        
                        // Get ranking position
                        $p1_pos = '';
                        $p2_pos = '';
                        foreach ($standings_sorted as $idx => $st) {
                            if (isset($st['id']) && $st['id'] == $p1_id) {
                                $p1_pos = ($idx + 1) . ($idx == 0 ? 'st' : ($idx == 1 ? 'nd' : ($idx == 2 ? 'rd' : 'th')));
                            }
                            if (isset($st['id']) && $st['id'] == $p2_id) {
                                $p2_pos = ($idx + 1) . ($idx == 0 ? 'st' : ($idx == 1 ? 'nd' : ($idx == 2 ? 'rd' : 'th')));
                            }
                        }
                        ?>
                        <tr class="playoff-phase">
                            <td><?php echo getPhaseLabel($match['phase']); ?></td>
                            <td><?php echo getPlayerName($p1_id); ?> (<?php echo $p1_pos; ?>)</td>
                            <td><?php echo getPlayerName($p2_id); ?> (<?php echo $p2_pos; ?>)</td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </table>
                    
                    <form method="POST">
                        <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                        <?php foreach ($playoff_matches as $match): ?>
                        <?php if ($match['phase'] == 'semi1' || $match['phase'] == 'semi2'): ?>
                            <?php if (isset($auto_assignments[$match['phase']])): ?>
                                <input type="hidden" name="playoff_<?php echo $match['phase']; ?>_p1" value="<?php echo $auto_assignments[$match['phase']]['p1']; ?>">
                                <input type="hidden" name="playoff_<?php echo $match['phase']; ?>_p2" value="<?php echo $auto_assignments[$match['phase']]['p2']; ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <input type="submit" name="assign_playoffs" value="Start Semi-Finals">
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- DISPLAY ALL PLAYOFF MATCHES -->
            <?php if (!$semis_unassigned): ?>
                <?php 
                $first_playoff = array_values($playoff_matches)[0];
                $show_sets = intval($first_playoff['firsttosets']) > 1;
                ?>
                <p class="match-format">Format: First to <?php echo $first_playoff['firsttosets']; ?> sets (each set first to <?php echo $first_playoff['firsttolegs']; ?> legs)</p>
                
                <?php foreach ($playoff_matches as $match): 
                    // Skip matches that haven't been assigned yet
                    if ($match['player1id'] == 0 || $match['player2id'] == 0) {
                        continue;
                    }
                    // Check if this match is being edited
                    $is_editing = ($edit_match == $match['id']);
                    
                    if ($is_editing):
                        // Show score entry form
                        $match_sets = loadSets($match['id']);
                        $sets_won_p1 = 0;
                        $sets_won_p2 = 0;
                        foreach ($match_sets as $set) {
                            if (intval($set['legs1']) > intval($set['legs2'])) $sets_won_p1++;
                            elseif (intval($set['legs2']) > intval($set['legs1'])) $sets_won_p2++;
                        }
                        $match_won = ($sets_won_p1 >= intval($match['firsttosets']) || $sets_won_p2 >= intval($match['firsttosets']));
                    ?>
                    
                    <div class="section"  id="match<?php echo $match['id']; ?>">
                        <h4><?php echo getPhaseLabel($match['phase']); ?> - Score Entry</h4>
                        
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="warning"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="info"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['show_cascade_warning']) && isset($_SESSION['cascade_warning'])): ?>
                            <?php 
                            $cascade = $_SESSION['cascade_warning'];
                            $delete_data = $_SESSION['delete_set_data'];
                            ?>
                            <div class="warning" style="border: 3px solid #ff0000; padding: 20px; margin: 20px 0; background-color: #fff3cd;">
                                <h3 style="color: #ff0000; margin-top: 0;">⚠️ CASCADE DELETE WARNING</h3>
                                <p><strong>Deleting this set will change the <?php echo $cascade['set_phase'] == 'group' ? 'group standings' : 'semi-final results'; ?>!</strong></p>
                                
                                <p>This will <strong>RESET and INVALIDATE</strong>:</p>
                                <ul style="color: #d00;">
                                    <?php if ($cascade['set_phase'] == 'group'): ?>
                                        <li>✗ All Playoff assignments (players will be cleared)</li>
                                        <li>✗ All Semi-Final results (if entered)</li>
                                        <li>✗ Final and 3rd Place Match assignments (if assigned)</li>
                                        <li>✗ All Final results (if entered)</li>
                                    <?php elseif (in_array($cascade['set_phase'], ['semi1', 'semi2'])): ?>
                                        <li>✗ Final and 3rd Place Match assignments (players will be cleared)</li>
                                        <li>✗ All Final results (if entered)</li>
                                        <li>✗ All 3rd Place Match results (if entered)</li>
                                    <?php endif; ?>
                                </ul>
                                
                                <p>After deletion, you will need to:</p>
                                <ul style="color: #000;">
                                    <?php if ($cascade['set_phase'] == 'group'): ?>
                                        <li>→ Re-assign all Playoff matches based on new group standings</li>
                                        <li>→ Re-enter all Playoff results</li>
                                    <?php elseif (in_array($cascade['set_phase'], ['semi1', 'semi2'])): ?>
                                        <li>→ Re-assign Finals based on new semi-final results</li>
                                        <li>→ Re-enter all Final results</li>
                                    <?php endif; ?>
                                </ul>
                                
                                <p style="margin-top: 20px;"><strong>Are you absolutely sure you want to continue?</strong></p>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="set_id" value="<?php echo $delete_data['set_id']; ?>">
                                    <input type="hidden" name="match_id" value="<?php echo $delete_data['match_id']; ?>">
                                    <input type="hidden" name="matchday_id" value="<?php echo $delete_data['matchday_id']; ?>">
                                    <input type="hidden" name="confirm_cascade" value="1">
                                    <input type="submit" name="delete_set" value="Yes, Delete and Reset Everything" style="background-color: #dc3545; color: white; padding: 10px 20px; font-size: 16px; font-weight: bold;">
                                </form>
                                
                                <a href="matchdays.php?view=<?php echo $delete_data['matchday_id']; ?>&edit_match=<?php echo $delete_data['match_id']; ?>#match<?php echo $delete_data['match_id']; ?>">
                                    <button type="button" style="background-color: #28a745; color: white; padding: 10px 20px; font-size: 16px; font-weight: bold;">Cancel - Keep Everything</button>
                                </a>
                            </div>
                            <?php 
                            unset($_SESSION['cascade_warning']);
                            unset($_SESSION['delete_set_data']);
                            ?>
                        <?php endif; ?>
                        
                        <p><strong><?php echo getPlayerName($match['player1id']); ?></strong> vs <strong><?php echo getPlayerName($match['player2id']); ?></strong></p>
                        <p>Current Score: <?php echo $sets_won_p1; ?> : <?php echo $sets_won_p2; ?> sets</p>
                        
                        <?php if (!empty($match_sets)): ?>
                            <h5>Entered Sets</h5>
                            <table style="margin-bottom: 20px;">
                                <tr>
                                    <th>Set</th>
                                    <th>Player</th>
                                    <th>Legs</th>
                                    <!--Added manually-->
                                    <th>Total Darts</th>
                                    <!--end-->
                                    <th>Avg</th>
                                    <th>Double Attempts</th>
                                    <th>Highscore</th>
                                    <th>Highest Checkout</th>
                                    <th>Best Leg</th>
                                    <th>180s</th>
                                    <th>140+</th>
                                    <th>100+</th>
                                    <th>Action</th>
                                </tr>
                                <?php 
                                $set_num = 1;
                                foreach ($match_sets as $set): 
                                ?>
                                <tr>
                                    <td rowspan="2"><?php echo $set_num; ?></td>
                                    <td><?php echo getPlayerName($match['player1id']); ?></td>
                                    <td><?php echo $set['legs1']; ?></td>
                                    <!--Added manually-->
                                    <td><?php echo $set['darts1']; ?></td>
                                    <!--end-->
                                    <td><?php echo $set['3da1']; ?></td>
                                    <td><?php echo $set['dblattempts1']; ?></td>
                                    <td><?php echo $set['highscore1']; ?></td>
                                    <td><?php echo $set['highco1']; ?></td>
                                    <td><?php echo $set['bestleg1']; ?></td>
                                    <td><?php echo $set['180s1']; ?></td>
                                    <td><?php echo $set['140s1']; ?></td>
                                    <td><?php echo $set['100s1']; ?></td>
                                    <td rowspan="2">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this set?');">
                                            <input type="hidden" name="set_id" value="<?php echo $set['id']; ?>">
                                            <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                            <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                            <input type="submit" name="delete_set" value="Delete Set">
                                        </form>
                                    </td>
                                </tr>
                                <tr>
                                    <td><?php echo getPlayerName($match['player2id']); ?></td>
                                    <td><?php echo $set['legs2']; ?></td>
                                    <!--Added manually-->
                                    <td><?php echo $set['darts2']; ?></td>
                                    <!--end-->
                                    <td><?php echo $set['3da2']; ?></td>
                                    <td><?php echo $set['dblattempts2']; ?></td>
                                    <td><?php echo $set['highscore2']; ?></td>
                                    <td><?php echo $set['highco2']; ?></td>
                                    <td><?php echo $set['bestleg2']; ?></td>
                                    <td><?php echo $set['180s2']; ?></td>
                                    <td><?php echo $set['140s2']; ?></td>
                                    <td><?php echo $set['100s2']; ?></td>
                                </tr>
                                <?php 
                                $set_num++;
                                endforeach; 
                                ?>
                            </table>
                        <?php endif; ?>
                        
                        <?php if (!$match_won): ?>
                            <h5>Add Set <?php echo count($match_sets) + 1; ?></h5>
                            <form method="POST">
                                <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                
                                <table>
                                    <tr>
                                        <th>Player</th>
                                        <th>Legs Won</th>
                                        <!--Added manually-->
                                        <th>Total Darts</th>
                                        <!--end-->
                                        <th>Avg</th>
                                        <th>Double Attemps</th>
                                        <th>Highscore</th>
                                        <th>Highest Checkout</th>
                                        <th>Best Leg</th>
                                        <th>180s</th>
                                        <th>140+</th>
                                        <th>100+</th>
                                    </tr>
                                    <tr>
                                        <td><?php echo getPlayerName($match['player1id']); ?></td>
                                        <td><input type="number" name="legs1" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                        <!--Added manually-->
                                        <td><input type="number" name="darts1" min="0" value="0" required style="width: 60px;"></td>
                                        <!--end-->
                                        <td><input type="number" name="3da1" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                        <td><input type="number" name="dblattempts1" min="0" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highscore1" min="0" max="180" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highco1" min="0" max="170" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="bestleg1" min="0" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="180s1" min="0" value="0" required style="width: 50px;"></td>
                                        <td><input type="number" name="140s1" min="0" value="0" required style="width: 50px;"></td>
                                        <td><input type="number" name="100s1" min="0" value="0" required style="width: 50px;"></td>
                                    </tr>
                                    <tr>
                                        <td><?php echo getPlayerName($match['player2id']); ?></td>
                                        <td><input type="number" name="legs2" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                        <!--Added manually-->
                                        <td><input type="number" name="darts2" min="0" value="0" required style="width: 60px;"></td>
                                        <!--end-->
                                        <td><input type="number" name="3da2" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                        <td><input type="number" name="dblattempts2" min="0" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highscore2" min="0" max="180" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highco2" min="0" max="170" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="bestleg2" min="0" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="180s2" min="0" value="0" required style="width: 50px;"></td>
                                        <td><input type="number" name="140s2" min="0" value="0" required style="width: 50px;"></td>
                                        <td><input type="number" name="100s2" min="0" value="0" required style="width: 50px;"></td>
                                    </tr>
                                </table>
                                
                                <input type="submit" name="add_set" value="Add Set">
                                <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Done</button></a>
                            </form>
                        <?php else: ?>
                            <div class="info">Match is complete!</div>
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Back to Overview</button></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php else:
                        // Show match summary
                        // Get detailed stats from sets.csv
                        $sets_data = [];
                        $p1_stats = ['total_legs' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0, '3da_weighted' => 0, 'bestleg' => PHP_INT_MAX, '180s' => 0, '140s' => 0, '100s' => 0, 'highscore' => 0, 'highco' => 0];
                        $p2_stats = ['total_legs' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0, '3da_weighted' => 0, 'bestleg' => PHP_INT_MAX, '180s' => 0, '140s' => 0, '100s' => 0, 'highscore' => 0, 'highco' => 0];
                        
                        $sets_file = 'tables/sets.csv';
                        if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                            $header = fgetcsv($fp);
                            while (($row = fgetcsv($fp)) !== false) {
                                if ($row[1] == $match['id']) { // matchid
                                    $legs1 = intval($row[4]);
                                    $legs2 = intval($row[5]);
                                    $total_legs_in_set = $legs1 + $legs2;
                                    
                                    $sets_data[] = [
                                        'legs1' => $legs1,
                                        'legs2' => $legs2,
                                        'darts1' => intval($row[6]),
                                        'darts2' => intval($row[7]),
                                        'dblattempts1' => intval($row[10]),
                                        'dblattempts2' => intval($row[11])
                                    ];
                                    
                                    // Both players played all legs in the set
                                    $p1_stats['total_legs'] += $total_legs_in_set;
                                    $p2_stats['total_legs'] += $total_legs_in_set;
                                    
                                    $p1_stats['dbl_attempts'] += intval($row[10]);
                                    $p2_stats['dbl_attempts'] += intval($row[11]);
                                    
                                    // Weighted 3DA calculation - each player's 3DA weighted by total legs in set
                                    $p1_stats['3da_weighted'] += floatval($row[8]) * $total_legs_in_set; // 3da1 * total legs
                                    $p2_stats['3da_weighted'] += floatval($row[9]) * $total_legs_in_set; // 3da2 * total legs
                                    
                                    // Count successful doubles (= legs won) - only when double attempts are recorded
                                    if (intval($row[10]) > 0) {
                                        $p1_stats['dbl_hit'] += $legs1;
                                    }
                                    if (intval($row[11]) > 0) {
                                        $p2_stats['dbl_hit'] += $legs2;
                                    }
                                    
                                    // New stats
                                    $bestleg1 = isset($row[16]) ? intval($row[16]) : 0;
                                    $bestleg2 = isset($row[17]) ? intval($row[17]) : 0;
                                    
                                    if ($bestleg1 > 0 && $bestleg1 < $p1_stats['bestleg']) {
                                        $p1_stats['bestleg'] = $bestleg1;
                                    }
                                    if ($bestleg2 > 0 && $bestleg2 < $p2_stats['bestleg']) {
                                        $p2_stats['bestleg'] = $bestleg2;
                                    }
                                    
                                    $p1_stats['180s'] += isset($row[18]) ? intval($row[18]) : 0;
                                    $p2_stats['180s'] += isset($row[19]) ? intval($row[19]) : 0;
                                    $p1_stats['140s'] += isset($row[20]) ? intval($row[20]) : 0;
                                    $p2_stats['140s'] += isset($row[21]) ? intval($row[21]) : 0;
                                    $p1_stats['100s'] += isset($row[22]) ? intval($row[22]) : 0;
                                    $p2_stats['100s'] += isset($row[23]) ? intval($row[23]) : 0;
                                    
                                    // Track max highscore and highco
                                    $hs1 = isset($row[12]) ? intval($row[12]) : 0;
                                    $hs2 = isset($row[13]) ? intval($row[13]) : 0;
                                    $hco1 = isset($row[14]) ? intval($row[14]) : 0;
                                    $hco2 = isset($row[15]) ? intval($row[15]) : 0;
                                    
                                    if ($hs1 > $p1_stats['highscore']) $p1_stats['highscore'] = $hs1;
                                    if ($hs2 > $p2_stats['highscore']) $p2_stats['highscore'] = $hs2;
                                    if ($hco1 > $p1_stats['highco']) $p1_stats['highco'] = $hco1;
                                    if ($hco2 > $p2_stats['highco']) $p2_stats['highco'] = $hco2;
                                }
                            }
                            fclose($fp);
                        }
                        
                        $p1_3da = ($p1_stats['total_legs'] > 0) ? round($p1_stats['3da_weighted'] / $p1_stats['total_legs'], 2) : '-';
                        $p2_3da = ($p2_stats['total_legs'] > 0) ? round($p2_stats['3da_weighted'] / $p2_stats['total_legs'], 2) : '-';
                        $p1_dbl = ($p1_stats['dbl_attempts'] > 0) ? round(($p1_stats['dbl_hit'] / $p1_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                        $p2_dbl = ($p2_stats['dbl_attempts'] > 0) ? round(($p2_stats['dbl_hit'] / $p2_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                        
                        $show_sets = intval($first_match['firsttosets']) > 1;

                    ?> 
                    
                    <table style="margin-bottom: 20px;">
                        <tr>
                            <th colspan="<?php echo $show_sets ? (10 + count($sets_data)) : 10; ?>">
                                <?php echo getPhaseLabel($match['phase']); ?>
                                <?php if ($is_admin): ?>
                                    <a href="matchdays.php?view=<?php echo $md['id']; ?>&edit_match=<?php echo $match['id']; ?>#match<?php echo $match['id']; ?>" style="float: right;"><button type="button">Enter/Edit Scores</button></a>
                                <?php endif; ?>
                            </th>
                        </tr>
                        <tr>
                            <th class="player-name">Player</th>
                            <?php if ($show_sets): ?>
                                <th>Sets</th>
                                <?php for ($i = 1; $i <= count($sets_data); $i++): ?>
                                    <th>Set <?php echo $i; ?></th>
                                <?php endfor; ?>
                            <?php else: ?>
                                <th>Legs</th>
                            <?php endif; ?>
                            <th>Avg</th>
                            <th>Doubles %</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">Highscore</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">Highest Checkout</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">Best Leg</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">180s</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">140+</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">100+</th>
                        </tr>
                        <tr>
                            <td class="player-name">
                            <?php 
                            $p1_won = (intval($match['sets1']) > intval($match['sets2']));
                            if ($p1_won) echo '<strong>';
                            echo getPlayerName($match['player1id']);
                            if ($p1_won) echo '</strong>';
                            ?>
                        </td>
                            <?php if ($show_sets): ?>
                                <td>
                                    <?php 
                                    $p1_won = (intval($match['sets1']) > intval($match['sets2']));
                                    if ($p1_won) echo '<strong>';
                                    echo $match['sets1'];
                                    if ($p1_won) echo '</strong>';
                                    ?>
                                </td>
                                <?php foreach ($sets_data as $set): ?>
                                    <td>
                                        <?php 
                                        $legs1_won = (intval($set['legs1']) > intval($set['legs2']));
                                        if ($legs1_won) echo '<strong>';
                                        echo $set['legs1'];
                                        if ($legs1_won) echo '</strong>';
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td>
                                    <?php 
                                    $p1_won = (intval($match['sets1']) > intval($match['sets2']));
                                    if ($p1_won) echo '<strong>';
                                    echo $p1_stats['dbl_hit'];
                                    if ($p1_won) echo '</strong>';
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo $p1_3da; ?></td>
                            <td><?php echo $p1_dbl; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p1_stats['highscore'] > 0 ? $p1_stats['highscore'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p1_stats['highco'] > 0 ? $p1_stats['highco'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo ($p1_stats['bestleg'] < PHP_INT_MAX) ? $p1_stats['bestleg'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p1_stats['180s']; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p1_stats['140s']; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p1_stats['100s']; ?></td>
                        </tr>
                        <tr>
                            <td class="player-name">
                            <?php 
                            $p2_won = (intval($match['sets2']) > intval($match['sets1']));
                            if ($p2_won) echo '<strong>';
                            echo getPlayerName($match['player2id']);
                            if ($p2_won) echo '</strong>';
                            ?>
                        </td>
                            <?php if ($show_sets): ?>
                                <td>
                                    <?php 
                                    $p2_won = (intval($match['sets2']) > intval($match['sets1']));
                                    if ($p2_won) echo '<strong>';
                                    echo $match['sets2'];
                                    if ($p2_won) echo '</strong>';
                                    ?>
                                </td>
                                <?php foreach ($sets_data as $set): ?>
                                    <td>
                                        <?php 
                                        $legs2_won = (intval($set['legs2']) > intval($set['legs1']));
                                        if ($legs2_won) echo '<strong>';
                                        echo $set['legs2'];
                                        if ($legs2_won) echo '</strong>';
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td>
                                    <?php 
                                    $p2_won = (intval($match['sets2']) > intval($match['sets1']));
                                    if ($p2_won) echo '<strong>';
                                    echo $p2_stats['dbl_hit'];
                                    if ($p2_won) echo '</strong>';
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo $p2_3da; ?></td>
                            <td><?php echo $p2_dbl; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p2_stats['highscore'] > 0 ? $p2_stats['highscore'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p2_stats['highco'] > 0 ? $p2_stats['highco'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo ($p2_stats['bestleg'] < PHP_INT_MAX) ? $p2_stats['bestleg'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p2_stats['180s']; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p2_stats['140s']; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $p2_stats['100s']; ?></td>
                        </tr>
                    </table>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                
                <!-- FINALS ASSIGNMENT -->
                <?php if (!$semis_unassigned && $finals_unassigned && $is_admin): ?>
                    <h3 id="finals">Final Matches Assignment</h3>
                    <div class="info">
                        <?php if (!$semis_complete): ?>
                            <strong>Note:</strong> Complete all semi-final matches first. Players will be auto-assigned to final matches based on semi-final results.
                        <?php else: ?>
                            <strong>Ready to assign:</strong> Winners will play in the final, losers in the 3rd place match.
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($semis_complete): ?>
                        <?php
                        // Get semi-final results for preview
                        $semi1_winner = 0;
                        $semi1_loser = 0;
                        $semi2_winner = 0;
                        $semi2_loser = 0;
                        
                        foreach ($playoff_matches as $pm) {
                            if ($pm['phase'] == 'semi1' && ($pm['sets1'] > 0 || $pm['sets2'] > 0)) {
                                if ($pm['sets1'] > $pm['sets2']) {
                                    $semi1_winner = $pm['player1id'];
                                    $semi1_loser = $pm['player2id'];
                                } else {
                                    $semi1_winner = $pm['player2id'];
                                    $semi1_loser = $pm['player1id'];
                                }
                            }
                            if ($pm['phase'] == 'semi2' && ($pm['sets1'] > 0 || $pm['sets2'] > 0)) {
                                if ($pm['sets1'] > $pm['sets2']) {
                                    $semi2_winner = $pm['player1id'];
                                    $semi2_loser = $pm['player2id'];
                                } else {
                                    $semi2_winner = $pm['player2id'];
                                    $semi2_loser = $pm['player1id'];
                                }
                            }
                        }
                        ?>
                        
                        <table>
                            <tr>
                                <th>Match</th>
                                <th>Player 1</th>
                                <th>Player 2</th>
                            </tr>
                            <?php foreach ($playoff_matches as $match): ?>
                            <?php if ($match['phase'] == 'third' || $match['phase'] == 'final'): ?>
                            <tr class="playoff-phase">
                                <td><?php echo getPhaseLabel($match['phase']); ?></td>
                                <td>
                                    <?php 
                                    if ($match['phase'] == 'third') {
                                        echo getPlayerName($semi1_loser) . ' (Semi 1 loser)';
                                    } else {
                                        echo getPlayerName($semi1_winner) . ' (Semi 1 winner)';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($match['phase'] == 'third') {
                                        echo getPlayerName($semi2_loser) . ' (Semi 2 loser)';
                                    } else {
                                        echo getPlayerName($semi2_winner) . ' (Semi 2 winner)';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </table>
                        
                        <form method="POST">
                            <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                            <input type="submit" name="auto_assign_finals" value="Start Finals">
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- MATCHDAY OVERALL STANDINGS -->
                <?php 
                // Check if ALL matches are complete
                $all_matchday_complete = true;
                $all_matchday_matches = array_merge($group_matches, $playoff_matches);
                
                foreach ($all_matchday_matches as $m) {
                    // Skip unassigned matches
                    if ($m['player1id'] == 0 || $m['player2id'] == 0) continue;
                    
                    // Check if match has results
                    if ($m['sets1'] == 0 && $m['sets2'] == 0) {
                        $all_matchday_complete = false;
                        break;
                    }
                }
                ?>
                
                <?php if ($all_matchday_complete): ?>
                    <h3>Matchday Overall Standings</h3>
                    <?php
                    // Calculate overall standings including all matches (group + playoffs)
                    $all_matchday_matches = array_merge($group_matches, $playoff_matches);
                    $overall_standings = calculateStandings($all_matchday_matches, $md);
                    ?>
                    
                    <table>
                        <tr>
                            <th>Pos</th>
                            <th>Player</th>
                            <th>Played</th>
                            <th>Won</th>
                            <th>Lost</th>
                            <th>Legs+</th>
                            <th>Legs-</th>
                            <th>Leg Diff</th>
                            <th>Avg</th>
                            <th>Doubles %</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">Highscore</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">Highest Checkout</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">Best Leg</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">180s</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">140+</th>
                            <th class="detailed-stats-col playoff-stats" style="display: none;">100+</th>
                        </tr>
                        <?php 
                        $pos = 1;
                        foreach ($overall_standings as $s): 
                            $three_da = ($s['total_legs'] > 0) ? round($s['total_darts'] / $s['total_legs'], 2) : '-';
                            $dbl_pct = ($s['dbl_attempts'] > 0) ? round(($s['dbl_hit'] / $s['dbl_attempts']) * 100, 1) . '%' : '-';
                        ?>
                        <tr>
                            <td><?php echo $pos++; ?></td>
                            <td><?php echo $s['name']; ?></td>
                            <td><?php echo $s['played']; ?></td>
                            <td><?php echo $s['won']; ?></td>
                            <td><?php echo $s['lost']; ?></td>
                            <td><?php echo $s['legs_for']; ?></td>
                            <td><?php echo $s['legs_against']; ?></td>
                            <td><?php echo $s['legs_for'] - $s['legs_against']; ?></td>
                            <td><?php echo $three_da; ?></td>
                            <td><?php echo $dbl_pct; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $s['highscore'] > 0 ? $s['highscore'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $s['highco'] > 0 ? $s['highco'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo ($s['bestleg'] < PHP_INT_MAX) ? $s['bestleg'] : '-'; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $s['180s']; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $s['140s']; ?></td>
                            <td class="detailed-stats-col playoff-stats" style="display: none;"><?php echo $s['100s']; ?></td>

                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
                
                <!-- EXTRA POINTS -->
                <?php if ($all_matchday_complete && $is_admin): ?>
                    <h3 id="extrapoints">Extra Points</h3>
                    <p>Award additional points for special achievements:</p>
                    
                    <?php
                    // Load existing extra points for this matchday
                    $extrapoints_file = 'tables/extrapoints.csv';
                    $existing_extra = [];
                    
                    if (!file_exists($extrapoints_file)) {
                        $fp = fopen($extrapoints_file, 'w');
                        fputcsv($fp, ['player_id', 'matchday_id', 'points']);
                        fclose($fp);
                    }
                    
                    if (file_exists($extrapoints_file) && ($fp = fopen($extrapoints_file, 'r')) !== false) {
                        $header = fgetcsv($fp);
                        while (($row = fgetcsv($fp)) !== false) {
                            if ($row[1] == $md['id']) {
                                $existing_extra[$row[0]] = intval($row[2]);
                            }
                        }
                        fclose($fp);
                    }
                    ?>
                    
                    <form method="POST">
                        <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                        <table>
                            <tr>
                                <th>Player</th>
                                <th>Extra Points</th>
                            </tr>
                            <?php foreach ($players as $player): ?>
                            <tr>
                                <td><?php echo getPlayerName($player['id']); ?></td>
                                <td>
                                    <input type="number" 
                                           name="extra_points[<?php echo $player['id']; ?>]" 
                                           value="<?php echo isset($existing_extra[$player['id']]) ? $existing_extra[$player['id']] : 0; ?>" 
                                           min="0" 
                                           style="width: 80px;">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <input type="submit" name="save_extra_points" value="Save Extra Points">
                    </form>
                <?php endif; ?>

                
                <!--<?php if ($is_admin): ?>
                    <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button>Edit Player Assignments</button></a>
                <?php endif; ?>-->
                
            <?php endif; ?>
        <?php endif; ?>
        <p><a href="matchdays.php"><button type="button">Back to All Matchdays</button></a></p>
        
    <?php else: ?>
        <!-- List All Matchdays -->
        <h2>All Matchdays</h2>
        <table>
            <tr>
                <th>Matchday</th>
                <th>Date</th>
                <th>Location</th>
                <th>Matches</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($matchdays as $md): ?>
            <?php $match_count = count(getMatchesByMatchday($md['id'])); ?>
            <tr>
                <td>Matchday <?php echo $md['id']; ?></td>
                <td><?php echo $md['date'] ? $md['date'] : '<em>Not set</em>'; ?></td>
                <td><?php echo $md['location'] ? htmlspecialchars($md['location']) : '<em>Not set</em>'; ?></td>
                <td><?php echo $match_count; ?> matches</td>
                <td>
                    <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button>View Details</button></a>
                    <?php if ($is_admin): ?>
                        <a href="matchdays.php?edit=<?php echo $md['id']; ?>"><button>Edit Date/Location</button></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    
    <hr style="margin-top: 40px;">
    <p>
        <a href="index.php">Home</a> | 
        <a href="matchdays.php">Matches Overview</a>
        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']): ?>
            | <a href="players.php">Player Management</a>
            | <a href="matchday_setup.php">Tournament Setup</a>
            | <a href="import_stats.php">Import Stats</a>
            | <a href="index.php?logout=1">Logout</a>
        <?php else: ?>
            | <a href="index.php#login">Login</a>
        <?php endif; ?>
</body>
</html>






