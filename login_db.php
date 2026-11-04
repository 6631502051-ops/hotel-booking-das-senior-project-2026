<?php 

    require_once 'config/security.php';
    require_once 'config/db.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

      
        if (empty($email)) {
            $_SESSION['auth_tab'] = 'login';
            $_SESSION['login_error'] = 'กรุณากรอกอีเมล';
            header("location: login.php");
            exit();
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['auth_tab'] = 'login';
            $_SESSION['login_error'] = 'รูปแบบอีเมลไม่ถูกต้อง';
            header("location: login.php");
            exit();
        } else if (empty($password)) {
            $_SESSION['auth_tab'] = 'login';
            $_SESSION['login_error'] = 'กรุณากรอกรหัสผ่าน';
            header("location: login.php");
            exit();
        } else if (strlen($_POST['password']) > 20 || strlen($_POST['password']) < 5) {
            $_SESSION['auth_tab'] = 'login';
            $_SESSION['login_error'] = 'รหัสผ่านต้องมีความยาวระหว่าง 5 ถึง 20 ตัวอักษร';
            header("location: login.php");
            exit();
        } else {
            try {

                $check_data = $conn->prepare("SELECT * FROM account WHERE email_account = :email");
                $check_data->bindParam(":email", $email);
                $check_data->execute();
                $row = $check_data->fetch(PDO::FETCH_ASSOC);

                if ($check_data->rowCount() > 0) {

                    if ($row['ban_account'] == 1 ){
                        $_SESSION['auth_tab'] = 'login';
                        $_SESSION['login_error'] = 'บัญชีนี้ถูกระงับการใช้งาน';
                        header("location: login.php");
                        exit();
                    }

                    if ($email == $row['email_account']) {
                        if (password_verify($password, $row['password_account'])) {
                            session_regenerate_id(true);
                            unset($_SESSION['login_error'], $_SESSION['signup_error']);
                            if ($row['role_account'] == 'admin') {
                                unset($_SESSION['id_account']);
                                $_SESSION['admin_login'] = $row['id_account'];
                                header("location: admin.php");
                            } else {
                                unset($_SESSION['admin_login']);
                                $_SESSION['id_account'] = $row['id_account'];
                                $_SESSION['firstname'] = $row['firstname'];
                                header("location: index.php");
                            }
                            exit();
                        } else {
                            $_SESSION['auth_tab'] = 'login';
                            $_SESSION['login_error'] = 'รหัสผ่านไม่ถูกต้อง';
                            header("location: login.php");
                            exit();
                        }
                    } else {
                        $_SESSION['auth_tab'] = 'login';
                        $_SESSION['login_error'] = 'อีเมลไม่ถูกต้อง';
                        header("location: login.php");
                        exit();
                    }
                } else {
                    $_SESSION['auth_tab'] = 'login';
                    $_SESSION['login_error'] = 'ไม่พบบัญชีผู้ใช้นี้ในระบบ';
                    header("location: login.php");
                    exit();
                }

            } catch(PDOException $e) {
                $_SESSION['auth_tab'] = 'login';
                $_SESSION['login_error'] = 'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่อีกครั้ง';
                header("location: login.php");
                exit();
            }
        }
    }


?>