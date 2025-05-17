<?php
session_start();

// Configuration centralisée
const CONFIG = [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'pfe_shaima',
        'user' => 'root',
        'pass' => ''
    ],
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'youssefcarma@gmail.com',
        'password' => 'oupl cahg lkac cxun'
    ],
    'google_oauth' => [
        'client_id' => '906846133961-k9bem1jp506ssfele6gvk3c0mfsp9iue.apps.googleusercontent.com',
        'client_secret' => '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',
        'redirect_uri' => 'http://localhost/PowerBI--integration/login.php'
    ],
    'security' => [
        'code_expiry' => 600, // 10 minutes
        'max_attempts' => 5
    ]
];

// Activer le débogage en développement uniquement
if (getenv('APP_ENV') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);
}

// Charger l'autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

// Importer les classes nécessaires
use Google\Client;
use Google\Service\Oauth2;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Fonction de connexion à la base de données
function getDbConnection() {
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = new PDO(
                "mysql:host=" . CONFIG['db']['host'] . ";dbname=" . CONFIG['db']['name'],
                CONFIG['db']['user'],
                CONFIG['db']['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new Exception("Erreur de connexion à la base de données : " . $e->getMessage());
        }
    }
    return $conn;
}

// Fonction de génération de token CSRF
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Initialisation des variables
$error = null;
$success = null;

// Vérification si déjà connecté
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Initialisation de la session pour mot de passe oublié
if (!isset($_SESSION['forgot_password'])) {
    $_SESSION['forgot_password'] = [
        'step' => 0,
        'code' => '',
        'email' => '',
        'timestamp' => 0,
        'attempts' => 0
    ];
}

// Gestion des requêtes POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    try {
        $conn = getDbConnection();

        // Gestion de la demande de réinitialisation
        if (isset($_POST['forgot_email'])) {
            $email = filter_var($_POST['forgot_email'], FILTER_SANITIZE_EMAIL);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Adresse email invalide.");
            }

            $stmt = $conn->prepare("SELECT id FROM login WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $code = sprintf("%06d", rand(100000, 999999));
                $_SESSION['forgot_password'] = [
                    'step' => 2,
                    'code' => $code,
                    'email' => $email,
                    'timestamp' => time(),
                    'attempts' => 0
                ];

                // Envoi de l'email
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = CONFIG['smtp']['host'];
                $mail->SMTPAuth = true;
                $mail->Username = CONFIG['smtp']['username'];
                $mail->Password = CONFIG['smtp']['password'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = CONFIG['smtp']['port'];
                $mail->setFrom('no-reply@yourdomain.com', 'No Reply');
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = "Code de vérification pour réinitialisation du mot de passe";
                $mail->Body = "<h1>Code de vérification</h1><p>Votre code est : <strong>$code</strong></p><p>Ce code est valide pendant 10 minutes.</p>";
                $mail->AltBody = "Votre code de vérification est : $code\nCe code est valide pendant 10 minutes.";

                $mail->send();
                $success = "Un code de vérification a été envoyé à votre email.";
            } else {
                $error = "Cet email n'est pas enregistré dans notre système.";
            }
        }
        // Vérification du code
        elseif (isset($_POST['verify_code'])) {
            $entered_code = preg_replace('/[^0-9]/', '', $_POST['verify_code']);
            $_SESSION['forgot_password']['attempts']++;

            if ((time() - $_SESSION['forgot_password']['timestamp']) > CONFIG['security']['code_expiry']) {
                $error = "Le code de vérification a expiré. Veuillez en demander un nouveau.";
                $_SESSION['forgot_password']['step'] = 0;
            } elseif ($_SESSION['forgot_password']['attempts'] > CONFIG['security']['max_attempts']) {
                $error = "Trop de tentatives. Veuillez redemander un nouveau code.";
                $_SESSION['forgot_password']['step'] = 0;
            } elseif ($_SESSION['forgot_password']['code'] === $entered_code) {
                $_SESSION['forgot_password']['step'] = 3;
            } else {
                $error = "Code de vérification incorrect.";
            }
        }
        // Réinitialisation du mot de passe
        elseif (isset($_POST['new_password'])) {
            if ($_SESSION['forgot_password']['step'] !== 3) {
                throw new Exception("Veuillez vérifier votre code avant de définir un nouveau mot de passe.");
            }

            $new_password = $_POST['new_password'];
            if (strlen($new_password) < 3) {
                throw new Exception("Le mot de passe doit contenir au moins 3 caractères.");
            }

            $stmt = $conn->prepare("UPDATE login SET password = ? WHERE email = ?");
            $stmt->execute([$new_password, $_SESSION['forgot_password']['email']]);
            unset($_SESSION['forgot_password']);
            header("Location: login.php");
            exit();
        }
        // Connexion standard
        elseif (isset($_POST['email'], $_POST['password'])) {
            $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];

            // Débogage
            error_log("Tentative de connexion pour l'email: " . $email);

            $stmt = $conn->prepare("SELECT id, email, password FROM login WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Débogage
            error_log("Utilisateur trouvé: " . ($user ? "Oui" : "Non"));
            if ($user) {
                error_log("Vérification du mot de passe...");
                // Comparaison directe du mot de passe en clair
                $password_verified = ($password === $user['password']);
                error_log("Mot de passe vérifié: " . ($password_verified ? "Oui" : "Non"));
            }

            if ($user && $password === $user['password']) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Email ou mot de passe incorrect.";
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Configuration Google OAuth
$client = new Google_Client();
$client->setClientId(CONFIG['google_oauth']['client_id']);
$client->setClientSecret(CONFIG['google_oauth']['client_secret']);
$client->setRedirectUri(CONFIG['google_oauth']['redirect_uri']);
$client->addScope('email');
$client->addScope('profile');

// Gestion de la réponse OAuth
if (isset($_GET['code'])) {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        if (!isset($token['error'])) {
            $client->setAccessToken($token['access_token']);
            $googleService = new Oauth2($client);
            $userInfo = $googleService->userinfo->get();
            $googleEmail = $userInfo->email;

            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT id, email FROM login WHERE email = ?");
            $stmt->execute([$googleEmail]);
            $user = $stmt->fetch();

            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Non autorisé : Cet email Gmail n'est pas enregistré dans notre système.";
            }
        } else {
            $error = "Erreur lors de l'authentification Google : " . ($token['error_description'] ?? $token['error']);
        }
    } catch (Exception $e) {
        $error = "Erreur lors de l'authentification : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: linear-gradient(135deg, #e8ecef 0%, #cbe7fa 100%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 8px 32px rgba(22, 169, 211, 0.13), 0 1.5px 3px 0 rgba(60,64,67,.10);
            max-width: 350px;
            width: 100%;
            padding: 36px 28px 28px 28px;
            margin: 40px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-sizing: border-box;
        }

        .heading {
            font-size: 2rem;
            color: #16a9d3;
            font-weight: bold;
            margin-bottom: 28px;
            letter-spacing: 1px;
            text-shadow: 0 2px 8px #b2e0f7;
            text-align: center;
        }

        .form {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 18px;
            box-sizing: border-box;
        }

        .input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e3f3fa;
            border-radius: 8px;
            background: #f8fafc;
            font-size: 1rem;
            color: #333;
            transition: border 0.2s, box-shadow 0.2s;
            outline: none;
            box-sizing: border-box;
        }
        .input:focus {
            border: 1.5px solid #16a9d3;
            box-shadow: 0 2px 8px #b2e0f7;
            background: #fff;
        }

        .login-button {
            width: 100%;
            padding: 12px 0;
            background: linear-gradient(90deg, #16a9d3 0%, #12b1d1 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1.08rem;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 2px 8px #b2e0f7;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .login-button:hover {
            background: linear-gradient(90deg, #12b1d1 0%, #16a9d3 100%);
        }

        .google-login {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 10px;
            padding: 0;
            background: #fff;
            color: #3c4043;
            text-decoration: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            box-shadow: 0 2px 8px #b2e0f7;
            transition: box-shadow 0.2s, background 0.2s;
            width: 100%;
            height: 45px;
            border: 1px solid #e3f3fa;
            overflow: hidden;
            cursor: pointer;
            margin-bottom: 10px;
        }
        .google-login:hover {
            background: #f7f7f7;
        }

        .google-icon-wrapper {
            background: #fff;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 14px;
            border-right: 1px solid #e3f3fa;
        }
        .google-icon {
            width: 24px;
            height: 24px;
            display: block;
        }
        .google-btn-text {
            flex: 1;
            text-align: center;
            color: #3c4043;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .forgot-password {
            text-align: center;
            margin: 8px 0 12px 0;
            font-size: 0.97rem;
        }
        .forgot-password a {
            color: #16a9d3;
            text-decoration: none;
            font-weight: 500;
            transition: text-decoration 0.2s;
        }
        .forgot-password a:hover {
            text-decoration: underline;
        }

        .error, .success {
            text-align: center;
            margin: 8px 0;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.97rem;
            width: 100%;
        }
        .error { 
            color: #721c24;
            background: #f8d7da;
        }
        .success { 
            color: #155724;
            background: #d4edda;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="heading">Connexion</div>
        
        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($success): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <!-- Formulaire de connexion principal -->
        <form action="login.php" method="POST" class="form">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input class="input" type="email" name="email" placeholder="Email" required>
            <input class="input" type="password" name="password" placeholder="Mot de passe" required>
            <input class="login-button" type="submit" value="Se connecter">
        </form>

        <!-- Lien mot de passe oublié -->
        <div class="forgot-password">
            <a href="#" onclick="showForgotForm(); return false;">Mot de passe oublié ?</a>
        </div>

        <!-- Formulaire demande code -->
        <form action="login.php" method="POST" class="form" id="forgot-form" 
              style="display: <?php echo ($_SESSION['forgot_password']['step'] == 1) ? 'block' : 'none'; ?>;">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input class="input" type="email" name="forgot_email" placeholder="Entrez votre email" required>
            <input class="login-button" type="submit" value="Envoyer le code">
        </form>

        <!-- Formulaire vérification code -->
        <form action="login.php" method="POST" class="form" id="verify-form" 
              style="display: <?php echo ($_SESSION['forgot_password']['step'] == 2) ? 'block' : 'none'; ?>;">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input class="input" type="text" name="verify_code" placeholder="Entrez le code reçu" required>
            <input class="login-button" type="submit" value="Vérifier le code">
        </form>

        <!-- Formulaire réinitialisation mot de passe -->
        <form action="login.php" method="POST" class="form" id="reset-form" 
              style="display: <?php echo ($_SESSION['forgot_password']['step'] == 3) ? 'block' : 'none'; ?>;">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input class="input" type="password" name="new_password" placeholder="Nouveau mot de passe" required>
            <input class="login-button" type="submit" value="Réinitialiser le mot de passe">
        </form>

        <!-- Bouton Google -->
        <a href="<?php echo $client->createAuthUrl(); ?>" class="google-login">
            <span class="google-icon-wrapper">
                <img class="google-icon" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADcAAAA4CAMAAABuU5ChAAAA+VBMVEX////pQjU0qFNChfT6uwU0f/O4zvs6gfSJr/j6twDoOisjePPoNSXpPjDrWU/oLRr+9vZ7pff/vAAUoUAkpEn0ran619b82pT7wgD+68j947H/+e7//PafvPm/0vuBw5Df7+P63tz3xcPxl5HnJQ7qUEXxj4n4z83zoJzqSz/vgXrucWrsY1r1tbHrSBPoOjbvcSr0kx74rRH80XntZC3xhSPmGRr86+r4sk/936EJcfPS3/yowvnbwVKjsTjx9f5urEjkuBu9tC+ErkJyvoRRpj2az6hWs23j6/0emX2z2btAiuI8k8AyqkE5nZU1pGxCiOxVmtHJ5M+PSt3WAAACGElEQVRIieWSa3fSQBCGk20CJRcW2AWKxgJtqCmieNdatV5SUtFq5f//GJeE7CXJJOT4TZ+PO+c58+7MaNr/SWd60mecTDs1pMFp28dODPZnZw/369TXseXqHNfCblDdte84krTDwUFFwnMnJyXm+bSsmZ/vlcb1+6A2x5C1xYeyPgIyJlhtYDjzjOYyZA3oFighLYxni8UMY6dCG/jy9KzTQfI8DXSnTNN0kcl1lNE9dlxYC8TnnEVmAJ02qHlPllyb58vgmQ2Np0tYgzGMo2ex6IKRihi1mPhcZyYuO8McL4yYl0vrrI6mJZpx9Or1mzqa10rFt8p7o5ArXh+lXutC8d6ZBdiXvH6PeyPFsw8KMBu8fsG9+3t473l9yD1vD+/BX3v1cgqv3lzE/8A9NCUK5sn33vugeN1DQTcVTbG/9M56H+lEAzg2d54t7iW5657xCdEx5PF+B9Lj9oO9z4hBgIZX6YyaXfmZaV9QQkU781h+Hra+7jQaFv6Or8RW3r1rhErES641D9XKigox8jJaQxyAfZOpIQm6kiuT6BvfujqVuEpkkY43u+d1RBBF35v55aVJidKSEBRFiJAk/+0PM3NjgjFFMLc/WVYzlzImLBPprzvzrlBjHUmZSH8DmqatS0QSZtcjTxUBWSlZw1bckhaYlISTcm1rIqKolJJxtRWnXUVscTFsjWFFwoy7WTM2+zX69/gDaLcy7SET9nsAAAAASUVORK5CYII=" alt="Google Logo"/>
            </span>
            <span class="google-btn-text">Se connecter avec Google</span>
        </a>
    </div>

    <script>
        function showForgotForm() {
            const forms = ['forgot-form', 'verify-form', 'reset-form'];
            forms.forEach(id => {
                const form = document.getElementById(id);
                form.style.display = id === 'forgot-form' ? 'block' : 'none';
            });
        }

        // Initialisation des formulaires
        document.addEventListener('DOMContentLoaded', () => {
            const forms = ['forgot-form', 'verify-form', 'reset-form'];
            forms.forEach(id => {
                const form = document.getElementById(id);
                if (form.style.display !== 'block') {
                    form.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>