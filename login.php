<?php 
  require_once 'config/security.php';
    require_once 'config/db.php';

    $activeTab = 'login';
    if (isset($_SESSION['auth_tab']) && in_array($_SESSION['auth_tab'], ['login', 'signup'], true)) {
      $activeTab = $_SESSION['auth_tab'];
      unset($_SESSION['auth_tab']);
    }
?>

<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log Horizon | Hotels Chiang Rai</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,400&family=Work+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="login.css">
</head>
<body class="auth-body">

<a href="index.php" class="auth-back">
  <span class="logo-mark">L</span>
  <span class="logo-text-dark">Log Horizon | <em>Hotels Chiang Rai</em></span>
</a>

<main class="auth-shell">
  <div class="auth-mist" aria-hidden="true"></div>

  <div class="auth-card">

    <p class="auth-context" id="authContext" hidden>
      กำลังดำเนินการต่อจาก <strong id="authContextDetail"></strong>
    </p>

    <div class="auth-tabs" role="tablist">
      <button type="button" class="auth-tab <?php echo $activeTab === 'login' ? 'active' : ''; ?>" id="tabLogin" role="tab" aria-selected="<?php echo $activeTab === 'login' ? 'true' : 'false'; ?>">Login</button>
      <button type="button" class="auth-tab <?php echo $activeTab === 'signup' ? 'active' : ''; ?>" id="tabSignup" role="tab" aria-selected="<?php echo $activeTab === 'signup' ? 'true' : 'false'; ?>">Register</button>
    </div>

    <?php if(isset($_SESSION['login_error'])) { ?>
      <p class="form-error" style="margin-bottom:1rem;">
        <?php
          echo $_SESSION['login_error'];
          unset($_SESSION['login_error']);
        ?>
      </p>
    <?php } ?>

    <?php if(isset($_SESSION['error'])) { ?>
      <p class="form-error" style="margin-bottom:1rem;">
        <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
      </p>
    <?php } ?>

    <?php if(isset($_SESSION['signup_error'])) { ?>
      <p class="form-error" style="margin-bottom:1rem;">
        <?php
          echo $_SESSION['signup_error'];
          unset($_SESSION['signup_error']);
        ?>
      </p>
    <?php } ?>

    <?php if(isset($_SESSION['signup_success'])) { ?>
      <p class="auth-context" style="margin-bottom:1rem;" >
        <?php
          echo $_SESSION['signup_success'];
          unset($_SESSION['signup_success']);
        ?>
      </p>
    <?php } ?>

    <!-- LOGIN FORM -->
    <form class="auth-form" id="loginForm" action="login_db.php" method="post">
      <?php echo csrf_field(); ?>
      <div class="form-field">
        <label for="loginEmail">Email</label>
        <input type="email" id="loginEmail" name="email" placeholder="[email protected]" required>
      </div>
      <div class="form-field">
        <label for="loginPassword">Password</label>
        <input type="password" id="loginPassword" name="password" placeholder="••••••••" required minlength="6" maxlength="72">
      </div>
      <p class="form-error" id="loginError" hidden></p>
      <button type="submit" name="signin" class="btn btn-primary auth-submit">เข้าสู่ระบบ</button>
    </form>

    <!-- SIGNUP FORM -->
    <form class="auth-form" id="signupForm" action="signup_db.php" method="post" <?php echo $activeTab === 'signup' ? '' : 'hidden'; ?>>
      <?php echo csrf_field(); ?>
      <div class="form-field">
        <label for="signupFirstname">First Name</label>
        <input type="text" id="signupFirstname" name="firstname" placeholder="firstname" required>
      </div>
      <div class="form-field">
        <label for="signupLastname">Last Name</label>
        <input type="text" id="signupLastname" name="lastname" placeholder="lastname" required>
      </div>
      <div class="form-field">
        <label for="signupEmail">Email</label>
        <input type="email" id="signupEmail" name="email" placeholder="[email protected]" required>
      </div>
      <div class="form-field">
        <label for="signupPassword">Create Password</label>
        <input type="password" id="signupPassword" name="password" placeholder="At least 6 characters" required minlength="6" maxlength="72">
      </div>
      <div class="form-field">
        <label for="signupConfirmPassword">Confirm Password</label>
        <input type="password" id="signupConfirmPassword" name="c_password" placeholder="Repeat your password" required minlength="6" maxlength="72">
      </div>
      <p class="form-error" id="signupError" hidden></p>
      <button type="submit" name="signup" class="btn btn-primary auth-submit">สร้างบัญชี</button>
    </form>

    <p class="auth-foot">
      กลับไปดูห้องพักทั้งหมด <a href="index.php">คลิกที่นี่</a>
    </p>
  </div>
</main>

<script src="login.js"></script>
</body>
</html>
