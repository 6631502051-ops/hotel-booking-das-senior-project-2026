<?php 

    require_once 'config/security.php';
    require_once 'config/db.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $c_password = $_POST['c_password'] ?? '';
        $urole = 'user';
        $ban ='0';

        if (empty($firstname)) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'กรุณากรอกชื่อ';
            header("location: login.php");
            exit();
        } else if (empty($lastname)) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'กรุณากรอกนามสกุล';
            header("location: login.php");
            exit();
        } else if (empty($email)) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'กรุณากรอกอีเมล';
            header("location: login.php");
            exit();
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'รูปแบบอีเมลไม่ถูกต้อง';
            header("location: login.php");
            exit();
        } else if (empty($password)) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'กรุณากรอกรหัสผ่าน';
            header("location: login.php");
            exit();
        } else if (strlen($_POST['password']) > 20 || strlen($_POST['password']) < 5) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'รหัสผ่านต้องมีความยาวระหว่าง 5 ถึง 20 ตัวอักษร';
            header("location: login.php");
            exit();
        } else if (empty($c_password)) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'กรุณายืนยันรหัสผ่าน';
            header("location: login.php");
            exit();
        } else if ($password != $c_password) {
            $_SESSION['auth_tab'] = 'signup';
            $_SESSION['signup_error'] = 'รหัสผ่านไม่ตรงกัน';
            header("location: login.php");
            exit();
        } else {
            try {

                $check_email = $conn->prepare("SELECT email_account FROM account WHERE email_account = :email");
                $check_email->bindParam(":email", $email);
                $check_email->execute();
                $row = $check_email->fetch(PDO::FETCH_ASSOC);

                if ($check_email->rowCount() > 0) {
                    $_SESSION['auth_tab'] = 'signup';
                    $_SESSION['signup_error'] = 'มีอีเมลนี้อยู่ในระบบแล้ว กรุณาเข้าสู่ระบบ';
                    header("location: login.php");
                    exit();
                } else if (!isset($_SESSION['signup_error'])) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO account (firstname, lastname, email_account, password_account, role_account, ban_account) 
                                            VALUES(:firstname, :lastname, :email, :password, :urole, $ban)");
                    $stmt->bindParam(":firstname", $firstname);
                    $stmt->bindParam(":lastname", $lastname);
                    $stmt->bindParam(":email", $email);
                    $stmt->bindParam(":password", $passwordHash);
                    $stmt->bindParam(":urole", $urole);
                    $stmt->execute();
                    $_SESSION['auth_tab'] = 'login';
                    $_SESSION['signup_success'] = 'สมัครสมาชิกเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ';
                    header("location: login.php");
                    exit();
                } else {
                    $_SESSION['auth_tab'] = 'signup';
                    $_SESSION['signup_error'] = 'มีบางอย่างผิดพลาด';
                    header("location: login.php");
                    exit();
                }

            } catch(PDOException $e) {
                $_SESSION['auth_tab'] = 'signup';
                $_SESSION['signup_error'] = 'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่อีกครั้ง';
                header("location: login.php");
                exit();
            }
        }
    }


?>