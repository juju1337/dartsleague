<?php
// player_mgmt.php - Player Management Interface

$csv_file = 'tables/players.csv';

// Initialize CSV file if it doesn't exist
if (!file_exists($csv_file)) {
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname']);
    fclose($fp);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                addPlayer($_POST['name'], $_POST['nickname']);
                break;
            case 'edit':
                editPlayer($_POST['id'], $_POST['name'], $_POST['nickname']);
                break;
            case 'delete':
                deletePlayer($_POST['id']);
                break;
        }
        header('Location: player_mgmt.php');
        exit;
    }
}

// Get player for editing
$edit_player = null;
if (isset($_GET['edit'])) {
    $edit_player = getPlayerById($_GET['edit']);
}

// Load all players
$players = loadPlayers();

// Functions
function loadPlayers() {
    global $csv_file;
    $players = [];
    if (($fp = fopen($csv_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $players[] = [
                'id' => $row[0],
                'name' => $row[1],
                'nickname' => $row[2]
            ];
        }
        fclose($fp);
    }
    return $players;
}

function getPlayerById($id) {
    $players = loadPlayers();
    foreach ($players as $player) {
        if ($player['id'] == $id) {
            return $player;
        }
    }
    return null;
}

function getNextId() {
    $players = loadPlayers();
    $max_id = 0;
    foreach ($players as $player) {
        if ($player['id'] > $max_id) {
            $max_id = $player['id'];
        }
    }
    return $max_id + 1;
}

function addPlayer($name, $nickname) {
    global $csv_file;
    $id = getNextId();
    $fp = fopen($csv_file, 'a');
    fputcsv($fp, [$id, $name, $nickname]);
    fclose($fp);
}

function editPlayer($id, $name, $nickname) {
    global $csv_file;
    $players = loadPlayers();
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname']);
    foreach ($players as $player) {
        if ($player['id'] == $id) {
            fputcsv($fp, [$id, $name, $nickname]);
        } else {
            fputcsv($fp, [$player['id'], $player['name'], $player['nickname']]);
        }
    }
    fclose($fp);
}

function deletePlayer($id) {
    global $csv_file;
    $players = loadPlayers();
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname']);
    foreach ($players as $player) {
        if ($player['id'] != $id) {
            fputcsv($fp, [$player['id'], $player['name'], $player['nickname']]);
        }
    }
    fclose($fp);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Player Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        input[type="text"] { padding: 5px; margin: 5px 0; }
        input[type="submit"], button { padding: 5px 10px; margin: 5px 5px 5px 0; }
        .form-section { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>Player Management</h1>
    
    <div class="form-section">
        <h2><?php echo $edit_player ? 'Edit Player' : 'Add New Player'; ?></h2>
        <form method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_player ? 'edit' : 'add'; ?>">
            <?php if ($edit_player): ?>
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_player['id']); ?>">
            <?php endif; ?>
            
            <label>Name: <input type="text" name="name" required value="<?php echo $edit_player ? htmlspecialchars($edit_player['name']) : ''; ?>"></label><br>
            <label>Nickname: <input type="text" name="nickname" value="<?php echo $edit_player ? htmlspecialchars($edit_player['nickname']) : ''; ?>"></label><br>
            
            <input type="submit" value="<?php echo $edit_player ? 'Update Player' : 'Add Player'; ?>">
            <?php if ($edit_player): ?>
                <a href="players_mgmt.php"><button type="button">Cancel</button></a>
            <?php endif; ?>
        </form>
    </div>

    <h2>Players List</h2>
    <?php if (empty($players)): ?>
        <p>No players added yet.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Nickname</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($players as $player): ?>
                <tr>
                    <td><?php echo htmlspecialchars($player['id']); ?></td>
                    <td><?php echo htmlspecialchars($player['name']); ?></td>
                    <td><?php echo htmlspecialchars($player['nickname']); ?></td>
                    <td>
                        <a href="player_mgmt.php?edit=<?php echo $player['id']; ?>"><button>Edit</button></a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this player?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $player['id']; ?>">
                            <input type="submit" value="Delete">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>
</html>
