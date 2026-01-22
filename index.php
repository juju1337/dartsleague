<?php
// index.php - Tournament Overview and Entry Point

session_start();

$admin_password = 'darts2026'; // Change this to your desired password

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Load data
$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';

$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// Analyze tournament structure
$tournament_info = analyzeTournament($matchdays, $all_matches);

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

function analyzeTournament($matchdays, $matches) {
    $info = [
        'has_tournament' => !empty($matchdays),
        'num_matchdays' => count($matchdays),
        'formats' => []
    ];
    
    if (empty($matchdays)) {
        return $info;
    }
    
    // Analyze each matchday to determine format
    foreach ($matchdays as $md) {
        $md_matches = array_filter($matches, function($m) use ($md) {
            return $m['matchdayid'] == $md['id'];
        });
        
        $group_matches = array_filter($md_matches, function($m) { return $m['phase'] == 'group'; });
        $playoff_matches = array_filter($md_matches, function($m) { return $m['phase'] != 'group'; });
        
        $format = [
            'matchday_id' => $md['id'],
            'has_group' => !empty($group_matches),
            'has_playoffs' => !empty($playoff_matches),
            'group_format' => null,
            'playoff_format' => null,
            'playoff_types' => []
        ];
        
        if (!empty($group_matches)) {
            $first_match = array_values($group_matches)[0];
            $format['group_format'] = [
                'sets' => $first_match['firsttosets'],
                'legs' => $first_match['firsttolegs']
            ];
        }
        
        if (!empty($playoff_matches)) {
            $first_playoff = array_values($playoff_matches)[0];
            $format['playoff_format'] = [
                'sets' => $first_playoff['firsttosets'],
                'legs' => $first_playoff['firsttolegs']
            ];
            
            foreach ($playoff_matches as $pm) {
                $format['playoff_types'][] = $pm['phase'];
            }
        }
        
        $info['formats'][$md['id']] = $format;
    }
    
    return $info;
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tournament Overview</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h1>Tournament Overview</h1>
    
    <!-- Tournament Information -->
    <div class="section">
        <?php if (!$tournament_info['has_tournament']): ?>
            <div class="info">
                <strong>No tournament created yet.</strong><br>
                <?php if ($is_admin): ?>
                    Please use the <a href="matchday_setup.php">Tournament Setup</a> to create your tournament structure.
                <?php else: ?>
                    The tournament has not been set up yet. Please check back later.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h2>Tournament Information</h2>
            
            <p><strong>Total Matchdays:</strong> <?php echo $tournament_info['num_matchdays']; ?></p>
            <p><strong>Registered Players:</strong> <?php echo count($players); ?></p>
            
            <?php if (!empty($players)): ?>
                <h3>Players</h3>
                <ul>
                    <?php foreach ($players as $player): ?>
                        <li><?php echo getPlayerName($player['id']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <h3>Matchday Formats</h3>
            
            <?php 
            // Group formats by similarity
            $format_groups = [];
            foreach ($tournament_info['formats'] as $md_id => $format) {
                $key = serialize([
                    'group' => $format['group_format'],
                    'playoff' => $format['playoff_format'],
                    'types' => $format['playoff_types']
                ]);
                
                if (!isset($format_groups[$key])) {
                    $format_groups[$key] = [
                        'format' => $format,
                        'matchdays' => []
                    ];
                }
                $format_groups[$key]['matchdays'][] = $md_id;
            }
            ?>
            
            <?php foreach ($format_groups as $group): ?>
                <div class="format-box">
                    <strong>Matchdays: <?php echo implode(', ', $group['matchdays']); ?></strong><br>
                    
                    <?php if ($group['format']['has_group']): ?>
                        <strong>Group Phase:</strong> 
                        First to <?php echo $group['format']['group_format']['sets']; ?> sets 
                        (each set first to <?php echo $group['format']['group_format']['legs']; ?> legs)<br>
                    <?php endif; ?>
                    
                    <?php if ($group['format']['has_playoffs']): ?>
                        <strong>Playoffs:</strong> 
                        First to <?php echo $group['format']['playoff_format']['sets']; ?> sets 
                        (each set first to <?php echo $group['format']['playoff_format']['legs']; ?> legs)<br>
                        <strong>Playoff Structure:</strong> 
                        <?php 
                        $types = array_map(function($t) {
                            $labels = ['semi1' => 'Semi 1', 'semi2' => 'Semi 2', 'third' => '3rd Place', 'final' => 'Final'];
                            return isset($labels[$t]) ? $labels[$t] : $t;
                        }, $group['format']['playoff_types']);
                        echo implode(', ', $types);
                        ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <h3>Matchdays Schedule</h3>
            <table>
                <tr>
                    <th>Matchday</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Structure</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($matchdays as $md): ?>
                <?php $format = $tournament_info['formats'][$md['id']]; ?>
                <tr>
                    <td>Matchday <?php echo $md['id']; ?></td>
                    <td><?php echo $md['date'] ? $md['date'] : '<em>Not scheduled</em>'; ?></td>
                    <td><?php echo $md['location'] ? htmlspecialchars($md['location']) : '<em>TBD</em>'; ?></td>
                    <td>
                        <?php 
                        $parts = [];
                        if ($format['has_group']) $parts[] = 'Group';
                        if ($format['has_playoffs']) $parts[] = 'Playoffs';
                        echo implode(' + ', $parts);
                        ?>
                    </td>
                    <td>
                        <?php if ($is_admin): ?>
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button>View Details</button></a>
                        <?php else: ?>
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    
            <!-- OVERALL TOURNAMENT STANDINGS -->
        <?php
        // Check which matchdays are complete
        $completed_matchdays = [];
        
        foreach ($matchdays as $md) {
            $md_id = $md['id'];
            
            // Get all matches for this matchday
            $md_matches = array_filter($all_matches, function($m) use ($md_id) {
                return $m['matchdayid'] == $md_id;
            });
            
            // Check if matchday is complete
            $md_complete = true;
            foreach ($md_matches as $m) {
                // Skip unassigned matches
                if ($m['player1id'] == 0 || $m['player2id'] == 0) continue;
                
                // Check if match has results
                if ($m['sets1'] == 0 && $m['sets2'] == 0) {
                    $md_complete = false;
                    break;
                }
            }
            
            if ($md_complete && !empty($md_matches)) {
                $completed_matchdays[] = $md_id;
            }
        }
        
        // Only show if at least one matchday is complete
        if (!empty($completed_matchdays)):
        ?>
        
        <h3>Overall Tournament Standings</h3>
        <p><em>Based on <?php echo count($completed_matchdays); ?> completed matchday(s)</em></p>
        
        <?php
        // Calculate aggregate stats across all completed matchdays
        $overall_stats = [];
        
        // Initialize for all players
        foreach ($players as $player) {
            $overall_stats[$player['id']] = [
                'id' => $player['id'],
                'name' => getPlayerName($player['id']),
                'total_darts' => 0,
                'total_legs' => 0,
                'dbl_attempts' => 0,
                'dbl_hit' => 0,
                'points' => 0  // Placeholder for future calculation
            ];
        }
        
        // Process all matches from completed matchdays
        $sets_file = 'tables/sets.csv';
        
        foreach ($completed_matchdays as $md_id) {
            $md_matches = array_filter($all_matches, function($m) use ($md_id) {
                return $m['matchdayid'] == $md_id;
            });
            
            foreach ($md_matches as $match) {
                // Skip unassigned matches
                if ($match['player1id'] == 0 || $match['player2id'] == 0) continue;
                
                $p1id = $match['player1id'];
                $p2id = $match['player2id'];
                
                // Get detailed stats from sets.csv
                if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                    $header = fgetcsv($fp);
                    while (($row = fgetcsv($fp)) !== false) {
                        if ($row[1] == $match['id']) { // matchid
                            $legs1 = intval($row[4]);
                            $legs2 = intval($row[5]);
                            $total_legs_in_set = $legs1 + $legs2;
                            
                            // Weighted 3DA calculation
                            $da1 = floatval($row[8]);
                            $da2 = floatval($row[9]);
                            
                            if ($da1 > 0) {
                                $overall_stats[$p1id]['total_darts'] += $da1 * $total_legs_in_set;
                                $overall_stats[$p1id]['total_legs'] += $total_legs_in_set;
                            }
                            if ($da2 > 0) {
                                $overall_stats[$p2id]['total_darts'] += $da2 * $total_legs_in_set;
                                $overall_stats[$p2id]['total_legs'] += $total_legs_in_set;
                            }
                            
                            // Double attempts and hits
                            $overall_stats[$p1id]['dbl_attempts'] += intval($row[10]);
                            $overall_stats[$p2id]['dbl_attempts'] += intval($row[11]);
                            $overall_stats[$p1id]['dbl_hit'] += $legs1;
                            $overall_stats[$p2id]['dbl_hit'] += $legs2;
                        }
                    }
                    fclose($fp);
                }
            }
        }
        
        // Load scoring scheme
        $scoring_file = 'tables/scoringscheme.csv';
        $scoring_scheme = [];
        if (file_exists($scoring_file) && ($fp = fopen($scoring_file, 'r')) !== false) {
            $header = fgetcsv($fp);
            while (($row = fgetcsv($fp)) !== false) {
                $scoring_scheme[$row[0]][$row[1]] = intval($row[2]);
            }
            fclose($fp);
        }
        
        // Calculate points for each completed matchday
        foreach ($completed_matchdays as $md_id) {
            // Get all matches for this matchday
            $md_matches = array_filter($all_matches, function($m) use ($md_id) {
                return $m['matchdayid'] == $md_id;
            });
            
            $md_group_matches = array_filter($md_matches, function($m) {
                return $m['phase'] == 'group';
            });
            
            $md_playoff_matches = array_filter($md_matches, function($m) {
                return $m['phase'] != 'group';
            });
            
            // Calculate group phase standings for this matchday
            $md_group_standings = [];
            foreach ($players as $player) {
                $md_group_standings[$player['id']] = [
                    'id' => $player['id'],
                    'points' => 0,
                    'legs_for' => 0,
                    'legs_against' => 0
                ];
            }
            
            foreach ($md_group_matches as $match) {
                if ($match['player1id'] == 0 || $match['player2id'] == 0) continue;
                
                $p1id = $match['player1id'];
                $p2id = $match['player2id'];
                $sets1 = intval($match['sets1']);
                $sets2 = intval($match['sets2']);
                
                if ($sets1 > 0 || $sets2 > 0) {
                    // Award match points
                    if ($sets1 > $sets2) {
                        $md_group_standings[$p1id]['points'] += 2;
                    } elseif ($sets2 > $sets1) {
                        $md_group_standings[$p2id]['points'] += 2;
                    }
                    
                    // Get legs from sets
                    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                        $header = fgetcsv($fp);
                        while (($row = fgetcsv($fp)) !== false) {
                            if ($row[1] == $match['id']) {
                                $md_group_standings[$p1id]['legs_for'] += intval($row[4]);
                                $md_group_standings[$p1id]['legs_against'] += intval($row[5]);
                                $md_group_standings[$p2id]['legs_for'] += intval($row[5]);
                                $md_group_standings[$p2id]['legs_against'] += intval($row[4]);
                            }
                        }
                        fclose($fp);
                    }
                }
            }
            
            // Sort group standings
            usort($md_group_standings, function($a, $b) {
                if ($b['points'] != $a['points']) {
                    return $b['points'] - $a['points'];
                }
                $diff_a = $a['legs_for'] - $a['legs_against'];
                $diff_b = $b['legs_for'] - $b['legs_against'];
                if ($diff_b != $diff_a) {
                    return $diff_b - $diff_a;
                }
                return $b['legs_for'] - $a['legs_for'];
            });
            
            // Award group phase position points
            for ($i = 0; $i < count($md_group_standings); $i++) {
                $player_id = $md_group_standings[$i]['id'];
                $rank = $i + 1;
                if (isset($scoring_scheme['pos_group_phase'][$rank])) {
                    $overall_stats[$player_id]['points'] += $scoring_scheme['pos_group_phase'][$rank];
                }
            }
            
            // Award final position points
            foreach ($md_playoff_matches as $pm) {
                if ($pm['sets1'] > 0 || $pm['sets2'] > 0) {
                    $winner_id = ($pm['sets1'] > $pm['sets2']) ? $pm['player1id'] : $pm['player2id'];
                    $loser_id = ($pm['sets1'] > $pm['sets2']) ? $pm['player2id'] : $pm['player1id'];
                    
                    if ($pm['phase'] == 'final') {
                        if (isset($scoring_scheme['pos_final']['1'])) {
                            $overall_stats[$winner_id]['points'] += $scoring_scheme['pos_final']['1'];
                        }
                        if (isset($scoring_scheme['pos_final']['2'])) {
                            $overall_stats[$loser_id]['points'] += $scoring_scheme['pos_final']['2'];
                        }
                    } elseif ($pm['phase'] == 'third') {
                        if (isset($scoring_scheme['pos_final']['3'])) {
                            $overall_stats[$winner_id]['points'] += $scoring_scheme['pos_final']['3'];
                        }
                        if (isset($scoring_scheme['pos_final']['4'])) {
                            $overall_stats[$loser_id]['points'] += $scoring_scheme['pos_final']['4'];
                        }
                    }
                }
            }
            
            // Calculate best stats for this matchday
            $md_stats = [];
            foreach ($players as $player) {
                $md_stats[$player['id']] = [
                    'total_darts' => 0,
                    'total_legs' => 0,
                    'dbl_attempts' => 0,
                    'dbl_hit' => 0,
                    'highscore' => 0,
                    'highco' => 0
                ];
            }
            
            foreach ($md_matches as $match) {
                if ($match['player1id'] == 0 || $match['player2id'] == 0) continue;
                
                if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                    $header = fgetcsv($fp);
                    while (($row = fgetcsv($fp)) !== false) {
                        if ($row[1] == $match['id']) {
                            $p1id = $match['player1id'];
                            $p2id = $match['player2id'];
                            
                            $legs1 = intval($row[4]);
                            $legs2 = intval($row[5]);
                            $total_legs = $legs1 + $legs2;
                            
                            $da1 = floatval($row[8]);
                            $da2 = floatval($row[9]);
                            
                            if ($da1 > 0) {
                                $md_stats[$p1id]['total_darts'] += $da1 * $total_legs;
                                $md_stats[$p1id]['total_legs'] += $total_legs;
                            }
                            if ($da2 > 0) {
                                $md_stats[$p2id]['total_darts'] += $da2 * $total_legs;
                                $md_stats[$p2id]['total_legs'] += $total_legs;
                            }
                            
                            $md_stats[$p1id]['dbl_attempts'] += intval($row[10]);
                            $md_stats[$p2id]['dbl_attempts'] += intval($row[11]);
                            $md_stats[$p1id]['dbl_hit'] += $legs1;
                            $md_stats[$p2id]['dbl_hit'] += $legs2;
                            
                            if (intval($row[12]) > $md_stats[$p1id]['highscore']) {
                                $md_stats[$p1id]['highscore'] = intval($row[12]);
                            }
                            if (intval($row[13]) > $md_stats[$p2id]['highscore']) {
                                $md_stats[$p2id]['highscore'] = intval($row[13]);
                            }
                            if (intval($row[14]) > $md_stats[$p1id]['highco']) {
                                $md_stats[$p1id]['highco'] = intval($row[14]);
                            }
                            if (intval($row[15]) > $md_stats[$p2id]['highco']) {
                                $md_stats[$p2id]['highco'] = intval($row[15]);
                            }
                        }
                    }
                    fclose($fp);
                }
            }
            
            // Award best stat bonuses
            // Best 3DA
            $best_3da = 0;
            $best_3da_players = [];
            foreach ($md_stats as $pid => $stats) {
                if ($stats['total_legs'] > 0) {
                    $avg = $stats['total_darts'] / $stats['total_legs'];
                    if ($avg > $best_3da) {
                        $best_3da = $avg;
                        $best_3da_players = [$pid];
                    } elseif ($avg == $best_3da) {
                        $best_3da_players[] = $pid;
                    }
                }
            }
            foreach ($best_3da_players as $pid) {
                if (isset($scoring_scheme['best_3da']['1'])) {
                    $overall_stats[$pid]['points'] += $scoring_scheme['best_3da']['1'];
                }
            }
            
            // Best Double %
            $best_dbl = 0;
            $best_dbl_players = [];
            foreach ($md_stats as $pid => $stats) {
                if ($stats['dbl_attempts'] > 0) {
                    $pct = ($stats['dbl_hit'] / $stats['dbl_attempts']) * 100;
                    if ($pct > $best_dbl) {
                        $best_dbl = $pct;
                        $best_dbl_players = [$pid];
                    } elseif ($pct == $best_dbl) {
                        $best_dbl_players[] = $pid;
                    }
                }
            }
            foreach ($best_dbl_players as $pid) {
                if (isset($scoring_scheme['best_dbl']['1'])) {
                    $overall_stats[$pid]['points'] += $scoring_scheme['best_dbl']['1'];
                }
            }
            
            // Best High Score
            $best_hs = 0;
            $best_hs_players = [];
            foreach ($md_stats as $pid => $stats) {
                if ($stats['highscore'] > $best_hs) {
                    $best_hs = $stats['highscore'];
                    $best_hs_players = [$pid];
                } elseif ($stats['highscore'] == $best_hs && $stats['highscore'] > 0) {
                    $best_hs_players[] = $pid;
                }
            }
            foreach ($best_hs_players as $pid) {
                if (isset($scoring_scheme['best_hs']['1'])) {
                    $overall_stats[$pid]['points'] += $scoring_scheme['best_hs']['1'];
                }
            }
            
            // Best High Checkout
            $best_hco = 0;
            $best_hco_players = [];
            foreach ($md_stats as $pid => $stats) {
                if ($stats['highco'] > $best_hco) {
                    $best_hco = $stats['highco'];
                    $best_hco_players = [$pid];
                } elseif ($stats['highco'] == $best_hco && $stats['highco'] > 0) {
                    $best_hco_players[] = $pid;
                }
            }
            foreach ($best_hco_players as $pid) {
                if (isset($scoring_scheme['best_hco']['1'])) {
                    $overall_stats[$pid]['points'] += $scoring_scheme['best_hco']['1'];
                }
            }
        }
        
        // Sort by points (currently 0 for all, will be updated later)
        usort($overall_stats, function($a, $b) {
            if ($b['points'] != $a['points']) {
                return $b['points'] - $a['points'];
            }
            // Secondary sort by 3DA
            $a_3da = ($a['total_legs'] > 0) ? $a['total_darts'] / $a['total_legs'] : 0;
            $b_3da = ($b['total_legs'] > 0) ? $b['total_darts'] / $b['total_legs'] : 0;
            return $b_3da - $a_3da;
        });
        ?>
        
        <table>
            <tr>
                <th>Pos</th>
                <th>Player</th>
                <th>3DA</th>
                <th>Dbl%</th>
                <th>Points</th>
            </tr>
            <?php 
            $pos = 1;
            foreach ($overall_stats as $s): 
                $three_da = ($s['total_legs'] > 0) ? round($s['total_darts'] / $s['total_legs'], 2) : '-';
                $dbl_pct = ($s['dbl_attempts'] > 0) ? round(($s['dbl_hit'] / $s['dbl_attempts']) * 100, 1) . '%' : '-';
            ?>
            <tr>
                <td><?php echo $pos++; ?></td>
                <td><?php echo $s['name']; ?></td>
                <td><?php echo $three_da; ?></td>
                <td><?php echo $dbl_pct; ?></td>
                <td><?php echo $s['points']; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <?php endif; ?>
    </div>
    
    <?php if ($is_admin): ?>
        <!-- Admin Panel -->
        <div class="admin-panel">
            <h3>Management Area</h3>
            <a href="players.php"><button>Player Management</button></a>
            <a href="matchday_setup.php"><button>Tournament Setup</button></a>
            <a href="matchdays.php"><button>Matchday Management</button></a>
            <a href="index.php?logout=1"><button>Logout</button></a>
        </div>
    <?php else: ?>
        <!-- Login Form -->
        <div class="login-box" id="login">
            <h3>Management Login</h3>
            <?php if (isset($login_error)): ?>
                <div class="warning"><?php echo $login_error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <label>Password:</label><br>
                <input type="password" name="password" required><br>
                <input type="submit" name="login" value="Login">
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
</body>
</html>
