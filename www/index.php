<?php
// ================= MULTI-SERVER CONFIG =================
// Load server configurations from environment variables
function loadServerConfigs() {
    $configs = [];
    
    $host = getenv('RCON_HOST') ?: '192.168.1.249';
    $password = getenv('RCON_PASSWORD') ?: '123456';
    $portsString = getenv('RCON_PORTS') ?: '48888';
    
    // Parse comma-separated ports
    $ports = array_map('trim', explode(',', $portsString));
    
    foreach ($ports as $index => $port) {
        $serverId = $index + 1;
        $configs[] = [
            'id' => $serverId,
            'name' => "Server {$serverId}",
            'host' => $host,
            'port' => (int)$port,
            'password' => $password
        ];
    }
    
    return $configs;
}

$SERVER_CONFIGS = loadServerConfigs();

// Get current server based on request
$currentServerId = isset($_GET['server']) ? (int)$_GET['server'] : 1;
$currentServerId = max(1, min($currentServerId, count($SERVER_CONFIGS)));
$currentServer = $SERVER_CONFIGS[$currentServerId - 1];

define('RCON_HOST', $currentServer['host']);
define('RCON_PORT', $currentServer['port']);
define('ADMIN_PASSWORD', $currentServer['password']);
define('MAP_INDEX_FILE', __DIR__ . '/mapindex.json');
// ==========================================

// BFBC2 RCON Protocol Implementation
class BFBC2Rcon {
    private $host;
    private $port;
    private $password;
    private $sequence = 0;
    private $socket = null;
    private $buffer = '';

    public function __construct($host, $port, $password = null) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
    }

    private function encodeInt32($value) {
        return pack('V', $value);
    }

    private function decodeInt32($data) {
        return unpack('V', substr($data, 0, 4))[1];
    }

    private function encodeHeader($isFromServer, $isResponse, $sequence) {
        $header = $sequence & 0x3fffffff;
        if ($isFromServer) $header |= 0x80000000;
        if ($isResponse) $header |= 0x40000000;
        return pack('V', $header);
    }

    private function decodeHeader($data) {
        $header = unpack('V', substr($data, 0, 4))[1];
        return [
            (bool)($header & 0x80000000),
            (bool)($header & 0x40000000),
            $header & 0x3fffffff
        ];
    }

    private function encodeWords($words) {
        $encoded = '';
        $size = 0;

        foreach ($words as $word) {
            $encoded .= $this->encodeInt32(strlen($word));
            $encoded .= $word;
            $encoded .= "\x00";
            $size += strlen($word) + 5;
        }

        return [$size, $encoded];
    }

    private function decodeWords($size, $data) {
        $words = [];
        $offset = 0;

        while ($offset < $size) {
            $wordLen = $this->decodeInt32(substr($data, $offset, 4));
            $word = substr($data, $offset + 4, $wordLen);
            $words[] = $word;
            $offset += $wordLen + 5;
        }

        return $words;
    }

    private function encodePacket($sequence, $words) {
        $header = $this->encodeHeader(false, false, $sequence);
        list($wordsSize, $encodedWords) = $this->encodeWords($words);
        $totalSize = $wordsSize + 12;
        return $header . $this->encodeInt32($totalSize) . $this->encodeInt32(count($words)) . $encodedWords;
    }

    private function decodePacket($data) {
        list($isFromServer, $isResponse, $sequence) = $this->decodeHeader($data);
        $wordsSize = $this->decodeInt32(substr($data, 4, 4)) - 12;
        $words = $this->decodeWords($wordsSize, substr($data, 12));
        return [$isFromServer, $isResponse, $sequence, $words];
    }

    public function connect() {
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            throw new Exception("Socket creation failed");
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 5, 'usec' => 0));

        if (!@socket_connect($this->socket, $this->host, $this->port)) {
            throw new Exception("Connection failed");
        }

        if ($this->password) {
            $this->login();
        }
    }

    private function login() {
        $this->send(['login.hashed']);
        $words = $this->receive();

        if ($words[0] !== 'OK') {
            throw new Exception("Failed to retrieve salt");
        }

        $salt = hex2bin($words[1]);
        $pwHash = strtoupper(md5($salt . $this->password));

        $this->send(['login.hashed', $pwHash]);
        $words = $this->receive();

        if ($words[0] !== 'OK') {
            throw new Exception("Login failed");
        }
    }

    private function send($words) {
        $packet = $this->encodePacket($this->sequence, $words);
        $this->sequence = ($this->sequence + 1) & 0x3fffffff;
        socket_write($this->socket, $packet, strlen($packet));
    }

    private function receive() {
        while (strlen($this->buffer) < 8) {
            $data = @socket_read($this->socket, 4096);
            if ($data === false) {
                throw new Exception("Socket read failed");
            }
            $this->buffer .= $data;
        }

        $packetSize = $this->decodeInt32(substr($this->buffer, 4, 4));

        while (strlen($this->buffer) < $packetSize) {
            $data = @socket_read($this->socket, 4096);
            if ($data === false) {
                throw new Exception("Socket read failed");
            }
            $this->buffer .= $data;
        }

        $packet = substr($this->buffer, 0, $packetSize);
        $this->buffer = substr($this->buffer, $packetSize);

        list(, , , $words) = $this->decodePacket($packet);
        return $words;
    }

    public function command($words) {
        $this->send($words);
        $response = $this->receive();

        if ($response[0] !== 'OK') {
            throw new Exception("Server error: " . implode(" ", $response));
        }

        return array_slice($response, 1);
    }

    public function close() {
        if ($this->socket) {
            socket_close($this->socket);
        }
    }
}

// API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['api']) && $_GET['api'])) {
    header('Content-Type: application/json');
    
    try {
        if (isset($_GET['api']) && $_GET['api'] === 'serverinfo') {
            $mapData = [];
            $mapNames = [];
            $mapImages = [];
            
            if (file_exists(MAP_INDEX_FILE)) {
                $mapData = json_decode(file_get_contents(MAP_INDEX_FILE), true);
                if (isset($mapData['mapNames'])) {
                    $mapNames = array_change_key_case($mapData['mapNames'], CASE_LOWER);
                }
                if (isset($mapData['mapImages'])) {
                    $mapImages = array_change_key_case($mapData['mapImages'], CASE_LOWER);
                }
            }
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();

            $base = $rcon->command(['serverInfo']);
            $idx = 1;
            $currentPlayers = (int)$base[$idx++];
            $maxPlayers = (int)$base[$idx++];
            $currentGameMode = $base[$idx++];
            $currentMapRaw = $base[$idx++];
            $roundsPlayed = (int)$base[$idx++];
            $roundsTotal = (int)$base[$idx++];
            $scoreCount = (int)$base[$idx++];
            $idx += $scoreCount + 1;
            $onlineState = $base[$idx++];
            $idx += 8;
            $region = $base[$idx];

            $serverName = $rcon->command(['vars.serverName'])[0];
            $serverDescription = $rcon->command(['vars.serverDescription'])[0];
            $gamePassword = $rcon->command(['vars.gamePassword'])[0];
            $ranked = $rcon->command(['vars.ranked'])[0] === 'true';
            $punkBuster = $rcon->command(['vars.punkBuster'])[0] === 'true';
            $hardcore = $rcon->command(['vars.hardCore'])[0] === 'true';
            $teambalance = $rcon->command(['vars.teamBalance'])[0] === 'true';
            $friendlyFire = $rcon->command(['vars.friendlyFire'])[0] === 'true';
            $killcam = $rcon->command(['vars.killCam'])[0] === 'true';
            $minimap = $rcon->command(['vars.miniMap'])[0] === 'true';
            $crosshair = $rcon->command(['vars.crossHair'])[0] === 'true';
            $spotting3d = $rcon->command(['vars.3dSpotting'])[0] === 'true';
            $minimapSpot = $rcon->command(['vars.miniMapSpotting'])[0] === 'true';
            $vehicleCam = $rcon->command(['vars.thirdPersonVehicleCameras'])[0] === 'true';
            $profanityFilter = $rcon->command(['vars.profanityFilter'])[0] === 'true';
            $teamKillCountForKick = (int)$rcon->command(['vars.teamKillCountForKick'])[0];
            $teamKillValueForKick = (int)$rcon->command(['vars.teamKillValueForKick'])[0];
            $teamKillValueIncrease = (int)$rcon->command(['vars.teamKillValueIncrease'])[0];
            $teamKillValueDecreasePerSecond = (int)$rcon->command(['vars.teamKillValueDecreasePerSecond'])[0];
            $idleTimeout = (int)$rcon->command(['vars.idleTimeout'])[0];

            $currentLevelRaw = $rcon->command(['admin.currentLevel'])[0];
            $playlist = $rcon->command(['admin.getPlaylist'])[0];

            $mapKey = strtolower($currentLevelRaw);
            $mapName = isset($mapNames[$mapKey]) ? $mapNames[$mapKey] : $currentLevelRaw;
            
            // Game mode name mapping
            $gameModeNames = [
                'CONQUEST' => 'Conquest',
                'RUSH' => 'Rush',
                'SQDM' => 'Squad Deathmatch',
                'SQRUSH' => 'Squad Rush'
            ];
            $gameModeName = isset($gameModeNames[$currentGameMode]) ? $gameModeNames[$currentGameMode] : $currentGameMode;

            $currentMapImage = isset($mapImages[$mapKey]) 
                ? "/static/BFBC2/Maps/AlphaPack/" . $mapImages[$mapKey]
                : null;

            $mapList = $rcon->command(['mapList.list']);
            $nextMapDisplay = '-';

            if (!empty($mapList)) {
                $normalized = array_map('strtolower', $mapList);
                $idxMap = array_search($mapKey, $normalized);
                
                if ($idxMap !== false) {
                    $nextIdx = ($idxMap + 1) % count($mapList);
                    $nextRaw = $mapList[$nextIdx];
                } else {
                    $nextRaw = $mapList[0];
                }

                $nextMapDisplay = isset($mapNames[strtolower($nextRaw)]) 
                    ? $mapNames[strtolower($nextRaw)] 
                    : $nextRaw;
            }

            $rcon->close();

            echo json_encode([
                'serverName' => $serverName,
                'serverDescription' => $serverDescription,
                'gamePassword' => $gamePassword,
                'currentPlayers' => $currentPlayers,
                'maxPlayers' => $maxPlayers,
                'currentMap' => $mapName,
                'gameMode' => $gameModeName,
                'roundsPlayed' => $roundsPlayed,
                'roundsTotal' => $roundsTotal,
                'nextMap' => $nextMapDisplay,
                'currentMapImage' => $currentMapImage,
                'onlineState' => $onlineState,
                'ranked' => $ranked,
                'punkBuster' => $punkBuster,
                'region' => $region,
                'hardcore' => $hardcore,
                'teambalance' => $teambalance,
                'friendlyFire' => $friendlyFire,
                'killcam' => $killcam,
                'minimap' => $minimap,
                'crosshair' => $crosshair,
                'spotting3d' => $spotting3d,
                'minimapSpotting' => $minimapSpot,
                'vehicleCam' => $vehicleCam,
                'profanityFilter' => $profanityFilter,
                'teamKillCountForKick' => $teamKillCountForKick,
                'teamKillValueForKick' => $teamKillValueForKick,
                'teamKillValueIncrease' => $teamKillValueIncrease,
                'teamKillValueDecreasePerSecond' => $teamKillValueDecreasePerSecond,
                'idleTimeout' => $idleTimeout
            ]);
            exit;
            
        } elseif (isset($_POST['command'])) {
            $cmdString = $_POST['command'];
            $words = explode(' ', $cmdString);

            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $output = $rcon->command($words);
            $rcon->close();

            echo json_encode([
                'success' => true,
                'output' => $output
            ]);
            exit;
            
        } elseif (isset($_POST['toggle'])) {
            $setting = $_POST['toggle'];
            $newValue = $_POST['value'];
            
            $commandMap = [
                'punkBuster' => 'vars.punkBuster',
                'hardcore' => 'vars.hardCore',
                'teambalance' => 'vars.teamBalance',
                'friendlyFire' => 'vars.friendlyFire',
                'killcam' => 'vars.killCam',
                'minimap' => 'vars.miniMap',
                'crosshair' => 'vars.crossHair',
                'spotting3d' => 'vars.3dSpotting',
                'minimapSpotting' => 'vars.miniMapSpotting',
                'vehicleCam' => 'vars.thirdPersonVehicleCameras',
                'profanityFilter' => 'vars.profanityFilter'
            ];
            
            if (!isset($commandMap[$setting])) {
                throw new Exception("Unknown setting: $setting");
            }
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command([$commandMap[$setting], $newValue]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'setting' => $setting,
                'value' => $newValue
            ]);
            exit;
            
        } elseif (isset($_POST['updateNumericSetting'])) {
            $setting = $_POST['setting'];
            $command = $_POST['command'];
            $newValue = (int)$_POST['newValue'];
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command([$command, (string)$newValue]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'setting' => $setting,
                'value' => $newValue
            ]);
            exit;
            
        } elseif (isset($_POST['updateServerName'])) {
            $newName = $_POST['newName'];
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command(['vars.serverName', $newName]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'newName' => $newName
            ]);
            exit;
            
        } elseif (isset($_POST['updateServerDescription'])) {
            $newDescription = $_POST['newDescription'];
            
            // Replace newlines with | as per BFBC2 spec
            $newDescription = str_replace(["\r\n", "\n", "\r"], '|', $newDescription);
            
            if (strlen($newDescription) > 400) {
                throw new Exception("Description must be less than 400 characters");
            }
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command(['vars.serverDescription', $newDescription]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'newDescription' => $newDescription
            ]);
            exit;
            
        } elseif (isset($_POST['updateGamePassword'])) {
            $newPassword = $_POST['newPassword'];
            
            // Validate password (0-16 chars, alphanumeric only)
            if (strlen($newPassword) > 16) {
                throw new Exception("Password must be 16 characters or less");
            }
            
            if ($newPassword !== '' && !preg_match('/^[a-zA-Z0-9]+$/', $newPassword)) {
                throw new Exception("Password can only contain letters and numbers");
            }
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command(['vars.gamePassword', $newPassword]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'newPassword' => $newPassword
            ]);
            exit;
            
        } elseif (isset($_POST['updateMaxPlayers'])) {
            $newMax = (int)$_POST['newMax'];
            
            if ($newMax < 8 || $newMax > 32) {
                throw new Exception("Max players must be between 8 and 32");
            }
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command(['vars.playerLimit', (string)$newMax]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'newMax' => $newMax
            ]);
            exit;
            
        } elseif (isset($_FILES['banner'])) {
            // Handle banner upload
            $uploadDir = __DIR__ . '/banners/';
            
            // Create banners directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $bannerPath = $uploadDir . 'banner.png';
            
            // Validate file
            $fileInfo = getimagesize($_FILES['banner']['tmp_name']);
            if ($fileInfo === false) {
                throw new Exception("File is not a valid image");
            }
            
            // Check if it's PNG
            if ($fileInfo['mime'] !== 'image/png') {
                throw new Exception("Banner must be a PNG file");
            }
            
            // Check dimensions (512x64 as per BFBC2 spec)
            if ($fileInfo[0] !== 512 || $fileInfo[1] !== 64) {
                throw new Exception("Banner must be exactly 512x64 pixels");
            }
            
            // Check file size (max 127kb as per BFBC2 spec)
            if ($_FILES['banner']['size'] > 127 * 1024) {
                throw new Exception("Banner must be smaller than 127KB");
            }
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['banner']['tmp_name'], $bannerPath)) {
                throw new Exception("Failed to save banner file");
            }
            
            // Use the RCON host IP for the banner URL so it's accessible externally
            $bannerUrl = "http://" . RCON_HOST . ":5010/banners/banner.png";
            
            // Update RCON banner URL
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $rcon->command(['vars.bannerUrl', $bannerUrl]);
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'bannerUrl' => $bannerUrl
            ]);
            exit;
            
        } elseif (isset($_GET['api']) && $_GET['api'] === 'bannerinfo') {
            // Get current banner info
            $bannerExists = file_exists(__DIR__ . '/banners/banner.png');
            
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            $currentUrl = $rcon->command(['vars.bannerUrl'])[0];
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'bannerExists' => $bannerExists,
                'currentUrl' => $currentUrl
            ]);
            exit;
            
        } elseif (isset($_GET['api']) && $_GET['api'] === 'playerlist') {
            $rcon = new BFBC2Rcon(RCON_HOST, RCON_PORT, ADMIN_PASSWORD);
            $rcon->connect();
            
            $response = $rcon->command(['admin.listPlayers', 'all']);
            
            // Parse the player info block format
            // First value is number of parameters
            $numParams = (int)array_shift($response);
            
            // Next N values are parameter names
            $paramNames = [];
            for ($i = 0; $i < $numParams; $i++) {
                $paramNames[] = array_shift($response);
            }
            
            // Next value is number of players
            $numPlayers = (int)array_shift($response);
            
            // Parse player data
            $players = [];
            for ($i = 0; $i < $numPlayers; $i++) {
                $player = [];
                for ($j = 0; $j < $numParams; $j++) {
                    $player[$paramNames[$j]] = array_shift($response);
                }
                $players[] = $player;
            }
            
            $rcon->close();
            
            echo json_encode([
                'success' => true,
                'players' => $players
            ]);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>BFBC2 Server Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #1e1e1e;
            color: #ffffff;
            margin: 40px;
        }
        h1 { margin-bottom: 20px; }
        
        .server-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 30px;
            border-bottom: 2px solid #444;
            padding-bottom: 5px;
        }
        .server-tab {
            background: #333;
            border: 1px solid #555;
            border-bottom: none;
            color: #aaa;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 5px 5px 0 0;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .server-tab:hover {
            background: #3a3a3a;
            color: #fff;
        }
        .server-tab.active {
            background: #4a90e2;
            color: #fff;
            border-color: #5aa0f2;
            font-weight: bold;
        }
        
        .banner-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #222;
            border: 1px solid #444;
            border-radius: 5px;
        }
        .banner-display {
            width: 256px;
            height: 32px;
            background: #111;
            border: 1px solid #555;
            margin: 10px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 12px;
        }
        .banner-display img {
            max-width: 100%;
            max-height: 100%;
        }
        .banner-upload {
            margin-top: 10px;
        }
        .banner-info {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
        .upload-btn {
            background: #4a90e2;
            border: 1px solid #5aa0f2;
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .upload-btn:hover {
            background: #5aa0f2;
        }
        
        .top-section {
            display: flex;
            gap: 40px;
            align-items: flex-start;
        }
        .grid {
            display: grid;
            grid-template-columns: 260px 1fr auto;
            gap: 10px 20px;
            align-items: center;
        }
        .label {
            font-weight: bold;
            text-align: right;
        }
        .value { 
            text-align: left;
        }
        .status {
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        .true { color: #00cc44; }
        .false { color: #cc0000; }
        
        .toggle-btn {
            background: #333;
            border: 1px solid #555;
            color: #aaa;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
            user-select: none;
        }
        .toggle-btn:hover {
            background: #444;
            border-color: #666;
            color: #fff;
            transform: scale(1.05);
        }
        .toggle-btn:active {
            transform: scale(0.95);
        }
        .toggle-btn.loading {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .editable-field {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .edit-btn {
            background: #333;
            border: 1px solid #555;
            color: #aaa;
            padding: 2px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        .edit-btn:hover {
            background: #444;
            border-color: #666;
            color: #fff;
        }
        .edit-input {
            background: #222;
            border: 1px solid #555;
            color: #fff;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 14px;
        }
        .save-btn {
            background: #00cc44;
            border: 1px solid #00dd55;
            color: #000;
            padding: 4px 12px;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
        }
        .cancel-btn {
            background: #cc0000;
            border: 1px solid #dd0011;
            color: #fff;
            padding: 4px 12px;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .tooltip {
            cursor: help;
            color: #aaa;
            font-size: 12px;
        }
        hr {
            grid-column: span 3;
            border: 1px solid #444;
            margin: 15px 0;
        }
        .map-image-box {
            width: 450px;
            height: 450px;
            background-color: #111;
            border: 1px solid #444;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .map-image-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        #consoleOutput {
            background-color: #111;
            border: 1px solid #444;
            padding: 10px;
            height: 250px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-family: monospace;
        }
        input { padding: 6px; font-size: 14px; }
        button { padding: 6px 12px; font-size: 14px; cursor: pointer; }
        
        .player-table-container {
            margin-top: 40px;
        }
        .player-table {
            width: 100%;
            border-collapse: collapse;
            background: #222;
            border: 1px solid #444;
        }
        .player-table th {
            background: #333;
            color: #fff;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #555;
            font-weight: bold;
        }
        .player-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #333;
        }
        .player-table tr:hover {
            background: #2a2a2a;
        }
        .player-table tbody tr:last-child td {
            border-bottom: none;
        }
        .team-1 { color: #4a90e2; } /* Blue team */
        .team-2 { color: #e24a4a; } /* Red team */
        .team-0 { color: #888; } /* Neutral */
        .empty-message {
            text-align: center;
            padding: 20px;
            color: #888;
            font-style: italic;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .action-btn {
            background: #333;
            border: 1px solid #555;
            color: #fff;
            padding: 4px 8px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .action-btn:hover {
            background: #444;
            transform: scale(1.05);
        }
        .action-btn.kick { border-color: #e2a84a; color: #e2a84a; }
        .action-btn.kick:hover { background: #3d2f1a; }
        .action-btn.ban { border-color: #e24a4a; color: #e24a4a; }
        .action-btn.ban:hover { background: #3d1a1a; }
        .action-btn.team { border-color: #4a90e2; color: #4a90e2; }
        .action-btn.team:hover { background: #1a2a3d; }
        .action-btn.squad { border-color: #8a4ae2; color: #8a4ae2; }
        .action-btn.squad:hover { background: #2a1a3d; }
    </style>
</head>
<body>

<h1>BFBC2 Server Dashboard</h1>

<?php if (count($SERVER_CONFIGS) > 1): ?>
<div class="server-tabs">
    <?php foreach ($SERVER_CONFIGS as $config): ?>
        <a href="?server=<?php echo $config['id']; ?>" 
           class="server-tab <?php echo $config['id'] === $currentServerId ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($config['name']); ?>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="top-section">
    <div class="grid">
        <div class="label">Server Name:</div>
        <div class="value editable-field">
            <span id="serverName" style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block;">-</span>
        </div>
        <button class="edit-btn" onclick="editServerName()">Edit</button>

        <div class="label">Server Description:</div>
        <div class="value editable-field">
            <span id="serverDescription" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block;">-</span>
        </div>
        <button class="edit-btn" onclick="editServerDescription()">Edit</button>

        <div class="label">Game Password:</div>
        <div class="value editable-field">
            <span id="gamePassword">-</span>
        </div>
        <button class="edit-btn" onclick="editGamePassword()">Edit</button>

        <div class="label">Players:</div>
        <div class="value editable-field">
            <span id="currentPlayers">0</span>/<span id="maxPlayers">32</span>
        </div>
        <button class="edit-btn" onclick="editMaxPlayers()">Edit Max</button>

        <div class="label">Current Map:</div>
        <div class="value" id="currentMap">-</div>
        <div></div>

        <div class="label">Game Mode:</div>
        <div class="value" id="gameMode">-</div>
        <div></div>

        <div class="label">Round:</div>
        <div class="value" id="roundInfo">-</div>
        <div></div>

        <div class="label">Next Map:</div>
        <div class="value" id="nextMap">-</div>
        <div></div>

        <div class="label">Round Control:</div>
        <div class="value" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-width: 400px;">
            <button class="action-btn" style="background: #4a90e2; border-color: #5aa0f2;" onclick="nextRound()">Next Round</button>
            <button class="action-btn" style="background: #e2a84a; border-color: #f2b85a;" onclick="restartRound()">Restart Round</button>
            <button class="action-btn team" onclick="endRound(1)">End (Team 1 Wins)</button>
            <button class="action-btn ban" onclick="endRound(2)">End (Team 2 Wins)</button>
        </div>
        <div></div>

        <hr>

        <div class="label">Ranked:</div>
        <div class="value status" id="ranked">-</div>
        <div></div>

        <div class="label">PunkBuster:</div>
        <div class="value status" id="punkBuster">-</div>
        <div></div>

        <div class="label">Hardcore:</div>
        <div class="value status" id="hardcore">-</div>
        <button class="toggle-btn" onclick="toggleSetting('hardcore')">Toggle</button>

        <div class="label">Teambalance:</div>
        <div class="value status" id="teambalance">-</div>
        <button class="toggle-btn" onclick="toggleSetting('teambalance')">Toggle</button>

        <div class="label">Friendly Fire <span class="tooltip" title="Works after round restart">(?)</span>:</div>
        <div class="value status" id="friendlyFire">-</div>
        <button class="toggle-btn" onclick="toggleSetting('friendlyFire')">Toggle</button>

        <div class="label">Killcam <span class="tooltip" title="Works after map switch">(?)</span>:</div>
        <div class="value status" id="killcam">-</div>
        <button class="toggle-btn" onclick="toggleSetting('killcam')">Toggle</button>

        <div class="label">Minimap <span class="tooltip" title="Works after map switch">(?)</span>:</div>
        <div class="value status" id="minimap">-</div>
        <button class="toggle-btn" onclick="toggleSetting('minimap')">Toggle</button>

        <div class="label">Crosshair <span class="tooltip" title="Works after map switch">(?)</span>:</div>
        <div class="value status" id="crosshair">-</div>
        <button class="toggle-btn" onclick="toggleSetting('crosshair')">Toggle</button>

        <div class="label">3D-Spotting <span class="tooltip" title="Works after map switch">(?)</span>:</div>
        <div class="value status" id="spotting3d">-</div>
        <button class="toggle-btn" onclick="toggleSetting('spotting3d')">Toggle</button>

        <div class="label">Minimap Spotting <span class="tooltip" title="Works after map switch">(?)</span>:</div>
        <div class="value status" id="minimapSpotting">-</div>
        <button class="toggle-btn" onclick="toggleSetting('minimapSpotting')">Toggle</button>

        <div class="label">3rd-Person Vehicle Cam <span class="tooltip" title="(Unconfirmed) Works but is bugged if someone ends round in 3rd person view.">(?)</span>:</div>
        <div class="value status" id="vehicleCam">-</div>
        <button class="toggle-btn" onclick="toggleSetting('vehicleCam')">Toggle</button>

        <hr>

        <div class="label">Profanity Filter:</div>
        <div class="value status" id="profanityFilter">-</div>
        <button class="toggle-btn" onclick="toggleSetting('profanityFilter')">Toggle</button>

        <div class="label">TK Count for Kick:</div>
        <div class="value">
            <span id="teamKillCountForKick">-</span>
        </div>
        <div></div>

        <div class="label">TK Value for Kick:</div>
        <div class="value">
            <span id="teamKillValueForKick">-</span>
        </div>
        <div></div>

        <div class="label">TK Value Increase:</div>
        <div class="value">
            <span id="teamKillValueIncrease">-</span>
        </div>
        <div></div>

        <div class="label">TK Value Decrease/sec:</div>
        <div class="value">
            <span id="teamKillValueDecreasePerSecond">-</span>
        </div>
        <div></div>

        <div class="label">Idle Timeout (seconds):</div>
        <div class="value">
            <span id="idleTimeout">-</span>
        </div>
        <div></div>
        
        <hr style="grid-column: span 3; border: 1px solid #444; margin: 20px 0;">
    </div>

    <div class="map-image-box">
        <img id="mapImage" src="" alt="Missing Picture">
    </div>
</div>

<h2 style="margin-top: 40px;">Server Banner</h2>

<div class="banner-section">
    <div class="banner-display" id="bannerDisplay">
        <span id="bannerPlaceholder">No banner set</span>
        <img id="bannerImage" src="" style="display: none;">
    </div>
    <div class="banner-upload">
        <input type="file" id="bannerFile" accept="image/png" style="display: none;">
        <button class="upload-btn" onclick="document.getElementById('bannerFile').click()">Upload New Banner</button>
        <div class="banner-info">
            Banner must be PNG, 512x64 pixels, under 127KB
        </div>
        <div class="banner-info" id="bannerUrlDisplay" style="margin-top: 10px;"></div>
    </div>
</div>

<h2 style="margin-top:40px;">Players</h2>

<div class="player-table-container">
    <table class="player-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Clan Tag</th>
                <th>Team</th>
                <th>Squad</th>
                <th>Kills</th>
                <th>Deaths</th>
                <th>Score</th>
                <th>Ping</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="playerTableBody">
            <tr>
                <td colspan="9" class="empty-message">Loading players...</td>
            </tr>
        </tbody>
    </table>
</div>

<h2 style="margin-top:40px;">Console</h2>
<div id="consoleOutput"></div>
<br>
<input type="text" id="commandInput" style="width:25%;">
<button onclick="sendCommand()">Send</button>

<script>
let currentState = {};

function setBool(id, value) {
    const el = document.getElementById(id);
    if (value) {
        el.innerHTML = "✓ Enabled";
        el.className = "value status true";
    } else {
        el.innerHTML = "✗ Disabled";
        el.className = "value status false";
    }
    currentState[id] = value;
}

async function toggleSetting(setting) {
    const btn = event.target;
    
    if (btn.classList.contains('loading')) {
        return;
    }
    
    const currentValue = currentState[setting];
    const newValue = !currentValue;
    
    btn.classList.add('loading');
    
    try {
        const formData = new FormData();
        formData.append('toggle', setting);
        formData.append('value', newValue ? 'true' : 'false');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            setBool(setting, newValue);
            
            const outputDiv = document.getElementById("consoleOutput");
            outputDiv.innerText += `> Toggled ${setting} to ${newValue ? 'enabled' : 'disabled'}\n\n`;
            outputDiv.scrollTop = outputDiv.scrollHeight;
        } else {
            alert('Error toggling setting: ' + data.error);
        }
        
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        btn.classList.remove('loading');
    }
}

function editServerName() {
    const currentName = document.getElementById('serverName').innerText;
    
    const newName = prompt('Edit Server Name:', currentName);
    
    if (newName === null || newName.trim() === '') return; // Cancelled or empty
    
    (async () => {
        try {
            const formData = new FormData();
            formData.append('updateServerName', '1');
            formData.append('newName', newName.trim());
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('serverName').innerText = newName.trim();
                
                const outputDiv = document.getElementById("consoleOutput");
                outputDiv.innerText += `> Server name changed to: ${newName.trim()}\n\n`;
                outputDiv.scrollTop = outputDiv.scrollHeight;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    })();
}

function editServerDescription() {
    // Replace | with newlines for editing
    const currentDesc = (window.currentServerDescription || '').replace(/\|/g, '\n');
    
    const newDesc = prompt('Edit Server Description:\n(Use line breaks for multiple lines, max 400 characters)', currentDesc);
    
    if (newDesc === null) return; // Cancelled
    
    if (newDesc.length > 400) {
        alert('Description must be less than 400 characters');
        return;
    }
    
    (async () => {
        try {
            const formData = new FormData();
            formData.append('updateServerDescription', '1');
            formData.append('newDescription', newDesc);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                window.currentServerDescription = data.newDescription;
                
                const outputDiv = document.getElementById("consoleOutput");
                outputDiv.innerText += `> Server description updated\n\n`;
                outputDiv.scrollTop = outputDiv.scrollHeight;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    })();
}

function editGamePassword() {
    const passwordSpan = document.getElementById('gamePassword');
    const currentPassword = passwordSpan.innerText === '(none)' ? '' : passwordSpan.innerText;
    
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentPassword;
    input.placeholder = 'Leave empty for no password';
    input.maxLength = 16;
    input.className = 'edit-input';
    input.style.width = '200px';
    
    const saveBtn = document.createElement('button');
    saveBtn.innerText = '✓';
    saveBtn.className = 'save-btn';
    saveBtn.onclick = async () => {
        const newPassword = input.value.trim();
        
        // Validate alphanumeric only
        if (newPassword !== '' && !/^[a-zA-Z0-9]+$/.test(newPassword)) {
            alert('Password can only contain letters and numbers');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('updateGamePassword', '1');
            formData.append('newPassword', newPassword);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                passwordSpan.innerText = data.newPassword || '(none)';
                
                const outputDiv = document.getElementById("consoleOutput");
                if (data.newPassword) {
                    outputDiv.innerText += `> Game password set to: ${data.newPassword}\n\n`;
                } else {
                    outputDiv.innerText += `> Game password removed\n\n`;
                }
                outputDiv.scrollTop = outputDiv.scrollHeight;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    };
    
    const cancelBtn = document.createElement('button');
    cancelBtn.innerText = '✗';
    cancelBtn.className = 'cancel-btn';
    cancelBtn.onclick = () => {
        passwordSpan.innerText = currentPassword || '(none)';
    };
    
    passwordSpan.innerHTML = '';
    passwordSpan.appendChild(input);
    passwordSpan.appendChild(saveBtn);
    passwordSpan.appendChild(cancelBtn);
    input.focus();
}

function editMaxPlayers() {
    const maxSpan = document.getElementById('maxPlayers');
    const currentMax = maxSpan.innerText;
    
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentMax;
    input.min = '8';
    input.max = '32';
    input.className = 'edit-input';
    input.style.width = '60px';
    
    const saveBtn = document.createElement('button');
    saveBtn.innerText = '✓';
    saveBtn.className = 'save-btn';
    saveBtn.onclick = async () => {
        const newMax = parseInt(input.value);
        if (newMax < 8 || newMax > 32) {
            alert('Max players must be between 8 and 32');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('updateMaxPlayers', '1');
            formData.append('newMax', newMax);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                maxSpan.innerText = newMax;
                
                const outputDiv = document.getElementById("consoleOutput");
                outputDiv.innerText += `> Max players changed to: ${newMax}\n\n`;
                outputDiv.scrollTop = outputDiv.scrollHeight;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    };
    
    const cancelBtn = document.createElement('button');
    cancelBtn.innerText = '✗';
    cancelBtn.className = 'cancel-btn';
    cancelBtn.onclick = () => {
        maxSpan.innerText = currentMax;
    };
    
    maxSpan.innerHTML = '';
    maxSpan.appendChild(input);
    maxSpan.appendChild(saveBtn);
    maxSpan.appendChild(cancelBtn);
    input.focus();
}

function editNumericSetting(settingId, command, min, max) {
    const span = document.getElementById(settingId);
    const currentValue = span.innerText;
    
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentValue;
    input.min = min.toString();
    input.max = max.toString();
    input.className = 'edit-input';
    input.style.width = '80px';
    
    const saveBtn = document.createElement('button');
    saveBtn.innerText = '✓';
    saveBtn.className = 'save-btn';
    saveBtn.onclick = async () => {
        const newValue = parseInt(input.value);
        if (newValue < min || newValue > max) {
            alert(`Value must be between ${min} and ${max}`);
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('updateNumericSetting', '1');
            formData.append('setting', settingId);
            formData.append('command', command);
            formData.append('newValue', newValue);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                span.innerText = newValue;
                
                const outputDiv = document.getElementById("consoleOutput");
                outputDiv.innerText += `> ${settingId} changed to: ${newValue}\n\n`;
                outputDiv.scrollTop = outputDiv.scrollHeight;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    };
    
    const cancelBtn = document.createElement('button');
    cancelBtn.innerText = '✗';
    cancelBtn.className = 'cancel-btn';
    cancelBtn.onclick = () => {
        span.innerText = currentValue;
    };
    
    span.innerHTML = '';
    span.appendChild(input);
    span.appendChild(saveBtn);
    span.appendChild(cancelBtn);
    input.focus();
}

async function loadServerInfo() {
    const response = await fetch(`?api=serverinfo${getServerParam()}`);
    const data = await response.json();

    document.getElementById('serverName').innerText = data.serverName;
    document.getElementById('gamePassword').innerText = data.gamePassword || '(none)';
    document.getElementById('currentPlayers').innerText = data.currentPlayers;
    document.getElementById('maxPlayers').innerText = data.maxPlayers;
    document.getElementById('currentMap').innerText = data.currentMap;
    document.getElementById('gameMode').innerText = data.gameMode;
    document.getElementById('roundInfo').innerText = `Round ${data.roundsPlayed} of ${data.roundsTotal}`;
    document.getElementById('nextMap').innerText = data.nextMap;

    if (data.currentMapImage) {
        document.getElementById('mapImage').src = data.currentMapImage;
    } else {
        document.getElementById('mapImage').src = "";
    }

    setBool("ranked", data.ranked);
    setBool("punkBuster", data.punkBuster);
    setBool("hardcore", data.hardcore);
    setBool("teambalance", data.teambalance);
    setBool("friendlyFire", data.friendlyFire);
    setBool("killcam", data.killcam);
    setBool("minimap", data.minimap);
    setBool("crosshair", data.crosshair);
    setBool("spotting3d", data.spotting3d);
    setBool("minimapSpotting", data.minimapSpotting);
    setBool("vehicleCam", data.vehicleCam);
    setBool("profanityFilter", data.profanityFilter);
    
    document.getElementById('teamKillCountForKick').innerText = data.teamKillCountForKick;
    document.getElementById('teamKillValueForKick').innerText = data.teamKillValueForKick;
    document.getElementById('teamKillValueIncrease').innerText = data.teamKillValueIncrease;
    document.getElementById('teamKillValueDecreasePerSecond').innerText = data.teamKillValueDecreasePerSecond;
    document.getElementById('idleTimeout').innerText = data.idleTimeout;
    
    // Store and display server description (truncated)
    window.currentServerDescription = data.serverDescription || '';
    const descText = window.currentServerDescription.replace(/\|/g, ' ');
    document.getElementById('serverDescription').innerText = descText || '(none)';
    document.getElementById('serverDescription').title = descText; // Show full text on hover
}

async function loadBannerInfo() {
    try {
        const response = await fetch(`?api=bannerinfo${getServerParam()}`);
        const data = await response.json();
        
        if (data.success && data.bannerExists) {
            document.getElementById('bannerImage').src = '/banners/banner.png?' + Date.now();
            document.getElementById('bannerImage').style.display = 'block';
            document.getElementById('bannerPlaceholder').style.display = 'none';
        } else {
            document.getElementById('bannerImage').style.display = 'none';
            document.getElementById('bannerPlaceholder').style.display = 'block';
        }
        
        if (data.currentUrl) {
            document.getElementById('bannerUrlDisplay').innerText = 'Current URL: ' + data.currentUrl;
        }
    } catch (error) {
        console.error('Error loading banner info:', error);
    }
}

// Handle banner file upload
document.getElementById('bannerFile').addEventListener('change', async function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    // Validate file type
    if (file.type !== 'image/png') {
        alert('Banner must be a PNG file');
        return;
    }
    
    // Validate file size
    if (file.size > 127 * 1024) {
        alert('Banner must be smaller than 127KB');
        return;
    }
    
    // Validate dimensions
    const img = new Image();
    img.onload = async function() {
        if (img.width !== 512 || img.height !== 64) {
            alert('Banner must be exactly 512x64 pixels');
            return;
        }
        
        // Upload the file
        const formData = new FormData();
        formData.append('banner', file);
        
        try {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const text = await response.text();
            let data;
            
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Server response:', text);
                alert('Server error: Invalid response. Check console for details.');
                return;
            }
            
            if (data.success) {
                const outputDiv = document.getElementById("consoleOutput");
                outputDiv.innerText += `> Banner uploaded successfully: ${data.bannerUrl}\n\n`;
                outputDiv.scrollTop = outputDiv.scrollHeight;
                
                // Reload banner
                loadBannerInfo();
            } else {
                alert('Error uploading banner: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    };
    
    img.onerror = function() {
        alert('Failed to load image for validation');
    };
    
    img.src = URL.createObjectURL(file);
    
    // Reset file input
    e.target.value = '';
});

async function loadPlayerList() {
    try {
        const response = await fetch(`?api=playerlist${getServerParam()}`);
        const data = await response.json();
        
        const tbody = document.getElementById('playerTableBody');
        
        if (!data.success || data.players.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="empty-message">No players online</td></tr>';
            return;
        }
        
        tbody.innerHTML = '';
        
        data.players.forEach(player => {
            const row = document.createElement('tr');
            
            const teamClass = `team-${player.teamId || '0'}`;
            const playerName = escapeHtml(player.name || '-');
            const teamId = parseInt(player.teamId) || 0;
            const squadId = parseInt(player.squadId) || 0;
            
            row.innerHTML = `
                <td class="${teamClass}">${playerName}</td>
                <td>${escapeHtml(player.clanTag || '-')}</td>
                <td class="${teamClass}">${getTeamName(player.teamId)}</td>
                <td>${getSquadName(player.squadId)}</td>
                <td>${player.kills || '0'}</td>
                <td>${player.deaths || '0'}</td>
                <td>${player.score || '0'}</td>
                <td>${player.ping || '-'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn kick" onclick="kickPlayer('${playerName.replace(/'/g, "\\'")}')">Kick</button>
                        <button class="action-btn ban" onclick="banPlayer('${playerName.replace(/'/g, "\\'")}')">Ban</button>
                        <button class="action-btn team" onclick="swapTeam('${playerName.replace(/'/g, "\\'")}', ${teamId})">Team</button>
                        <button class="action-btn squad" onclick="swapSquad('${playerName.replace(/'/g, "\\'")}', ${teamId}, ${squadId})">Squad</button>
                    </div>
                </td>
            `;
            
            tbody.appendChild(row);
        });
        
    } catch (error) {
        console.error('Error loading player list:', error);
    }
}

async function nextRound() {
    if (!confirm('Start the next round?\n\nThis will end the current round and load the next map.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('command', 'admin.runNextRound');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const outputDiv = document.getElementById("consoleOutput");
            outputDiv.innerText += `> Starting next round...\n\n`;
            outputDiv.scrollTop = outputDiv.scrollHeight;
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function restartRound() {
    if (!confirm('Restart the current round?\n\nThis will reset the current round to the beginning.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('command', 'admin.restartRound');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const outputDiv = document.getElementById("consoleOutput");
            outputDiv.innerText += `> Restarting current round...\n\n`;
            outputDiv.scrollTop = outputDiv.scrollHeight;
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function endRound(winningTeam) {
    const teamName = winningTeam === 1 ? 'Team 1' : 'Team 2';
    
    if (!confirm(`End the round with ${teamName} winning?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('command', `admin.endRound ${winningTeam}`);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const outputDiv = document.getElementById("consoleOutput");
            outputDiv.innerText += `> Ending round, ${teamName} wins\n\n`;
            outputDiv.scrollTop = outputDiv.scrollHeight;
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

function getTeamName(teamId) {
    const id = parseInt(teamId);
    if (id === 1) return 'Team 1';
    if (id === 2) return 'Team 2';
    if (id === 0) return 'Neutral';
    return `Team ${id}`;
}

function getSquadName(squadId) {
    const squadNames = {
        "1": "Alpha",
        "2": "Bravo",
        "3": "Charlie",
        "4": "Delta",
        "5": "Echo",
        "6": "Foxtrot",
        "7": "Golf",
        "8": "Hotel"
    };
    
    const id = String(squadId);
    if (id === "0") return "No Squad";
    return squadNames[id] || `Squad ${id}`;
}

async function kickPlayer(playerName) {
    if (!confirm(`Are you sure you want to kick ${playerName}?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('command', `admin.kickPlayer ${playerName}`);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const outputDiv = document.getElementById("consoleOutput");
            outputDiv.innerText += `> Kicked player: ${playerName}\n\n`;
            outputDiv.scrollTop = outputDiv.scrollHeight;
            
            // Refresh player list
            setTimeout(loadPlayerList, 500);
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function banPlayer(playerName) {
    // Create custom dialog
    const dialog = document.createElement('div');
    dialog.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #2a2a2a;
        border: 2px solid #555;
        padding: 20px;
        border-radius: 8px;
        z-index: 10000;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        min-width: 350px;
    `;
    
    dialog.innerHTML = `
        <h3 style="margin-top: 0; color: #e24a4a;">Ban Player: ${playerName}</h3>
        
        <div style="margin: 15px 0;">
            <label style="display: block; margin-bottom: 10px;">
                <input type="radio" name="banType" value="perm" checked style="margin-right: 8px;">
                Permanent Ban
            </label>
            <label style="display: block; margin-bottom: 10px;">
                <input type="radio" name="banType" value="round" style="margin-right: 8px;">
                Ban Until End of Round
            </label>
            <label style="display: block; margin-bottom: 5px;">
                <input type="radio" name="banType" value="seconds" style="margin-right: 8px;">
                Temporary Ban (seconds):
            </label>
            <input type="number" id="banSeconds" min="1" value="3600" 
                   style="margin-left: 28px; width: 150px; background: #1a1a1a; 
                          border: 1px solid #555; color: #fff; padding: 5px; border-radius: 3px;">
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button id="confirmBan" style="flex: 1; padding: 8px; background: #e24a4a; 
                    border: none; color: white; border-radius: 4px; cursor: pointer; font-weight: bold;">
                Ban Player
            </button>
            <button id="cancelBan" style="flex: 1; padding: 8px; background: #555; 
                    border: none; color: white; border-radius: 4px; cursor: pointer;">
                Cancel
            </button>
        </div>
    `;
    
    // Add backdrop
    const backdrop = document.createElement('div');
    backdrop.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.7);
        z-index: 9999;
    `;
    
    document.body.appendChild(backdrop);
    document.body.appendChild(dialog);
    
    // Handle confirm
    document.getElementById('confirmBan').onclick = async () => {
        const banType = document.querySelector('input[name="banType"]:checked').value;
        let banCommand = `banList.add name ${playerName}`;
        
        if (banType === 'perm') {
            banCommand += ' perm';
        } else if (banType === 'round') {
            banCommand += ' round';
        } else if (banType === 'seconds') {
            const seconds = document.getElementById('banSeconds').value;
            banCommand += ` seconds ${seconds}`;
        }
        
        // Close dialog
        document.body.removeChild(backdrop);
        document.body.removeChild(dialog);
        
        try {
            const formData = new FormData();
            formData.append('command', banCommand);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                const outputDiv = document.getElementById("consoleOutput");
                outputDiv.innerText += `> Banned player: ${playerName} (${banType})\n\n`;
                outputDiv.scrollTop = outputDiv.scrollHeight;
                
                // Refresh player list
                setTimeout(loadPlayerList, 500);
            } else {
                alert('Error: ' + data.error);
            }
        } catch (error) {
            alert('Error: ' + error.message);
        }
    };
    
    // Handle cancel
    document.getElementById('cancelBan').onclick = () => {
        document.body.removeChild(backdrop);
        document.body.removeChild(dialog);
    };
    
    // Close on backdrop click
    backdrop.onclick = () => {
        document.body.removeChild(backdrop);
        document.body.removeChild(dialog);
    };
}

async function swapTeam(playerName, currentTeam) {
    const newTeam = currentTeam === 1 ? 2 : 1;
    const newTeamName = getTeamName(newTeam);
    
    if (!confirm(`Move ${playerName} to ${newTeamName}?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('command', `admin.movePlayer ${playerName} ${newTeam} 0 true`);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const outputDiv = document.getElementById("consoleOutput");
            outputDiv.innerText += `> Moved ${playerName} to ${newTeamName}\n\n`;
            outputDiv.scrollTop = outputDiv.scrollHeight;
            
            // Refresh player list
            setTimeout(loadPlayerList, 500);
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function swapSquad(playerName, teamId, currentSquad) {
    const squadNames = ["No Squad", "Alpha", "Bravo", "Charlie", "Delta", "Echo", "Foxtrot", "Golf", "Hotel"];
    
    // Create custom dialog
    const dialog = document.createElement('div');
    dialog.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #2a2a2a;
        border: 2px solid #555;
        padding: 20px;
        border-radius: 8px;
        z-index: 10000;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        min-width: 300px;
    `;
    
    let buttonsHtml = '';
    for (let i = 0; i <= 8; i++) {
        const isCurrentSquad = i === currentSquad;
        const buttonStyle = isCurrentSquad 
            ? 'background: #4a90e2; border: 2px solid #5aa0f2;' 
            : 'background: #333; border: 1px solid #555;';
        
        buttonsHtml += `
            <button data-squad="${i}" style="${buttonStyle} color: white; padding: 10px 15px; 
                    margin: 5px; border-radius: 4px; cursor: pointer; width: calc(50% - 12px); 
                    font-weight: ${isCurrentSquad ? 'bold' : 'normal'};">
                ${i}: ${squadNames[i]}${isCurrentSquad ? ' (current)' : ''}
            </button>
        `;
    }
    
    dialog.innerHTML = `
        <h3 style="margin-top: 0; color: #8a4ae2;">Select Squad for ${playerName}</h3>
        <div style="display: flex; flex-wrap: wrap; margin: 15px 0;">
            ${buttonsHtml}
        </div>
        <button id="cancelSquadSelect" style="width: 100%; padding: 8px; background: #555; 
                border: none; color: white; border-radius: 4px; cursor: pointer; margin-top: 10px;">
            Cancel
        </button>
    `;
    
    // Add backdrop
    const backdrop = document.createElement('div');
    backdrop.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.7);
        z-index: 9999;
    `;
    
    document.body.appendChild(backdrop);
    document.body.appendChild(dialog);
    
    // Handle squad button clicks
    dialog.querySelectorAll('button[data-squad]').forEach(btn => {
        btn.onclick = async () => {
            const squadNum = parseInt(btn.getAttribute('data-squad'));
            
            // Close dialog
            document.body.removeChild(backdrop);
            document.body.removeChild(dialog);
            
            try {
                const formData = new FormData();
                formData.append('command', `admin.movePlayer ${playerName} ${teamId} ${squadNum} true`);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const outputDiv = document.getElementById("consoleOutput");
                    outputDiv.innerText += `> Moved ${playerName} to ${squadNames[squadNum]}\n\n`;
                    outputDiv.scrollTop = outputDiv.scrollHeight;
                    
                    // Refresh player list
                    setTimeout(loadPlayerList, 500);
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        };
    });
    
    // Handle cancel
    document.getElementById('cancelSquadSelect').onclick = () => {
        document.body.removeChild(backdrop);
        document.body.removeChild(dialog);
    };
    
    // Close on backdrop click
    backdrop.onclick = () => {
        document.body.removeChild(backdrop);
        document.body.removeChild(dialog);
    };
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to get current server parameter from URL
function getServerParam() {
    const urlParams = new URLSearchParams(window.location.search);
    const server = urlParams.get('server');
    return server ? `&server=${server}` : '';
}

async function sendCommand() {
    const input = document.getElementById("commandInput");
    const command = input.value.trim();
    if (!command) return;

    const formData = new FormData();
    formData.append('command', command);

    const response = await fetch('', {
        method: 'POST',
        body: formData
    });

    const data = await response.json();
    const outputDiv = document.getElementById("consoleOutput");

    outputDiv.innerText += "> " + command + "\n";

    if (data.success) {
        outputDiv.innerText += data.output.join(" ") + "\n\n";
    } else {
        outputDiv.innerText += "ERROR: " + data.error + "\n\n";
    }

    outputDiv.scrollTop = outputDiv.scrollHeight;
    input.value = "";
}

document.getElementById("commandInput").addEventListener("keyup", function(event) {
    if (event.key === "Enter") {
        sendCommand();
    }
});

loadServerInfo();
loadPlayerList();
loadBannerInfo();
setInterval(() => {
    loadServerInfo();
    loadPlayerList();
}, 10000);
</script>

</body>
</html>
