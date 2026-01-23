<?php
session_start();

// import_stats.php - Import Match Statistics from SQLite

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

// Handle form submission
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['select_matches'])) {
        // Store selected matches in session
        $selected_sets = isset($_POST['selected_sets']) ? $_POST['selected_sets'] : [];
        
        if (empty($selected_sets)) {
            $_SESSION['error'] = 'Please select at least one set to import.';
            header('Location: import_stats.php?step=1');
            exit;
        }
        
        $_SESSION['selected_matches'] = isset($_POST['selected_matches']) ? $_POST['selected_matches'] : [];
        $_SESSION['selected_sets'] = $selected_sets;
        header('Location: import_stats.php?step=2');
        exit;
    }
    
    if (isset($_POST['upload_file']) && isset($_FILES['sqlite_file'])) {
        // Check for upload errors
        if ($_FILES['sqlite_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
            ];
            $error = 'Upload error: ' . ($upload_errors[$_FILES['sqlite_file']['error']] ?? 'Unknown error');
        } else {
            $tmp_file = $_FILES['sqlite_file']['tmp_name'];
            
            if (empty($tmp_file) || !file_exists($tmp_file)) {
                $error = "Uploaded file not found.";
            } else {
                try {
                    // Open SQLite database
                    $db = new SQLite3($tmp_file);
                    
                    // Get available tables
                    $tables_result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
                    $available_tables = [];
                    
                    if ($tables_result) {
                        while ($row = $tables_result->fetchArray(SQLITE3_ASSOC)) {
                            $available_tables[] = $row['name'];
                        }
                    }
                    
                    if (empty($available_tables)) {
                        $error = "Database appears to be empty.";
                        $db->close();
                    } else {
                        // Try to find the players table
                        $player_table = null;
                        $possible_names = ['Spieler', 'spieler', 'Player', 'player', 'Players', 'players'];
                        foreach ($possible_names as $table_name) {
                            if (in_array($table_name, $available_tables)) {
                                $player_table = $table_name;
                                break;
                            }
                        }
                        
                        if (!$player_table) {
                            $error = "Could not find player table. Available tables: " . implode(', ', $available_tables);
                            $db->close();
                        } else {
                            // Read first row to determine columns
                            $result = $db->query("SELECT * FROM $player_table LIMIT 1");
                            
                            if ($result) {
                                $first_row = $result->fetchArray(SQLITE3_ASSOC);
                                
                                if ($first_row) {
                                    $columns = array_keys($first_row);
                                    
                                    // Find ID and name columns
                                    $id_col = null;
                                    $name_col = null;
                                    
                                    foreach (['id', 'ID', 'spieler_id', 'player_id'] as $possible_id) {
                                        if (in_array($possible_id, $columns)) {
                                            $id_col = $possible_id;
                                            break;
                                        }
                                    }
                                    
                                    foreach (['name', 'Name', 'NAME', 'spielername', 'player_name', 'fullname'] as $possible_name) {
                                        if (in_array($possible_name, $columns)) {
                                            $name_col = $possible_name;
                                            break;
                                        }
                                    }
                                    
                                    if (!$id_col || !$name_col) {
                                        $error = "Could not identify ID and name columns. Available columns: " . implode(', ', $columns);
                                        $db->close();
                                    } else {
                                        // Read all players
                                        $result = $db->query("SELECT $id_col as id, $name_col as name FROM $player_table");
                                        $sqlite_players = [];
                                        
                                        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                                            $sqlite_players[] = [
                                                'id' => $row['id'],
                                                'name' => $row['name']
                                            ];
                                        }
                                        
                                        $db->close();
                                        
                                        // Store in session for next step
                                        $_SESSION['sqlite_file'] = $tmp_file;
                                        $_SESSION['sqlite_players'] = $sqlite_players;
                                        $_SESSION['player_table'] = $player_table;
                                        $_SESSION['id_col'] = $id_col;
                                        $_SESSION['name_col'] = $name_col;
                                        
                                        header('Location: import_stats.php?step=3');
                                        exit;
                                    }
                                } else {
                                    $error = "Player table is empty.";
                                    $db->close();
                                }
                            } else {
                                $error = "Failed to read player table.";
                                $db->close();
                            }
                        }
                    }
                    
                } catch (Exception $e) {
                    $error = "Failed to read SQLite database: " . $e->getMessage();
                }
            }
        }
    }
    
    if (isset($_POST['match_players'])) {
        // Store player mappings
        $_SESSION['player_mapping'] = $_POST['player_mapping'];
        
        // Validate: check no duplicate selections
        $selected = array_filter($_POST['player_mapping']);
        if (count($selected) != count(array_unique($selected))) {
            $_SESSION['error'] = 'Each SQLite player can only be matched once.';
            header('Location: import_stats.php?step=3');
            exit;
        }
        
        header('Location: import_stats.php?step=4');
        exit;
    }
}

// Load data
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

function loadSets($match_id) {
    global $sets_file;
    $sets = [];
    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[1] == $match_id) {
                $sets[] = [
                    'id' => $row[0],
                    'matchid' => $row[1],
                    'player1id' => $row[2],
                    'player2id' => $row[3],
                    'legs1' => $row[4],
                    'legs2' => $row[5]
                ];
            }
        }
        fclose($fp);
    }
    return $sets;
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

function findBestMatch($our_player_name, $sqlite_players) {
    // Simple similarity matching - find most similar name
    $best_match = null;
    $best_score = 0;
    
    foreach ($sqlite_players as $sp) {
        // Calculate similarity
        similar_text(strtolower($our_player_name), strtolower($sp['name']), $percent);
        
        if ($percent > $best_score) {
            $best_score = $percent;
            $best_match = $sp['id'];
        }
    }
    
    // Only return match if similarity is reasonable (>50%)
    return $best_score > 50 ? $best_match : null;
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

function setHasDetailedStats($set_id) {
    global $sets_file;
    
    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] == $set_id) {
                // Check if any detailed stats are filled (darts, 3da, dbl attempts, highscore, highco)
                $has_stats = (intval($row[6]) > 0 || intval($row[7]) > 0 ||  // darts
                             floatval($row[8]) > 0 || floatval($row[9]) > 0 ||  // 3da
                             intval($row[10]) > 0 || intval($row[11]) > 0 ||  // dbl attempts
                             intval($row[12]) > 0 || intval($row[13]) > 0 ||  // highscore
                             intval($row[14]) > 0 || intval($row[15]) > 0);   // highco
                fclose($fp);
                return $has_stats;
            }
        }
        fclose($fp);
    }
    return false;
}

$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// Filter matches with results
$matches_with_results = [];
foreach ($all_matches as $match) {
    if (($match['sets1'] > 0 || $match['sets2'] > 0) && $match['player1id'] != 0 && $match['player2id'] != 0) {
        $matches_with_results[] = $match;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Statistics - Darts League</title>
    <link rel="stylesheet" href="styles.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        function toggleMatchdaySets(matchdayId, checked) {
            // Check/uncheck all matches in this matchday
            const matchCheckboxes = document.querySelectorAll('.matchday-' + matchdayId + '-match');
            matchCheckboxes.forEach(cb => cb.checked = checked);
            
            // Check/uncheck all sets in this matchday
            const setCheckboxes = document.querySelectorAll('.matchday-' + matchdayId + '-set');
            setCheckboxes.forEach(cb => cb.checked = checked);
        }
        
        function toggleMatchSets(matchId, checked) {
            const checkboxes = document.querySelectorAll('.match-' + matchId + '-set');
            checkboxes.forEach(cb => cb.checked = checked);
        }
    </script>
</head>
<body>
    <?php
    // Navigation
    ?>
    <nav style="background-color: #f0f0f0; padding: 10px; margin-bottom: 20px; border-bottom: 2px solid #ddd;">
        <a href="index.php">Tournament Overview</a>
        <?php if (!empty($matchdays)): ?>
            <?php foreach ($matchdays as $md): ?>
                | <a href="matchdays.php?view=<?php echo $md['id']; ?>">Matchday <?php echo $md['id']; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    
    <h1>Import Match Statistics</h1>
    
    <?php if ($step == 1): ?>
        <!-- Step 1: Select Matches/Sets -->
        <div class="info">
            <strong>Step 1 of 3:</strong> Select the matches and sets for which you want to import statistics from the SQLite file.
        </div>
        
        <?php if (empty($matches_with_results)): ?>
            <div class="warning">
                <strong>No matches with results found.</strong> Play some matches first before importing statistics.
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="warning">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <form method="POST">
                <?php
                // Group matches by matchday
                $matches_by_matchday = [];
                foreach ($matches_with_results as $match) {
                    $matches_by_matchday[$match['matchdayid']][] = $match;
                }
                
                foreach ($matches_by_matchday as $md_id => $md_matches):
                    $matchday = array_filter($matchdays, function($m) use ($md_id) {
                        return $m['id'] == $md_id;
                    });
                    $matchday = array_values($matchday)[0];
                ?>
                
                <div class="section">
                    <h2>
                        <label style="font-weight: normal; font-size: 0.9em; margin-right: 15px;">
                            <input type="checkbox" onclick="toggleMatchdaySets(<?php echo $md_id; ?>, this.checked)">
                            Select All
                        </label>
                        Matchday <?php echo $md_id; ?>
                        <?php if ($matchday['date']): ?>
                            - <?php echo $matchday['date']; ?>
                        <?php endif; ?>
                    </h2>
                    
                    <table>
                        <tr>
                            <th>Select</th>
                            <th>Match</th>
                            <th>Players</th>
                            <th>Score</th>
                            <th>Sets</th>
                        </tr>
                        <?php foreach ($md_matches as $match): 
                            $sets = loadSets($match['id']);
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" 
                                       name="selected_matches[]" 
                                       value="<?php echo $match['id']; ?>"
                                       class="matchday-<?php echo $md_id; ?>-match"
                                       onclick="toggleMatchSets(<?php echo $match['id']; ?>, this.checked)">
                            </td>
                            <td>
                                <?php echo getPhaseLabel($match['phase']); ?>
                                (Match #<?php echo $match['id']; ?>)
                            </td>
                            <td>
                                <?php echo getPlayerName($match['player1id']); ?> vs 
                                <?php echo getPlayerName($match['player2id']); ?>
                            </td>
                            <td><?php echo $match['sets1']; ?> : <?php echo $match['sets2']; ?></td>
                            <td>
                                <?php if (!empty($sets)): ?>
                                    <?php foreach ($sets as $idx => $set): 
                                        $has_stats = setHasDetailedStats($set['id']);
                                    ?>
                                        <label style="display: block; margin: 2px 0; <?php echo $has_stats ? 'color: #ff6600;' : ''; ?>">
                                            <input type="checkbox" 
                                                   name="selected_sets[]" 
                                                   value="<?php echo $set['id']; ?>"
                                                   class="matchday-<?php echo $md_id; ?>-set match-<?php echo $match['id']; ?>-set">
                                            Set <?php echo ($idx + 1); ?>: 
                                            <?php echo $set['legs1']; ?>-<?php echo $set['legs2']; ?>
                                            <?php if ($has_stats): ?>
                                                <strong title="This set already has detailed statistics">[! Has Stats]</strong>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <em>No sets recorded</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                
                <?php endforeach; ?>
                
                <div class="warning" style="display: none;" id="stats-warning">
                    <strong>Warning:</strong> Some selected sets already have detailed statistics. Importing will overwrite existing data.
                </div>
                
                <script>
                    // Show warning if sets with stats are checked
                    document.addEventListener('change', function(e) {
                        if (e.target.type === 'checkbox' && e.target.name === 'selected_sets[]') {
                            const checkedWithStats = document.querySelectorAll('input[name="selected_sets[]"]:checked');
                            let hasStats = false;
                            checkedWithStats.forEach(cb => {
                                if (cb.parentElement.querySelector('strong[title*="statistics"]')) {
                                    hasStats = true;
                                }
                            });
                            document.getElementById('stats-warning').style.display = hasStats ? 'block' : 'none';
                        }
                    });
                </script>
                
                <input type="submit" name="select_matches" value="Next: Upload SQLite File">
            </form>
        <?php endif; ?>
        
    <?php elseif ($step == 2): ?>
        <!-- Step 2: Upload SQLite File -->
        <div class="info">
            <strong>Step 2 of 3:</strong> Upload the SQLite database file containing the match statistics.
        </div>
        
        <?php
        $selected_count = isset($_SESSION['selected_sets']) ? count($_SESSION['selected_sets']) : 0;
        ?>
        
        <div class="section">
            <p><strong>Selected sets:</strong> <?php echo $selected_count; ?></p>
            
            <?php if (isset($error)): ?>
                <div class="warning"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <label><strong>Select SQLite Database File:</strong></label><br>
                <input type="file" name="sqlite_file" accept=".db,.sqlite,.sqlite3,.mdt" required><br><br>
                
                <input type="submit" name="upload_file" value="Upload and Process">
                <a href="import_stats.php?step=1"><button type="button">Back</button></a>
            </form>
        </div>
        
    <?php elseif ($step == 3): ?>
        <!-- Step 3: Match Players -->
        <div class="info">
            <strong>Step 3 of 4:</strong> Match your players with the players in the SQLite database.
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="warning">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php
        $sqlite_players = isset($_SESSION['sqlite_players']) ? $_SESSION['sqlite_players'] : [];
        
        if (empty($sqlite_players)):
        ?>
            <div class="warning">
                <strong>No players found in SQLite database.</strong> The database might be empty or have a different structure.
            </div>
            <a href="import_stats.php?step=1"><button type="button">Start Over</button></a>
        <?php else: ?>
            <div class="section">
                <p><strong>Players in SQLite database:</strong> <?php echo count($sqlite_players); ?></p>
                
                <form method="POST">
                    <table>
                        <tr>
                            <th>Your Player</th>
                            <th>Match with SQLite Player</th>
                        </tr>
                        <?php 
                        // Get unique players involved in selected sets
                        $involved_players = [];
                        $selected_sets = isset($_SESSION['selected_sets']) ? $_SESSION['selected_sets'] : [];
                        
                        // Load all sets to find which players are involved
                        if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                            $header = fgetcsv($fp);
                            while (($row = fgetcsv($fp)) !== false) {
                                if (in_array($row[0], $selected_sets)) { // set id
                                    $involved_players[$row[2]] = true; // player1id
                                    $involved_players[$row[3]] = true; // player2id
                                }
                            }
                            fclose($fp);
                        }
                        
                        foreach ($involved_players as $player_id => $dummy):
                            if ($player_id == 0) continue;
                            
                            $player_name = getPlayerName($player_id);
                            $suggested_match = findBestMatch($player_name, $sqlite_players);
                        ?>
                        <tr>
                            <td><strong><?php echo $player_name; ?></strong></td>
                            <td>
                                <select name="player_mapping[<?php echo $player_id; ?>]" required>
                                    <option value="">-- Select Player --</option>
                                    <?php foreach ($sqlite_players as $sp): ?>
                                        <option value="<?php echo $sp['id']; ?>" 
                                                <?php echo ($sp['id'] == $suggested_match) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sp['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    
                    <input type="submit" name="match_players" value="Next: Import Data">
                    <a href="import_stats.php?step=2"><button type="button">Back</button></a>
                </form>
            </div>
            
            <script>
                // Check for duplicate selections
                document.querySelectorAll('select[name^="player_mapping"]').forEach(select => {
                    select.addEventListener('change', function() {
                        const selects = document.querySelectorAll('select[name^="player_mapping"]');
                        const values = Array.from(selects).map(s => s.value).filter(v => v !== '');
                        const duplicates = values.filter((v, i) => values.indexOf(v) !== i);
                        
                        selects.forEach(s => {
                            if (duplicates.includes(s.value) && s.value !== '') {
                                s.style.borderColor = 'red';
                                s.style.backgroundColor = '#ffe0e0';
                            } else {
                                s.style.borderColor = '';
                                s.style.backgroundColor = '';
                            }
                        });
                    });
                });
            </script>
        <?php endif; ?>
        
    <?php elseif ($step == 4): ?>
        <!-- Step 4: Preview/Process (Placeholder for now) -->
        <div class="info">
            <strong>Step 4 of 4:</strong> Review and import the data.
        </div>
        
        <div class="section">
            <p><strong>Player mapping completed!</strong></p>
            
            <?php if (isset($_SESSION['player_mapping'])): ?>
                <h3>Player Matches:</h3>
                <ul>
                    <?php 
                    foreach ($_SESSION['player_mapping'] as $our_id => $sqlite_id):
                        $sqlite_name = 'Unknown';
                        foreach ($_SESSION['sqlite_players'] as $sp) {
                            if ($sp['id'] == $sqlite_id) {
                                $sqlite_name = $sp['name'];
                                break;
                            }
                        }
                    ?>
                        <li><?php echo getPlayerName($our_id); ?> → <?php echo htmlspecialchars($sqlite_name); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <p><em>Data import processing will be implemented next.</em></p>
            
            <a href="import_stats.php?step=1"><button type="button">Start Over</button></a>
            <a href="index.php"><button type="button">Back to Home</button></a>
        </div>
        
    <?php endif; ?>
    
    <hr style="margin-top: 40px;">
    <p>
        <a href="index.php">Home</a> | 
        <a href="matchdays.php">Matches Overview</a>
        <?php if ($is_admin): ?>
            | <a href="players.php">Player Management</a>
            | <a href="matchday_setup.php">Tournament Setup</a>
            | <a href="index.php?logout=1">Logout</a>
        <?php endif; ?>
    </p>
</body>
</html>