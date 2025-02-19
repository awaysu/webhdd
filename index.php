<?php
session_start();

// === 基本設定 ===
$baseDir     = __DIR__;
$recycleDir  = $baseDir . '/recycle';
$loginFile   = $baseDir . '/login.config';
$historyLog  = $baseDir . '/history.log';   // 新增的 history.log

// 取得 Web 根目錄
$baseURL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

// 未登入時跳轉到登入頁面
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// 取得當前目錄
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
// 寫入 history.log
// --------------------------------------------------
function writeHistoryLog($user, $action, $fullPath) {
    global $historyLog, $baseDir;
    if (empty($fullPath)) return;

    $real = realpath($fullPath);
    if (!$real) return;  // 防呆

    $relative = str_replace($baseDir, '', $real);
    // 時間：yyyyMMddHHmm
    $timeStr = date('YmdHi');
    $line = sprintf("%s,%s,%s,%s\n", $user, $timeStr, $action, $relative);
    file_put_contents($historyLog, $line, FILE_APPEND);
}

// --------------------------------------------------
// 輔助: 移除 update.history 指定檔名紀錄
// --------------------------------------------------
function removeHistoryRecord($targetFilenames, $historyFile) {
    if (!file_exists($historyFile)) {
        return;
    }
    if (!is_array($targetFilenames)) {
        $targetFilenames = [$targetFilenames];
    }
    $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $newLines = [];
    foreach ($lines as $line) {
        $parts = explode('|', $line);
        if (count($parts) !== 3) {
            continue;
        }
        $fn = $parts[0];
        if (!in_array($fn, $targetFilenames, true)) {
            $newLines[] = $line;
        }
    }
    file_put_contents($historyFile, implode("\n", $newLines) . "\n");
}

// --------------------------------------------------
// 1. 建立目錄
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder'])) {
    $newFolderName = trim($_POST['new_folder']);
    if ($newFolderName !== '') {
        $newFolderPath = $currentDir . '/' . $newFolderName;
        if (!file_exists($newFolderPath)) {
            mkdir($newFolderPath, 0755);
        }
    }
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 2. 上傳檔案 + update.history
// --------------------------------------------------
$updateHistoryFile = $baseDir . '/update.history';
$uploadInfos = [];

if (file_exists($updateHistoryFile)) {
    $lines = file($updateHistoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode('|', $line);
        if (count($parts) === 3) {
            list($filename, $uploader, $uploadTime) = $parts;
            // 同檔名多筆，保留最後一筆
            $uploadInfos[$filename] = [
                'user' => $uploader,
                'time' => $uploadTime
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_upload'])) {
    $count = count($_FILES['file_upload']['name']);
    for ($i=0; $i<$count; $i++) {
        $fileName = $_FILES['file_upload']['name'][$i];
        $tmpName  = $_FILES['file_upload']['tmp_name'][$i];
        if (empty($fileName)) {
            continue;
        }
        $targetPath = $currentDir . '/' . $fileName;
        if (move_uploaded_file($tmpName, $targetPath)) {
            // update.history
            $record = $fileName . '|' . $_SESSION['user'] . '|' . date('Y-m-d H:i:s') . "\n";
            file_put_contents($updateHistoryFile, $record, FILE_APPEND);

            // history.log -> upload
            writeHistoryLog($_SESSION['user'], 'upload', $targetPath);
        }
    }
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 3. 刪除檔案 / 資料夾
// --------------------------------------------------
if (isset($_GET['delete'])) {
    $deleteFile = $_GET['delete'];
    $deletePath = $currentDir . '/' . $deleteFile;
    if (file_exists($deletePath) && is_file($deletePath)) {
        // history.log -> del
        writeHistoryLog($_SESSION['user'], 'del', $deletePath);

        // 移至回收筒
        $newName = date('ymdHis') . '.' . $deleteFile;
        rename($deletePath, $recycleDir . '/' . $newName);

        // 移除 update.history
        removeHistoryRecord($deleteFile, $updateHistoryFile);
    }
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

if (isset($_GET['delete_folder'])) {
    $deleteFolder = $_GET['delete_folder'];
    $deleteFolderPath = $currentDir . '/' . $deleteFolder;
    if (file_exists($deleteFolderPath) && is_dir($deleteFolderPath)) {
        // history.log -> del
        writeHistoryLog($_SESSION['user'], 'del', $deleteFolderPath);

        $newRecycleName = $recycleDir . '/' . date('ymdHis') . '.' . $deleteFolder;
        rename($deleteFolderPath, $newRecycleName);

        // 資料夾內所有檔案 from update.history
        $folderFiles = [];
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($newRecycleName));
        foreach ($rii as $info) {
            if ($info->isFile()) {
                $folderFiles[] = $info->getFilename();
            }
        }
        removeHistoryRecord($folderFiles, $updateHistoryFile);
    }
    header("Location: ?dir=" . urlencode(str_replace($baseDir, '', $currentDir)));
    exit;
}

// --------------------------------------------------
// 4. 遞迴取得檔案(搜尋用)
// --------------------------------------------------
function getAllFilesRecursively($dir, $baseDir) {
    $results = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (in_array($item, [
            'recycle','history.log','login.config','index.php','login.php','logout.php','update.history','settings.php'
        ])) {
            continue;
        }
        $fullPath = $dir . '/' . $item;
        if (is_dir($fullPath)) {
            $results = array_merge($results, getAllFilesRecursively($fullPath, $baseDir));
        } else {
            $relative = str_replace($baseDir, '', $fullPath);
            $results[] = $relative;
        }
    }
    return $results;
}

// --------------------------------------------------
// 5. 搜尋或普通模式
// --------------------------------------------------
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($searchQuery === '') {
    // 普通模式
    $allItems = scandir($currentDir);
    $filesOrDirs = array_filter($allItems, function($f) {
        return !in_array($f, [
            '.', '..','recycle','login.config','index.php','login.php',
            'logout.php','update.history','settings.php','history.log'
        ]);
    });
    // 資料夾在前, 檔案在後
    $dirs = [];
    $files = [];
    foreach ($filesOrDirs as $f) {
        if (is_dir($currentDir . '/' . $f)) {
            $dirs[] = $f;
        } else {
            $files[] = $f;
        }
    }
    $itemsForDisplay = array_merge($dirs, $files);
    $listMode = 'normal';
} else {
    // 搜尋模式
    $allFiles = getAllFilesRecursively($currentDir, $baseDir);
    $matched = array_filter($allFiles, function($rp) use ($searchQuery) {
        $fn = basename($rp);
        return stripos($fn, $searchQuery) !== false;
    });
    $itemsForDisplay = $matched;
    $listMode = 'search';
}

// --------------------------------------------------
// 檔案大小/建立時間
// --------------------------------------------------
function formatFileSize($bytes) {
    if ($bytes >= 1024*1024) {
        return round($bytes/(1024*1024),2).'M';
    } else {
        return round($bytes/1024,2).'K';
    }
}

// --------------------------------------------------
// 副檔名：新視窗 or 下載
// --------------------------------------------------
$openInNewTab = ['txt','pdf','jpg','bmp','png','html','htm'];
$forceDownload= ['doc','docx','xls','xlsx','ppt','pptx'];

// 顯示路徑
$displayPath = str_replace($baseDir, '', $currentDir);
$displayPath = $displayPath ? $displayPath : '/';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>簡易網路硬碟</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2   { color: #333; text-align: center; font-size: 28px; margin-bottom: 5px; }
        .path{ text-align: center; font-size: 18px; color: #666; }

        /* 整個容器 80% */
        .file-list {
            width: 80%;
            margin: 0 auto;
            text-align: left;
        }
        ul { list-style: none; padding: 0; margin: 0; }

        /* li: flex */
        .file-list ul li {
            display: flex;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .file-list ul li:nth-child(odd) {
            background-color: #F0F8FF;
        }
        .file-list ul li:nth-child(even) {
            background-color: #FFFFFF;
        }

        /* 左半: filename 65% */
        .filename {
            width: 65%;
            text-align: left;
            overflow: hidden;
        }

        /* 右半: 包含 檔案資訊 & actions ，各分兩塊 */
        .rightside {
            width: 35%;
            display: flex;
            justify-content: space-between; /* 左是 fileinfo, 右是 actions */
            align-items: center;
        }

        /* 檔案資訊置左 */
        .fileinfo {
            color: #666;
            font-size: 14px;
            text-align: left;
            margin-right: 5px;
        }

        /* 按鈕區 置最右 */
        .actions {
            text-align: right;
            min-width: 50px;
        }

        .delete-btn, .info-btn {
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
            padding: 5px;
        }
        .delete-btn { color: red; }
        .info-btn   { color: blue; margin-right: 5px; }
        .delete-btn:hover { color: darkred; }
        .info-btn:hover   { color: darkblue; }

        .bottom-section {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #ccc;
            font-size: 18px;
            text-align: center;
        }
        input, button { padding: 8px; margin-right: 5px; }
        .logout {
            text-align: center; 
            margin-top: 30px; 
            font-size: 16px;
        }
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
        .footer-download {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
        }
    </style>
    <script>
    function confirmDelete(fileName, isFolder) {
        let msg = isFolder ? "確定要刪除此目錄嗎？" : "確定要刪除此檔案嗎？";
        if (confirm(msg)) {
            window.location.href = isFolder
                ? "?dir=<?php echo urlencode(str_replace($baseDir, '', $currentDir)); ?>&delete_folder=" + encodeURIComponent(fileName)
                : "?dir=<?php echo urlencode(str_replace($baseDir, '', $currentDir)); ?>&delete=" + encodeURIComponent(fileName);
        }
    }
    function showFileInfo(fn, uploader, time) {
        alert("檔案名稱：" + fn + "\n上傳者：" + uploader + "\n上傳時間：" + time);
    }
    </script>
</head>
<body>
    <h2>簡易網路硬碟</h2>

    <p class="path">
        <a href="?dir=" style="margin-right: 10px; text-decoration: none;">🏠</a>
        <strong>當前路徑：</strong> <?php echo htmlspecialchars($displayPath); ?>
    </p>
    <hr>

    <!-- 搜尋表單 -->
    <div class="search-form">
        <form method="GET">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($_GET['dir'] ?? ''); ?>">
            <input type="text" name="search" placeholder="搜尋檔案"
                   value="<?php echo htmlspecialchars($searchQuery); ?>">
            <button type="submit">搜尋</button>
        </form>
    </div>

    <div class="file-list">
        <ul>
            <?php if ($listMode === 'normal'): ?>
                <?php if ($currentDir !== $baseDir): ?>
                    <li>
                        <a href="?dir=<?php echo urlencode(dirname(str_replace($baseDir, '', $currentDir))); ?>">
                            🔙 返回上層
                        </a>
                    </li>
                <?php endif; ?>

                <?php foreach ($itemsForDisplay as $file):
                    $filePath = $currentDir . '/' . $file;
                    $relPath  = str_replace($baseDir, '', $filePath);
                    $isDir    = is_dir($filePath);

                    // 檔案資訊 (建立時間 + 大小)
                    $infoStr = '';
                    if (!$isDir) {
                        $ctime  = filectime($filePath);
                        $ftime  = date('Y-m-d H:i:s', $ctime);
                        $size   = filesize($filePath);
                        $sizeStr= formatFileSize($size);
                        $infoStr= $ftime . ", Size: " . $sizeStr;
                    }

                    // 開啟方式
                    $ext    = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $nTab   = in_array($ext, $openInNewTab);
                    $dLoad  = in_array($ext, $forceDownload);

                    // update.history
                    $uploader= $uploadInfos[$file]['user'] ?? '不明';
                    $upTime  = $uploadInfos[$file]['time'] ?? '不明';
                ?>
                    <li>
                        <!-- 左: 檔名(70%) -->
                        <span class="filename">
                            <?php if ($isDir): ?>
                                📁 <a href="?dir=<?php echo urlencode($relPath); ?>">
                                    <?php echo htmlspecialchars($file); ?>
                                </a>
                            <?php else: ?>
                                📄 <a href="<?php echo htmlspecialchars($baseURL . ltrim($relPath, '/')); ?>"
                                      <?php echo $nTab ? 'target="_blank"' : ''; ?>
                                      <?php echo $dLoad ? 'download' : ''; ?>>
                                    <?php echo htmlspecialchars($file); ?>
                                </a>
                            <?php endif; ?>
                        </span>

                        <!-- 右: 檔案資訊(左), info/delete(右) -->
                        <span class="rightside">
                            <span class="fileinfo">
                                <?php echo !$isDir ? htmlspecialchars($infoStr) : ''; ?>
                            </span>
                            <span class="actions">
                                <button class="info-btn"
                                  onclick="showFileInfo(
                                    '<?php echo htmlspecialchars($file); ?>',
                                    '<?php echo htmlspecialchars($uploader); ?>',
                                    '<?php echo htmlspecialchars($upTime); ?>'
                                  )">ℹ</button>
                                <button class="delete-btn"
                                  onclick="confirmDelete(
                                    '<?php echo htmlspecialchars($file); ?>',
                                    <?php echo $isDir?'true':'false'; ?>
                                  )">🗑</button>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>

            <?php else: /* 搜尋模式 */ ?>
                <li><em>以下為「<?php echo htmlspecialchars($searchQuery); ?>」的搜尋結果：</em></li>
                <?php foreach ($itemsForDisplay as $relPath):
                    $fullPath = $baseDir . $relPath;
                    $filename = basename($relPath);
                    $isDir    = is_dir($fullPath);

                    $infoStr = '';
                    if (!$isDir) {
                        $ctime  = filectime($fullPath);
                        $ftime  = date('Y-m-d H:i:s', $ctime);
                        $size   = filesize($fullPath);
                        $sizeStr= formatFileSize($size);
                        $infoStr= $ftime . ", Size: " . $sizeStr;
                    }

                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $nTab   = in_array($ext, $openInNewTab);
                    $dLoad  = in_array($ext, $forceDownload);

                    $uploader= $uploadInfos[$filename]['user'] ?? '不明';
                    $upTime  = $uploadInfos[$filename]['time'] ?? '不明';
                ?>
                    <li>
                        <span class="filename">
                            <?php if ($isDir): ?>
                                📁 <a href="?dir=<?php echo urlencode(dirname($relPath)); ?>">
                                    <?php echo htmlspecialchars($filename); ?>
                                </a>
                            <?php else: ?>
                                📄 <a href="<?php echo htmlspecialchars($baseURL . ltrim($relPath, '/')); ?>"
                                      <?php echo $nTab ? 'target="_blank"' : ''; ?>
                                      <?php echo $dLoad ? 'download' : ''; ?>>
                                    <?php echo htmlspecialchars($filename); ?>
                                </a>
                            <?php endif; ?>
                        </span>
                        <span class="rightside">
                            <span class="fileinfo">
                                <?php echo !$isDir ? htmlspecialchars($infoStr) : ''; ?>
                            </span>
                            <span class="actions">
                                <button class="info-btn"
                                  onclick="showFileInfo(
                                    '<?php echo htmlspecialchars($filename); ?>',
                                    '<?php echo htmlspecialchars($uploader); ?>',
                                    '<?php echo htmlspecialchars($upTime); ?>'
                                  )">ℹ</button>
                                <button class="delete-btn"
                                  onclick="confirmDelete(
                                    '<?php echo htmlspecialchars($filename); ?>',
                                    <?php echo $isDir?'true':'false'; ?>
                                  )">🗑</button>
                            </span>
                        </span>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <hr>

    <!-- 若非搜尋模式，顯示管理功能 -->
    <?php if ($searchQuery === ''): ?>
    <div class="bottom-section">
        <h3>📂 管理文件</h3>
        <form method="POST">
            <input type="text" name="new_folder" placeholder="新目錄名稱" required>
            <button type="submit">📁 建立目錄</button>
        </form>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="file_upload[]" multiple required>
            <button type="submit">📤 上傳檔案</button>
        </form>
    </div>
    <?php endif; ?>

    <hr>

    <div class="logout">
        使用者：<?php echo htmlspecialchars($_SESSION['user']); ?> &nbsp; | &nbsp;
        <a href="settings.php">⚙ 設定</a> &nbsp; | &nbsp;
        <a href="logout.php">🔒 登出</a>
    </div>

    <div class="footer-download">
        <a href="https://github.com/awaysu/webhdd" target="_blank">程式碼下載</a>
    </div>
</body>
</html>
