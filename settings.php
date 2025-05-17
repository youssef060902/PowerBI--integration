<?php
session_start();

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Redirection vers login.php
    exit();
}

// Gestion de la requête AJAX pour le chatbot
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message'])) {
    $message = $_POST['message'];

    // Intégration de l'API Gemini
    $apiKey = "AIzaSyDwTXOO_AUAlC6kBmHiKGpGlstlV4XV6kg";
    $modelName = "models/gemini-1.5-flash";
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/{$modelName}:generateContent?key=" . $apiKey;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    $data = json_encode([
        "contents" => [
            [
                "parts" => [
                    ["text" => $message]
                ]
            ]
        ],
        "generationConfig" => [
            "maxOutputTokens" => 150,
            "temperature" => 0.7
        ]
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        $responseText = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Erreur : aucune réponse de l'API";
    } else {
        $responseText = "Erreur avec l'API : Code HTTP " . $httpCode;
    }

    echo $responseText;
    exit();
}

// Connexion à la base de données
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "pfe_shaima";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connexion échouée : " . $e->getMessage());
}

// Récupérer les informations actuelles de l'utilisateur
$stmt = $conn->prepare("SELECT email FROM login WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Traitement du formulaire
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $new_password = $_POST['password'];

    // Mettre à jour l'email
    if (!empty($new_email)) {
        $stmt = $conn->prepare("UPDATE login SET email = ? WHERE id = ?");
        $stmt->execute([$new_email, $_SESSION['user_id']]);
        $_SESSION['email'] = $new_email;
    }

    // Mettre à jour le mot de passe
    if (!empty($new_password)) {
        $stmt = $conn->prepare("UPDATE login SET password = ? WHERE id = ?");
        $stmt->execute([$new_password, $_SESSION['user_id']]);
    }

    // Afficher la popup et rediriger
    echo "
    <div id='popup-success' class='popup-success'>
        <span>Mise à jour avec succès</span>
    </div>
    <script>
        setTimeout(function() {
            window.location.href = 'logout.php';
        }, 2000); // 1 seconde

        // Afficher la popup
        document.getElementById('popup-success').style.display = 'flex';
    </script>
    <style>
    .popup-success {
        display: flex;
        align-items: center;
        justify-content: center;
        position: fixed;
        top: 30px;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(90deg, #12B1D1 0%, #1089D3 100%);
        color: #fff;
        padding: 18px 40px;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(16,137,211,0.15);
        font-size: 20px;
        font-weight: 600;
        z-index: 9999;
        animation: fadeIn 0.4s;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-30px) translateX(-50%);}
        to { opacity: 1; transform: translateY(0) translateX(-50%);}
    }
    </style>
    ";
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Styles généraux */
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #e8ecef;
        }

        .dashboard-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: #F8F9FD;
            background: linear-gradient(0deg, rgb(255, 255, 255) 0%, rgb(244, 247, 251) 100%);
            border-right: 5px solid rgb(255, 255, 255);
            box-shadow: rgba(133, 189, 215, 0.8784313725) 5px 0 10px -5px;
            padding: 20px;
        }

        .sidebar-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .sidebar-header h2 {
            font-size: 24px;
            color: rgb(16, 137, 211);
            margin: 0;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin-bottom: 10px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            color: rgb(16, 137, 211);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.2s ease-in-out;
        }

        .sidebar-link:hover {
            background: rgba(16, 137, 211, 0.1);
            transform: scale(1.03);
        }

        .sidebar-link.active {
            background: linear-gradient(45deg, rgb(16, 137, 211) 0%, rgb(18, 177, 209) 100%);
            color: white;
        }

        .sidebar-icon {
            margin-right: 10px;
            fill: currentColor;
        }

        /* Contenu principal */
        .main-content {
            flex-grow: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            background: #F8F9FD;
            background: linear-gradient(0deg, rgb(255, 255, 255) 0%, rgb(244, 247, 251) 100%);
            border-radius: 40px;
            padding: 25px 35px;
            border: 5px solid rgb(255, 255, 255);
            box-shadow: rgba(133, 189, 215, 0.8784313725) 0px 30px 30px -20px;
            margin: 20px;
        }

        .heading {
            text-align: center;
            font-weight: 900;
            font-size: 30px;
            color: rgb(16, 137, 211);
        }

        .powerbi-container {
            margin-top: 20px;
            width: 100%;
            max-width: 800px;
        }

        .powerbi-container h3 {
            text-align: center;
            color: rgb(16, 137, 211);
            margin-bottom: 15px;
        }

        .powerbi-container iframe {
            border: none;
            border-radius: 20px;
            box-shadow: rgba(133, 189, 215, 0.8784313725) 0px 10px 10px -5px;
        }

        /* Styles pour le chatbot */
        .chatbox {
            display: none;
            flex-direction: column;
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 300px;
            height: 400px;
            background: #F8F9FD;
            background: linear-gradient(0deg, rgb(255, 255, 255) 0%, rgb(244, 247, 251) 100%);
            border-radius: 20px;
            border: 5px solid rgb(255, 255, 255);
            box-shadow: rgba(133, 189, 215, 0.8784313725) 0px 30px 30px -20px;
            z-index: 1000;
        }

        .chat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: linear-gradient(45deg, rgb(16, 137, 211) 0%, rgb(18, 177, 209) 100%);
            color: white;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }

        .chat-header h3 {
            margin: 0;
            font-size: 16px;
        }

        .close-btn {
            cursor: pointer;
            font-size: 18px;
        }

        .chat-messages {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px;
            list-style: none;
            margin: 0;
        }

        .chat-messages li {
            padding: 8px 12px;
            margin-bottom: 10px;
            border-radius: 10px;
            max-width: 80%;
            word-wrap: break-word;
        }

        .chat-outgoing {
            background: linear-gradient(45deg, rgb(16, 137, 211) 0%, rgb(18, 177, 209) 100%);
            color: white;
            margin-left: auto;
        }

        .chat-incoming {
            background: #e9ecef;
            color: #333;
        }

        .chat-input {
            display: flex;
            padding: 10px;
            border-top: 1px solid #ddd;
        }

        .chat-input textarea {
            flex-grow: 1;
            padding: 5px;
            border: none;
            border-radius: 10px;
            box-shadow: #cff0ff 0px 10px 10px -5px;
            border-inline: 2px solid transparent;
            resize: none;
            font-size: 14px;
        }

        .chat-input textarea:focus {
            outline: none;
            border-inline: 2px solid #12B1D1;
        }

        .chat-input button {
            padding: 5px 10px;
            margin-left: 10px;
            background: linear-gradient(45deg, rgb(16, 137, 211) 0%, rgb(18, 177, 209) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            box-shadow: rgba(133, 189, 215, 0.8784313725) 0px 20px 10px -15px;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
        }

        .chat-input button:hover {
            transform: scale(1.03);
            box-shadow: rgba(133, 189, 215, 0.8784313725) 0px 23px 10px -20px;
        }

        /* Media Queries pour la responsivité */
        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 5px solid rgb(255, 255, 255);
                box-shadow: rgba(133, 189, 215, 0.8784313725) 0px 5px 10px -5px;
            }

            .main-content {
                padding: 10px;
            }

            .container {
                max-width: 100%;
                margin: 10px;
            }

            .chatbox {
                width: 90%;
                left: 5%;
                right: 5%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Admin Dashboard</h2>
            </div>
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" class="sidebar-link">
                        <svg class="sidebar-icon" xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 512 512">
                            <path d="M0 96C0 60.7 28.7 32 64 32H448c35.3 0 64 28.7 64 64V416c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V96zm64 0v64h64V96H64zm384 0H192v64H448V96zM64 224v64h64V224H64zm384 0H192v64H448V224zM64 352v64h64V352H64zm384 0H192v64H448V352z"/>
                        </svg>
                        Tableau de bord
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="sidebar-link active">
                        <svg class="sidebar-icon" xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 512 512">
                            <path d="M495.9 166.6c3.2 8.7 .5 18.4-6.4 24.6l-43.3 44.4c1.1 8.3 1.7 16.8 1.7 25.4s-.6 17.1-1.7 25.4l43.3 44.4c6.9 7 9.6 16.7 6.4 24.6c-4.4 11-11.9 19.6-21.5 25.6-9.6 6-21.2 8.3-33.1 6.4l-54.8-16.5c-7.1 5.7-14.8 10.8-23 15.1V432c0 17.7-14.3 32-32 32s-32-14.3-32-32V351.7c-8.2-4.3-15.9-9.4-23-15.1L177.1 353.1c-11.9 1.9-23.5-.4-33.1-6.4-9.6-6-17.1-14.6-21.5-25.6-3.2-8.7-.5-18.4 6.4-24.6l43.3-44.4c-1.1-8.3-1.7-16.8-1.7-25.4s.6-17.1 1.7-25.4L129.6 142.2c-6.9-7-9.6-16.7-6.4-24.6c4.4-11 11.9-19.6 21.5-25.6 9.6-6 21.2-8.3 33.1-6.4l54.8 16.5c7.1-5.7 14.8-10.8 23-15.1V32c0-17.7 14.3-32 32-32s32 14.3 32 32v80.3c8.2 4.3 15.9 9.4 23 15.1l54.8-16.5c11.9-1.9 23.5 .4 33.1 6.4 9.6 6 17.1 14.6 21.5 25.6zM256 336c44.2 0 80-35.8 80-80s-35.8-80-80-80-80 35.8-80 80 35.8 80 80 80z"/>
                        </svg>
                        Paramètres
                    </a>
                </li>
                <li>
                    <a href="#" class="sidebar-link chatbot-toggle">
                        <svg class="sidebar-icon" xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 512 512">
                            <path d="M256 448c106 0 192-86 192-192S362 64 256 64 64 150 64 256s86 192 192 192zm-64-320c26.5 0 48 21.5 48 48s-21.5 48-48 48-48-21.5-48-48 21.5-48 48-48zm0 192c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48zm96-192c26.5 0 48 21.5 48 48s-21.5 48-48 48-48-21.5-48-48 21.5-48 48-48zm0 192c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48z"/>
                        </svg>
                        Chatbot
                    </a>
                </li>
                <li>
                    <a href="logout.php" class="sidebar-link">
                        <svg class="sidebar-icon" xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 512 512">
                            <path d="M502.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-128-128c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L402.7 224H192c-17.7 0-32 14.3-32 32s14.3 32 32 32h210.7l-73.4 73.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l128-128zM160 96c17.7 0 32-14.3 32-32s-14.3-32-32-32H96C43 32 0 75 0 128V384c0 53 43 96 96 96h64c17.7 0 32-14.3 32-32s-14.3-32-32-32H96c-17.7 0-32-14.3-32-32V128c0-17.7 14.3-32 32-32h64z"/>
                        </svg>
                        Déconnexion
                    </a>
                </li>
            </ul>
        </div>
        <!-- Fenêtre de chat -->
        <div class="chatbox" id="chatbox">
            <div class="chat-header">
                <h3>Chatbot</h3>
                <span class="close-btn">X</span>
            </div>
            <ul class="chat-messages" id="chat-messages"></ul>
            <div class="chat-input">
                <textarea id="chat-input" placeholder="Tapez votre message..."></textarea>
                <button onclick="sendMessage()">Envoyer</button>
            </div>
        </div>
        <!-- Contenu principal -->
        <div class="main-content">
            <div class="container">
                <div class="heading">Paramètres</div>
                <?php if (isset($success)): ?>
                    <p style="color: green; text-align: center;"><?php echo htmlspecialchars($success); ?></p>
                <?php endif; ?>
                <form action="settings.php" method="POST" class="form">
                    <label for="email">Email</label>
                    <input class="input" type="email" name="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="Nouvel email">
                    <label for="password">Mot de passe</label>
                    <input class="input" type="password" name="password" id="password" placeholder="Nouveau mot de passe">
                    <input class="login-button" type="submit" value="Mettre à jour">
                </form>
            </div>
        </div>
    </div>
    <script>
        // Ouvrir/fermer la fenêtre de chat
        document.querySelector('.chatbot-toggle').addEventListener('click', function() {
            document.getElementById('chatbox').style.display = 'flex';
        });
        document.querySelector('.close-btn').addEventListener('click', function() {
            document.getElementById('chatbox').style.display = 'none';
        });

        // Envoyer un message au chatbot
        function sendMessage() {
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (message === '') return;

            // Ajouter le message de l'utilisateur
            const chatMessages = document.getElementById('chat-messages');
            const userLi = document.createElement('li');
            userLi.className = 'chat-outgoing';
            userLi.textContent = message;
            chatMessages.appendChild(userLi);

            // Appeler l'API via AJAX
            fetch('settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'message=' + encodeURIComponent(message)
            })
            .then(response => response.text())
            .then(data => {
                const botLi = document.createElement('li');
                botLi.className = 'chat-incoming';
                botLi.textContent = data;
                chatMessages.appendChild(botLi);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            })
            .catch(error => console.error('Erreur:', error));

            input.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Envoyer avec Entrée
        document.getElementById('chat-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    </script>
</body>
</html>