<?php
// src/Views/InvitationView.php
namespace Views;

class InvitationView
{
    public static function render($email, $token, $directory, $baseUrl)
    {
        // Construct the invitation URL
        $url = $baseUrl
            . '/login?email=' . urlencode($email)
            . '&token=' . urlencode($token)
            . '&directory=' . urlencode($directory);
        
        // Start output buffering
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation to Collaborate</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        .email-header {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .email-content {
            line-height: 1.6;
        }
        .email-content a {
            color: #007BFF;
            text-decoration: none;
        }
        .email-content a:hover {
            text-decoration: underline;
        }
        .email-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 12px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">Invitation to Collaborate</div>
        <div class="email-content">
            <p>Dear Contributor,</p>
            <p>You have been invited to collaborate in the directory <strong><?php echo htmlspecialchars($directory); ?></strong> on <strong><?php echo htmlspecialchars(parse_url($baseUrl, PHP_URL_HOST)); ?></strong>.</p>
            <p>To upload files and participate, please click the link below:</p>
            <p><a href="<?php echo htmlspecialchars($url); ?>">Collaborate Now</a></p>
            <p>If the button above does not work, copy and paste the following URL into your browser:</p>
            <p><code><?php echo htmlspecialchars($url); ?></code></p>
            <p>We look forward to your contributions!</p>
        </div>
        <div class="email-footer">
            <p>This invitation is valid for a limited time. Please act promptly.</p>
            <p>&copy; <?php echo date('Y'); ?> DFIR-FM. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
        <?php
        // Return the buffered content
        return ob_get_clean();
    }
}
