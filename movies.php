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
$movies_json = "movies.json";
// ================== HELPER FUNCTIONS ==================
// Extract Drive ID from any GDrive link
function extractDriveId($url) {
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) return $m[1];
    if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $m)) return $m[1];
    return null;
}
// GDFLIX API
function getGDFLIXLink($driveId, $gdflix_api_key) {
    $endpoint = "https://ddflix.xyz/v2/share?id=" . urlencode($driveId) . "&key=" . urlencode($gdflix_api_key);
    
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
            'url'  => "https://ddflix.xyz/file/" . $result['key'],
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
// Storage/update util
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

// Save movie structure data to movies.json
function addMovie($movieId, $movieTitle, $movieData) {
    global $movies_json;
    
    error_log("DEBUG: addMovie called with ID: $movieId, Title: $movieTitle");
    error_log("DEBUG: movies_json path: $movies_json");
    error_log("DEBUG: movieData: " . json_encode($movieData));
    
    $data = file_exists($movies_json) ? json_decode(file_get_contents($movies_json), true) : [];
    
    error_log("DEBUG: Existing data count: " . count($data));
    
    $data[$movieId] = [
        'title' => $movieTitle,
        'created' => date('Y-m-d H:i:s'),
        'updated' => date('Y-m-d H:i:s'),
        'structure' => $movieData
    ];
    
    $result = file_put_contents($movies_json, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    if ($result === false) {
        error_log("ERROR: Failed to write to $movies_json");
    } else {
        error_log("SUCCESS: Written $result bytes to $movies_json");
        error_log("DEBUG: File exists after write: " . (file_exists($movies_json) ? 'YES' : 'NO'));
    }
}
// ================= LOGIN/HANDLERS/ADMIN UI =================
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $CORRECT_PASSWORD) {
        $_SESSION['admin'] = true;
        if (isset($_POST['remember-me'])) {
            setcookie("rememberMeToken", $CORRECT_PASSWORD, time() + (86400 * 7), "/");
        }
        header("Location: movie.php");
        exit;
    } else {
        $error = "Incorrect password.";
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie("rememberMeToken", "", time() - 3600, "/");
    header("Location: movie.php");
    exit;
}
if (isset($_COOKIE['rememberMeToken']) && $_COOKIE['rememberMeToken'] === $CORRECT_PASSWORD) {
    $_SESSION['admin'] = true;
}
// --- AJAX HANDLER: Save link + generate mirrors ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_download') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id         = $data['id'] ?? null;
    $fileName   = $data['fileName'] ?? '';
    $fileType   = $data['fileType'] ?? '';
    $fileSize   = $data['fileSize'] ?? '';
    $workerLink = $data['workerLink'] ?? '';
    $origUrl    = $data['origUrl'] ?? '';
    
    $altLinks = getAltLinksAll($origUrl, $gdflix_api_key, $filepress_key, $gcloud_api_key);
    
    addDownloadLink($id, $fileName, $fileType, $fileSize, $workerLink, $altLinks);
    echo json_encode(["status"=>"ok","altLinks"=>$altLinks]);
    exit;
}
// --- AJAX HANDLER: Update/add R2 link only for a file (NEW)
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
            echo json_encode(['status'=>'fail','msg'=>'Unknown fileid']);
        }
    } else {
        echo json_encode(['status'=>'fail','msg'=>'Missing data']);
    }
    exit;
}

// --- AJAX HANDLER: Save movie structure data ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_movie_structure') {
    error_log("DEBUG: save_movie_structure AJAX handler called");
    
    $data = json_decode(file_get_contents("php://input"), true);
    error_log("DEBUG: Received data: " . json_encode($data));
    
    $movieId = $data['movieId'] ?? null;
    $movieTitle = $data['movieTitle'] ?? '';
    $movieData = $data['movieData'] ?? [];
    
    error_log("DEBUG: Parsed - ID: $movieId, Title: $movieTitle, DataEmpty: " . (empty($movieData) ? 'YES' : 'NO'));
    
    if ($movieId && $movieTitle && !empty($movieData)) {
        error_log("DEBUG: Calling addMovie function...");
        addMovie($movieId, $movieTitle, $movieData);
        echo json_encode([
            "status" => "ok", 
            "message" => "Movie structure saved",
            "movieId" => $movieId,
            "movieTitle" => $movieTitle
        ]);
    } else {
        error_log("ERROR: Invalid movie data - missing required fields");
        echo json_encode(["status" => "error", "message" => "Invalid movie data"]);
    }
    exit;
}

// ================= ADMIN PANEL UI =================
function showAdminPanel($worker_api) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>NoMovies Movie Panel</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: 'Space Grotesk', Arial, sans-serif; background: #14192e; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #1e263b; padding: 26px 22px 24px 22px; border-radius: 13px; width: 95vw; max-width: 470px; box-shadow: 0 4px 24px #13ffe21a; text-align: center; }
    input, textarea { width: 94%; margin: 8px 0 7px 0; padding: 12px; font-size: 16px; border-radius: 7px; border: 1.8px solid #232d55; background:#0b0c1a; color:#fff;}
    button { padding: 11px 26px; font-size: 17px; background: linear-gradient(92deg,#00f5ff 0%,#9d4edd 100%); color: #0b0c1a; border: none; border-radius: 7px; cursor: pointer; font-family: 'Space Grotesk'; font-weight:700;}
    button:hover { background: linear-gradient(91deg,#9d4edd,#00f5ff 100%); }
    h1 { color: #00f5ff; text-shadow: 0 0 11px #9d4edd70; margin-bottom: 9px; }
    ul { text-align:left; color:#fff;}
    hr { border: none; border-top: 1.4px solid #222d46; margin:24px 2px;}
    .generated-link { margin-top: 11px; color:#fff; }
    .logout { margin-top: 20px; }
    .mirror-status {font-size:.95em; color:#00f5ffe8; margin-top:13px;}
    a,a:visited{color:#9d4edd;}
    #r2-step {margin:1em 0 1.5em 0;padding:15px 10px;background:#0e162e;border-radius:9px;}
    #r2-step b{color:#00f5ff;}
    #r2-status {margin-top:11px;}
    .r2-status {margin-top:7px;font-size:0.98em;}
  </style>
</head>
<body>
<div class="box">
  <h1>NoMovies - Movie Panel</h1>
  <form id="single-form">
    <input type="url" name="url" placeholder="Enter Google Drive file or folder link here" required><br>
    <input type="text" name="filename" placeholder="Optional: File name"><br>
    <button type="submit">Generate Download Page</button>
  </form>
  <hr>
  <form id="bulk-form">
    <h2 style="margin-top:3px;font-size:1.11em;">Bulk Generation</h2>
    <textarea name="links" rows="4" placeholder="Paste multiple GDrive links, one per line..."></textarea><br>
    <button type="submit">Generate Multiple</button>
  </form>
  <div id="result"></div>
  <div class="logout"><a href="?logout=1" style="color:#ffacee;">Logout</a></div>
</div>
<script>
const resultDiv = document.getElementById('result');

// Movie structure helper functions
function extractMovieTitle(metaArray) {
    if (metaArray.length === 0) return null;
    
    // Extract common title from filenames by removing resolution, codec, and format info
    const firstFile = metaArray[0].fileName;
    
    let title = firstFile
        .replace(/\.(mkv|mp4|avi|mov|wmv|flv|webm|m4v)$/i, '') // Remove extensions
        .replace(/\b(1080p|720p|480p|4k|uhd|2160p|1440p|1080i|720i)\b/gi, '') // Remove resolution
        .replace(/\b(x264|x265|hevc|avc|h264|h265)\b/gi, '') // Remove codec
        .replace(/\b(bluray|brrip|dvdrip|webrip|web-dl|hdtv|cam|ts|tc)\b/gi, '') // Remove source
        .replace(/\b(aac|ac3|dts|mp3|flac)\b/gi, '') // Remove audio
        .replace(/[\[\]()]/g, '') // Remove brackets
        .replace(/[-_.]/g, ' ') // Replace separators with spaces
        .replace(/\s+/g, ' ') // Collapse multiple spaces
        .trim();
    
    return title || 'Unknown Movie';
}

function generateMovieId(title) {
    return 'movie_' + title.toLowerCase()
        .replace(/[^a-z0-9]/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '') + '_' + Date.now();
}

async function saveMovieStructure(movieId, movieTitle, metaArray) {
    const movieData = {
        files: metaArray.map(meta => ({
            id: meta.id,
            fileName: meta.fileName,
            fileType: meta.fileType,
            fileSize: meta.fileSize,
            resolution: extractResolution(meta.fileName),
            codec: extractCodec(meta.fileName),
            workerLink: meta.workerLink
        }))
    };
    
    try {
        console.log('Attempting to save movie structure:', movieId, movieTitle);
        
        const res = await fetch('movie.php?action=save_movie_structure', {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                movieId: movieId,
                movieTitle: movieTitle,
                movieData: movieData
            })
        });
        
        const result = await res.json();
        console.log('Movie structure save result:', result);
        
        if (result.status === 'ok' && result.movieId) {
            console.log('Movie structure saved successfully:', result.movieId, result.movieTitle);
        } else {
            console.error('Failed to save movie structure:', result.message || 'Unknown error');
        }
    } catch (error) {
        console.error('Error saving movie structure:', error);
    }
}


// Export functions for WordPress plugin integration
function generateExportCode(filesData, baseUrl = null) {
    if (!baseUrl) {
        // Get current pathname (e.g. /new/index.php or /new/)
        const path = window.location.pathname;

        // Extract folder path (e.g. "/new/")
        const folder = path.substring(0, path.lastIndexOf('/') + 1);

        // Build base URL dynamically
        baseUrl = window.location.origin + folder;
    }

    const exportData = filesData.map(file => ({
        title: formatExportTitle(file.fileName, file.fileSize),
        url: `${baseUrl}download.php?id=${file.id}`
    }));
    
    return `---BEGIN NOMOVIES EXPORT---
${JSON.stringify(exportData, null, 2)}
---END NOMOVIES EXPORT---`;
}

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

function formatExportTitle(fileName, fileSize) {
    // Remove file extension
    let title = fileName.replace(/\.(mkv|mp4|avi|mov|wmv|flv|webm|m4v)$/i, '');
    
    // Extract movie name and year - look for pattern like "Movie Name (Year)"
    let movieName = '';
    let year = '';
    
    const yearMatch = title.match(/^(.*?)\s*\((\d{4})\)/);
    if (yearMatch) {
        movieName = yearMatch[1].trim();
        year = `(${yearMatch[2]})`;
    } else {
        // Fallback: take first part before resolution or other technical terms
        const fallbackMatch = title.match(/^(.*?)\s*(?:\d{3,4}p|BluRay|WEB-DL|HDTV|x264|x265|H\.264|H\.265)/i);
        movieName = fallbackMatch ? fallbackMatch[1].trim() : title.split(/\s+/).slice(0, 3).join(' ');
        
        // Try to find year in the string
        const foundYear = title.match(/\((\d{4})\)/);
        year = foundYear ? `(${foundYear[1]})` : '';
    }
    
    // Extract resolution
    let resolution = '';
    const resMatch = title.match(/\b(480p|720p|1080p|1440p|2160p|4k|uhd)\b/i);
    if (resMatch) {
        resolution = resMatch[1].toLowerCase() === '4k' || resMatch[1].toLowerCase() === 'uhd' ? '2160p' : resMatch[1];
    }
    
    // Extract source
    let source = '';
    const sourceMatch = title.match(/\b(BluRay|BRRip|DVDRip|WEB-DL|WebRip|HDTV|CAM|TS|TC)\b/i);
    if (sourceMatch) {
        source = sourceMatch[1];
    }
    
    // Extract codec
    let codec = '';
    const codecMatch = title.match(/\b(x264|x265|H\.264|H\.265|HEVC|AVC)\b/i);
    if (codecMatch) {
        let detectedCodec = codecMatch[1];
        // Normalize codec names
        if (detectedCodec.toLowerCase() === 'h.264' || detectedCodec.toLowerCase() === 'avc') {
            codec = 'x264';
        } else if (detectedCodec.toLowerCase() === 'h.265' || detectedCodec.toLowerCase() === 'hevc') {
            codec = 'x265';
        } else {
            codec = detectedCodec;
        }
    }
    
    // Build the formatted title
    let formattedTitle = movieName;
    if (year) formattedTitle += ` ${year}`;
    if (resolution) formattedTitle += ` ${resolution}`;
    if (source) formattedTitle += ` ${source}`;
    if (codec) formattedTitle += ` ${codec}`;
    
    // Add file size in brackets if available - convert bytes to readable format
    if (fileSize) {
        const readableSize = formatFileSize(fileSize);
        if (readableSize) {
            formattedTitle += ` [${readableSize}]`;
        }
    }
    
    return formattedTitle.trim();
}

function showExportButton(filesData, containerElement, movieTitle = '') {
    const exportContainer = document.createElement('div');
    exportContainer.style.marginTop = '20px';
    exportContainer.style.padding = '15px';
    exportContainer.style.background = '#0e162e';
    exportContainer.style.border = '2px solid #9d4edd';
    exportContainer.style.borderRadius = '8px';
    
    const exportCode = generateExportCode(filesData);
    const fileCount = filesData.length;
    const titleText = movieTitle ? `"${movieTitle}"` : 'download links';
    
    // Create header
    const header = document.createElement('div');
    header.style.color = '#9d4edd';
    header.style.fontWeight = 'bold';
    header.style.marginBottom = '10px';
    header.textContent = `📋 WordPress Plugin Export (${fileCount} files)`;
    
    // Create description
    const description = document.createElement('div');
    description.style.color = '#fff';
    description.style.marginBottom = '8px';
    description.style.fontSize = '14px';
    description.textContent = `Copy this code and paste it into your WordPress plugin for ${titleText}:`;
    
    // Create flex container for textarea and button
    const flexContainer = document.createElement('div');
    flexContainer.style.display = 'flex';
    flexContainer.style.alignItems = 'center';
    flexContainer.style.gap = '10px';
    
    // Create textarea (secure way)
    const textarea = document.createElement('textarea');
    textarea.id = `export-code-${Date.now()}`;
    textarea.readOnly = true;
    textarea.value = exportCode; // Safe assignment
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
    copyButton.className = 'copy-export-btn';
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
    statusDiv.className = 'export-status';
    statusDiv.style.marginTop = '8px';
    statusDiv.style.fontSize = '12px';
    
    // Assemble the container
    flexContainer.appendChild(textarea);
    flexContainer.appendChild(copyButton);
    exportContainer.appendChild(header);
    exportContainer.appendChild(description);
    exportContainer.appendChild(flexContainer);
    exportContainer.appendChild(statusDiv);
    containerElement.appendChild(exportContainer);
    
    // Add copy functionality
    copyButton.onclick = function() {
        try {
            navigator.clipboard.writeText(exportCode).then(function() {
                statusDiv.style.color = '#1effa3';
                statusDiv.textContent = '✅ Export code copied to clipboard! Paste it in your WordPress plugin.';
                setTimeout(() => statusDiv.textContent = '', 5000);
            }, function() {
                // Fallback for older browsers
                textarea.select();
                textarea.setSelectionRange(0, 99999);
                document.execCommand('copy');
                statusDiv.style.color = '#1effa3';
                statusDiv.textContent = '✅ Export code copied to clipboard! Paste it in your WordPress plugin.';
                setTimeout(() => statusDiv.textContent = '', 5000);
            });
        } catch (err) {
            statusDiv.style.color = '#ff6b6b';
            statusDiv.textContent = '❌ Failed to copy. Please select all text in the box above and copy manually.';
        }
    };
}

function extractResolution(fileName) {
    const resMatch = fileName.match(/\b(480p|720p|1080p|1440p|2160p|4k|uhd)\b/i);
    return resMatch ? resMatch[1].toLowerCase() : 'unknown';
}

function extractCodec(fileName) {
    const codecMatch = fileName.match(/\b(x264|x265|hevc|avc|h264|h265)\b/i);
    return codecMatch ? codecMatch[1].toLowerCase() : 'unknown';
}

// Sorting helper functions
function getResolutionOrder(name) {
    if (!name) return Infinity;
    if (name.match(/480p/i)) return 1;
    if (name.match(/720p/i)) return 2;
    if (name.match(/1080p/i)) return 3;
    if (name.match(/2160p|4k/i)) return 4;
    return 5;
}
function getEncodingOrder(name) {
    if (!name) return Infinity;
    if (name.match(/x264/i)) return 1;
    if (name.match(/x265|hevc/i)) return 2;
    return 3;
}
function compareLinks(a, b) {
    const resA = getResolutionOrder(a.fileName);
    const resB = getResolutionOrder(b.fileName);
    if (resA !== resB) return resA - resB;

    const sizeA = parseInt(a.fileSize) || Infinity;
    const sizeB = parseInt(b.fileSize) || Infinity;
    if (sizeA !== sizeB) return sizeA - sizeB;

    const encA = getEncodingOrder(a.fileName);
    const encB = getEncodingOrder(b.fileName);
    return encA - encB;
}

async function saveDownload(meta, origUrl) {
    const res = await fetch('movie.php?action=save_download', {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({...meta, origUrl})
    });
    return await res.json();
}
async function updateR2Link(id, r2url) {
    let res = await fetch('movie.php?action=update_r2', {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({id, r2url})
    });
    return await res.json();
}
function showR2Step(id) {
    const container = document.createElement('div');
    container.innerHTML = `
      <div id="r2-step">
        <b>Step 2 (Optional): Add R2 Download Link</b>
        <form id="r2-form">
          <input style="width:70%;" type="url" required name="r2url" placeholder="Paste R2 URL for this file...">
          <input type="hidden" name="fileid" value="${id}">
          <button type="submit" style="padding:7px 18px;border-radius:6px;">Update R2 Link</button>
        </form>
        <div id="r2-status"></div>
      </div>
    `;
    resultDiv.appendChild(container);
    document.getElementById('r2-form').onsubmit = async function(e) {
        e.preventDefault();
        let url = this.r2url.value.trim();
        let fileid = this.fileid.value;
        let st = document.getElementById('r2-status');
        st.innerHTML = "Saving...";
        let resp = await updateR2Link(fileid, url);
        if(resp && resp.status === 'ok') {
            st.style.color = '#1effa3';
            st.innerHTML = "R2 link added ✅. <a href='download.php?id="+fileid+"' target='_blank'>View download page</a>";
        } else {
            st.style.color = '#ff6b6b';
            st.innerHTML = "❌ Failed to save R2 link.";
        }
    };
}

function showR2StepInline(parentElement, id) {
    const uniqueId = 'r2-form-' + id;
    const statusId = 'r2-status-' + id;
    
    const container = document.createElement('div');
    container.innerHTML = `
      <div style="margin-top:10px;padding:10px;background:#1e263b;border-radius:6px;">
        <b style="color:#00f5ff;">Step 2 (Optional): Add R2 Download Link</b>
        <form id="${uniqueId}" style="margin-top:8px;">
          <input style="width:65%;margin-right:8px;" type="url" required name="r2url" placeholder="Paste R2 URL for this file...">
          <input type="hidden" name="fileid" value="${id}">
          <button type="submit" style="padding:7px 18px;border-radius:6px;">Update R2 Link</button>
        </form>
        <div id="${statusId}" style="margin-top:8px;"></div>
      </div>
    `;
    parentElement.appendChild(container);
    
    document.getElementById(uniqueId).onsubmit = async function(e) {
        e.preventDefault();
        let url = this.r2url.value.trim();
        let fileid = this.fileid.value;
        let st = document.getElementById(statusId);
        st.innerHTML = "Saving...";
        let resp = await updateR2Link(fileid, url);
        if(resp && resp.status === 'ok') {
            st.style.color = '#1effa3';
            st.innerHTML = "R2 link added ✅. <a href='download.php?id="+fileid+"' target='_blank'>View download page</a>";
        } else {
            st.style.color = '#ff6b6b';
            st.innerHTML = "❌ Failed to save R2 link.";
        }
    };
}

document.getElementById('single-form').onsubmit = async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = "<p style='color:#00f5ff;'>Processing...</p>";
    const formData = new FormData();
    formData.append('url', this.url.value);
    formData.append('filename', this.filename.value);

    try {
        const res = await fetch('<?=$worker_api?>/submit', { method: "POST", body: formData });
        const meta = await res.json();
        
        // Check if it's a folder (array) or single file (object)
        if (Array.isArray(meta)) {
            // Handle folder - treat as bulk processing
            if (meta.length === 0) {
                resultDiv.innerHTML = "<p style='color:#ff6b6b;'>❌ No files found in folder</p>";
                return;
            }

            meta.sort(compareLinks);
            
            // Extract movie title and ID for structure saving
            const movieTitle = extractMovieTitle(meta);
            const movieId = generateMovieId(movieTitle);
            
            // Create the container
            resultDiv.innerHTML = "<div style='color:#fff;'><strong>📁 Generated Download Pages from Folder:</strong></div>";
            const container = document.createElement('div');
            resultDiv.appendChild(container);
            
            for (const file of meta) {
                // Use file.originalUrl for proper mirror generation
                const saveRes = await saveDownload(file, file.originalUrl || '');
                
                // Create file item container
                const fileItem = document.createElement('div');
                fileItem.style.marginBottom = '20px';
                fileItem.style.padding = '15px';
                fileItem.style.background = '#0e162e';
                fileItem.style.borderRadius = '8px';
                
                // Display mirror status for each file
                let mirrorStatus = "";
                if (saveRes.status === 'ok' && saveRes.altLinks) {
                    const mirrors = saveRes.altLinks;
                    mirrorStatus = "<br><span style='font-size:0.9em;color:#00f5ffe8;'>🔗 ";
                    if (mirrors.GDFLIX) mirrorStatus += "✅ GDFlix ";
                    if (mirrors.FilePress) mirrorStatus += "✅ FilePress ";
                    if (mirrors.GCLOUD) mirrorStatus += "✅ GCloud ";
                    mirrorStatus += "</span>";
                }
                
                fileItem.innerHTML = `
                    <div style='margin-bottom:10px;'>
                        <a href="download.php?id=${file.id}" target="_blank" style="color:#1effa3;font-weight:bold;">${file.fileName}</a>
                        ${mirrorStatus}
                    </div>
                `;
                
                container.appendChild(fileItem);
                
                // Add R2 step form directly under this file
                showR2StepInline(fileItem, file.id);
            }
            
            // Save movie structure if multiple files
            if (meta.length > 1 && movieTitle) {
                await saveMovieStructure(movieId, movieTitle, meta);
            }
            
            // Add export button for WordPress plugin
            showExportButton(meta, resultDiv, movieTitle);
            
        } else {
            // Handle single file
            const saveRes = await saveDownload(meta, this.url.value);
            
            if (saveRes.status === 'ok') {
                const mirrors = saveRes.altLinks;
                let mirrorStatus = "<div class='mirror-status'><strong>🔗 Mirror Links Generated:</strong><br>";
                if (mirrors.GDFLIX) mirrorStatus += "✅ GDFlix<br>";
                if (mirrors.FilePress) mirrorStatus += "✅ FilePress<br>";
                if (mirrors.GCLOUD) mirrorStatus += "✅ GCloud<br>";
                mirrorStatus += "</div>";
                
                resultDiv.innerHTML = `
                    <div class="generated-link">
                        ✅ <strong>Download Page Generated!</strong><br>
                        <a href="download.php?id=${meta.id}" target="_blank" style="color:#1effa3;font-weight:bold;">
                            ${meta.fileName}
                        </a>
                        ${mirrorStatus}
                    </div>
                `;
                
                showR2Step(meta.id);
                
                // Add export button for single file
                showExportButton([meta], resultDiv);
            }
        }
    } catch (error) {
        resultDiv.innerHTML = `<p style='color:#ff6b6b;'>❌ Error: ${error.message}</p>`;
    }
};

document.getElementById('bulk-form').onsubmit = async function(e) {
    e.preventDefault();
    resultDiv.innerHTML = "<p style='color:#00f5ff;'>Processing bulk links...</p>";
    const formData = new FormData();
    formData.append('links', this.links.value);

    try {
        const res = await fetch('<?=$worker_api?>/bulk-submit', { method: "POST", body: formData });
        const metaArray = await res.json();
        
        if (metaArray.length === 0) {
            resultDiv.innerHTML = "<p style='color:#ff6b6b;'>❌ No valid links found</p>";
            return;
        }

        metaArray.sort(compareLinks);
        
        let bulkHtml = "<div style='color:#fff;'><strong>📁 Generated Download Pages:</strong><ul>";
        
        // Check if this looks like a movie structure (multiple versions of same movie)
        const movieTitle = extractMovieTitle(metaArray);
        const movieId = generateMovieId(movieTitle);
        
        for (const meta of metaArray) {
            const saveRes = await saveDownload(meta, ""); // bulk doesn't need origUrl for mirrors
            bulkHtml += `<li><a href="download.php?id=${meta.id}" target="_blank" style="color:#1effa3;">${meta.fileName}</a></li>`;
        }
        
        // Save movie structure data if we have multiple files for same movie
        if (metaArray.length > 1 && movieTitle) {
            await saveMovieStructure(movieId, movieTitle, metaArray);
        }
        
        bulkHtml += "</ul></div>";
        resultDiv.innerHTML = bulkHtml;
        
        // Add export button for bulk processing
        showExportButton(metaArray, resultDiv, movieTitle);
        
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
      <title>NoMovies Movie Panel Login</title>
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
        <h1>Movie Panel</h1>
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
    // Show admin panel
    showAdminPanel($worker_api);
}
?>