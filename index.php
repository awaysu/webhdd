<?php
session_start();

// 設定基礎目錄
$baseDir = __DIR__;
$recycleDir = $baseDir . '/recycle';
$loginFile = $baseDir . '/login.config';

// 取得 Web 根目錄 (連結的起始位置)
$baseURL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

// 未登入時跳轉到登入頁面
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// 取得當前目錄（如果沒指定，預設為 $baseDir）
$currentDir = isset($_GET['dir']) ? realpath($baseDir . '/' . $_GET['dir']) : $baseDir;

// 防止目錄遍歷攻擊
if (strpos($currentDir, realpath($baseDir)) !== 0) {
    die("Access Denied");
}

// 確保 recycle 目錄存在
if (!is_dir($recycleDir)) {
    mkdir($recycleDir, 0755, true);
}

// --------------------------------------------------
// 輔助函式：從 update.history 移除指定檔名的所有紀錄
// --------------------------------------------------
function removeHistoryRecord($targetFilenames, $historyFile) {
    if (!file_exists($historyFile)) {
        return; // 沒有檔案就不做事
    }
    if (!is_array($targetFilenames)) {
        $targetFilenames = [$targetFilenames];
    }

    $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $newLines = [];
    foreach ($lines as $line) {
        // 格式：filename|user|time
        $parts = explode('|', $line);
        if (count($parts) !== 3) {
            continue; // 格式不符合，直接跳過或保留都可以
        }

        $filename = $parts[0];
        // 若檔名不在刪除清單內，才保留
        if (!in_array($filename, $targetFilenames, true)) {
            $newLines[] = $line;
        }
    }
    // 回寫檔案
    file_put_contents($historyFile, implode("\n", $newLines) . "\n");
}

// --------------------------------------------------
// 1. 處理建立目錄
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder'])) {
    $newFolderName = trim($_POST['new_folder']);
    if ($newFolderName !== '') {
        $newFolderPath = $currentDir . '/' . $newFolderName;
        if (!file_exists($newFolderPath)) {
            mkdir($newFolderPath, 0755);
        }
    }
    // 重新導向以避免表單重送
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 2. 處理上傳檔案 + 記錄 update.history (多檔案)
// --------------------------------------------------
$updateHistoryFile = $baseDir . '/update.history';

// 將上傳紀錄讀取到陣列，只顯示最後一筆同檔名紀錄
$uploadInfos = [];
if (file_exists($updateHistoryFile)) {
    $lines = file($updateHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // 格式：filename|user|time
        $parts = explode('|', $line);
        if (count($parts) === 3) {
            list($filename, $uploader, $uploadTime) = $parts;
            // 同檔名多筆時，保留最新一筆
            $uploadInfos[$filename] = [
                'user' => $uploader,
                'time' => $uploadTime
            ];
        }
    }
}

// 多檔案上傳
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload'])) {
    $fileCount = count($_FILES['file_upload']['name']);
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['file_upload']['name'][$i];
        $tmpName  = $_FILES['file_upload']['tmp_name'][$i];

        if (empty($fileName)) {
            continue; // 若沒選到檔案，跳過
        }

        $targetPath = $currentDir . '/' . $fileName;
        if (move_uploaded_file($tmpName, $targetPath)) {
            // 寫入 update.history
            $record = $fileName . '|' . $_SESSION['user'] . '|' . date('Y-m-d H:i:s') . "\n";
            file_put_contents($updateHistoryFile, $record, FILE_APPEND);
        }
    }
    // 上傳後重新導向
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 3. 處理刪除檔案 / 刪除資料夾 → 回收筒 (加上"時間戳.原本檔名")
// --------------------------------------------------

// 刪除檔案
if (isset($_GET['delete'])) {
    $deleteFile = $_GET['delete'];
    $deletePath = $currentDir . '/' . $deleteFile;

    if (file_exists($deletePath) && is_file($deletePath)) {
        // 新格式：YYMMDDHHMMSS.原本檔名
        $newName = date('ymdHis') . '.' . $deleteFile;
        rename($deletePath, $recycleDir . '/' . $newName);

        // 移除 update.history 的紀錄
        removeHistoryRecord($deleteFile, $updateHistoryFile);
    }

    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// 刪除資料夾
if (isset($_GET['delete_folder'])) {
    $deleteFolder = $_GET['delete_folder'];
    $deleteFolderPath = $currentDir . '/' . $deleteFolder;

    if (file_exists($deleteFolderPath) && is_dir($deleteFolderPath)) {
        // 同樣時間戳 + '.' + 資料夾名稱
        $newRecycleName = $recycleDir . '/' . date('ymdHis') . '.' . $deleteFolder;
        rename($deleteFolderPath, $newRecycleName);

        // 資料夾裡所有檔案也要從 update.history 移除
        $folderFiles = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($newRecycleName));
        foreach ($rii as $fileInfo) {
            if ($fileInfo->isFile()) {
                $folderFiles[] = $fileInfo->getFilename(); 
            }
        }
        removeHistoryRecord($folderFiles, $updateHistoryFile);
    }

    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 4. 取得目錄下(含子目錄)所有檔案（遞迴）
// --------------------------------------------------
function getAllFilesRecursively($dir, $baseDir) {
    $results = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        // 排除不需顯示 / 特殊檔
        if (in_array($item, [
            'recycle', 'login.config', 'index.php',
            'login.php', 'logout.php', 'update.history',
            'settings.php' // 不顯示 settings.php
        ])) {
            continue;
        }

        $fullPath = $dir . '/' . $item;
        if (is_dir($fullPath)) {
            // 遞迴往下
            $results = array_merge($results, getAllFilesRecursively($fullPath, $baseDir));
        } else {
            $relative = str_replace($baseDir, '', $fullPath);
            $results[] = $relative;
        }
    }
    return $results;
}

// --------------------------------------------------
// 5. 搜尋邏輯：若無搜尋關鍵字 -> 顯示當前目錄；有 -> 遞迴搜尋
// --------------------------------------------------
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($searchQuery === '') {
    // 普通模式
    $allItems = scandir($currentDir);
    $filesOrDirs = array_filter($allItems, function($file) {
        // 同樣排除 settings.php
        return !in_array($file, [
            '.', '..', 'recycle', 'login.config', 'index.php',
            'login.php', 'logout.php', 'update.history', 'settings.php'
        ]);
    });

    $listMode = 'normal';
    $itemsForDisplay = $filesOrDirs;
} else {
    // 搜尋模式
    $allFiles = getAllFilesRecursively($currentDir, $baseDir);

    // 不分大小寫，比對檔名
    $matchedFiles = array_filter($allFiles, function($relPath) use ($searchQuery) {
        $filename = basename($relPath);
        return stripos($filename, $searchQuery) !== false;
    });

    $listMode = 'search';
    $itemsForDisplay = $matchedFiles;
}

// 顯示用的路徑（相對 $baseDir）
$displayPath = str_replace($baseDir, '', $currentDir);
$displayPath = $displayPath ? $displayPath : '/';

// --------------------
// 副檔名分流：
//   - txt、pdf -> 新分頁 (target="_blank")
//   - doc, docx, xls, xlsx, ppt, pptx -> 強制下載 (download)
//   - 其餘依需求自行調整
// --------------------
$openInNewTab = ['txt', 'pdf']; 
$forceDownload = ['doc','docx','xls','xlsx','ppt','pptx'];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>簡易網路硬碟</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { color: #333; text-align: center; font-size: 28px; margin-bottom: 5px; }
        .path { text-align: center; font-size: 18px; color: #666; }
        .file-list {
            max-width: 900px; /* 1.5倍寬度 */
            margin: 0 auto;
            text-align: left;
        }
        ul { list-style: none; padding: 0; margin: 0; }
        
        /* 交錯行背景：1,3,5... (odd) -> AliceBlue; 2,4,6... (even) -> white */
        .file-list ul li:nth-child(odd) {
            background-color: #F0F8FF; /* 很淡的淺藍色 */
        }
        .file-list ul li:nth-child(even) {
            background-color: #FFFFFF;
        }
        
        li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .delete-btn, .info-btn {
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
            padding: 5px;
        }
        .delete-btn { color: red; }
        .info-btn { color: blue; margin-right: 5px; }
        .delete-btn:hover { color: darkred; }
        .info-btn:hover { color: darkblue; }
        .bottom-section { margin-top: 20px; padding-top: 10px; border-top: 2px solid #ccc; font-size: 18px; text-align: center; }
        input, button { padding: 8px; margin-right: 5px; }
        .logout { text-align: center; margin-top: 30px; font-size: 16px; }
        hr { width: 100%; }
        .search-form {
            text-align: center;
            margin: 15px 0;
        }
        a {
            text-decoration: none;
            color: #333;
        }
        a:hover {
            color: #666;
        }
        /* 小字體的程式碼下載 */
        .footer-download {
            text-align: center;
            margin-top: 20px;
            font-size: 12px; /* 文字變小 */
        }
    </style>
    <script>
        function confirmDelete(fileName, isFolder) {
            let message = isFolder ? "確定要刪除此目錄嗎？" : "確定要刪除此檔案嗎？";
            if (confirm(message)) {
                window.location.href = isFolder
                    ? "?dir=<?php echo urlencode(str_replace($baseDir, '', $currentDir)); ?>&delete_folder=" + encodeURIComponent(fileName)
                    : "?dir=<?php echo urlencode(str_replace($baseDir, '', $currentDir)); ?>&delete=" + encodeURIComponent(fileName);
            }
        }

        function showFileInfo(filename, uploader, time) {
            alert(
                "檔案名稱：" + filename +
                "\n上傳者：" + uploader +
                "\n上傳時間：" + time
            );
        }
    </script>
</head>
<body>
    <!-- 標題只顯示文字，無超連結 -->
    <h2>簡易網路硬碟</h2>

    <!-- Home icon, 點擊回根目錄 -->
    <p class="path">
        <a href="?dir=" title="回到根目錄" style="margin-right: 10px; text-decoration: none;">🏠</a>
        <strong>當前路徑：</strong> <?php echo htmlspecialchars($displayPath); ?>
    </p>
    <hr>

    <!-- 搜尋表單 -->
    <div class="search-form">
        <form method="GET">
            <input type="hidden" name="dir" value="<?php echo isset($_GET['dir']) ? htmlspecialchars($_GET['dir']) : ''; ?>">
            <input type="text" name="search" placeholder="搜尋檔案" value="<?php echo htmlspecialchars($searchQuery); ?>">
            <button type="submit">搜尋</button>
        </form>
    </div>

    <div class="file-list">
        <ul>
            <?php if ($listMode === 'normal'): ?>
                <!-- 普通模式：顯示當前資料夾的檔案/子目錄 -->
                <?php if ($currentDir !== $baseDir): ?>
                    <li>
                        <a href="?dir=<?php echo urlencode(dirname(str_replace($baseDir, '', $currentDir))); ?>">
                            🔙 返回上層
                        </a>
                    </li>
                <?php endif; ?>

                <?php foreach ($itemsForDisplay as $file):
                    $filePath = $currentDir . '/' . $file;
                    $relPath = str_replace($baseDir, '', $filePath);
                    $fullURL = $baseURL . ltrim($relPath, '/');
                    $isDir = is_dir($filePath);

                    // 副檔名
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $needNewTab  = in_array($extension, $openInNewTab);
                    $needDownload = in_array($extension, $forceDownload);

                    // 取出該檔在 update.history 的上傳者/時間
                    $infoUser = isset($uploadInfos[$file]) ? $uploadInfos[$file]['user'] : '不明';
                    $infoTime = isset($uploadInfos[$file]) ? $uploadInfos[$file]['time'] : '不明';
                ?>
                    <li>
                        <span>
                            <?php if ($isDir): ?>
                                📁 <a href="?dir=<?php echo urlencode($relPath); ?>">
                                    <?php echo htmlspecialchars($file); ?>
                                </a>
                            <?php else: ?>
                                📄 
                                <a href="<?php echo htmlspecialchars($fullURL); ?>"
                                   <?php echo $needNewTab ? 'target="_blank"' : ''; ?>
                                   <?php echo $needDownload ? 'download' : ''; ?>>
                                    <?php echo htmlspecialchars($file); ?>
                                </a>
                            <?php endif; ?>
                        </span>
                        <span>
                            <!-- info icon -->
                            <button class="info-btn"
                                onclick="showFileInfo(
                                    '<?php echo htmlspecialchars($file); ?>',
                                    '<?php echo htmlspecialchars($infoUser); ?>',
                                    '<?php echo htmlspecialchars($infoTime); ?>'
                                )">
                                ℹ
                            </button>
                            <!-- delete icon -->
                            <button class="delete-btn"
                                onclick="confirmDelete(
                                    '<?php echo htmlspecialchars($file); ?>',
                                    <?php echo $isDir ? 'true' : 'false'; ?>
                                )">
                                🗑
                            </button>
                        </span>
                    </li>
                <?php endforeach; ?>

            <?php else: ?>
                <!-- 搜尋模式：顯示符合關鍵字的檔案列表(含子目錄) -->
                <li><em>以下為「<?php echo htmlspecialchars($searchQuery); ?>」的搜尋結果：</em></li>
                <?php foreach ($itemsForDisplay as $relPath):
                    $filename = basename($relPath);
                    $fullFilePath = $baseDir . $relPath;
                    $fullURL = $baseURL . ltrim($relPath, '/');

                    // 副檔名
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $needNewTab  = in_array($extension, $openInNewTab);
                    $needDownload = in_array($extension, $forceDownload);

                    // 上傳資訊
                    $infoUser = isset($uploadInfos[$filename]) ? $uploadInfos[$filename]['user'] : '不明';
                    $infoTime = isset($uploadInfos[$filename]) ? $uploadInfos[$filename]['time'] : '不明';
                ?>
                    <li>
                        <span>
                            📄 
                            <a href="<?php echo htmlspecialchars($fullURL); ?>"
                               <?php echo $needNewTab ? 'target="_blank"' : ''; ?>
                               <?php echo $needDownload ? 'download' : ''; ?>>
                                <?php echo htmlspecialchars($relPath); ?>
                            </a>
                        </span>
                        <span>
                            <!-- info icon -->
                            <button class="info-btn"
                                onclick="showFileInfo(
                                    '<?php echo htmlspecialchars($filename); ?>',
                                    '<?php echo htmlspecialchars($infoUser); ?>',
                                    '<?php echo htmlspecialchars($infoTime); ?>'
                                )">
                                ℹ
                            </button>
                            <!-- delete icon -->
                            <button class="delete-btn"
                                onclick="confirmDelete(
                                    '<?php echo htmlspecialchars($filename); ?>',
                                    false
                                )">
                                🗑
                            </button>
                        </span>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <hr>

    <!-- 管理功能 (只有在非搜尋模式才顯示) -->
    <?php if ($searchQuery === ''): ?>
        <div class="bottom-section">
            <h3>📂 管理文件</h3>

            <!-- 建立目錄 -->
            <form method="POST">
                <input type="text" name="new_folder" placeholder="新目錄名稱" required>
                <button type="submit">📁 建立目錄</button>
            </form>

            <!-- 多檔案上傳 -->
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="file_upload[]" multiple required>
                <button type="submit">📤 上傳檔案</button>
            </form>
        </div>
    <?php endif; ?>

    <hr>

    <!-- 在設定 icon 左邊顯示使用者 -->
    <div class="logout">
        使用者：<?php echo htmlspecialchars($_SESSION['user']); ?> &nbsp; | &nbsp;
        <a href="settings.php">⚙ 設定</a> &nbsp; | &nbsp;
        <a href="logout.php">🔒 登出</a>
    </div>

    <!-- 程式碼下載 連結 (字小一點) -->
    <div class="footer-download">
        <a href="https://github.com/awaysu/webhdd" target="_blank">程式碼下載</a>
    </div>
</body>
</html>

