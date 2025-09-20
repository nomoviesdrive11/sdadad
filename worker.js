export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const origin = request.headers.get("Origin") || "";

    // Allow multiple origins for development and production
    const allowedOrigins = [
      "https://dl.nolinks.site",
      "https://nocdn.richardgraccia.workers.dev",
      "https://nomovies.xx.kg",
      "http://localhost:5000",
      "https://localhost:5000"
    ];
    const isAllowedOrigin = allowedOrigins.includes(origin);

    const corsHeaders = {
      "Access-Control-Allow-Origin": isAllowedOrigin ? origin : "null",
      "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
      "Access-Control-Allow-Headers": "Content-Type",
      "Access-Control-Allow-Credentials": "true"
    };

    // Handle preflight
    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: corsHeaders });
    }

    // Your testing API Key, replace with your real one when needed
    const API_KEY = env.GOOGLE_DRIVE_API_KEY || "AIzaSyAA9ERw-9LZVEohRYtCWka_TQc6oXmvcVU";

    // New recursive folder endpoint
    if (url.pathname === "/gdrive/folder-tree" && request.method === "POST") {
      const formData = await request.formData();
      const folderUrl = formData.get("url");
      const depth = formData.get("depth") || "recursive";

      if (!folderUrl || !folderUrl.includes("/folders/")) {
        return new Response(JSON.stringify({ error: "Invalid folder URL" }), {
          status: 400,
          headers: { "Content-Type": "application/json", ...corsHeaders }
        });
      }

      const folderIdMatch = folderUrl.match(/\/folders\/([a-zA-Z0-9_-]+)/);
      if (!folderIdMatch) {
        return new Response(JSON.stringify({ error: "Could not extract folder ID" }), {
          status: 400,
          headers: { "Content-Type": "application/json", ...corsHeaders }
        });
      }

      const folderId = folderIdMatch[1];

      try {
        const folderStructure = await getFolderStructure(folderId, API_KEY, depth === "recursive");
        const processedFiles = await processFilesForSeries(folderStructure, API_KEY, url.origin, env);

        return new Response(JSON.stringify({
          success: true,
          structure: folderStructure,
          files: processedFiles,
          metadata: {
            totalFiles: processedFiles.length,
            hasNestedFolders: hasNestedFolders(folderStructure),
            structureType: detectStructureType(folderStructure)
          }
        }), {
          headers: { "Content-Type": "application/json", ...corsHeaders }
        });

      } catch (error) {
        return new Response(JSON.stringify({
          error: "Failed to process folder structure",
          details: error.message
        }), {
          status: 500,
          headers: { "Content-Type": "application/json", ...corsHeaders }
        });
      }
    }

    // /submit - Enhanced single file OR folder link submission
    if (url.pathname === "/submit" && request.method === "POST") {
      const formData = await request.formData();
      let fileUrl = formData.get("url");
      let fileName = formData.get("filename") || "";
      const fileId = generateUniqueId();
      let fileType = "";
      let fileSize = "";

      // Check if it's a Google Drive folder link - enhanced for nested processing
      if (fileUrl.includes("/folders/")) {
        const folderIdMatch = fileUrl.match(/\/folders\/([a-zA-Z0-9_-]+)/);
        if (folderIdMatch) {
          const folderId = folderIdMatch[1];

          try {
            // Use recursive traversal for TV series support
            const folderStructure = await getFolderStructure(folderId, API_KEY, true);
            const allFiles = extractAllFiles(folderStructure);

            const resultArr = [];
            for (const file of allFiles) {
              const innerFileId = generateUniqueId();
              const innerFileUrl = `https://www.googleapis.com/drive/v3/files/${file.id}?alt=media&key=${API_KEY}&supportsAllDrives=true`;

              // UPDATED: Store both original fileUrl AND googleDriveId for bypass worker
              await env.SECURE_URLS_KV.put(innerFileId, JSON.stringify({
                fileUrl: innerFileUrl,
                fileName: file.name,
                folderPath: file.path || "",
                googleDriveId: file.id,  // Added for bypass worker
                fileSize: file.size || ""  // Added for file size display
              }));

              resultArr.push({
                id: innerFileId,
                fileName: file.name,
                fileType: file.mimeType || "",
                fileSize: file.size || "",
                folderPath: file.path || "",
                workerLink: `${url.origin}/download/${innerFileId}/${encodeURIComponent(file.name)}`,
                originalUrl: `https://drive.google.com/file/d/${file.id}/view`
              });
            }

            return new Response(JSON.stringify(resultArr), {
              headers: { "Content-Type": "application/json", ...corsHeaders }
            });
          } catch (error) {
            // Fallback to flat folder processing if recursive fails
            console.log("Recursive processing failed, falling back to flat:", error.message);
            return await processFlatFolder(folderId, API_KEY, url.origin, env, corsHeaders);
          }
        }
      }

      // Process single file link as before (no folder)
      let driveId = null;
      if (fileUrl.includes("drive.google.com")) {
        const match = fileUrl.match(/\/d\/(.*?)(?:\/|\?|$)/);
        if (match) {
          driveId = match[1];

          // Get file metadata from Drive API first
          try {
            const metaUrl = `https://www.googleapis.com/drive/v3/files/${driveId}?fields=name,mimeType,size&key=${API_KEY}&supportsAllDrives=true`;
            const metaRes = await fetch(metaUrl);
            if (metaRes.ok) {
              const metadata = await metaRes.json();
              fileName = fileName || metadata.name || "file";
              fileType = metadata.mimeType || "";
              fileSize = metadata.size || "";
            }
          } catch (e) {
            // If metadata fetch fails, continue with filename fallback
            if (!fileName) fileName = "file";
          }

          // Google Drive direct link for download
          fileUrl = `https://www.googleapis.com/drive/v3/files/${driveId}?alt=media&key=${API_KEY}&supportsAllDrives=true`;
        }
      }

      // If Drive API didn't provide metadata, try HEAD request as fallback
      if (!fileType || !fileSize) {
        try {
          const headRes = await fetch(fileUrl, {
            method: "HEAD",
            headers: {
              'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            }
          });
          if (headRes.ok) {
            if (!fileType) fileType = headRes.headers.get("Content-Type") || "";
            if (!fileSize) fileSize = headRes.headers.get("Content-Length") || "";
          }
        } catch (e) {
          // Set defaults if all methods fail
          if (!fileType) fileType = "application/octet-stream";
          if (!fileSize) fileSize = "";
        }
      }

      // UPDATED: Store in KV with Google Drive ID for bypass worker
      const kvData = {
        fileUrl,
        fileName,
        fileSize: fileSize || ""  // Added for file size display
      };
      
      // Add Google Drive ID if available for bypass worker
      if (driveId) {
        kvData.googleDriveId = driveId;
        console.log(`✅ Stored Google Drive ID ${driveId} for bypass worker access`);
      }
      
      await env.SECURE_URLS_KV.put(fileId, JSON.stringify(kvData));

      // Worker download link
      const workerLink = `${url.origin}/download/${fileId}/${encodeURIComponent(fileName)}`;

      // Return JSON for PHP panel (single file)
      return new Response(JSON.stringify({
        id: fileId,
        fileName,
        fileType,
        fileSize,
        workerLink
      }), { headers: { "Content-Type": "application/json", ...corsHeaders } });
    }

    // /bulk-submit - multiple (enhanced with Google Drive ID storage)
    if (url.pathname === "/bulk-submit" && request.method === "POST") {
      const formData = await request.formData();
      const linksText = formData.get("links") || "";
      const urls = linksText.split("\n").map(l => l.trim()).filter(Boolean);

      const arr = [];
      for (const originalUrl of urls) {
        let fileUrl = originalUrl;
        let fileName = "file";
        let fileType = "";
        let fileSize = "";
        let driveId = null;
        const fileId = generateUniqueId();

        if (fileUrl.includes("drive.google.com")) {
          const match = fileUrl.match(/\/d\/(.*?)(?:\/|\?|$)/);
          if (match) {
            driveId = match[1];

            // Get metadata from Drive API
            try {
              const metaUrl = `https://www.googleapis.com/drive/v3/files/${driveId}?fields=name,mimeType,size&key=${API_KEY}&supportsAllDrives=true`;
              const metaRes = await fetch(metaUrl);
              if (metaRes.ok) {
                const metadata = await metaRes.json();
                fileName = metadata.name || "file";
                fileType = metadata.mimeType || "";
                fileSize = metadata.size || "";
              }
            } catch (e) {}

            fileUrl = `https://www.googleapis.com/drive/v3/files/${driveId}?alt=media&key=${API_KEY}&supportsAllDrives=true`;
          }
        }

        // Fallback HEAD request if no metadata yet
        if (!fileType || !fileSize) {
          try {
            const headRes = await fetch(fileUrl, {
              method: "HEAD",
              headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
              }
            });
            if (headRes.ok) {
              if (!fileType) fileType = headRes.headers.get("Content-Type") || "";
              if (!fileSize) fileSize = headRes.headers.get("Content-Length") || "";
            }
          } catch (e) {}
        }

        // UPDATED: Store with Google Drive ID for bypass worker
        const kvData = { 
          fileUrl, 
          fileName,
          fileSize: fileSize || ""  // Added for file size display
        };
        if (driveId) {
          kvData.googleDriveId = driveId;
        }
        
        await env.SECURE_URLS_KV.put(fileId, JSON.stringify(kvData));

        arr.push({
          id: fileId,
          fileName,
          fileType,
          fileSize,
          workerLink: `${url.origin}/download/${fileId}/${encodeURIComponent(fileName)}`
        });
      }

      return new Response(JSON.stringify(arr), { headers: { "Content-Type": "application/json", ...corsHeaders } });
    }

    // /download/:id/:filename/raw (unchanged)
    if (url.pathname.startsWith("/download/")) {
      const parts = url.pathname.split("/");
      const fileId = parts[2];
      const fileName = decodeURIComponent(parts[3] || "file");
      // If URL ends in .../raw, stream the file
      if (parts[4] === "raw") {
        const fileData = await env.SECURE_URLS_KV.get(fileId);
        if (!fileData) {
          return new Response("File Not Found", { status: 404, headers: corsHeaders });
        }
        const { fileUrl } = JSON.parse(fileData);
        const rangeHeader = request.headers.get("Range");
        const fetchHeaders = rangeHeader ? { "Range": rangeHeader } : {};
        const upstreamRes = await fetch(fileUrl, { headers: fetchHeaders });

        const headers = new Headers(corsHeaders);
        headers.set("Content-Disposition", `attachment; filename="${fileName}"`);
        headers.set("Cache-Control", "public, max-age=3600");

        const contentType = upstreamRes.headers.get("Content-Type") || "application/octet-stream";
        headers.set("Content-Type", contentType);
        const contentLength = upstreamRes.headers.get("Content-Length");
        if (contentLength) headers.set("Content-Length", contentLength);

        if (rangeHeader && upstreamRes.status === 206) {
          headers.set("Content-Range", upstreamRes.headers.get("Content-Range"));
          return new Response(upstreamRes.body, { status: 206, headers });
        }
        return new Response(upstreamRes.body, { status: 200, headers });
      }

      // If not /raw, just inform: "Use your PHP download page"
      return new Response("Please use the download page provided by your panel.", { status: 200, headers: { "Content-Type": "text/plain", ...corsHeaders } });
    }

    return new Response("Not Found", { status: 404, headers: corsHeaders });
  }
};

// Helper Functions - Standalone
async function getFolderStructure(folderId, apiKey, recursive = true, path = "") {
  let allItems = [];
  let nextPageToken = null;

  do {
    const driveApiUrl = `https://www.googleapis.com/drive/v3/files?q='${folderId}'+in+parents+and+trashed=false&key=${apiKey}&supportsAllDrives=true&includeItemsFromAllDrives=true&fields=nextPageToken,files(id,name,mimeType,size,parents)&pageToken=${nextPageToken || ""}`;
    const folderRes = await fetch(driveApiUrl);

    if (!folderRes.ok) {
      throw new Error(`Drive API error: ${folderRes.status}`);
    }

    const folderJson = await folderRes.json();
    const items = folderJson.files || [];

    for (const item of items) {
      const itemPath = path ? `${path}/${item.name}` : item.name;

      if (item.mimeType === 'application/vnd.google-apps.folder' && recursive) {
        // It's a folder - recursively get its contents
        const subfolderContents = await getFolderStructure(item.id, apiKey, true, itemPath);
        allItems.push({
          id: item.id,
          name: item.name,
          type: 'folder',
          path: itemPath,
          children: subfolderContents
        });
      } else if (item.mimeType !== 'application/vnd.google-apps.folder') {
        // It's a file
        allItems.push({
          id: item.id,
          name: item.name,
          type: 'file',
          mimeType: item.mimeType,
          size: item.size,
          path: itemPath,
          parents: item.parents
        });
      }
    }

    nextPageToken = folderJson.nextPageToken;
  } while (nextPageToken);

  return allItems;
}

// Extract all files from nested structure
function extractAllFiles(structure) {
  let files = [];

  for (const item of structure) {
    if (item.type === 'file') {
      files.push(item);
    } else if (item.type === 'folder' && item.children) {
      files = files.concat(extractAllFiles(item.children));
    }
  }

  return files;
}

// Process files for series with enhanced metadata (UPDATED with Google Drive ID storage)
async function processFilesForSeries(structure, apiKey, origin, env) {
  const allFiles = extractAllFiles(structure);
  const processedFiles = [];

  for (const file of allFiles) {
    const fileId = generateUniqueId();
    const fileUrl = `https://www.googleapis.com/drive/v3/files/${file.id}?alt=media&key=${apiKey}&supportsAllDrives=true`;

    // UPDATED: Store both original data AND Google Drive ID for bypass worker
    await env.SECURE_URLS_KV.put(fileId, JSON.stringify({
      fileUrl,
      fileName: file.name,
      folderPath: file.path || "",
      googleDriveId: file.id,  // Added for bypass worker
      fileSize: file.size || ""  // Added for file size display
    }));

    processedFiles.push({
      id: fileId,
      fileName: file.name,
      fileType: file.mimeType || "",
      fileSize: file.size || "",
      folderPath: file.path || "",
      workerLink: `${origin}/download/${fileId}/${encodeURIComponent(file.name)}`,
      originalUrl: `https://drive.google.com/file/d/${file.id}/view`
    });
  }

  return processedFiles;
}

// Fallback flat folder processing (UPDATED with Google Drive ID storage)
async function processFlatFolder(folderId, apiKey, origin, env, corsHeaders) {
  try {
    const driveApiUrl = `https://www.googleapis.com/drive/v3/files?q='${folderId}'+in+parents+and+trashed=false&key=${apiKey}&supportsAllDrives=true&includeItemsFromAllDrives=true&fields=files(id,name,mimeType,size)`;
    const folderRes = await fetch(driveApiUrl);

    if (!folderRes.ok) {
      throw new Error(`Drive API error: ${folderRes.status}`);
    }

    const folderJson = await folderRes.json();
    const files = folderJson.files || [];

    const resultArr = [];
    for (const file of files) {
      if (file.mimeType !== 'application/vnd.google-apps.folder') {
        const fileId = generateUniqueId();
        const fileUrl = `https://www.googleapis.com/drive/v3/files/${file.id}?alt=media&key=${apiKey}&supportsAllDrives=true`;

        // UPDATED: Store with Google Drive ID for bypass worker
        await env.SECURE_URLS_KV.put(fileId, JSON.stringify({
          fileUrl,
          fileName: file.name,
          googleDriveId: file.id,  // Added for bypass worker
          fileSize: file.size || ""  // Added for file size display
        }));

        resultArr.push({
          id: fileId,
          fileName: file.name,
          fileType: file.mimeType || "",
          fileSize: file.size || "",
          workerLink: `${origin}/download/${fileId}/${encodeURIComponent(file.name)}`,
          originalUrl: `https://drive.google.com/file/d/${file.id}/view`
        });
      }
    }

    return new Response(JSON.stringify(resultArr), {
      headers: { "Content-Type": "application/json", ...corsHeaders }
    });

  } catch (error) {
    return new Response(JSON.stringify({
      error: "Failed to process folder",
      details: error.message
    }), {
      status: 500,
      headers: { "Content-Type": "application/json", ...corsHeaders }
    });
  }
}

// Utility functions
function generateUniqueId() {
  return Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
}

function hasNestedFolders(structure) {
  return structure.some(item => item.type === 'folder' && item.children && item.children.length > 0);
}

function detectStructureType(structure) {
  const folders = structure.filter(item => item.type === 'folder');
  const files = structure.filter(item => item.type === 'file');
  
  if (folders.length > 0) {
    return hasNestedFolders(structure) ? 'nested_series' : 'flat_series';
  }
  return files.length > 1 ? 'multiple_files' : 'single_file';
}