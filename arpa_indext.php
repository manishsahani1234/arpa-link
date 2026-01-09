<?php
session_start();

define('DATA_DIR', __DIR__);
define('HEARTBEAT_TIMEOUT', 30);

if (!is_writable(DATA_DIR)) {
    die('<div style="font-family:sans-serif;padding:20px;text-align:center;"><h1>⚠️ Configuration Error</h1><p>Directory not writable. Run: <code>chmod 755 ' . DATA_DIR . '</code></p></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'init') {
        $userId = uniqid('user_', true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['is_owner'] = true;
        echo json_encode([
            'success' => true,
            'userId' => $userId,
            'shareUrl' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?track=' . $userId
        ]);
        exit;
    }
    
    if ($action === 'update') {
        $userId = $_POST['userId'] ?? '';
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        
        if ($userId && $lat && $lng) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            $data = file_exists($filename) ? json_decode(file_get_contents($filename), true) : ['points' => [], 'startTime' => time(), 'isActive' => true, 'lastHeartbeat' => time()];
            
            $data['points'][] = ['lat' => $lat, 'lng' => $lng, 'timestamp' => time()];
            if (count($data['points']) > 5000) {
                $data['points'] = array_slice($data['points'], -5000);
            }
            
            $data['current'] = ['lat' => $lat, 'lng' => $lng, 'timestamp' => time()];
            if (!isset($data['startTime'])) {
                $data['startTime'] = $data['points'][0]['timestamp'];
            }
            
            $data['lastHeartbeat'] = time();
            $data['isActive'] = true;
            file_put_contents($filename, json_encode($data));
            echo json_encode(['success' => true]);
            exit;
        }
    }
    
    if ($action === 'heartbeat') {
        $userId = $_POST['userId'] ?? '';
        if ($userId) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            if (file_exists($filename)) {
                $data = json_decode(file_get_contents($filename), true);
                $data['lastHeartbeat'] = time();
                $data['isActive'] = true;
                file_put_contents($filename, json_encode($data));
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    if ($action === 'stop') {
        $userId = $_POST['userId'] ?? '';
        if ($userId) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            if (file_exists($filename)) {
                $data = json_decode(file_get_contents($filename), true);
                $data['isActive'] = false;
                $data['endTime'] = time();
                file_put_contents($filename, json_encode($data));
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    if ($action === 'get') {
        $userId = $_POST['userId'] ?? '';
        if ($userId) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            if (file_exists($filename)) {
                $data = json_decode(file_get_contents($filename), true);
                $lastHeartbeat = $data['lastHeartbeat'] ?? 0;
                $timeSinceHeartbeat = time() - $lastHeartbeat;
                
                if ($timeSinceHeartbeat > HEARTBEAT_TIMEOUT && ($data['isActive'] ?? false)) {
                    $data['isActive'] = false;
                    $data['endTime'] = $lastHeartbeat;
                    $data['autoStopped'] = true;
                    file_put_contents($filename, json_encode($data));
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    echo json_encode(['success' => false]);
    exit;
}

$trackUserId = $_GET['track'] ?? '';
$isViewer = !empty($trackUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Live Location Dashboard</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2da44e;
            --primary-hover: #2c974b;
            --danger: #d1242f;
            --bg: #f6f8fa;
            --card-bg: rgba(255, 255, 255, 0.8);
            --accent: #0969da;
            --radius: 12px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; outline: none; }
        
        body { 
            font-family: 'Inter', -apple-system, sans-serif; 
            background: #f0f2f5;
            color: #1a1f23;
            overflow-x: hidden;
        }

        .container { max-width: 900px; margin: 0 auto; padding: 20px; }

        /* Animation Keyframes */
        @keyframes pulseLive {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Header UI */
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            animation: slideUp 0.5s ease-out;
        }
        .header h1 { font-size: 24px; font-weight: 800; color: #000; letter-spacing: -0.5px; }
        .header p { color: #666; font-size: 14px; margin-top: 5px; }

        /* Card System */
        .card { 
            background: var(--card-bg); 
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: var(--radius); 
            padding: 24px; 
            margin-bottom: 20px; 
            box-shadow: 0 8px 32px rgba(0,0,0,0.05);
            animation: slideUp 0.6s ease-out;
        }

        /* Buttons UI */
        .btn-primary { 
            background: var(--primary); color: white; border: none; padding: 14px 28px; 
            font-size: 16px; font-weight: 600; border-radius: 10px; cursor: pointer; 
            transition: 0.3s; width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(45,164,78,0.3); }

        .btn-secondary { 
            background: #fff; color: #333; border: 1px solid #ddd; padding: 10px 20px; 
            font-size: 14px; font-weight: 600; border-radius: 8px; cursor: pointer; transition: 0.2s;
        }

        .btn-danger { 
            background: var(--danger); color: white; border: none; padding: 14px 28px; 
            font-weight: 600; border-radius: 10px; cursor: pointer; transition: 0.3s;
        }

        /* Status Indicator */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 15px;
        }
        .status-active { background: #e6ffec; color: #055d20; }
        .status-active i { color: #2da44e; animation: pulseLive 1.5s infinite; }

        /* Location Grid */
        .location-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 20px;
        }
        .grid-item {
            background: rgba(0,0,0,0.03);
            padding: 15px;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.02);
        }
        .grid-item label { display: block; font-size: 11px; text-transform: uppercase; color: #777; font-weight: 700; }
        .grid-item span { font-size: 16px; font-weight: 700; color: #111; font-family: 'Monaco', monospace; }

        /* Map UI */
        #map { 
            height: 450px; 
            border-radius: var(--radius); 
            border: 4px solid #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .map-wrapper { position: relative; border-radius: var(--radius); overflow: hidden; }

        /* Replay Controls */
        .replay-bar {
            margin: 20px 0;
            background: #fff;
            padding: 15px;
            border-radius: 10px;
        }
        .progress-container {
            height: 6px;
            background: #eee;
            border-radius: 10px;
            cursor: pointer;
            position: relative;
            margin: 10px 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 10px;
            width: 0%;
            transition: width 0.1s linear;
        }

        /* Share Area */
        .share-box {
            background: #000;
            color: #fff;
            padding: 15px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 15px;
            cursor: pointer;
        }
        .share-box code { font-size: 12px; opacity: 0.8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Modal Design */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-card {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        }

        @media (max-width: 600px) {
            #map { height: 350px; }
            .location-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="container">
    <header class="header">
        <h1><i class="fa-solid fa-location-crosshairs" style="color:var(--primary)"></i> Live Tracker</h1>
        <p>Real-time path visualization & sharing with Arpalink</p>
    </header>

    <?php if (!$isViewer): ?>
        <div id="homepage">
            <div class="card" style="text-align: center;">
                <h2 style="margin-bottom: 10px;">Start New Session</h2>
                <p style="margin-bottom: 25px; font-size: 14px;">Broadcasting your location securely via unique link with Arpalink</p>
                
                <div style="text-align: left; background: #fff; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                    <label style="font-size: 12px; font-weight: 800; color: #888;">UPDATE INTERVAL (SEC)</label>
                    <input type="number" id="initialUpdateInterval" min="1" max="60" value="5" 
                           style="width: 100%; border: none; font-size: 22px; font-weight: 800; margin-top: 5px;">
                </div>

                <button id="startTracking" class="btn-primary">
                    <i class="fa-solid fa-play"></i> START TRACKING
                </button>
            </div>
        </div>

        <div id="trackingView" style="display: none;">
            <div class="status-pill status-active" id="statusIndicator">
                <i class="fa-solid fa-circle"></i> Live Now
            </div>

            <div class="map-wrapper">
                <div id="map"></div>
                <button onclick="toggleFullscreen()" style="position:absolute; top:15px; right:15px; z-index:1000; border:none; background:#fff; width:40px; height:40px; border-radius:8px; cursor:pointer; box-shadow:0 4px 10px rgba(0,0,0,0.1)">
                    <i class="fa-solid fa-expand"></i>
                </button>
            </div>

            <div class="location-grid">
                <div class="grid-item"><label>Latitude</label><span id="currentLat">--</span></div>
                <div class="grid-item"><label>Longitude</label><span id="currentLng">--</span></div>
                <div class="grid-item"><label>Duration</label><span id="journeyDuration">--</span></div>
                <div class="grid-item"><label>Last Sync</label><span id="lastUpdate">--</span></div>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3 style="font-size: 14px;">SHARE PRIVATE ACCESS</h3>
                <div class="share-box" onclick="copyShareUrl()">
                    <code id="shareUrl">Generating...</code>
                    <i class="fa-solid fa-copy"></i>
                </div>
            </div>

            <div class="replay-bar" id="replayControls" style="display: none;">
                <h3 style="font-size: 14px; margin-bottom: 10px;">REPLAY CONTROL</h3>
                <div class="progress-container" onclick="seekReplay(event)">
                    <div class="progress-bar-fill" id="progressFill"></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; margin-bottom: 15px;">
                    <span id="replayCurrentTime">00:00</span>
                    <span id="replayTotalTime">00:00</span>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-secondary" onclick="toggleReplay()" id="playPauseText">Play</button>
                    <button class="btn-secondary" onclick="restartReplay()">Restart</button>
                </div>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button id="stopTrackingBtn" class="btn-danger" style="flex:1;">STOP TRACKING</button>
                <button id="showReplayBtn" class="btn-primary" onclick="showReplayMode()" style="display: none; flex:1;">VIEW REPLAY</button>
            </div>
        </div>

    <?php else: ?>
        <div id="viewerMode">
            <div class="status-pill status-active" id="viewerStatusIndicator">
                <i class="fa-solid fa-circle"></i> Live Stream
            </div>

            <div class="map-wrapper">
                <div id="map"></div>
            </div>

            <div class="location-grid">
                <div class="grid-item"><label>Latitude</label><span id="viewerLat">--</span></div>
                <div class="grid-item"><label>Longitude</label><span id="viewerLng">--</span></div>
                <div class="grid-item"><label>Time Offset</label><span id="viewerDuration">--</span></div>
                <div class="grid-item"><label>Signal</label><span id="viewerLastUpdate">--</span></div>
            </div>

            <div class="replay-bar" id="viewerReplayControls" style="display: none;">
                <h3>🎬 Journey Replay</h3>
                <div class="progress-container" onclick="seekReplay(event)">
                    <div class="progress-bar-fill" id="viewerProgressFill"></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 15px;">
                    <span id="viewerReplayCurrentTime">00:00</span>
                    <span id="viewerReplayTotalTime">00:00</span>
                </div>
                <button class="btn-secondary" onclick="toggleReplay()" id="viewerPlayPauseText">Play</button>
            </div>

            <div style="margin-top: 20px;">
                <button class="btn-primary" onclick="showReplayMode()" id="viewerReplayBtn" style="display: none;">LOAD COMPLETE JOURNEY</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="modal" class="modal-overlay">
    <div class="modal-card">
        <div id="modalIcon" style="font-size: 40px; margin-bottom: 15px; color: var(--primary);">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <h2 id="modalTitle" style="margin-bottom: 10px;">Success</h2>
        <p id="modalBody" style="color: #666; font-size: 14px; margin-bottom: 25px;">Action completed successfully.</p>
        <button class="btn-primary" onclick="closeModal()">OK</button>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // Logic remains exactly as provided in the original code, only referencing new IDs for design.
    let map, marker, routeLine, userId = null, trackingInterval = null, heartbeatInterval = null, viewingInterval = null, replayInterval = null;
    let isReplayMode = false, replayPlaying = false, replaySpeed = 1, replayIndex = 0, replayData = null, startTime = null, wakeLock = null;
    let currentUpdateInterval = 5000, isTracking = false, isFullscreen = false;
    const isViewer = <?php echo $isViewer ? 'true' : 'false'; ?>;
    const trackUserId = '<?php echo htmlspecialchars($trackUserId); ?>';

    async function requestWakeLock() {
        try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } 
        catch (err) { console.error('Wake Lock failed:', err); }
    }

    function initMap(lat, lng) {
        if (!map) {
            map = L.map('map', { zoomControl: false }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OpenStreetMap &copy; CARTO'
            }).addTo(map);
            L.control.zoom({ position: 'bottomright' }).addTo(map);
        } else {
            map.setView([lat, lng], 15);
        }
    }

    function updateMarker(lat, lng, label = 'Current Location') {
        const icon = L.divIcon({
            html: `<div style="background: var(--primary); width: 18px; height: 18px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 10px rgba(0,0,0,0.2);"></div>`,
            iconSize: [18, 18],
            className: ''
        });
        if (!marker) {
            marker = L.marker([lat, lng], { icon: icon }).addTo(map);
        } else {
            marker.setLatLng([lat, lng]);
            if (!isReplayMode) map.panTo([lat, lng]);
        }
    }

    function drawRoute(points) {
        if (routeLine) map.removeLayer(routeLine);
        if (points && points.length > 1) {
            const latLngs = points.map(p => [p.lat, p.lng]);
            routeLine = L.polyline(latLngs, { color: '#0969da', weight: 4, opacity: 0.6, lineJoin: 'round' }).addTo(map);
        }
    }

    function formatDuration(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return (h > 0 ? `${h}h ` : '') + `${m}m ${s}s`;
    }

    function formatTime(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }

    function showModal(title, body, type = 'success') {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').textContent = body;
        document.getElementById('modalIcon').style.color = type === 'error' ? 'var(--danger)' : 'var(--primary)';
        document.getElementById('modalIcon').innerHTML = type === 'error' ? '<i class="fa-solid fa-triangle-exclamation"></i>' : '<i class="fa-solid fa-circle-check"></i>';
        document.getElementById('modal').style.display = 'flex';
    }

    function closeModal() { document.getElementById('modal').style.display = 'none'; }

    function copyShareUrl() {
        const url = document.getElementById('shareUrl').textContent;
        navigator.clipboard.writeText(url).then(() => {
            const el = document.getElementById('shareUrl');
            const old = el.textContent;
            el.textContent = "COPIED TO CLIPBOARD!";
            setTimeout(() => el.textContent = old, 1500);
        });
    }

    function toggleFullscreen() {
        const el = document.getElementById('map');
        if (!document.fullscreenElement) el.requestFullscreen();
        else document.exitFullscreen();
    }

    // Tracking Logic Connect
    if (!isViewer) {
        document.getElementById('startTracking').addEventListener('click', function() {
            navigator.geolocation.getCurrentPosition(pos => {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=init'
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        userId = data.userId;
                        startTime = Date.now();
                        isTracking = true;
                        document.getElementById('shareUrl').textContent = data.shareUrl;
                        document.getElementById('homepage').style.display = 'none';
                        document.getElementById('trackingView').style.display = 'block';
                        requestWakeLock();
                        initMap(pos.coords.latitude, pos.coords.longitude);
                        updateMarker(pos.coords.latitude, pos.coords.longitude);
                        
                        const interval = parseInt(document.getElementById('initialUpdateInterval').value) || 5;
                        currentUpdateInterval = interval * 1000;
                        
                        startLocationTracking();
                        startHeartbeat();
                    }
                });
            }, err => showModal("Permission Required", "Please enable GPS to start tracking.", "error"), {enableHighAccuracy: true});
        });

        document.getElementById('stopTrackingBtn').addEventListener('click', function() {
            if(confirm("End live session?")) {
                stopTrackingNow();
                this.style.display = 'none';
                document.getElementById('statusIndicator').className = 'status-pill status-inactive';
                document.getElementById('statusIndicator').innerHTML = '<i class="fa-solid fa-circle-stop"></i> Session Ended';
                document.getElementById('showReplayBtn').style.display = 'block';
            }
        });
    }

    function startLocationTracking() {
        trackingInterval = setInterval(() => {
            navigator.geolocation.getCurrentPosition(pos => {
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                document.getElementById('currentLat').textContent = lat.toFixed(5);
                document.getElementById('currentLng').textContent = lng.toFixed(5);
                document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
                document.getElementById('journeyDuration').textContent = formatDuration(Math.floor((Date.now() - startTime)/1000));
                
                updateMarker(lat, lng);
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update&userId=${userId}&lat=${lat}&lng=${lng}`
                }).then(r => r.json()).then(res => {
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=get&userId=${userId}`
                    }).then(r => r.json()).then(routeData => {
                        if (routeData.success) drawRoute(routeData.data.points);
                    });
                });
            }, null, {enableHighAccuracy: true});
        }, currentUpdateInterval);
    }

    function startHeartbeat() {
        heartbeatInterval = setInterval(() => {
            if (userId && isTracking) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=heartbeat&userId=${userId}`
                });
            }
        }, 10000);
    }

    function stopTrackingNow() {
        isTracking = false;
        clearInterval(trackingInterval);
        clearInterval(heartbeatInterval);
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=stop&userId=${userId}`
        });
    }

    // Replay System
    function showReplayMode() {
        isReplayMode = true;
        const ctrls = isViewer ? 'viewerReplayControls' : 'replayControls';
        document.getElementById(ctrls).style.display = 'block';
        loadReplayData();
    }

    function loadReplayData() {
        const tid = isViewer ? trackUserId : userId;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get&userId=${tid}`
        }).then(r => r.json()).then(data => {
            if (data.success && data.data.points.length > 0) {
                replayData = data.data.points;
                const duration = replayData[replayData.length-1].timestamp - replayData[0].timestamp;
                const durEl = isViewer ? 'viewerReplayTotalTime' : 'replayTotalTime';
                document.getElementById(durEl).textContent = formatTime(duration);
                drawRoute(replayData);
                replayIndex = 0;
            }
        });
    }

    function toggleReplay() {
        replayPlaying = !replayPlaying;
        const btn = isViewer ? 'viewerPlayPauseText' : 'playPauseText';
        document.getElementById(btn).textContent = replayPlaying ? "Pause" : "Play";
        if (replayPlaying) {
            replayInterval = setInterval(() => {
                if (replayIndex < replayData.length) {
                    const p = replayData[replayIndex];
                    updateMarker(p.lat, p.lng);
                    const pct = (replayIndex / (replayData.length - 1)) * 100;
                    const fill = isViewer ? 'viewerProgressFill' : 'progressFill';
                    document.getElementById(fill).style.width = pct + '%';
                    replayIndex++;
                } else {
                    clearInterval(replayInterval);
                }
            }, 500);
        } else {
            clearInterval(replayInterval);
        }
    }

    function restartReplay() {
        replayIndex = 0;
        const fill = isViewer ? 'viewerProgressFill' : 'progressFill';
        document.getElementById(fill).style.width = '0%';
        if(replayPlaying) toggleReplay();
    }

    if (isViewer) {
        function updateViewer() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get&userId=${trackUserId}`
            }).then(r => r.json()).then(data => {
                if (data.success && data.data.current) {
                    const c = data.data.current;
                    if (!map) initMap(c.lat, c.lng);
                    updateMarker(c.lat, c.lng);
                    drawRoute(data.data.points);
                    document.getElementById('viewerLat').textContent = c.lat.toFixed(5);
                    document.getElementById('viewerLng').textContent = c.lng.toFixed(5);
                    document.getElementById('viewerLastUpdate').textContent = 'Online';
                    if (!data.data.isActive) {
                        document.getElementById('viewerStatusIndicator').innerHTML = '<i class="fa-solid fa-circle-stop"></i> Ended';
                        document.getElementById('viewerReplayBtn').style.display = 'block';
                        clearInterval(viewingInterval);
                    }
                }
            });
        }
        viewingInterval = setInterval(updateViewer, 5000);
        updateViewer();
    }
</script>

</body>
</html>
