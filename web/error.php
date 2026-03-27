<?php
/**
 * Branded Error Page
 *
 * Standalone error page — no init.php, no database, no session required.
 * Uses hardcoded default theme so it works even when the app is broken.
 *
 * Nginx configuration — add to your server block:
 *
 *   error_page 400 /error.php?code=400;
 *   error_page 403 /error.php?code=403;
 *   error_page 404 /error.php?code=404;
 *   error_page 500 /error.php?code=500;
 *   error_page 502 /error.php?code=502;
 *   error_page 503 /error.php?code=503;
 */

// --- Error code detection ---
$code = 0;
if (isset($_GET['code']) && is_numeric($_GET['code'])) {
    $code = intval($_GET['code']);
}
if ($code === 0 && isset($_SERVER['REDIRECT_STATUS'])) {
    $code = intval($_SERVER['REDIRECT_STATUS']);
}
if ($code < 400 || $code > 599) {
    $code = 404;
}

http_response_code($code);

// --- Error definitions ---
$errors = [
    400 => [
        'title'       => 'Bad Request',
        'description' => 'The server could not understand your request. Please check the URL and try again.',
        'icon'        => '⚠',
    ],
    403 => [
        'title'       => 'Access Denied',
        'description' => 'You do not have permission to access this page. If you believe this is an error, please contact your administrator.',
        'icon'        => '🔒',
    ],
    404 => [
        'title'       => 'Page Not Found',
        'description' => 'The page you are looking for does not exist or has been moved. Please check the URL or return to the home page.',
        'icon'        => '🔍',
    ],
    500 => [
        'title'       => 'Internal Server Error',
        'description' => 'Something went wrong on our end. The issue has been logged and our team will investigate. Please try again later.',
        'icon'        => '⚡',
    ],
    502 => [
        'title'       => 'Bad Gateway',
        'description' => 'The server received an invalid response from an upstream service. Please try again in a few moments.',
        'icon'        => '🔌',
    ],
    503 => [
        'title'       => 'Service Unavailable',
        'description' => 'The service is temporarily unavailable due to maintenance or high load. Please try again shortly.',
        'icon'        => '🚧',
    ],
];

$error = $errors[$code] ?? [
    'title'       => 'Error',
    'description' => 'An unexpected error occurred. Please try again or contact the administrator.',
    'icon'        => '⚠',
];

// --- Standalone CSP nonce (same pattern as setup.php) ---
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none';");
header_remove('X-Powered-By');

// --- Hardcoded theme defaults (matches getUserTheme() in init.php) ---
$headerColor   = '#35a0a3';
$footerColor   = '#1a365d';
$buttonColor   = '#35a0a3';
$logoUrl       = 'app/images/logo-default-418x78.png';
$footerLogoUrl = 'app/images/logo-inverse-416x78.png';

$e = function ($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo $code; ?> - <?php echo $e($error['title']); ?> - Third Party Risk Management</title>
    <link rel="shortcut icon" href="app/images/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="app/css/bootstrap.css">
    <link rel="stylesheet" href="app/css/fonts.css">
    <style nonce="<?php echo $e($nonce); ?>">
        :root {
            --theme-header-color: <?php echo $e($headerColor); ?>;
            --theme-footer-color: <?php echo $e($footerColor); ?>;
            --theme-button-color: <?php echo $e($buttonColor); ?>;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Roboto', sans-serif;
            background: #f9fafb;
            color: #333;
        }
        .error-page {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Header */
        .error-header {
            background: #f5f5f5;
            padding: 15px 30px;
            flex-shrink: 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .error-header a {
            display: inline-block;
            text-decoration: none;
        }
        .error-header img {
            max-height: 40px;
            width: auto;
        }

        /* Error content */
        .error-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            text-align: center;
        }
        .error-icon {
            font-size: 64px;
            margin-bottom: 20px;
            line-height: 1;
        }
        .error-code {
            font-size: 120px;
            font-weight: 700;
            color: var(--theme-header-color);
            line-height: 1;
            margin-bottom: 10px;
        }
        .error-title {
            font-size: 28px;
            color: #333;
            font-weight: 500;
            margin: 0 0 15px;
        }
        .error-description {
            font-size: 16px;
            color: #666;
            max-width: 500px;
            line-height: 1.6;
            margin: 0 0 30px;
        }
        .error-home-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: var(--theme-button-color);
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .error-home-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            color: #fff;
            text-decoration: none;
        }

        /* Footer */
        .error-footer {
            background: var(--theme-footer-color);
            padding: 25px 30px;
            color: #fff;
            flex-shrink: 0;
        }
        .error-footer-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .footer-brand img {
            max-height: 40px;
            width: auto;
        }
        .rights {
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            margin: 0;
        }

        /* Responsive */
        @media (max-width: 576px) {
            .error-code { font-size: 80px; }
            .error-title { font-size: 22px; }
            .error-description { font-size: 14px; }
            .error-icon { font-size: 48px; }
            .error-home-btn { width: 100%; justify-content: center; }
            .error-header { padding: 12px 20px; }
            .error-footer { padding: 20px; }
            .error-footer-inner {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="error-page">
        <div class="error-header">
            <a href="/">
                <img src="<?php echo $e($logoUrl); ?>" alt="Logo">
            </a>
        </div>

        <div class="error-content">
            <div class="error-icon"><?php echo e($error['icon']); ?></div>
            <div class="error-code"><?php echo $code; ?></div>
            <h1 class="error-title"><?php echo $e($error['title']); ?></h1>
            <p class="error-description"><?php echo $e($error['description']); ?></p>
            <a href="/" class="error-home-btn">&#8592; Back to Home</a>
        </div>

        <footer class="error-footer">
            <div class="error-footer-inner">
                <div class="footer-brand">
                    <img src="<?php echo $e($footerLogoUrl); ?>" alt="Logo">
                </div>
                <p class="rights">&copy; <?php echo date('Y'); ?>. All Rights Reserved</p>
            </div>
        </footer>
    </div>
</body>
</html>
