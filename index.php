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
            continue; // 格式不符合，直接丟棄或保留都可以
        }

        $filename = $parts[0];
        // 如果檔名不在要刪除的清單內，才保留
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
// 2. 處理上傳檔案 + 記錄 update.history
// --------------------------------------------------
$updateHistoryFile = $baseDir . '/update.history';

// 將上傳紀錄讀取到陣列，方便後續顯示（只取最後一筆同檔名紀錄）
$uploadInfos = [];
if (file_exists($updateHistoryFile)) {
    $lines = file($updateHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // 格式：filename|user|time
        $parts = explode('|', $line);
        if (count($parts) === 3) {
            list($filename, $uploader, $uploadTime) = $parts;
            // 若同檔名有多筆，只留下最後一筆 (最新)
            $uploadInfos[$filename] = [
                'user' => $uploader,
                'time' => $uploadTime
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload'])) {
    if (!empty($_FILES['file_upload']['name'])) {
        $targetPath = $currentDir . '/' . $_FILES['file_upload']['name'];
        if (move_uploaded_file($_FILES['file_upload']['tmp_name'], $targetPath)) {
            // 寫入 update.history
            $record = $_FILES['file_upload']['name']
                    . '|' . $_SESSION['user']
                    . '|' . date('Y-m-d H:i:s') . "\n";
            file_put_contents($updateHistoryFile, $record, FILE_APPEND);
        }
    }
    // 重新導向以避免表單重送
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 3. 處理刪除檔案 / 刪除資料夾（移動到 recycle 資料夾）
// --------------------------------------------------
if (isset($_GET['delete'])) {
    // 刪除單一檔案
    $deleteFile = $_GET['delete'];
    $deletePath = $currentDir . '/' . $deleteFile;

    if (file_exists($deletePath) && is_file($deletePath)) {
        // 1) 移到回收筒
        rename($deletePath, $recycleDir . '/' . $deleteFile);

        // 2) 刪除 update.history 裡相符的紀錄
        removeHistoryRecord($deleteFile, $updateHistoryFile);
    }

    // 刪除後重新導向
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

if (isset($_GET['delete_folder'])) {
    // 刪除資料夾（可選：連同裡面的檔案紀錄一起刪除）
    $deleteFolder = $_GET['delete_folder'];
    $deleteFolderPath = $currentDir . '/' . $deleteFolder;

    if (file_exists($deleteFolderPath) && is_dir($deleteFolderPath)) {
        // 先將該資料夾移到回收筒 (加上時間戳避免重名)
        $newRecycleName = $recycleDir . '/' . $deleteFolder . '_' . time();
        rename($deleteFolderPath, $newRecycleName);

        // 如果想同時刪除該資料夾裡所有檔案在 update.history 的紀錄，可以：
        $folderFiles = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($newRecycleName));
        foreach ($rii as $fileInfo) {
            if ($fileInfo->isFile()) {
                $folderFiles[] = $fileInfo->getFilename(); 
            }
        }
        removeHistoryRecord($folderFiles, $updateHistoryFile);
    }

    // 刪除後重新導向
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 4. 定義：取得目錄下(含子目錄)所有檔案（遞迴）
// --------------------------------------------------
function getAllFilesRecursively($dir, $baseDir) {
    $results = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        // 排除一些不顯示或不處理的特殊檔
        if (in_array($item, ['recycle','login.config','index.php','login.php','logout.php','update.history'])) {
            continue;
        }

        $fullPath = $dir . '/' . $item;
        if (is_dir($fullPath)) {
            // 遞迴往下搜子目錄
            $results = array_merge($results, getAllFilesRecursively($fullPath, $baseDir));
        } else {
            // 只要「檔案」的相對路徑
            $relative = str_replace($baseDir, '', $fullPath);
            $results[] = $relative;
        }
    }
    return $results;
}

// --------------------------------------------------
// 5. 搜尋邏輯：若無關鍵字，顯示當前資料夾；若有關鍵字，遞迴搜索
// --------------------------------------------------
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($searchQuery === '') {
    // 沒有搜尋：顯示當前資料夾
    $allItems = scandir($currentDir);
    // 過濾要顯示的檔案/資料夾
    $filesOrDirs = array_filter($allItems, function($file) {
        return !in_array($file, [
            '.', '..', 'recycle', 'login.config', 'index.php', 
            'login.php', 'logout.php', 'update.history'
        ]);
    });

    $listMode = 'normal';  // 顯示檔案＆資料夾
    $itemsForDisplay = $filesOrDirs;
} else {
    // 有搜尋：顯示符合條件的所有檔案(含子目錄)
    $allFiles = getAllFilesRecursively($currentDir, $baseDir);

    // 根據關鍵字過濾(不分大小寫)，只針對檔名（basename）比對
    $matchedFiles = array_filter($allFiles, function($relPath) use ($searchQuery) {
        $filename = basename($relPath);
        return stripos($filename, $searchQuery) !== false;
    });

    $listMode = 'search';  // 只顯示檔案
    $itemsForDisplay = $matchedFiles;
}

// 處理當前路徑的顯示
$displayPath = str_replace($baseDir, '', $currentDir);
$displayPath = $displayPath ? $displayPath : '/';

// --------------------
// 副檔名分流：
//   - txt、pdf：在新視窗開啟 (target="_blank")，不加 download
//   - doc, docx, xls, xlsx, ppt, pptx：直接下載 (加 download)
//   - 其餘視需要加到清單或預設下載
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
        .file-list { max-width: 600px; margin: 0 auto; text-align: left; }
        ul { list-style: none; padding: 0; }
        li { display: flex; justify-content: space-between; align-items: center; padding: 8px; border-bottom: 1px solid #ddd; }
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
    <!-- 點擊標題 -> 回到根目錄 (dir=空) -->
    <h2><a href="?dir=">簡易網路硬碟</a></h2>

    <p class="path"><strong>當前路徑：</strong> <?php echo htmlspecialchars($displayPath); ?></p>
    <hr>

    <!-- 搜尋表單 -->
    <div class="search-form">
        <form method="GET">
            <!-- 保留當前資料夾路徑 (dir) -->
            <input type="hidden" name="dir" value="<?php echo isset($_GET['dir']) ? htmlspecialchars($_GET['dir']) : ''; ?>">
            <input type="text" name="search" placeholder="搜尋檔案" value="<?php echo htmlspecialchars($searchQuery); ?>">
            <button type="submit">搜尋</button>
        </form>
    </div>

    <div class="file-list">
        <ul>
            <?php if ($listMode === 'normal'): ?>
                <!-- 普通模式，顯示資料夾/檔案清單 -->
                <?php if ($currentDir !== $baseDir): ?>
                    <li>
                        <a href="?dir=<?php echo urlencode(dirname(str_replace($baseDir, '', $currentDir))); ?>">
                            🔙 返回上層
                        </a>
                    </li>
                <?php endif; ?>

                <?php foreach ($itemsForDisplay as $file):
                    $filePath = $currentDir . '/' . $file;    // 實體路徑
                    $relPath = str_replace($baseDir, '', $filePath);
                    $fullURL = $baseURL . ltrim($relPath, '/');
                    $isDir = is_dir($filePath);

                    // 取得副檔名 (小寫)
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                    // 檢查要不要新開分頁、或是直接下載
                    $needNewTab  = in_array($extension, $openInNewTab);
                    $needDownload = in_array($extension, $forceDownload);

                    // 從 update.history 取出上傳資訊
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
                                <!-- 針對 txt, pdf -> target="_blank"；針對 doc, xls, ppt -> download；其餘自行決定 -->
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
                <!-- 搜尋模式，顯示所有符合檔案(含子目錄) -->
                <li><em>以下為「<?php echo htmlspecialchars($searchQuery); ?>」的搜尋結果：</em></li>
                <?php foreach ($itemsForDisplay as $relPath):
                    // $relPath 例如 /子資料夾/xxx.txt
                    $filename = basename($relPath);
                    $fullFilePath = $baseDir . $relPath;  // 實體路徑
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

    <!-- 只有在「非搜尋模式」下才顯示管理功能 -->
    <?php if ($searchQuery === ''): ?>
        <div class="bottom-section">
            <h3>📂 管理文件</h3>

            <!-- 建立目錄 -->
            <form method="POST">
                <input type="text" name="new_folder" placeholder="新目錄名稱" required>
                <button type="submit">📁 建立目錄</button>
            </form>

            <!-- 上傳檔案 -->
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="file_upload" required>
                <button type="submit">📤 上傳檔案</button>
            </form>
        </div>
    <?php endif; ?>

    <hr>

    <div class="logout">
        <a href="logout.php">🔒 登出</a>
    </div>
</body>
</html>

