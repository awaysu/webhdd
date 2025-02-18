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
</head>
<body>
    <h2>登入簡易網路硬碟</h2>
    <?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
    <form method="POST">
        帳號: <input type="text" name="username" required><br>
        密碼: <input type="password" name="password" required><br>
        <button type="submit">登入</button>
    </form>
</body>
</html>

