<?php
session_start();
$loginFile = __DIR__ . '/login.config';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $validUsers = file($loginFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($validUsers as $user) {
        list($storedUser, $storedPass) = explode(',', $user);
        if ($username === $storedUser && $password === $storedPass) {
            $_SESSION['user'] = $username;
            header("Location: index.php");
            exit;
        }
    }
    $error = "登入失敗，請檢查帳號或密碼。";
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>登入</title>
    <style>
        /* 使 body 以 Flex 置中，垂直與水平都集中 */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh; /* 使內容能完整置中 */
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .login-container {
            text-align: center;
            border: 1px solid #ccc;
            padding: 30px;
            border-radius: 8px;
            background-color: #f9f9f9;
        }
        .error {
            color: red;
            margin-bottom: 10px;
        }
        input {
            margin-bottom: 12px;
            padding: 6px;
            width: 200px;
        }
        button {
            padding: 8px 16px;
            cursor: pointer;
        }
        button:hover {
            background-color: #eee;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>登入簡易網路硬碟</h2>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="POST">
            <div>
                <label>帳號：</label><br>
                <input type="text" name="username" required>
            </div>
            <div>
                <label>密碼：</label><br>
                <input type="password" name="password" required>
            </div>
            <button type="submit">登入</button>
        </form>
    </div>
</body>
</html>

