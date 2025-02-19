<?php
session_start();

// 如果沒有登入，轉回 login.php
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// login.config 的路徑
$configFile = __DIR__ . '/login.config';

// 用於顯示訊息
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 取得表單輸入
    $oldPassword = trim($_POST['old_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    // 基本檢查
    if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $message = '請完整填寫所有欄位。';
    } elseif ($newPassword !== $confirmPassword) {
        $message = '「新密碼」與「確認新密碼」不一致。';
    } else {
        // 嘗試讀取 login.config 並更新
        if (file_exists($configFile)) {
            $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $foundUser = false;
            $newLines = [];

            foreach ($lines as $line) {
                // 每行格式： username,password
                $parts = explode(',', $line);
                if (count($parts) !== 2) {
                    // 這行格式不正確，可依需求自行處理
                    $newLines[] = $line;
                    continue;
                }
                
                list($username, $password) = $parts;
                
                // 只針對目前登入者做修改
                if ($username === $_SESSION['user']) {
                    // 比對舊密碼
                    if ($password === $oldPassword) {
                        // 修改為新密碼
                        $newLines[] = $username . ',' . $newPassword;
                        $foundUser = true;
                    } else {
                        // 舊密碼錯誤
                        $message = '舊密碼不正確。';
                        $newLines[] = $line; // 保留原行
                    }
                } else {
                    // 其他使用者不動
                    $newLines[] = $line;
                }
            }

            // 如果有成功修改，就覆寫 login.config
            if ($foundUser && $message === '') {
                file_put_contents($configFile, implode("\n", $newLines) . "\n");
                $message = '密碼修改成功！';
            } elseif (!$foundUser && $message === '') {
                // 若連自己 username 都沒找到
                $message = '找不到使用者資料，修改失敗。';
            }
        } else {
            $message = '找不到 login.config，無法修改密碼。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>修改密碼</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 400px; margin: 0 auto; }
        .message { margin-top: 10px; color: red; }
        input { padding: 6px; width: 100%; margin-bottom: 10px; }
        button { padding: 8px; cursor: pointer; }
        a { text-decoration: none; color: #333; }
        a:hover { color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h2>修改密碼</h2>

        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <!-- 在這裡顯示目前登入的使用者 -->
        <p style="margin-top: 20px; font-size: 16px; color: #333;">
            使用者：<?php echo htmlspecialchars($_SESSION['user']); ?>
        </p>

        <form method="POST">
            <label for="old_password">舊密碼：</label>
            <input type="password" id="old_password" name="old_password" required>

            <label for="new_password">新密碼：</label>
            <input type="password" id="new_password" name="new_password" required>

            <label for="confirm_password">確認新密碼：</label>
            <input type="password" id="confirm_password" name="confirm_password" required>

            <button type="submit">確認修改</button>
        </form>

        <p style="margin-top:20px;">
            <a href="index.php">返回首頁</a>
        </p>
    </div>
</body>
</html>

