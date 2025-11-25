<?php
// ============================
// Config
// ============================

// Public base URL of your site (no trailing slash)
$BASE_URL = 'https://file.kr.md';

// Folder (relative to webroot) where uploads are stored
$ASSET_ROOT_WEB = '/ezassest'; // used for URLs
$ASSET_ROOT_FS  = realpath(__DIR__ . '/../ezassest'); // filesystem path

// Secret upload token (change if you want a different one)
$UPLOAD_TOKEN = 'changeme';

// Allowed extensions (all will be re-encoded to strip metadata where possible)
$ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// ============================
// Helpers
// ============================

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_folder(string $folder): ?string {
    // Only allow letters, numbers, underscore, dash
    $folder = trim($folder);
    if ($folder === '') {
        return 'default';
    }
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $folder)) {
        return $folder;
    }
    return null;
}

function ensure_folder(string $baseFs, string $folder): string {
    $path = $baseFs . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

function strip_metadata_and_save(string $tmpPath, string $targetPath, string $ext): bool {
    $ext = strtolower($ext);
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $img = @imagecreatefromjpeg($tmpPath);
            if (!$img) return false;
            $ok = imagejpeg($img, $targetPath, 90);
            imagedestroy($img);
            return $ok;

        case 'png':
            $img = @imagecreatefrompng($tmpPath);
            if (!$img) return false;
            $ok = imagepng($img, $targetPath, 9);
            imagedestroy($img);
            return $ok;

        case 'gif':
            $img = @imagecreatefromgif($tmpPath);
            if (!$img) return false;
            $ok = imagegif($img, $targetPath);
            imagedestroy($img);
            return $ok;

        case 'webp':
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
                // Fallback: just move file if webp not supported
                return move_uploaded_file($tmpPath, $targetPath);
            }
            $img = @imagecreatefromwebp($tmpPath);
            if (!$img) return false;
            $ok = imagewebp($img, $targetPath, 90);
            imagedestroy($img);
            return $ok;

        default:
            // Not expected if we validate extensions earlier
            return move_uploaded_file($tmpPath, $targetPath);
    }
}

function build_response_payload(string $url): array {
    $html     = '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" width="80%">';
    $markdown = '![](' . $url . ')';

    return [
        'success'  => true,
        'url'      => $url,
        'html'     => $html,
        'markdown' => $markdown,
    ];
}

// ============================
// Read folder + token from GET for page context
// ============================

$folderParam = $_GET['folder'] ?? 'default';
$folder = sanitize_folder($folderParam);
if ($folder === null) {
    http_response_code(400);
    echo 'Invalid folder name. Allowed: letters, numbers, underscore, dash.';
    exit;
}

$tokenParam   = $_GET['token'] ?? '';
$hasValidTokenGet = ($tokenParam !== '' && hash_equals($UPLOAD_TOKEN, $tokenParam));

// ============================
// Handle upload (POST, AJAX)
// ============================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // For uploads, prefer POSTed folder/token. Fallback to GET for old links.
    $postFolder = $_POST['folder'] ?? '';
    $postToken  = $_POST['token'] ?? '';

    if ($postFolder !== '') {
        $folderForUpload = sanitize_folder($postFolder);
        if ($folderForUpload === null) {
            json_response(['success' => false, 'error' => 'Invalid folder name.'], 400);
        }
    } else {
        $folderForUpload = $folder; // from GET/default
    }

    // Decide token to check
    $tokenToCheck = $postToken !== '' ? $postToken : $tokenParam;
    if ($tokenToCheck === '' || !hash_equals($UPLOAD_TOKEN, $tokenToCheck)) {
        json_response(['success' => false, 'error' => 'Invalid or missing token.'], 403);
    }

    if (!isset($_FILES['file'])) {
        json_response(['success' => false, 'error' => 'No file uploaded.'], 400);
    }

    $file = $_FILES['file'];

    if (!isset($file['error']) || is_array($file['error'])) {
        json_response(['success' => false, 'error' => 'Invalid upload.'], 400);
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'error' => 'Upload error code: ' . $file['error']], 400);
    }

    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, $ALLOWED_EXTENSIONS, true)) {
        json_response([
            'success' => false,
            'error'   => 'Unsupported file type. Allowed: ' . implode(', ', $ALLOWED_EXTENSIONS),
        ], 400);
    }

    if (!is_dir($ASSET_ROOT_FS)) {
        mkdir($ASSET_ROOT_FS, 0755, true);
    }

    $folderFsPath = ensure_folder($ASSET_ROOT_FS, $folderForUpload);

    // Generate unique filename
    $baseName   = bin2hex(random_bytes(8)); // 16 hex chars
    $finalName  = $baseName . '.' . $ext;
    $targetPath = $folderFsPath . DIRECTORY_SEPARATOR . $finalName;

    if (!strip_metadata_and_save($file['tmp_name'], $targetPath, $ext)) {
        json_response(['success' => false, 'error' => 'Failed to save file.'], 500);
    }

    // Build URL
    $url = rtrim($BASE_URL, '/') . $ASSET_ROOT_WEB . '/'
        . rawurlencode($folderForUpload) . '/'
        . rawurlencode($finalName);

    json_response(build_response_payload($url));
}

// ============================
// Show HTML page (GET)
// ============================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ezdnd by david and gpt</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            max-width: 800px;
            margin: 20px auto;
            padding: 0 12px;
        }
        .info {
            font-size: 14px;
            margin-bottom: 12px;
        }
        .token-warning {
            color: red;
            font-weight: bold;
        }
        .config-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
        }
        .config-row label {
            font-size: 13px;
        }
        .config-row input {
            padding: 4px 8px;
            font-size: 14px;
        }
        .dropzone {
            border: 2px dashed #999;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 16px;
        }
        .dropzone.dragover {
            border-color: #333;
            background: #f8f8f8;
        }
        .preview {
            margin-top: 16px;
        }
        .preview-item {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 12px;
        }
        .preview-item img {
            max-width: 40%;
            height: auto;
            display: block;
            margin-top: 8px;
        }
        .codes {
            font-family: Menlo, Monaco, "Courier New", monospace;
            font-size: 14px;
            background: #f5f5f5;
            padding: 6px;
            border-radius: 4px;
            overflow-x: auto;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .codes input,
        .codes textarea {
            flex: 1 1 auto;
            border: none;
            background: transparent;
            font: inherit;
            padding: 0;
            outline: none;
            color: #003366;
            text-decoration: underline;
        }
        .codes textarea {
            resize: none;
        }
        .copy-btn {
            flex: 0 0 auto;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #999;
            background: #e0e0ff;
            cursor: pointer;
            white-space: nowrap;
        }
        .copy-btn:hover {
            background: #c5c5ff;
        }
        .upload-progress {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        button {
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid #999;
            background: #f0f0f0;
            cursor: pointer;
        }
        button:hover {
            background: #e2e2e2;
        }
        .github-note {
            margin-top: 24px;
            border-top: 1px solid #ddd;
            padding-top: 12px;
            font-size: 14px;
        }
        .github-note a {
            font-family: Menlo, Monaco, "Courier New", monospace;
        }
    </style>
</head>
<body>
<h1>EZ Drop &amp; Paste</h1>

<div class="config-row">
    <label>
        Folder:
        <input type="text" id="folderInput" value="<?php echo htmlspecialchars($folder, ENT_QUOTES); ?>" placeholder="myfolder">
    </label>

    <label>
        Token:
        <input type="password" id="tokenInput" value="<?php echo htmlspecialchars($tokenParam, ENT_QUOTES); ?>" placeholder="your-secret-token">
    </label>

    <button id="applyUrlBtn" type="button">Apply (update URL)</button>
</div>

<div class="info">
    <div>Current folder (server-side default): <strong id="currentFolderLabel"><?php echo htmlspecialchars($folder, ENT_QUOTES); ?></strong></div>
    <div>Uploads will go to:
        <code id="pathLabel"><?php echo htmlspecialchars($ASSET_ROOT_WEB . '/' . $folder, ENT_QUOTES); ?></code>
    </div>
    <div>Allowed types: jpg, jpeg, png, gif, webp</div>
    <?php if (!$hasValidTokenGet): ?>
        <div class="token-warning">
            Hint: add your token above and click “Apply (update URL)”, or make sure Chrome auto-fills it.
        </div>
    <?php endif; ?>
</div>

<div id="dropzone" class="dropzone">
    <p><strong>Drag &amp; drop</strong> files here or click to choose.</p>
    <p>After upload, you’ll see: URL, HTML &lt;img&gt;, Markdown, then a preview image.</p>
    <input id="fileInput" type="file" style="display:none;" accept="image/*">
</div>

<div>
    <button id="chooseBtn" type="button">Choose File</button>
</div>

<div id="preview" class="preview"></div>

<div class="github-note">
    <div><a href="https://github.com/iamblueming/ezdnd" target="_blank" rel="noopener">https://github.com/iamblueming/ezdnd</a></div>
</div>

<script>
(function () {
    const dropzone    = document.getElementById('dropzone');
    const fileInput   = document.getElementById('fileInput');
    const chooseBtn   = document.getElementById('chooseBtn');
    const previewBox  = document.getElementById('preview');
    const folderInput = document.getElementById('folderInput');
    const tokenInput  = document.getElementById('tokenInput');
    const applyUrlBtn = document.getElementById('applyUrlBtn');
    const currentFolderLabel = document.getElementById('currentFolderLabel');
    const pathLabel          = document.getElementById('pathLabel');

    // Base endpoint for uploads (no query string; we send folder/token in POST)
    const uploadEndpoint = window.location.pathname;

    function getCurrentFolder() {
        const val = folderInput.value.trim();
        return val === '' ? 'default' : val;
    }

    function getCurrentToken() {
        return tokenInput.value.trim();
    }

    function updateLabels() {
        const folder = getCurrentFolder();
        currentFolderLabel.textContent = folder;
        pathLabel.textContent = '/ezassest/' + folder;
    }

    function updateUrlQuery() {
        const folder = getCurrentFolder();
        const token  = getCurrentToken();

        const params = new URLSearchParams(window.location.search);
        params.set('folder', folder);
        if (token !== '') {
            params.set('token', token);
        } else {
            params.delete('token');
        }

        const newUrl = window.location.pathname + '?' + params.toString();
        history.replaceState(null, '', newUrl);
    }

    function makeCopyButton(targetElement) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'copy-btn';
        btn.textContent = 'Copy';

        btn.addEventListener('click', () => {
            const text = ('value' in targetElement && targetElement.value !== undefined)
                ? targetElement.value
                : targetElement.textContent;

            navigator.clipboard.writeText(text).then(() => {
                const old = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => {
                    btn.textContent = old;
                }, 800);
            }).catch(() => {
                alert('Failed to copy.');
            });
        });

        return btn;
    }

    // Upload with progress (percentage)
    function uploadFile(file) {
        const folder = getCurrentFolder();
        const token  = getCurrentToken();

        if (token === '') {
            alert('Token is empty. Please enter your token first.');
            return;
        }

        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', folder);
        formData.append('token', token);

        // Placeholder item with progress
        const item = document.createElement('div');
        item.className = 'preview-item';

        const title = document.createElement('div');
        title.textContent = file.name;
        title.style.fontSize = '13px';
        item.appendChild(title);

        const progressText = document.createElement('div');
        progressText.className = 'upload-progress';
        progressText.textContent = 'Uploading... 0%';
        item.appendChild(progressText);

        previewBox.prepend(item);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', uploadEndpoint, true);

        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressText.textContent = 'Uploading... ' + percent + '%';
            }
        };

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                let data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {
                    alert('Upload error: invalid server response.');
                    return;
                }
                if (!data.success) {
                    alert('Upload failed: ' + (data.error || 'unknown error'));
                    return;
                }
                // Replace placeholder with final preview
                item.remove();
                addPreview(file.name, data.url, data.html, data.markdown);
            } else {
                alert('Upload failed: HTTP ' + xhr.status);
            }
        };

        xhr.onerror = function () {
            alert('Upload error.');
        };

        xhr.send(formData);
    }

    function addPreview(filename, url, htmlCode, mdCode) {
        const item = document.createElement('div');
        item.className = 'preview-item';

        const title = document.createElement('div');
        title.textContent = filename + ' → ' + url;
        title.style.fontSize = '13px';
        title.style.marginBottom = '6px';
        item.appendChild(title);

        // URL
        const urlBox = document.createElement('div');
        urlBox.className = 'codes';
        const urlInput = document.createElement('input');
        urlInput.readOnly = true;
        urlInput.value = url;
        urlBox.appendChild(urlInput);
        urlBox.appendChild(makeCopyButton(urlInput));
        item.appendChild(urlBox);

        // HTML
        const htmlBox = document.createElement('div');
        htmlBox.className = 'codes';
        const htmlArea = document.createElement('textarea');
        htmlArea.readOnly = true;
        htmlArea.rows = 2;
        htmlArea.value = htmlCode;
        htmlBox.appendChild(htmlArea);
        htmlBox.appendChild(makeCopyButton(htmlArea));
        item.appendChild(htmlBox);

        // Markdown
        const mdBox = document.createElement('div');
        mdBox.className = 'codes';
        const mdArea = document.createElement('textarea');
        mdArea.readOnly = true;
        mdArea.rows = 2;
        mdArea.value = mdCode;
        mdBox.appendChild(mdArea);
        mdBox.appendChild(makeCopyButton(mdArea));
        item.appendChild(mdBox);

        // Image preview (smaller, at the end)
        const img = document.createElement('img');
        img.src = url;
        item.appendChild(img);

        previewBox.prepend(item);
    }

    function handleFiles(files) {
        for (const file of files) {
            uploadFile(file);
        }
    }

    dropzone.addEventListener('click', () => fileInput.click());

    dropzone.addEventListener('dragover', e => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            handleFiles(e.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', e => {
        if (e.target.files && e.target.files.length > 0) {
            handleFiles(e.target.files);
            // Allow picking more files without reload
            fileInput.value = '';
        }
    });

    chooseBtn.addEventListener('click', () => fileInput.click());

    applyUrlBtn.addEventListener('click', () => {
        updateLabels();
        updateUrlQuery();
    });

    folderInput.addEventListener('input', updateLabels);

    // On load, show correct info based on current inputs
    updateLabels();
})();
</script>
</body>
</html>
