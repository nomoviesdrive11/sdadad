<?php
// ==================== CONFIGURATION ====================
$CORRECT_PASSWORD = "S@m14";
$worker_api = "https://nocdn.richardgraccia.workers.dev";
// ---- Mirror service credentials (fill your real keys later) ---
$gdflix_api_key    = '02e8ca395a667eb2da89e44ba8153611';
$filepress_key     = 'jzkQCt53ImQy49Ug4bfbv2K0LHAw9qYG';
$gcloud_api_key    = 'user2apikey0987654321fedcfedcfedc';
// ================== STORAGE FILENAME ==================
$downloads_json = "downloads.json";
$series_json = "series.json";
// ================== HELPER FUNCTIONS ==================
// Extract Drive ID from any GDrive link
function extractDriveId($url) {
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) return $m[1];
    if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $m)) return $m[1];
    return null;
}
// GDFLIX API
function getGDFLIXLink($driveId, $gdflix_api_key) {
    $endpoint = "https://new.gdflix.me/v2/share?id=" . urlencode($driveId) . "&key=" . urlencode($gdflix_api_key);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) { 
        curl_close($ch); 
        return null; 
    }
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (!empty($result['status']) && $result['status'] == 1 && !empty($result['key'])) {
        return [
            'name' => $result['name'] ?? 'Unknown',
            'url'  => "https://new.gdflix.me/file/" . $result['key'],
            'size' => $result['size'] ?? null
        ];
    }
    
    return null;
}
// FilePress API
function getFilePressLink($driveId, $key) {
    $endpoint = "https://new3.filepress.today/api/v1/file/add";
    $payload = [
        'key' => $key,
        'id'  => $driveId
    ];
    $jsonData = json_encode($payload);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ]);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) { curl_close($ch); return null; }
    curl_close($ch);
    $data = json_decode($response, true);
    if (!empty($data['status']) && !empty($data['data'])) {
        return [
            'name' => $data['data']['name'] ?? '',
            'url'  => "https://fpgo.xyz/file/" . ($data['data']['_id'] ?? ''),
            'size' => isset($data['data']['size']) ? $data['data']['size'] : null
        ];
    }
    return null;
}
// GCLOUD API
function getGCLOUDLink($gdlink, $api_key) {
    $drive_id = extractDriveId($gdlink);
    if (!$drive_id) return null;
    $endpoint = "https://gcloud.cfd/api/v1/" . urlencode($api_key) . "/" . urlencode($drive_id);
    $response = @file_get_contents($endpoint);
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['success']) && $data['success'] && !empty($data['data']['download_url'])) {
            return $data['data']['download_url'];
        }
    }
    return null;
}
// Get all alt links (mirrors)
function getAltLinksAll($gdlink, $gdflix_api_key, $filepress_key, $gcloud_api_key) {
    $driveId = extractDriveId($gdlink);
    
    $gdflix_res = getGDFLIXLink($driveId, $gdflix_api_key);
    $gdflix_url = !empty($gdflix_res['url']) ? $gdflix_res['url'] : null;
    
    $fp_res = getFilePressLink($driveId, $filepress_key);
    $filepress_url = !empty($fp_res['url']) ? $fp_res['url'] : null;
    
    $gcloud_url = getGCLOUDLink($gdlink, $gcloud_api_key);
    
    return [
        'GDFLIX'   => $gdflix_url,
        'FilePress'=> $filepress_url,
        'GCLOUD'   => $gcloud_url
    ];
}
// Auto-detect series title from folder structure or filenames
function detectSeriesTitle($files) {
    if (empty($files)) return 'Untitled Series';
    
    // Extract from first filename (most reliable approach)
    if (!empty($files[0]['fileName'])) {
        $fileName = $files[0]['fileName'];
        
        // Remove file extension and common patterns
        $title = preg_replace('/\.(mp4|mkv|avi|m4v|mov|zip|7z|rar)$/i', '', $fileName);
        $title = preg_replace('/\b(s\d+e\d+|season\s*\d+|episode\s*\d+|ep\s*\d+|\d+x\d+)\b/i', '', $title);
        
        // Remove resolutions (improved regex)
        $title = preg_replace('/\b(480p|720p|1080p|1440p|2160p|4k|uhd)\b/i', '', $title);
        
        // Remove years and codecs
        $title = preg_replace('/\b\d{4}\b/', '', $title); // Remove years
        $title = preg_replace('/\b(x265|x264|hevc|h264|av1)\b/i', '', $title); // Remove codecs
        
        // Remove common release tags
        $title = preg_replace('/\b(web-dl|bluray|brrip|webrip|hdrip|dvdrip|amzn|nf|hbo|max|hulu)\b/i', '', $title);
        $title = preg_replace('/\b(ntb|rarbg|yts|eztv|torrentgalaxy|1337x)\b/i', '', $title); // Release groups
        
        $title = preg_replace('/[\[\(].*?[\]\)]/', '', $title); // Remove brackets content
        $title = preg_replace('/[._-]+/', ' ', $title); // Replace separators with spaces
        $title = trim($title);
        
        if (strlen($title) > 2) {
            return ucwords(strtolower($title));
        }
    }
    
    return 'Untitled Series';
}

// Storage/update util for movies
function addDownloadLink($id, $fileName, $fileType, $fileSize, $workerLink, $altLinks = []) {
    global $downloads_json;
    $data = file_exists($downloads_json) ? json_decode(file_get_contents($downloads_json), true) : [];
    $data[$id] = [
        "fileName" => $fileName,
        "fileType" => $fileType,
        "fileSize" => $fileSize,
        "workerLink" => $workerLink,
        "altLinks" => $altLinks
    ];
    file_put_contents($downloads_json, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
// Series-specific functions
function parseEpisodeInfo($filename) {
    // Extract season and episode from filename
    $patterns = [
        '/S(\d+)E(\d+)/i',           // S01E01
        '/Season\s*(\d+).*Episode\s*(\d+)/i', // Season 1 Episode 1
        '/(\d+)x(\d+)/i',            // 1x01
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $filename, $matches)) {
            return [
                'season' => intval($matches[1]),
                'episode' => intval($matches[2])
            ];
        }
    }
    
    // Match Episode 01, Ep 1, E01 patterns in filename (for Complete structures)
    if (preg_match('/(?:^|[\s._-])(?:episode)\s*(\d+)/i', $filename, $matches)) {
        return ['season' => 0, 'episode' => intval($matches[1])]; // Season will be extracted from folder
    }
    
    if (preg_match('/(?:^|[\s._-])(?:ep|e)\s*(\d+)/i', $filename, $matches)) {
        return ['season' => 0, 'episode' => intval($matches[1])]; // Season will be extracted from folder
    }
    
    return null;
}
function detectResolution($filename) {
    if (preg_match('/(480p|720p|1080p|1440p|2160p|4k)/i', $filename, $matches)) {
        return strtolower($matches[1]);
    }
    return 'unknown';
}
function detectCodec($filename) {
    if (preg_match('/(x265|hevc|h265)/i', $filename)) return 'x265';
    if (preg_match('/(x264|h264)/i', $filename)) return 'x264';
    if (preg_match('/(av1)/i', $filename)) return 'AV1'; 
    return 'unknown';
}
function getSeriesStructureType($files) {
    $hasCompleteStructure = false; // Season → Resolution folder → Episode files
    $hasOngoingStructure = false;  // Season → Episode folder → Multiple resolutions
    
    foreach ($files as $file) {
        $folderPath = $file['folderPath'] ?? '';
        $pathParts = array_filter(explode('/', $folderPath), 'strlen');
        
        // Check for Complete structure: season1/1080p/episode files
        if (count($pathParts) >= 2) {
            $seasonFolder = $pathParts[0];
            $subFolder = $pathParts[1];
            
            // Is first level a season folder?
            if (preg_match('/season\s*\d+|s\d+/i', $seasonFolder)) {
                // Is second level a resolution folder? (1080p, 1080p x265, UHD HEVC, etc.)
                if (preg_match('/\b(1080p|720p|480p|4k|uhd|2160p|1440p|1080i|720i)\b/i', $subFolder)) {
                    $hasCompleteStructure = true;
                }
            }
        }
        
        // Check for Ongoing structure: season1/episode1/multiple resolution files
        if (count($pathParts) >= 2) {
            $seasonFolder = $pathParts[0];
            $subFolder = $pathParts[1];
            
            // Is first level a season folder?
            if (preg_match('/season\s*\d+|s\d+/i', $seasonFolder)) {
                // Is second level an episode folder? (episode1, S01E01, 1x08, Ep 1, E01, etc.)
                if (preg_match('/episode\s*\d+|s\d+e\d+|\d+x\d+|(?:^|[\s._-])(ep|e)\s*\d+/i', $subFolder)) {
                    $hasOngoingStructure = true;
                }
            }
        }
    }
    
    if ($hasCompleteStructure) return 'complete';
    if ($hasOngoingStructure) return 'ongoing';
    return 'unknown';
}
function organizeSeries($files) {
    $structure = getSeriesStructureType($files);
    $organized = [
        'type' => $structure,
        'seasons' => []
    ];
    
    foreach ($files as $file) {
        $episodeInfo = parseEpisodeInfo($file['fileName']);
        $folderPath = $file['folderPath'] ?? '';
        $pathParts = array_filter(explode('/', $folderPath), 'strlen');
        
        $season = 0;
        $episode = 0;
        
        if ($episodeInfo) {
            $season = $episodeInfo['season'];
            $episode = $episodeInfo['episode'];
        } else if ($structure === 'ongoing' && count($pathParts) >= 2) {
            // For Ongoing: Extract episode info from folder structure (season1/episode1/files)
            $episodeFolderName = $pathParts[1];
            if (preg_match('/episode\s*(\d+)|s\d+e(\d+)|(\d+)x(\d+)|(?:^|[\s._-])(ep|e)\s*(\d+)/i', $episodeFolderName, $matches)) {
                $episode = intval($matches[1] ?? $matches[2] ?? $matches[4] ?? $matches[6] ?? 0); // Include Ep/E variants
            }
        }
        
        // Extract season from folder path if not found
        if ($season <= 0 && !empty($pathParts)) {
            $seasonFolder = $pathParts[0];
            if (preg_match('/season\s*(\d+)|s(\d+)/i', $seasonFolder, $matches)) {
                $season = intval($matches[1] ?? $matches[2]);
            }
        }
        
        // Default to season 1 if still no season found
        if ($season <= 0) {
            $season = 1;
        }
        
        // Handle ZIP files specially - they might not have episode numbers but should still be processed
        $isZipFile = preg_match('/\.(zip|7z|rar)$/i', $file['fileName']);
        
        // Only proceed if we have episode info (from filename or folder) OR if it's a ZIP file
        if ($episode > 0 || $isZipFile) {
            
            // For ZIP files without episode info, try to extract from filename position or use incremental numbering
            if ($episode <= 0 && $isZipFile) {
                // Try to extract any number that might indicate episode/part
                if (preg_match('/(\d+)(?:\.(zip|7z|rar))?$/i', $file['fileName'], $matches)) {
                    $episode = intval($matches[1]);
                } else {
                    // If no number found, assign a sequential number based on existing episodes
                    $existingEpisodes = isset($organized['seasons'][$season]) ? $organized['seasons'][$season] : [];
                    $episode = count($existingEpisodes) + 1;
                }
            }
            
            if (!isset($organized['seasons'][$season])) {
                $organized['seasons'][$season] = [];
            }
            
            // Extract resolution and codec based on structure type
            $resolution = detectResolution($file['fileName']);
            $codec = detectCodec($file['fileName']);
            
 if ($structure === 'complete' && count($pathParts) >= 2) {
                // Complete: season1/1080p/episode files - resolution from folder
                $resolutionFolder = $pathParts[1];
                if (preg_match('/\b(1080p|720p|480p|4k|uhd|2160p|1440p|1080i|720i)\b/i', $resolutionFolder, $resMatches)) {
                    $resolution = $resMatches[1];
                }
            } elseif ($structure === 'ongoing' && count($pathParts) >= 2) {
                // Ongoing: season1/episode1/multiple resolution files - extract from filename
                // Resolution and codec already extracted from filename above
            }
            
            $organized['seasons'][$season][] = [
                'episode' => $episode,
                'fileName' => $file['fileName'],
                'fileSize' => $file['fileSize'],
                'id' => $file['id'],
                'resolution' => $resolution,
                'codec' => $codec,
                'workerLink' => $file['workerLink'],
                'altLinks' => $file['altLinks'] ?? [],
                'folderPath' => $folderPath
            ];
        }
    }
    
    // Sort episodes within each season - by resolution first, then codec
    foreach ($organized['seasons'] as &$season) {
        usort($season, function($a, $b) use ($structure) {
            // For Complete structures, prioritize ZIP files at the top
            if ($structure === 'complete') {
                $aIsZip = preg_match('/\.(zip|7z|rar)$/i', $a['fileName']);
                $bIsZip = preg_match('/\.(zip|7z|rar)$/i', $b['fileName']);

                // If one is ZIP and other is not, ZIP goes first
                if ($aIsZip && !$bIsZip) return -1;
                if (!$aIsZip && $bIsZip) return 1;
            }

            // First sort by episode number
            $episodeDiff = $a['episode'] - $b['episode'];
            if ($episodeDiff != 0) return $episodeDiff;
            
            // Then sort by resolution (1080p, 720p, 480p)
            $resolutionOrder = ['1080p' => 1, '720p' => 2, '480p' => 3, '4k' => 0, '2160p' => 0];
            $aResOrder = $resolutionOrder[$a['resolution']] ?? 99;
            $bResOrder = $resolutionOrder[$b['resolution']] ?? 99;
            
            $resDiff = $aResOrder - $bResOrder;
            if ($resDiff != 0) return $resDiff;
            
            // Finally sort by codec (x264, x265, AV1)
            $codecOrder = ['x264' => 1, 'x265' => 2, 'AV1' => 3];
            $aCodecOrder = $codecOrder[$a['codec']] ?? 99;
            $bCodecOrder = $codecOrder[$b['codec']] ?? 99;
            
            return $aCodecOrder - $bCodecOrder;
        });
    }
    
    return $organized;
}
function addSeries($seriesId, $seriesTitle, $seriesData) {
    global $series_json;
    $data = file_exists($series_json) ? json_decode(file_get_contents($series_json), true) : [];
    $data[$seriesId] = [
        'title' => $seriesTitle,
        'created' => date('Y-m-d H:i:s'),
        'updated' => date('Y-m-d H:i:s'),
        'structure' => $seriesData
    ];
    file_put_contents($series_json, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ================= LOGIN/HANDLERS/ADMIN UI =================
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $CORRECT_PASSWORD) {
        $_SESSION['admin'] = true;
        if (isset($_POST['remember-me'])) {
            setcookie("rememberMeToken", $CORRECT_PASSWORD, time() + (86400 * 7), "/");
        }
        header("Location: series.php");
        exit;
    } else {
        $error = "Incorrect password.";
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie("rememberMeToken", "", time() - 3600, "/");
    header("Location: series.php");
    exit;
}
if (isset($_COOKIE['rememberMeToken']) && $_COOKIE['rememberMeToken'] === $CORRECT_PASSWORD) {
    $_SESSION['admin'] = true;
}
// --- AJAX HANDLER: Save series folder ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_series') {
    $data = json_decode(file_get_contents("php://input"), true);
    $seriesTitle = $data['seriesTitle'] ?? '';
    $folderFiles = $data['files'] ?? [];
    
    if (empty($folderFiles)) {
        echo json_encode(['status' => 'fail', 'msg' => 'No files provided']);
        exit;
    }
    
    // Auto-detect title if not provided
    if (empty(trim($seriesTitle))) {
        $seriesTitle = detectSeriesTitle($folderFiles);
    }
    
    $processedFiles = [];
    
    // Process each file and generate mirrors
    foreach ($folderFiles as $file) {
        $altLinks = getAltLinksAll($file['originalUrl'] ?? '', $gdflix_api_key, $filepress_key, $gcloud_api_key);
        addDownloadLink($file['id'], $file['fileName'], $file['fileType'], $file['fileSize'], $file['workerLink'], $altLinks);
        
        $processedFiles[] = [
            'id' => $file['id'],
            'fileName' => $file['fileName'],
            'fileType' => $file['fileType'],
            'fileSize' => $file['fileSize'],
            'workerLink' => $file['workerLink'],
            'altLinks' => $altLinks,
            'folderPath' => $file['folderPath'] ?? ''  // CRITICAL: Preserve folderPath for structure detection
        ];
    }
    
    // Organize into series structure
    $organized = organizeSeries($processedFiles);
    $seriesId = 'series_' . uniqid();
    addSeries($seriesId, $seriesTitle, $organized);
    
    echo json_encode([
        'status' => 'ok',
        'seriesId' => $seriesId,
        'title' => $seriesTitle,
        'structure' => $organized
    ]);
    exit;
}
// --- AJAX HANDLER: Update/add R2 link for episode ---
// --- AJAX HANDLER: Save single episode download ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_download') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $data['id'] ?? '';
    $fileName = $data['fileName'] ?? '';
    $fileType = $data['fileType'] ?? '';
    $fileSize = $data['fileSize'] ?? '';
    $workerLink = $data['workerLink'] ?? '';
    $origUrl = $data['origUrl'] ?? '';
    
    if (empty($id) || empty($fileName) || empty($workerLink)) {
        echo json_encode(['status' => 'fail', 'msg' => 'Missing required data']);
        exit;
    }
    
    // Generate alt links for single episode
    $altLinks = getAltLinksAll($origUrl, $gdflix_api_key, $filepress_key, $gcloud_api_key);
    addDownloadLink($id, $fileName, $fileType, $fileSize, $workerLink, $altLinks);
    
    echo json_encode([
        'status' => 'ok',
        'altLinks' => $altLinks
    ]);
    exit;
}
// --- AJAX HANDLER: Update/add R2 link for episode ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update_r2') {
    $in = json_decode(file_get_contents("php://input"), true);
    $id = $in['id'] ?? null;
    $r2url = $in['r2url'] ?? '';
    if ($id && $r2url) {
        $data = file_exists($downloads_json) ? json_decode(file_get_contents($downloads_json), true) : [];
        if (isset($data[$id])) {
            $data[$id]['altLinks']['R2'] = $r2url;
            file_put_contents($downloads_json, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo json_encode(['status'=>'ok']);
        } else {
            echo json_encode(['status'=>'fail','msg'=>'Unknown episode id']);
        }
    } else {
        echo json_encode(['status'=>'fail','msg'=>'Missing data']);
    }
    exit;
}
// ================= ADMIN PANEL UI =================
function showSeriesPanel($worker_api) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NoMovies Series Panel</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: 'Space Grotesk', Arial, sans-serif; background: #14192e; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #1e263b; padding: 26px 22px 24px 22px; border-radius: 13px; width: 95vw; max-width: 550px; box-shadow: 0 4px 24px #13ffe21a; text-align: center; }
    input, textarea { width: 94%; margin: 8px 0 7px 0; padding: 12px; font-size: 16px; border-radius: 7px; border: 1.8px solid #232d55; background:#0b0c1a; color:#fff;}
    button { padding: 11px 26px; font-size: 17px; background: linear-gradient(92deg,#00f5ff 0%,#9d4edd 100%); color: #0b0c1a; border: none; border-radius: 7px; cursor: pointer; font-family: 'Space Grotesk'; font-weight:700;}
    button:hover { background: linear-gradient(91deg,#9d4edd,#00f5ff 100%); }
    h1 { color: #00f5ff; text-shadow: 0 0 11px #9d4edd70; margin-bottom: 9px; }
    h2 { color: #00f5ff; font-size: 1.2em; margin: 20px 0 10px 0; }
    ul { text-align:left; color:#fff;}
    hr { border: none; border-top: 1.4px solid #222d46; margin:24px 2px;}
    .generated-link { margin-top: 11px; color:#fff; }
    .logout { margin-top: 20px; }
    .mirror-status {font-size:.95em; color:#00f5ffe8; margin-top:13px;}
    a,a:visited{color:#9d4edd;}
    .series-structure { background: #0e162e; border-radius: 9px; padding: 15px; margin: 15px 0; text-align: left; }
    .series-structure h4 { color: #00f5ff; margin: 0 0 10px 0; }
    .season-group { margin: 10px 0; }
    .season-title { color: #9d4edd; font-weight: bold; }
    .episode-item { margin: 5px 0; font-size: 0.9em; }
    .episode-item a { color: #1effa3; }
    .structure-type { color: #ffacee; font-size: 0.9em; text-transform: uppercase; }
  </style>
</head>
<body>
<div class="box">
  <h1>NoMovies - Series Panel</h1>
  
  <form id="series-form">
    <input type="text" name="series-title" placeholder="Series Title (optional - will be auto-detected)"><br>
    <input type="url" name="folder-url" placeholder="Enter Google Drive folder link containing episodes" required><br>
    <button type="submit">Process TV Series Folder</button>
  </form>
  
  <hr>
  
  <h2>Single Episode Upload</h2>
  <form id="single-episode-form">
    <input type="url" name="url" placeholder="Enter Google Drive episode link" required><br>
    <input type="text" name="filename" placeholder="Optional: Episode filename"><br>
    <button type="submit">Generate Episode Download</button>
  </form>
  
  <div id="result"></div>
  <div class="logout"><a href="?logout=1" style="color:#ffacee;">Logout</a></div>
</div>

<script>
const resultDiv = document.getElementById('result');

// Sorting helper functions
function getResolutionOrder(name) {
    if (!name) return Infinity;
    if (name.match(/480p/i)) return 1;
    if (name.match(/720p/i)) return 2;
    if (name.match(/1080p/i)) return 3;
    if (name.match(/1440p|2k/i)) return 4;
    if (name.match(/2160p|4k/i)) return 5;
    return 6;
}

function getCodecOrder(name) {
    if (!name) return Infinity;
    if (name.match(/x265|hevc/i)) return 1;
    if (name.match(/x264/i)) return 2;
    return 3;
}

function compareEpisodes(a, b) {
    // First by resolution
    const resA = getResolutionOrder(a.fileName);
    const resB = getResolutionOrder(b.fileName);
    if (resA !== resB) return resA - resB;

    // Then by codec (HEVC/x265 first)
    const codecA = getCodecOrder(a.fileName);
    const codecB = getCodecOrder(b.fileName);
    if (codecA !== codecB) return codecA - codecB;

    // Finally by file size (smaller first)
    const sizeA = parseInt(a.fileSize) || 0;
    const sizeB = parseInt(b.fileSize) || 0;
    return sizeA - sizeB;
}

async function saveSeries(title, files) {
    const res = await fetch('series.php?action=save_series', {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ seriesTitle: title, files: files })
    });
    return await res.json();
}

async function saveDownload(meta, origUrl) {
    const res = await fetch('series.php?action=save_download', {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({...meta, origUrl})
    });
    return await res.json();
}

function displaySeriesStructure(structure, title) {
    let html = `<div class="series-structure">
        <h4>${title} <span class="structure-type">[${structure.type}]</span></h4>`;
    
    if (structure.seasons && Object.keys(structure.seasons).length > 0) {
        for (const [seasonNum, episodes] of Object.entries(structure.seasons)) {
            html += `<div class="season-group">
                <div class="season-title">Season ${seasonNum}:</div>`;
            
            episodes.forEach(ep => {
                // Check if it's a ZIP file to display "Zip:" instead of "Episode X:"
                const isZipFile = /\.(zip|7z|rar)$/i.test(ep.fileName);
                const episodeLabel = isZipFile ? 'Zip' : `Episode ${ep.episode}`;
                
                html += `<div class="episode-item">
                    ${episodeLabel}: <a href="download.php?id=${ep.id}" target="_blank">${ep.fileName}</a>
                    <span style="color:#888;"> [${ep.resolution}, ${ep.codec}]</span>
                </div>`;
            });
            
            html += `</div>`;
        }
    }
    
    html += `</div>`;
    return html;
}

// Series folder form - Enhanced with recursive folder support
document.getElementById('series-form').onsubmit = async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = "<p style='color:#00f5ff;'>Analyzing folder structure...</p>";
    
    const seriesTitle = this['series-title'].value.trim();
    const folderUrl = this['folder-url'].value.trim();
    
    try {
        // Use enhanced worker API for recursive folder analysis
        const formData = new FormData();
        formData.append('url', folderUrl);
        formData.append('depth', 'recursive'); // Enable recursive traversal
        
        const res = await fetch('<?=$worker_api?>/gdrive/folder-tree', { method: "POST", body: formData });
        const response = await res.json();
        
        if (!response.success || !response.files || response.files.length === 0) {
            // Fallback to old submit endpoint if new one fails
            resultDiv.innerHTML = "<p style='color:#ffac33;'>⚠️ Using fallback processing...</p>";
            return await processFolderFallback(seriesTitle, folderUrl);
        }
        
        const folderFiles = response.files;
        const metadata = response.metadata;
        
        // Enhanced status with structure detection
        resultDiv.innerHTML = `
            <p style='color:#00f5ff;'>
                📁 Found ${folderFiles.length} files<br>
                📂 Structure: <strong>${metadata.structureType.toUpperCase()}</strong><br>
                🔄 Processing episodes...
            </p>
        `;
        
        // Sort files by episode order with enhanced metadata
        folderFiles.sort(compareEpisodesEnhanced);
        
        // Add original URLs for mirror generation (use individual file URLs now)
        folderFiles.forEach(file => {
            file.originalUrl = file.originalUrl || folderUrl;
        });
        
        // Save series with organized structure
        const saveRes = await saveSeries(seriesTitle, folderFiles);
        
        if (saveRes.status === 'ok') {
            const finalTitle = saveRes.title || seriesTitle || 'Untitled Series';
            resultDiv.innerHTML = `
                <div class="generated-link">
                    ✅ <strong>TV Series Processed Successfully!</strong><br>
                    <span style="color:#1effa3;">${folderFiles.length} episodes organized</span><br>
                    <span style="color:#9d4edd;">Structure: ${metadata.structureType} ${metadata.hasNestedFolders ? '(nested folders detected)' : '(flat structure)'}</span>
                    ${!seriesTitle.trim() ? '<br><span style="color:#ffacee;">Title auto-detected: ' + finalTitle + '</span>' : ''}
                </div>
                ${displaySeriesStructure(saveRes.structure, finalTitle)}
            `;
            
            // Show export button
            showSeriesExportButton(saveRes.structure, finalTitle, resultDiv);
        } else {
            resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${saveRes.msg}</p>`;
        }
        
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${error.message}</p>`;
        console.error('Series processing error:', error);
    }
};

// Fallback function for when enhanced API fails
async function processFolderFallback(seriesTitle, folderUrl) {
    try {
        const formData = new FormData();
        formData.append('url', folderUrl);
        
        const res = await fetch('<?=$worker_api?>/submit', { method: "POST", body: formData });
        const folderFiles = await res.json();
        
        if (!Array.isArray(folderFiles) || folderFiles.length === 0) {
            resultDiv.innerHTML = "<p style='color:#ff6b6b;'>❌ No episodes found in folder</p>";
            return;
        }
        
        // Sort files by episode order
        folderFiles.sort(compareEpisodes);
        
        folderFiles.forEach(file => {
            file.originalUrl = folderUrl;
        });
        
        const saveRes = await saveSeries(seriesTitle, folderFiles);
        
        if (saveRes.status === 'ok') {
            const finalTitle = saveRes.title || seriesTitle || 'Untitled Series';
            resultDiv.innerHTML = `
                <div class="generated-link">
                    ✅ <strong>TV Series Processed (Flat Structure)!</strong><br>
                    <span style="color:#1effa3;">${folderFiles.length} episodes organized</span>
                    ${!seriesTitle.trim() ? '<br><span style="color:#ffacee;">Title auto-detected: ' + finalTitle + '</span>' : ''}
                </div>
                ${displaySeriesStructure(saveRes.structure, finalTitle)}
            `;
            
            // Show export button
            showSeriesExportButton(saveRes.structure, finalTitle, resultDiv);
        } else {
            resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${saveRes.msg}</p>`;
        }
        
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Fallback failed: ${error.message}</p>`;
    }
}

// Enhanced episode comparison with folder path support
function compareEpisodesEnhanced(a, b) {
    // First compare by folder path (for season ordering)
    const pathA = a.folderPath || '';
    const pathB = b.folderPath || '';
    
    if (pathA !== pathB) {
        // Extract season numbers from paths for proper ordering
        const seasonA = extractSeasonFromPath(pathA);
        const seasonB = extractSeasonFromPath(pathB);
        if (seasonA !== seasonB) return seasonA - seasonB;
        return pathA.localeCompare(pathB);
    }
    
    // Then use existing resolution/codec/size logic
    return compareEpisodes(a, b);
}

function extractSeasonFromPath(path) {
    const seasonMatch = path.match(/season\s*(\d+)|s(\d+)/i);
    if (seasonMatch) {
        return parseInt(seasonMatch[1] || seasonMatch[2]);
    }
    return 0; // Default for root files
}

// Single episode form (same as movie panel)
document.getElementById('single-episode-form').onsubmit = async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = "<p style='color:#00f5ff;'>Processing episode...</p>";
    const formData = new FormData();
    formData.append('url', this.url.value);
    formData.append('filename', this.filename.value);

    try {
        const res = await fetch('<?=$worker_api?>/submit', { method: "POST", body: formData });
        const meta = await res.json();
        
        // For single episodes, use the regular movie download system
        const saveRes = await saveDownload(meta, this.url.value);
        
        if (saveRes && saveRes.status === 'ok') {
            const mirrors = saveRes.altLinks;
            let mirrorStatus = "<div class='mirror-status'><strong>🔗 Mirror Links Generated:</strong><br>";
            if (mirrors.GDFLIX) mirrorStatus += "✅ GDFlix<br>";
            if (mirrors.FilePress) mirrorStatus += "✅ FilePress<br>";
            if (mirrors.GCLOUD) mirrorStatus += "✅ GCloud<br>";
            mirrorStatus += "</div>";
            
            resultDiv.innerHTML = `
                <div class="generated-link">
                    ✅ <strong>Episode Download Page Generated!</strong><br>
                    <a href="download.php?id=${meta.id}" target="_blank" style="color:#1effa3;font-weight:bold;">
                        ${meta.fileName}
                    </a>
                    ${mirrorStatus}
                </div>
            `;
        }
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${error.message}</p>`;
    }
};

// Export functionality for series
function formatFileSize(bytes) {
    if (!bytes || isNaN(bytes)) return '';
    
    const size = parseInt(bytes);
    
    if (size >= 1024 * 1024 * 1024) {
        // Convert to GB
        const gb = (size / (1024 * 1024 * 1024)).toFixed(1);
        return `${gb} GB`;
    } else if (size >= 1024 * 1024) {
        // Convert to MB
        const mb = Math.round(size / (1024 * 1024));
        return `${mb} MB`;
    } else if (size >= 1024) {
        // Convert to KB
        const kb = Math.round(size / 1024);
        return `${kb} KB`;
    } else {
        return `${size} B`;
    }
}

function formatSeriesExportTitle(fileName, fileSize, season, episode, seriesTitle) {
    // Check if it's a ZIP file
    const isZipFile = /\.(zip|7z|rar)$/i.test(fileName);
    
    // Remove file extension
    let title = fileName.replace(/\.(mkv|mp4|avi|mov|wmv|flv|webm|m4v|zip|7z|rar)$/i, '');
    
    // For ZIP files, create a "Zip/Pack" entry
    if (isZipFile) {
        let zipTitle = `${seriesTitle} S${String(season).padStart(2, '0')} Zip/Pack`;
        
        // Extract resolution if available in filename
        const resMatch = title.match(/\b(480p|720p|1080p|1440p|2160p|4k|uhd)\b/i);
        if (resMatch) {
            const resolution = resMatch[1].toLowerCase() === '4k' || resMatch[1].toLowerCase() === 'uhd' ? '2160p' : resMatch[1];
            zipTitle += ` ${resolution}`;
        }
        
        // Add file size if available
        if (fileSize) {
            const readableSize = formatFileSize(fileSize);
            if (readableSize) {
                zipTitle += ` [${readableSize}]`;
            }
        }
        
        return zipTitle;
    }
    
    // For regular episodes
    let episodeTitle = `${seriesTitle} S${String(season).padStart(2, '0')}E${String(episode).padStart(2, '0')}`;
    
    // Extract resolution
    const resMatch = title.match(/\b(480p|720p|1080p|1440p|2160p|4k|uhd)\b/i);
    if (resMatch) {
        const resolution = resMatch[1].toLowerCase() === '4k' || resMatch[1].toLowerCase() === 'uhd' ? '2160p' : resMatch[1];
        episodeTitle += ` ${resolution}`;
    }
    
    // Extract source
    const sourceMatch = title.match(/\b(BluRay|BRRip|DVDRip|WEB-DL|WebRip|HDTV|CAM|TS|TC)\b/i);
    if (sourceMatch) {
        episodeTitle += ` ${sourceMatch[1]}`;
    }
    
    // Extract codec
    const codecMatch = title.match(/\b(x264|x265|H\.264|H\.265|HEVC|AVC)\b/i);
    if (codecMatch) {
        let detectedCodec = codecMatch[1];
        // Normalize codec names
        if (detectedCodec.toLowerCase() === 'h.264' || detectedCodec.toLowerCase() === 'avc') {
            episodeTitle += ' x264';
        } else if (detectedCodec.toLowerCase() === 'h.265' || detectedCodec.toLowerCase() === 'hevc') {
            episodeTitle += ' x265';
        } else {
            episodeTitle += ` ${detectedCodec}`;
        }
    }
    
    // Add file size if available
    if (fileSize) {
        const readableSize = formatFileSize(fileSize);
        if (readableSize) {
            episodeTitle += ` [${readableSize}]`;
        }
    }
    
    return episodeTitle;
}

function generateSeriesExportCode(seriesStructure, seriesTitle, baseUrl = null) {
    if (!baseUrl) {
            // get the folder path (e.g. "/new/")
    const path = window.location.pathname.replace(/[^/]+$/, '');
    baseUrl = window.location.origin + path;
    }
    
    const exportLines = [];
    
    // Helper function to normalize resolution values (handle aliases and clean up)
    function normalizeResolution(resolution) {
        if (!resolution) return '';
        const cleaned = resolution.trim().replace(/\s+/g, ' ').toLowerCase();
        
        // Handle aliases
        const aliases = {
            '4k': '2160p',
            'uhd': '2160p',
            'fhd': '1080p',
            'hd': '720p'
        };
        
        return aliases[cleaned] || cleaned;
    }
    
    // Helper function to normalize codec values
    function normalizeCodec(codec) {
        if (!codec) return '';
        const cleaned = codec.trim().replace(/\s+/g, ' ').toLowerCase();
        
        // Handle aliases
        const aliases = {
            'h.264': 'x264',
            'avc': 'x264',
            'h.265': 'x265',
            'hevc': 'x265'
        };
        
        return aliases[cleaned] || cleaned;
    }
    
    // Helper function to format file size (fallback if formatFileSize not available)
    function safeFormatFileSize(bytes) {
        if (typeof formatFileSize === 'function') {
            return formatFileSize(bytes);
        }
        
        // Fallback implementation
        if (!bytes || bytes <= 0) return '';
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Helper function to clean and format resolution labels
    function formatResolutionLabel(resolution, codec, fileSize) {
        // Normalize and clean values
        const cleanResolution = normalizeResolution(resolution);
        const cleanCodec = normalizeCodec(codec);
        
        // Build label components (skip unknown values)
        const parts = [];
        if (cleanResolution && cleanResolution !== 'unknown') parts.push(cleanResolution);
        if (cleanCodec && cleanCodec !== 'unknown') parts.push(cleanCodec);
        
        let label = parts.join(' ').trim();
        if (!label) label = 'Download'; // Fallback if no resolution/codec info
        
        // Add file size if available
        if (fileSize) {
            const readableSize = safeFormatFileSize(fileSize);
            if (readableSize) {
                label += ` (${readableSize})`;
            }
        }
        
        return label;
    }
    
    // Helper function to sort resolutions and codecs properly
    function sortResolutions(a, b) {
        const resOrder = { '2160p': 5, '1440p': 4, '1080p': 3, '720p': 2, '480p': 1, '360p': 0 };
        const codecOrder = { 'x265': 2, 'hevc': 2, 'x264': 1, 'h264': 1, 'avc': 1 };
        
        // Normalize values before sorting
        const aRes = normalizeResolution(a.resolution);
        const bRes = normalizeResolution(b.resolution);
        const aCodec = normalizeCodec(a.codec);
        const bCodec = normalizeCodec(b.codec);
        
        const aResScore = resOrder[aRes] || -1;
        const bResScore = resOrder[bRes] || -1;
        
        if (aResScore !== bResScore) return bResScore - aResScore; // Higher resolution first
        
        const aCodecScore = codecOrder[aCodec] || -1;
        const bCodecScore = codecOrder[bCodec] || -1;
        
        return bCodecScore - aCodecScore; // Better codec first
    }
    
    // Process each season
    if (seriesStructure.seasons && Object.keys(seriesStructure.seasons).length > 0) {
        // Sort seasons numerically
        const sortedSeasons = Object.keys(seriesStructure.seasons).sort((a, b) => parseInt(a) - parseInt(b));
        
        sortedSeasons.forEach(seasonNum => {
            const episodes = seriesStructure.seasons[seasonNum];
            
            // Add season title (DooPlay parser expects lines starting with "Season")
            exportLines.push(`Season ${seasonNum} - ${seriesTitle}`);
            exportLines.push(''); // Empty line
            
            // Group episodes by type: ZIP files first, then individual episodes
            const zipEpisodes = [];
            const regularEpisodes = [];
            
            episodes.forEach(ep => {
                const isZipFile = /\.(zip|7z|rar)$/i.test(ep.fileName);
                if (isZipFile) {
                    zipEpisodes.push(ep);
                } else {
                    regularEpisodes.push(ep);
                }
            });
            
            // Process ZIP/Pack files first (complete season downloads)
            if (zipEpisodes.length > 0) {
                exportLines.push(`Zip/Pack S${String(seasonNum).padStart(2, '0')}`);
                
                // Sort ZIP files by resolution and codec
                zipEpisodes.sort(sortResolutions);
                
                zipEpisodes.forEach(ep => {
                    const resolutionLabel = formatResolutionLabel(ep.resolution, ep.codec, ep.fileSize);
                    exportLines.push(`${resolutionLabel} ${baseUrl}download.php?id=${ep.id}`);
                });
                exportLines.push(''); // Empty line after ZIP section
            }
            
            // Process individual episodes - group by episode number
            const episodeGroups = {};
            regularEpisodes.forEach(ep => {
                if (!episodeGroups[ep.episode]) {
                    episodeGroups[ep.episode] = [];
                }
                episodeGroups[ep.episode].push(ep);
            });
            
            // Sort episodes numerically and process each group
            const sortedEpisodeNums = Object.keys(episodeGroups).sort((a, b) => parseInt(a) - parseInt(b));
            
            sortedEpisodeNums.forEach(episodeNum => {
                const episodeFiles = episodeGroups[episodeNum];
                
                // Single episode header per group
                exportLines.push(`Episode ${episodeNum}`);
                
                // Sort episode files by resolution and codec
                episodeFiles.sort(sortResolutions);
                
                // Add all resolution variants for this episode
                episodeFiles.forEach(ep => {
                    const resolutionLabel = formatResolutionLabel(ep.resolution, ep.codec, ep.fileSize);
                    exportLines.push(`${resolutionLabel} ${baseUrl}download.php?id=${ep.id}`);
                });
                exportLines.push(''); // Empty line after each episode
            });
        });
    }
    
    // Ensure final newline for proper text format
    return `---BEGIN NOMOVIES SERIES EXPORT---
${exportLines.join('\n')}
---END NOMOVIES SERIES EXPORT---`;
}

function showSeriesExportButton(seriesStructure, seriesTitle, containerElement) {
    const exportContainer = document.createElement('div');
    exportContainer.style.marginTop = '20px';
    exportContainer.style.padding = '15px';
    exportContainer.style.background = '#0e162e';
    exportContainer.style.border = '2px solid #9d4edd';
    exportContainer.style.borderRadius = '8px';
    
    const exportCode = generateSeriesExportCode(seriesStructure, seriesTitle);
    
    // Count total episodes
    let totalFiles = 0;
    if (seriesStructure.seasons) {
        Object.values(seriesStructure.seasons).forEach(episodes => {
            totalFiles += episodes.length;
        });
    }
    
    // Create header
    const header = document.createElement('div');
    header.style.color = '#9d4edd';
    header.style.fontWeight = 'bold';
    header.style.marginBottom = '10px';
    header.textContent = `📺 WordPress Plugin Export (${totalFiles} episodes)`;
    
    // Create description
    const description = document.createElement('div');
    description.style.color = '#fff';
    description.style.marginBottom = '8px';
    description.style.fontSize = '14px';
    description.textContent = `Copy this code and paste it into your WordPress plugin for "${seriesTitle}":`;
    
    // Create flex container for textarea and button
    const flexContainer = document.createElement('div');
    flexContainer.style.display = 'flex';
    flexContainer.style.alignItems = 'center';
    flexContainer.style.gap = '10px';
    
    // Create textarea
    const textarea = document.createElement('textarea');
    textarea.id = `series-export-code-${Date.now()}`;
    textarea.readOnly = true;
    textarea.value = exportCode;
    textarea.style.flex = '1';
    textarea.style.height = '60px';
    textarea.style.padding = '8px';
    textarea.style.background = '#1e263b';
    textarea.style.color = '#fff';
    textarea.style.border = '1px solid #9d4edd';
    textarea.style.borderRadius = '4px';
    textarea.style.fontFamily = 'monospace';
    textarea.style.fontSize = '12px';
    textarea.style.resize = 'vertical';
    
    // Create copy button
    const copyButton = document.createElement('button');
    copyButton.className = 'copy-series-export-btn';
    copyButton.style.padding = '15px';
    copyButton.style.background = '#9d4edd';
    copyButton.style.color = '#fff';
    copyButton.style.border = 'none';
    copyButton.style.borderRadius = '4px';
    copyButton.style.cursor = 'pointer';
    copyButton.style.fontWeight = 'bold';
    copyButton.style.whiteSpace = 'nowrap';
    copyButton.innerHTML = '📋 Copy<br>Export Code';
    
    // Create status div
    const statusDiv = document.createElement('div');
    statusDiv.style.marginTop = '10px';
    statusDiv.style.color = '#1effa3';
    statusDiv.style.fontSize = '14px';
    statusDiv.style.display = 'none';
    statusDiv.textContent = '✓ Export code copied to clipboard!';
    
    // Copy functionality
    copyButton.onclick = async function() {
        try {
            await navigator.clipboard.writeText(exportCode);
            statusDiv.style.display = 'block';
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 3000);
        } catch (err) {
            console.error('Failed to copy export code:', err);
            // Fallback: select the textarea content
            textarea.select();
            textarea.setSelectionRange(0, 99999);
            try {
                document.execCommand('copy');
                statusDiv.textContent = '✓ Export code copied to clipboard!';
                statusDiv.style.display = 'block';
                setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 3000);
            } catch (fallbackErr) {
                statusDiv.textContent = '❌ Failed to copy. Please copy manually.';
                statusDiv.style.color = '#ff6b6b';
                statusDiv.style.display = 'block';
            }
        }
    };
    
    flexContainer.appendChild(textarea);
    flexContainer.appendChild(copyButton);
    
    exportContainer.appendChild(header);
    exportContainer.appendChild(description);
    exportContainer.appendChild(flexContainer);
    exportContainer.appendChild(statusDiv);
    
    containerElement.appendChild(exportContainer);
}

// Series folder form - Enhanced with recursive folder support
document.getElementById('series-form').onsubmit = async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = "<p style='color:#00f5ff;'>Analyzing folder structure...</p>";
    
    const seriesTitle = this['series-title'].value.trim();
    const folderUrl = this['folder-url'].value.trim();
    
    try {
        // Use enhanced worker API for recursive folder analysis
        const formData = new FormData();
        formData.append('url', folderUrl);
        formData.append('depth', 'recursive'); // Enable recursive traversal
        
        const res = await fetch('<?=$worker_api?>/gdrive/folder-tree', { method: "POST", body: formData });
        const response = await res.json();
        
        if (!response.success || !response.files || response.files.length === 0) {
            // Fallback to old submit endpoint if new one fails
            resultDiv.innerHTML = "<p style='color:#ffac33;'>⚠️ Using fallback processing...</p>";
            return await processFolderFallback(seriesTitle, folderUrl);
        }
        
        const folderFiles = response.files;
        const metadata = response.metadata;
        
        // Enhanced status with structure detection
        resultDiv.innerHTML = `
            <p style='color:#00f5ff;'>
                📁 Found ${folderFiles.length} files<br>
                📂 Structure: <strong>${metadata.structureType.toUpperCase()}</strong><br>
                🔄 Processing episodes...
            </p>
        `;
        
        // Sort files by episode order with enhanced metadata
        folderFiles.sort(compareEpisodesEnhanced);
        
        // Add original URLs for mirror generation (use individual file URLs now)
        folderFiles.forEach(file => {
            file.originalUrl = file.originalUrl || folderUrl;
        });
        
        // Save series with organized structure
        const saveRes = await saveSeries(seriesTitle, folderFiles);
        
        if (saveRes.status === 'ok') {
            const finalTitle = saveRes.title || seriesTitle || 'Untitled Series';
            resultDiv.innerHTML = `
                <div class="generated-link">
                    ✅ <strong>TV Series Processed Successfully!</strong><br>
                    <span style="color:#1effa3;">${folderFiles.length} episodes organized</span><br>
                    <span style="color:#9d4edd;">Structure: ${metadata.structureType} ${metadata.hasNestedFolders ? '(nested folders detected)' : '(flat structure)'}</span>
                    ${!seriesTitle.trim() ? '<br><span style="color:#ffacee;">Title auto-detected: ' + finalTitle + '</span>' : ''}
                </div>
                ${displaySeriesStructure(saveRes.structure, finalTitle)}
            `;
            
            // Show export button
            showSeriesExportButton(saveRes.structure, finalTitle, resultDiv);
        } else {
            resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${saveRes.msg}</p>`;
        }
        
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${error.message}</p>`;
        console.error('Series processing error:', error);
    }
};

// Fallback function for when enhanced API fails
async function processFolderFallback(seriesTitle, folderUrl) {
    try {
        const formData = new FormData();
        formData.append('url', folderUrl);
        
        const res = await fetch('<?=$worker_api?>/submit', { method: "POST", body: formData });
        const folderFiles = await res.json();
        
        if (!Array.isArray(folderFiles) || folderFiles.length === 0) {
            resultDiv.innerHTML = "<p style='color:#ff6b6b;'>❌ No episodes found in folder</p>";
            return;
        }
        
        // Sort files by episode order
        folderFiles.sort(compareEpisodes);
        
        folderFiles.forEach(file => {
            file.originalUrl = folderUrl;
        });
        
        const saveRes = await saveSeries(seriesTitle, folderFiles);
        
        if (saveRes.status === 'ok') {
            const finalTitle = saveRes.title || seriesTitle || 'Untitled Series';
            resultDiv.innerHTML = `
                <div class="generated-link">
                    ✅ <strong>TV Series Processed (Flat Structure)!</strong><br>
                    <span style="color:#1effa3;">${folderFiles.length} episodes organized</span>
                    ${!seriesTitle.trim() ? '<br><span style="color:#ffacee;">Title auto-detected: ' + finalTitle + '</span>' : ''}
                </div>
                ${displaySeriesStructure(saveRes.structure, finalTitle)}
            `;
        } else {
            resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${saveRes.msg}</p>`;
        }
        
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Fallback failed: ${error.message}</p>`;
    }
}

// Enhanced episode comparison with folder path support
function compareEpisodesEnhanced(a, b) {
    // First compare by folder path (for season ordering)
    const pathA = a.folderPath || '';
    const pathB = b.folderPath || '';
    
    if (pathA !== pathB) {
        // Extract season numbers from paths for proper ordering
        const seasonA = extractSeasonFromPath(pathA);
        const seasonB = extractSeasonFromPath(pathB);
        if (seasonA !== seasonB) return seasonA - seasonB;
        return pathA.localeCompare(pathB);
    }
    
    // Then use existing resolution/codec/size logic
    return compareEpisodes(a, b);
}

function extractSeasonFromPath(path) {
    const seasonMatch = path.match(/season\s*(\d+)|s(\d+)/i);
    if (seasonMatch) {
        return parseInt(seasonMatch[1] || seasonMatch[2]);
    }
    return 0; // Default for root files
}

// Single episode form (same as movie panel)
document.getElementById('single-episode-form').onsubmit = async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = "<p style='color:#00f5ff;'>Processing episode...</p>";
    const formData = new FormData();
    formData.append('url', this.url.value);
    formData.append('filename', this.filename.value);

    try {
        const res = await fetch('<?=$worker_api?>/submit', { method: "POST", body: formData });
        const meta = await res.json();
        
        // For single episodes, use the regular movie download system
        const saveRes = await saveDownload(meta, this.url.value);
        
        if (saveRes && saveRes.status === 'ok') {
            const mirrors = saveRes.altLinks;
            let mirrorStatus = "<div class='mirror-status'><strong>🔗 Mirror Links Generated:</strong><br>";
            if (mirrors.GDFLIX) mirrorStatus += "✅ GDFlix<br>";
            if (mirrors.FilePress) mirrorStatus += "✅ FilePress<br>";
            if (mirrors.GCLOUD) mirrorStatus += "✅ GCloud<br>";
            mirrorStatus += "</div>";
            
            resultDiv.innerHTML = `
                <div class="generated-link">
                    ✅ <strong>Episode Download Page Generated!</strong><br>
                    <a href="download.php?id=${meta.id}" target="_blank" style="color:#1effa3;font-weight:bold;">
                        ${meta.fileName}
                    </a>
                    ${mirrorStatus}
                </div>
            `;
        }
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${error.message}</p>`;
    }
};
</script>
</body>
</html>
<?php
}

// ================= MAIN LOGIC =================
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>NoMovies Series Panel Login</title>
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <style>
        body { font-family: 'Space Grotesk', Arial, sans-serif; background: #14192e; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-box { background: #1e263b; padding: 30px; border-radius: 13px; width: 100%; max-width: 350px; box-shadow: 0 4px 24px #13ffe21a; text-align: center; }
        input { width: 90%; margin: 10px 0; padding: 12px; font-size: 16px; border-radius: 7px; border: 1.8px solid #232d55; background: #0b0c1a; color: #fff; }
        button { padding: 12px 30px; font-size: 17px; background: linear-gradient(92deg,#00f5ff 0%,#9d4edd 100%); color: #0b0c1a; border: none; border-radius: 7px; cursor: pointer; font-weight: 700; }
        button:hover { background: linear-gradient(91deg,#9d4edd,#00f5ff 100%); }
        h1 { color: #00f5ff; text-shadow: 0 0 11px #9d4edd70; margin-bottom: 20px; }
        .error { color: #ff6b6b; margin-top: 10px; }
        .remember { color: #fff; font-size: 14px; margin: 10px 0; }
      </style>
    </head>
    <body>
      <div class="login-box">
        <h1>Series Panel</h1>
        <form method="POST">
          <input type="password" name="password" placeholder="Enter password" required>
          <div class="remember">
            <input type="checkbox" name="remember-me" id="remember"> 
            <label for="remember">Remember me for 7 days</label>
          </div>
          <button type="submit">Login</button>
        </form>
        <?php if (isset($error)): ?>
          <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
      </div>
    </body>
    </html>
    <?php
} else {
    // Show series panel
    showSeriesPanel($worker_api);
}
?>