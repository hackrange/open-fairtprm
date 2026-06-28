<?php
/**
 * Email Service - The Carrier Pigeon of the TPRM System
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * This bad boy wraps PHPMailer 5.2.9 (yes, the old faithful) and turns it into
 * a convenient email-slinging machine. It pulls all SMTP settings straight from
 * the database so nobody has to hardcode credentials like a caveman. The main gig
 * is firing off annual vendor review reminder emails -- you know, the ones everyone
 * ignores until the third "OVERDUE" notice. Handles encryption for passwords too,
 * because storing plaintext SMTP creds is a fireable offense.
 */

require_once dirname(__DIR__, 2) . '/app/template/site/bat/phpmailer/class.phpmailer.php';
require_once dirname(__DIR__, 2) . '/app/template/site/bat/phpmailer/class.smtp.php';

class EmailService {
    private $db;
    private $encryption;
    private $config = [];
    private $enabled = false;  // Guilty until proven innocent -- emails stay off until config says otherwise
    private $graphAccessToken = null;
    private $graphTokenExpiry = 0;

    /**
     * Constructor - Wire up the database and encryption, then go raid the config table.
     * If the DB or encryption blows up here, well, no emails for anyone today.
     */
    public function __construct(Database $db, Encryption $encryption) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->loadConfig();
    }

    /**
     * Raids the app_config table for anything that smells like email/SMTP settings.
     * Decrypts passwords on the fly because we're not animals who store plaintext.
     * If the DB takes a nap, we just disable email and quietly log the shame.
     */
    private function loadConfig() {
        try {
            // Grab every config row that starts with email_ or smtp_ -- cast a wide net
            $configs = $this->db->fetchAll(
                "SELECT config_key, config_value, is_encrypted FROM app_config WHERE config_key LIKE 'email_%' OR config_key LIKE 'smtp_%'"
            );

            foreach ($configs as $row) {
                $value = $row['config_value'];

                // If somebody bothered to encrypt this value, let's decrypt it.
                // Otherwise we'd just be emailing base64 gibberish as our password.
                if ($row['is_encrypted'] == 1 && !empty($value)) {
                    $value = $this->encryption->decrypt($value);
                }

                $this->config[$row['config_key']] = $value;
            }

            // Only flip the switch if someone explicitly set email_enabled to '1'
            $this->enabled = isset($this->config['email_enabled']) && $this->config['email_enabled'] === '1';
        } catch (Exception $e) {
            // Something went sideways -- log it and pretend email doesn't exist
            error_log("EmailService: Failed to load config - " . $e->getMessage());
            $this->enabled = false;
        }
    }

    /**
     * Quick sanity check: are emails even turned on?
     * Spoiler: half the time they're not, and you'll spend 20 minutes debugging
     * before you check this flag. Ask me how I know.
     */
    public function isEnabled() {
        return $this->enabled;
    }

    /**
     * Creates and configures a PHPMailer instance with all SMTP settings from the database.
     * Handles host, port, encryption, authentication, TLS peer verification, and from address.
     */
    private function createMailer() {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $this->config['smtp_host'] ?? 'localhost';
        $mail->Port = intval($this->config['smtp_port'] ?? 25);

        $encryption = $this->config['smtp_encryption'] ?? 'none';
        if ($encryption === 'tls') {
            $mail->SMTPSecure = 'tls';
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = 'ssl';
        }

        if (!empty($this->config['smtp_username'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['smtp_username'];
            $mail->Password = $this->config['smtp_password'] ?? '';
        }

        $mail->setFrom(
            $this->config['email_from_email'] ?? 'noreply@example.com',
            $this->config['email_from_name'] ?? 'TPRM System'
        );

        $mail->XMailer = 'FairTPRM 2.0.0';
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    /**
     * Returns the configured email transport method: 'smtp' or 'microsoft_graph'.
     */
    public function getEmailMethod(): string
    {
        return $this->config['email_method'] ?? 'smtp';
    }

    /**
     * Obtains an OAuth2 access token for Microsoft Graph API using client credentials flow.
     * Caches the token in memory for the duration of the request to avoid repeated auth calls.
     */
    private function getGraphAccessToken(): string
    {
        if ($this->graphAccessToken && time() < $this->graphTokenExpiry) {
            return $this->graphAccessToken;
        }

        $tenantId = $this->config['email_ms_tenant_id'] ?? '';
        $clientId = $this->config['email_ms_client_id'] ?? '';
        $clientSecret = $this->config['email_ms_client_secret'] ?? '';

        if (empty($tenantId) || empty($clientId) || empty($clientSecret)) {
            throw new Exception('Microsoft 365 Graph API credentials are not configured.');
        }

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        $postFields = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Graph API token request failed: ' . $curlError);
        }

        $data = json_decode($response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $errorDesc = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
            throw new Exception('Graph API token request failed: ' . $errorDesc);
        }

        $this->graphAccessToken = $data['access_token'];
        $this->graphTokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;

        return $this->graphAccessToken;
    }

    /**
     * Sends an email via Microsoft Graph API using the /users/{sender}/sendMail endpoint.
     * Expects a fully configured PHPMailer object (for subject, body, recipients, etc.)
     * but routes delivery through Graph instead of SMTP.
     */
    private function sendViaGraph(PHPMailer $mail): void
    {
        $token = $this->getGraphAccessToken();
        $sender = $this->config['email_ms_sender'] ?? '';

        if (empty($sender)) {
            throw new Exception('Microsoft 365 sender email is not configured.');
        }

        // Build recipient arrays from PHPMailer's address lists
        $toRecipients = [];
        foreach ($mail->getToAddresses() as $addr) {
            $recipient = ['emailAddress' => ['address' => $addr[0]]];
            if (!empty($addr[1])) {
                $recipient['emailAddress']['name'] = $addr[1];
            }
            $toRecipients[] = $recipient;
        }

        $ccRecipients = [];
        foreach ($mail->getCCAddresses() as $addr) {
            $recipient = ['emailAddress' => ['address' => $addr[0]]];
            if (!empty($addr[1])) {
                $recipient['emailAddress']['name'] = $addr[1];
            }
            $ccRecipients[] = $recipient;
        }

        $bccRecipients = [];
        foreach ($mail->getBCCAddresses() as $addr) {
            $recipient = ['emailAddress' => ['address' => $addr[0]]];
            if (!empty($addr[1])) {
                $recipient['emailAddress']['name'] = $addr[1];
            }
            $bccRecipients[] = $recipient;
        }

        $message = [
            'subject' => $mail->Subject,
            'body' => [
                'contentType' => $mail->ContentType === 'text/html' ? 'HTML' : 'Text',
                'content' => $mail->Body,
            ],
            'toRecipients' => $toRecipients,
        ];

        if (!empty($ccRecipients)) {
            $message['ccRecipients'] = $ccRecipients;
        }
        if (!empty($bccRecipients)) {
            $message['bccRecipients'] = $bccRecipients;
        }

        $payload = json_encode(['message' => $message, 'saveToSentItems' => false]);
        $graphUrl = "https://graph.microsoft.com/v1.0/users/" . urlencode($sender) . "/sendMail";

        $ch = curl_init($graphUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Graph API sendMail request failed: ' . $curlError);
        }

        // 202 Accepted is the success response for sendMail
        if ($httpCode !== 202) {
            $data = json_decode($response, true);
            $errorMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception('Graph API sendMail failed: ' . $errorMsg);
        }
    }

    /**
     * Unified email dispatch: routes to either PHPMailer SMTP or Graph API
     * based on the configured email_method. Accepts a fully configured PHPMailer instance.
     */
    private function sendEmail(PHPMailer $mail): void
    {
        if ($this->getEmailMethod() === 'microsoft_graph') {
            $this->sendViaGraph($mail);
        } else {
            $mail->send();
        }
    }

    /**
     * Fetch an email template from the database.
     * Tries specific assessment_template_id first (for per-type vendor templates),
     * then falls back to the generic template (assessment_template_id IS NULL).
     * Returns null if nothing found -- caller should use hardcoded default.
     */
    private function getTemplateFromDb(string $category, string $key, ?int $assessmentTemplateId = null): ?array
    {
        try {
            // Try specific template first (e.g., ISO 27001 assessment request)
            if ($assessmentTemplateId !== null) {
                $template = $this->db->fetchOne(
                    "SELECT * FROM email_templates WHERE template_category = ? AND template_key = ? AND assessment_template_id = ? AND is_active = 1",
                    [$category, $key, $assessmentTemplateId]
                );
                if ($template) return $template;
            }

            // Fall back to generic template (assessment_template_id IS NULL)
            $template = $this->db->fetchOne(
                "SELECT * FROM email_templates WHERE template_category = ? AND template_key = ? AND assessment_template_id IS NULL AND is_active = 1",
                [$category, $key]
            );

            return $template ?: null;
        } catch (Exception $e) {
            // Table might not exist yet (migration not run) -- silently fall back
            return null;
        }
    }

    /**
     * Replace {{placeholder}} tokens in a template string with actual values.
     * Simple str_replace -- no eval, no regex, no funny business.
     */
    private function renderTemplate(string $template, array $variables): string
    {
        // Handle conditional blocks: {{#key}}...{{/key}} — show block if value is truthy
        $template = preg_replace_callback('/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s', function ($m) use ($variables) {
            $key = $m[1];
            $block = $m[2];
            return !empty($variables[$key]) ? $block : '';
        }, $template);

        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        }
        return $template;
    }

    /**
     * Renders a template with branding variables automatically injected.
     * Merges app-level branding (colors, logo, company name) with the provided variables.
     * Explicit variables take precedence over branding defaults.
     */
    private function renderWithBranding(string $template, array $variables): string
    {
        $branding = self::getEmailBrandingVars();
        $allVars = array_merge($branding, $variables);
        return $this->renderTemplate($template, $allVars);
    }

    /**
     * Returns branding variables for email templates from app_config.
     * Provides header_color, button_color, footer_color, system_title, logo_img, company_name.
     */
    public static function getEmailBrandingVars(): array
    {
        $companyName = '';
        $headerColor = '#35a0a3';
        $buttonColor = '#35a0a3';
        $footerColor = '#1a365d';
        $logoUrl = '';

        try {
            $companyName = getAppConfig('company_name', '') ?: '';
            $headerColor = getAppConfig('header_color', '#35a0a3') ?: '#35a0a3';
            $buttonColor = getAppConfig('button_color', '#35a0a3') ?: '#35a0a3';
            $footerColor = getAppConfig('footer_color', '#1a365d') ?: '#1a365d';
            $rawLogo = getAppConfig('logo_url', '') ?: '';
            if ($rawLogo) {
                $logoUrl = (parse_url($rawLogo, PHP_URL_SCHEME) !== null) ? $rawLogo : baseUrl($rawLogo);
            }
        } catch (Exception $e) {
            error_log('EmailService: Failed to load branding vars - ' . $e->getMessage());
        }

        // Validate hex colors
        $hexRx = '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/';
        if (!preg_match($hexRx, $headerColor)) $headerColor = '#35a0a3';
        if (!preg_match($hexRx, $buttonColor)) $buttonColor = '#35a0a3';
        if (!preg_match($hexRx, $footerColor)) $footerColor = '#1a365d';

        $systemTitle = $companyName
            ? htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . ' - Third Party Risk Management'
            : 'Third Party Risk Management';

        $logoImg = $logoUrl
            ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($companyName ?: 'Logo', ENT_QUOTES, 'UTF-8') . '" style="max-width: 200px; max-height: 60px;">'
            : '';

        return [
            'header_color' => $headerColor,
            'button_color' => $buttonColor,
            'footer_color' => $footerColor,
            'system_title' => $systemTitle,
            'logo_img' => $logoImg,
            'company_name' => htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'),
        ];
    }

    /**
     * Returns the factory-default template content for a given category and key.
     * Used by the admin UI's "Reset to Default" button. Static so it can be called
     * without an EmailService instance.
     *
     * @return array|null ['subject' => string, 'html' => string, 'text' => string] or null
     */
    public static function getDefaultTemplateContent(string $category, string $key): ?array
    {
        $defaults = [
            'vendor' => [
                'assessment_request' => [
                    'subject' => 'Security Assessment Request{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}
                            <h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;">
                            <p style="margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;">Security Assessment Request</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px;">
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">{{greeting}}</p>
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">As part of our vendor risk management process, we require <strong>{{vendor_name}}</strong> to complete a security assessment.</p>
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Please click the button below to access and complete the assessment:</p>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{assessment_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Assessment</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;">This link will expire on {{expires_at}}. If you have any questions, please contact us.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: {{footer_color}}; padding: 20px 30px;">
                            <p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>',
                    'text' => '{{greeting}}

As part of our vendor risk management process, we require {{vendor_name}} to complete a security assessment.

Please click the link below to access and complete the assessment:

{{assessment_url}}

This link will expire on {{expires_at}}. If you have any questions, please contact us.

Thank you,
Third Party Risk Management Team',
                ],
                'assessment_7_days_before' => [
                    'subject' => 'Reminder: Assessment Due in 7 Days{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;"><p style="margin: 0; color: #856404; font-size: 16px; font-weight: bold;">Assessment Due in 7 Days</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">{{greeting}}</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">This is a reminder that the security assessment for <strong>{{vendor_name}}</strong> is due in <strong>{{days_left}} days</strong> on <strong>{{expires_at}}</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Please click the button below to complete the assessment before it expires:</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{assessment_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Assessment</a></td></tr></table>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Assessment Due in 7 Days
==========================================

{{greeting}}

This is a reminder that the security assessment for {{vendor_name}} is due in {{days_left}} days on {{expires_at}}.

Please click the link below to complete the assessment before it expires:

{{assessment_url}}

==========================================
This is an automated reminder from {{system_title}}. Please do not reply to this email.',
                ],
                'assessment_3_days_before' => [
                    'subject' => 'Urgent: Assessment Due in 3 Days{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #FFF3CD; border-left: 4px solid #FF9800; padding: 15px 30px;"><p style="margin: 0; color: #856404; font-size: 16px; font-weight: bold;">Assessment Due in 3 Days</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">{{greeting}}</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">This is an urgent reminder that the security assessment for <strong>{{vendor_name}}</strong> is due in <strong>{{days_left}} days</strong> on <strong>{{expires_at}}</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Please complete it as soon as possible to avoid expiration:</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{assessment_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Assessment</a></td></tr></table>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Assessment Due in 3 Days
==========================================

{{greeting}}

This is an urgent reminder that the security assessment for {{vendor_name}} is due in {{days_left}} days on {{expires_at}}.

Please complete it as soon as possible to avoid expiration:

{{assessment_url}}

==========================================
This is an automated reminder from {{system_title}}. Please do not reply to this email.',
                ],
                'assessment_expiry_day' => [
                    'subject' => 'FINAL NOTICE: Assessment Expires Today{{#assessment_name}} ({{assessment_name}}){{/assessment_name}} - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;"><p style="margin: 0; color: #721C24; font-size: 16px; font-weight: bold;">Assessment Expires Today</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">{{greeting}}</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">The security assessment for <strong>{{vendor_name}}</strong> expires <strong>today, {{expires_at}}</strong>. After this date, the assessment link will no longer be accessible.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Please complete it immediately:</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{assessment_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Assessment Now</a></td></tr></table>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated reminder from {{system_title}}. Please do not reply to this email.</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Assessment Expires Today
==========================================

{{greeting}}

The security assessment for {{vendor_name}} expires today, {{expires_at}}. After this date, the assessment link will no longer be accessible.

Please complete it immediately:

{{assessment_url}}

==========================================
This is an automated reminder from {{system_title}}. Please do not reply to this email.',
                ],
            ],
            'stakeholder' => [
                '30_days_before' => [
                    'subject' => 'Upcoming Vendor Review: {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;"><p style="margin: 0; color: #856404; font-size: 16px; font-weight: bold;">Annual Vendor Review Due Soon</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">The annual review for <strong>{{vendor_name}}</strong> is due in 30 days on <strong>{{due_date}}</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>
                    <ul style="color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{review_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Review Now</a></td></tr></table>
                    <p style="margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style="margin: 10px 0 0 0; color: #ffffff; font-size: 12px;"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Annual Vendor Review Due Soon
==========================================

The annual review for {{vendor_name}} is due in 30 days on {{due_date}}.

As the assigned stakeholder, you are required to complete the annual review, which includes:
- Confirming your role as the current stakeholder
- Reviewing and updating the scope of services
- Verifying vendor contact information

Complete your review here:
{{review_url}}

If you are no longer the stakeholder for this vendor, you can reassign it during the review process.

==========================================
Vendor: {{vendor_name}}

This is an automated notification from {{system_title}}. Please do not reply to this email.',
                ],
                'due_date' => [
                    'subject' => 'Action Required: Vendor Review Due Today - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #FFF3CD; border-left: 4px solid #FF9800; padding: 15px 30px;"><p style="margin: 0; color: #856404; font-size: 16px; font-weight: bold;">Annual Vendor Review Due Today</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">The annual review for <strong>{{vendor_name}}</strong> is due today, <strong>{{due_date}}</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>
                    <ul style="color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{review_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Review Now</a></td></tr></table>
                    <p style="margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style="margin: 10px 0 0 0; color: #ffffff; font-size: 12px;"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Annual Vendor Review Due Today
==========================================

The annual review for {{vendor_name}} is due today, {{due_date}}.

As the assigned stakeholder, you are required to complete the annual review, which includes:
- Confirming your role as the current stakeholder
- Reviewing and updating the scope of services
- Verifying vendor contact information

Complete your review here:
{{review_url}}

If you are no longer the stakeholder for this vendor, you can reassign it during the review process.

==========================================
Vendor: {{vendor_name}}

This is an automated notification from {{system_title}}. Please do not reply to this email.',
                ],
                'overdue' => [
                    'subject' => 'OVERDUE: Vendor Review Required - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;"><p style="margin: 0; color: #721C24; font-size: 16px; font-weight: bold;">Annual Vendor Review Overdue</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">The annual review for <strong>{{vendor_name}}</strong> was due on <strong>{{due_date}}</strong> and is now <strong>{{days_overdue}} days overdue</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">As the assigned stakeholder, you are required to complete the annual review, which includes:</p>
                    <ul style="color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;"><li>Confirming your role as the current stakeholder</li><li>Reviewing and updating the scope of services</li><li>Verifying vendor contact information</li></ul>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{review_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">Complete Review Now</a></td></tr></table>
                    <p style="margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;">If you are no longer the stakeholder for this vendor, you can reassign it during the review process.</p>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p><p style="margin: 10px 0 0 0; color: #ffffff; font-size: 12px;"><strong>Vendor:</strong> {{vendor_name}}</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Annual Vendor Review Overdue
==========================================

The annual review for {{vendor_name}} was due on {{due_date}} and is now {{days_overdue}} days overdue.

As the assigned stakeholder, you are required to complete the annual review, which includes:
- Confirming your role as the current stakeholder
- Reviewing and updating the scope of services
- Verifying vendor contact information

Complete your review here:
{{review_url}}

If you are no longer the stakeholder for this vendor, you can reassign it during the review process.

==========================================
Vendor: {{vendor_name}}

This is an automated notification from {{system_title}}. Please do not reply to this email.',
                ],
            ],
            'procurement' => [
                'contract_expiring' => [
                    'subject' => 'Contract Expiring Soon: {{contract_name}} - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px 30px;"><p style="margin: 0; color: #856404; font-size: 16px; font-weight: bold;">Contract Expiring Soon</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">The contract <strong>{{contract_name}}</strong> for vendor <strong>{{vendor_name}}</strong> is expiring in <strong>{{days_until_expiry}} days</strong> on <strong>{{expiration_date}}</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Contract type: <strong>{{contract_type}}</strong></p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Please review this contract and take appropriate action before the expiration date.</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{contract_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">View Contract</a></td></tr></table>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Contract Expiring Soon
==========================================

The contract {{contract_name}} for vendor {{vendor_name}} is expiring in {{days_until_expiry}} days on {{expiration_date}}.

Contract type: {{contract_type}}

Please review this contract and take appropriate action before the expiration date.

View contract: {{contract_url}}

==========================================
This is an automated notification from {{system_title}}. Please do not reply to this email.',
                ],
                'contract_expired' => [
                    'subject' => 'Contract Expired: {{contract_name}} - {{vendor_name}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #F8D7DA; border-left: 4px solid #DC3545; padding: 15px 30px;"><p style="margin: 0; color: #721C24; font-size: 16px; font-weight: bold;">Contract Expired</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">The contract <strong>{{contract_name}}</strong> for vendor <strong>{{vendor_name}}</strong> expired on <strong>{{expiration_date}}</strong> and is now <strong>{{days_overdue}} days overdue</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Contract type: <strong>{{contract_type}}</strong></p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Immediate action is required to renew or address this expired contract.</p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{contract_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">View Contract</a></td></tr></table>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Contract Expired
==========================================

The contract {{contract_name}} for vendor {{vendor_name}} expired on {{expiration_date}} and is now {{days_overdue}} days overdue.

Contract type: {{contract_type}}

Immediate action is required to renew or address this expired contract.

View contract: {{contract_url}}

==========================================
This is an automated notification from {{system_title}}. Please do not reply to this email.',
                ],
                'case_assigned' => [
                    'subject' => 'Contract Case Assigned: {{case_title}}',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr><td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <tr><td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}<h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1></td></tr>
                <tr><td style="background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;"><p style="margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;">Contract Case Assigned to You</p></td></tr>
                <tr><td style="padding: 30px;">
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">A contract expiration case has been assigned to you by <strong>{{assigned_by}}</strong>.</p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Case: <strong>{{case_title}}</strong></p>
                    <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">Vendor: <strong>{{vendor_name}}</strong></p>
                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;"><tr><td align="center"><a href="{{case_url}}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">View Case</a></td></tr></table>
                </td></tr>
                <tr><td style="background-color: {{footer_color}}; padding: 20px 30px;"><p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p></td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>',
                    'text' => 'Contract Case Assigned to You
==========================================

A contract expiration case has been assigned to you by {{assigned_by}}.

Case: {{case_title}}
Vendor: {{vendor_name}}

View case: {{case_url}}

==========================================
This is an automated notification from {{system_title}}. Please do not reply to this email.',
                ],
            ],
            'grc' => [
                'task_digest' => [
                    'subject' => 'GRC Task Summary: {{task_count}} task(s) assigned to you',
                    'html' => '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
  <tr><td style="background:{{header_color}};padding:24px 32px;">
    <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:600;">GRC Task Assignment Summary</h1>
  </td></tr>
  <tr><td style="padding:24px 32px;">
    <p style="margin:0 0 16px;color:#374151;font-size:14px;">Hello {{recipient_name}},</p>
    <p style="margin:0 0 16px;color:#374151;font-size:14px;">You have <strong>{{task_count}} task(s)</strong> assigned to you in the GRC Assessment module.</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-bottom:20px;">
      <tr style="background:#f9fafb;">
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Ref</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Task</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Assessment</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Priority</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Due</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Status</th>
      </tr>
      {{task_rows_html}}
    </table>
    <p style="text-align:center;margin:20px 0;">
      <a href="{{assessment_url}}" style="display:inline-block;background:{{button_color}};color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:14px;font-weight:600;">View My Tasks</a>
    </p>
  </td></tr>
  <tr><td style="background:{{footer_color}};padding:16px 32px;text-align:center;">
    <p style="margin:0;color:#ffffff;font-size:12px;opacity:0.8;">{{system_title}}</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>',
                    'text' => 'GRC Task Assignment Summary

Hello {{recipient_name}},

You have {{task_count}} task(s) assigned to you in the GRC Assessment module.

Your Tasks:
{{task_rows_text}}

View your tasks: {{assessment_url}}',
                ],
            ],
        ];

        return $defaults[$category][$key] ?? null;
    }

    /**
     * Seeds the email_templates table with factory defaults if it exists but is empty.
     * Called from the admin email settings page on first load after migration.
     * Uses getDefaultTemplateContent() so there's one source of truth for defaults.
     *
     * @param Database $db The database instance
     * @return bool True if templates were seeded, false otherwise
     */
    public static function seedDefaultTemplates(Database $db): bool
    {
        try {
            // Define the templates to seed: [category, key, display_name, available_variables]
            $templates = [
                ['vendor', 'assessment_request', 'Assessment Request (Default)', '["vendor_name","assessment_name","assessment_url","greeting","contact_name","full_name","from_name","company_name","expires_at","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['vendor', 'assessment_7_days_before', 'Assessment Reminder - 7 Days', '["vendor_name","assessment_name","assessment_url","greeting","contact_name","expires_at","days_left","from_name","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['vendor', 'assessment_3_days_before', 'Assessment Reminder - 3 Days', '["vendor_name","assessment_name","assessment_url","greeting","contact_name","expires_at","days_left","from_name","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['vendor', 'assessment_expiry_day', 'Assessment Reminder - Expiry Day', '["vendor_name","assessment_name","assessment_url","greeting","contact_name","expires_at","days_left","from_name","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['stakeholder', '30_days_before', 'Annual Review - 30 Day Reminder', '["vendor_name","review_url","due_date","from_name","company_name","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['stakeholder', 'due_date', 'Annual Review - Due Today', '["vendor_name","review_url","due_date","from_name","company_name","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['stakeholder', 'overdue', 'Annual Review - Overdue Notice', '["vendor_name","review_url","due_date","days_overdue","from_name","company_name","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['procurement', 'contract_expiring', 'Contract Expiring Soon', '["contract_name","vendor_name","expiration_date","days_until_expiry","contract_type","contract_url","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['procurement', 'contract_expired', 'Contract Expired', '["contract_name","vendor_name","expiration_date","days_overdue","contract_type","contract_url","header_color","button_color","footer_color","system_title","logo_img"]'],
                ['procurement', 'case_assigned', 'Contract Case Assigned', '["case_title","vendor_name","assigned_by","case_url","header_color","button_color","footer_color","system_title","logo_img"]'],
            ];

            // Check if table has any rows
            $count = $db->fetchOne("SELECT COUNT(*) as cnt FROM email_templates");
            if ($count && (int)$count['cnt'] > 0) {
                // Already seeded -- update available_variables on existing rows
                // and insert any new templates that were added since initial seeding
                $seededNew = false;
                foreach ($templates as $tpl) {
                    $existing = $db->fetchOne(
                        "SELECT id FROM email_templates WHERE template_category = ? AND template_key = ? AND assessment_template_id IS NULL",
                        [$tpl[0], $tpl[1]]
                    );
                    if ($existing) {
                        $db->query(
                            "UPDATE email_templates SET available_variables = ? WHERE template_category = ? AND template_key = ? AND assessment_template_id IS NULL",
                            [$tpl[3], $tpl[0], $tpl[1]]
                        );
                    } else {
                        // New template added since initial seeding -- insert it
                        $defaults = self::getDefaultTemplateContent($tpl[0], $tpl[1]);
                        if ($defaults) {
                            $db->query(
                                "INSERT IGNORE INTO email_templates (template_category, template_key, assessment_template_id, display_name, email_subject, email_body_html, email_body_text, available_variables)
                                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?)",
                                [$tpl[0], $tpl[1], $tpl[2], $defaults['subject'], $defaults['html'], $defaults['text'], $tpl[3]]
                            );
                            $seededNew = true;
                        }
                    }
                }
                return $seededNew;
            }

            foreach ($templates as $tpl) {
                $defaults = self::getDefaultTemplateContent($tpl[0], $tpl[1]);
                if (!$defaults) continue;

                $db->query(
                    "INSERT IGNORE INTO email_templates (template_category, template_key, assessment_template_id, display_name, email_subject, email_body_html, email_body_text, available_variables)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?)",
                    [$tpl[0], $tpl[1], $tpl[2], $defaults['subject'], $defaults['html'], $defaults['text'], $tpl[3]]
                );
            }

            return true;
        } catch (Exception $e) {
            error_log('EmailService: Failed to seed default templates - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The main event -- sends an annual review reminder email to a stakeholder.
     *
     * Takes the vendor info, the stakeholder's email, what flavor of reminder
     * we're sending (30 days heads-up, due today, or the dreaded "overdue"),
     * and the due date. Spins up PHPMailer, builds a pretty HTML email, and
     * launches it into the SMTP void.
     *
     * Returns true if the email gods accepted our offering, false if they smited us.
     */
    public function sendAnnualReviewReminder($vendor, $stakeholderEmail, $reminderType, $dueDate) {
        if (!$this->enabled) {
            error_log("EmailService: Email notifications are disabled");
            return false;
        }

        if (empty($stakeholderEmail)) {
            error_log("EmailService: No stakeholder email provided");
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($stakeholderEmail);

            $emailData = $this->getEmailContent($vendor, $reminderType, $dueDate);
            $mail->Subject = $emailData['subject'];
            $mail->Body = $emailData['html'];
            $mail->AltBody = $emailData['text'];

            $this->sendEmail($mail);
            return true;

        } catch (Exception $e) {
            error_log("EmailService: Failed to send email - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a test email to verify SMTP configuration is working.
     * Returns an array with 'success' (bool) and 'message' (string) for UI feedback.
     */
    public function sendTestEmail($recipientEmail) {
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid recipient email address.'];
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);
            $branding = self::getEmailBrandingVars();
            $mail->Subject = $branding['system_title'] . ' - Test Email';

            $timestamp = date('F j, Y \a\t g:i A T');
            $isGraph = $this->getEmailMethod() === 'microsoft_graph';

            if ($isGraph) {
                $transportLabel = 'Microsoft 365 Graph API';
                $senderEmail = htmlspecialchars($this->config['email_ms_sender'] ?? '');
                $tenantId = htmlspecialchars($this->config['email_ms_tenant_id'] ?? '');
                $configMessage = "your Microsoft 365 Graph API configuration is working correctly.";
                $configRows = <<<ROWS
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Transport</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">Microsoft 365 Graph API</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Tenant ID</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$tenantId}</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Sender</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$senderEmail}</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Sent At</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$timestamp}</td></tr>
ROWS;
                $altBody = "{$branding['system_title']} - Test Email\n\nThis is a test email. Your Microsoft 365 Graph API configuration is working correctly.\n\nTransport: Microsoft 365 Graph API\nTenant ID: {$tenantId}\nSender: {$senderEmail}\nSent At: {$timestamp}";
            } else {
                $smtpHost = htmlspecialchars($this->config['smtp_host'] ?? 'localhost');
                $smtpPort = htmlspecialchars($this->config['smtp_port'] ?? '25');
                $smtpEnc = htmlspecialchars($this->config['smtp_encryption'] ?? 'none');
                $fromEmail = htmlspecialchars($this->config['email_from_email'] ?? 'noreply@example.com');
                $fromName = htmlspecialchars($this->config['email_from_name'] ?? 'TPRM System');
                $configMessage = "your SMTP configuration is working correctly.";
                $configRows = <<<ROWS
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Transport</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">SMTP</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">SMTP Host</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$smtpHost}</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">SMTP Port</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$smtpPort}</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Encryption</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$smtpEnc}</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">From</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$fromName} &lt;{$fromEmail}&gt;</td></tr>
                                <tr><td style="padding: 8px 12px; background: #f8f9fa; border: 1px solid #dee2e6; font-weight: bold; color: #495057;">Sent At</td><td style="padding: 8px 12px; border: 1px solid #dee2e6; color: #333;">{$timestamp}</td></tr>
ROWS;
                $altBody = "{$branding['system_title']} - Test Email\n\nThis is a test email. Your SMTP configuration is working correctly.\n\nSMTP Host: {$smtpHost}\nSMTP Port: {$smtpPort}\nEncryption: {$smtpEnc}\nFrom: {$fromName} <{$fromEmail}>\nSent At: {$timestamp}";
            }

            $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background-color: {$branding["header_color"]}; padding: 30px; text-align: center;">{$branding["logo_img"]}
                            <h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{$branding["system_title"]}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #D4EDDA; border-left: 4px solid #28A745; padding: 15px 30px;">
                            <p style="margin: 0; color: #155724; font-size: 16px; font-weight: bold;">Test Email - Configuration Verified</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px;">
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                This is a test email from {$branding['system_title']}. If you're reading this, {$configMessage}
                            </p>
                            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
{$configRows}
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: {$branding["footer_color"]}; padding: 20px 30px;">
                            <p style="margin: 0; color: #ffffff; font-size: 12px;">This is a test email from {$branding["system_title"]} admin panel. No action is required.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

            $mail->AltBody = $altBody;

            $this->sendEmail($mail);
            return ['success' => true, 'message' => "Test email sent successfully to {$recipientEmail}"];

        } catch (Exception $e) {
            error_log("EmailService: Test email failed - " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()];
        }
    }

    /**
     * Sends a test email using a specific template with sample data.
     */
    public function sendTemplateTest(array $template, string $recipientEmail): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'Email notifications are disabled.'];
        }
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid recipient email address.'];
        }

        try {
            $baseUrl = rtrim(getAppConfig('base_url', ''), '/');
            if (empty($baseUrl)) {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $sampleVars = [
                'vendor_name' => 'Acme Corporation',
                'assessment_url' => $baseUrl . '/assessment.php?token=sample-test-preview',
                'review_url' => $baseUrl . '/index.php',
                'contract_url' => $baseUrl . '/index.php',
                'case_url' => $baseUrl . '/index.php',
                'greeting' => 'Dear Vendor Contact,',
                'contact_name' => 'Jane Smith',
                'full_name' => 'Jane Smith',
                'from_name' => 'TPRM Admin',
                'company_name' => getAppConfig('company_name', '') ?: 'Your Company',
                'expires_at' => date('F j, Y', strtotime('+30 days')),
                'due_date' => date('F j, Y', strtotime('+30 days')),
                'days_left' => '7',
                'days_overdue' => '5',
                'days_until_expiry' => '14',
                'contract_name' => 'Cloud Services Agreement',
                'contract_type' => 'SaaS Subscription',
                'expiration_date' => date('F j, Y', strtotime('+14 days')),
                'case_title' => 'Contract Renewal - Acme Corporation',
                'assigned_by' => 'TPRM Admin',
            ];

            $branding = self::getEmailBrandingVars();
            $allVars = array_merge($branding, $sampleVars);

            $htmlBody = $template['email_body_html'];
            $textBody = $template['email_body_text'] ?? '';
            $subject = $template['email_subject'] ?? 'Test Email';

            foreach ($allVars as $key => $value) {
                $htmlBody = str_replace('{{' . $key . '}}', (string)$value, $htmlBody);
                $textBody = str_replace('{{' . $key . '}}', (string)$value, $textBody);
                $subject = str_replace('{{' . $key . '}}', (string)$value, $subject);
            }

            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);
            $mail->Subject = '[TEST] ' . $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
            $this->sendEmail($mail);

            return ['success' => true, 'message' => "Test email sent to {$recipientEmail} using template \"{$template['template_key']}\"."];
        } catch (\Exception $e) {
            error_log("EmailService: Template test failed - " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()];
        }
    }

    /**
     * Sends a vendor security assessment request email with the magic link.
     * Used when email is enabled and someone clicks "Email" on the assessments page
     * instead of falling back to a mailto: link.
     *
     * @param string $vendorEmail Recipient email address
     * @param string $vendorName Vendor's display name
     * @param string $assessmentUrl Full URL to the assessment form
     * @param string|null $contactName Optional contact person name
     * @return array ['success' => bool, 'message' => string]
     */
    public function sendAssessmentEmail(string $vendorEmail, string $vendorName, string $assessmentUrl, ?string $contactName = null, ?int $assessmentTemplateId = null, ?string $fullName = null, ?string $expiresAt = null, ?string $assessmentName = null): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'message' => 'Email notifications are disabled.'];
        }

        if (empty($vendorEmail) || !filter_var($vendorEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid vendor email address.'];
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($vendorEmail, $contactName ?? '');

            $safeVendorName = htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8');
            $safeUrl = htmlspecialchars($assessmentUrl, ENT_QUOTES, 'UTF-8');
            $greeting = $contactName ? 'Dear ' . htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8') . ',' : 'Hello,';

            // Try DB template first, fall back to hardcoded
            $dbTemplate = $this->getTemplateFromDb('vendor', 'assessment_request', $assessmentTemplateId);
            // Resolve the assessment (template) name for the subject/body. Callers may
            // pass it explicitly; otherwise look it up from the assessment template id
            // so every send path gets it without threading the value through.
            if (($assessmentName === null || $assessmentName === '') && $assessmentTemplateId !== null) {
                try {
                    $tplRow = $this->db->fetchOne(
                        "SELECT name FROM assessment_templates WHERE id = ?",
                        [(int)$assessmentTemplateId]
                    );
                    if ($tplRow && !empty($tplRow['name'])) {
                        $assessmentName = $tplRow['name'];
                    }
                } catch (Exception $e) {
                    // Non-fatal: subject simply omits the assessment name.
                }
            }

            if ($dbTemplate) {
                $companyName = '';
                try { $companyName = getAppConfig('company_name', ''); } catch (Exception $e) {}
                $formattedExpiry = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : date('F j, Y', strtotime('+30 days'));
                $vars = [
                    'vendor_name' => $safeVendorName,
                    'assessment_url' => $safeUrl,
                    'greeting' => $greeting,
                    'contact_name' => htmlspecialchars($contactName ?? '', ENT_QUOTES, 'UTF-8'),
                    'full_name' => htmlspecialchars($fullName ?? $contactName ?? '', ENT_QUOTES, 'UTF-8'),
                    'from_name' => htmlspecialchars($this->config['email_from_name'] ?? 'TPRM System', ENT_QUOTES, 'UTF-8'),
                    'company_name' => htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'),
                    'expires_at' => htmlspecialchars($formattedExpiry, ENT_QUOTES, 'UTF-8'),
                    'assessment_name' => htmlspecialchars($assessmentName ?? '', ENT_QUOTES, 'UTF-8'),
                ];
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);

                $this->sendEmail($mail);
                return ['success' => true, 'message' => "Assessment email sent to {$vendorEmail}"];
            }

            // Hardcoded fallback (used if email_templates table doesn't exist yet)
            $formattedExpiryFallback = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : date('F j, Y', strtotime('+30 days'));
            $namePart = !empty($assessmentName) ? " ({$assessmentName})" : '';
            $mail->Subject = "Security Assessment Request{$namePart} - {$vendorName}";

            $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background-color: {{header_color}}; padding: 30px; text-align: center;">{{logo_img}}
                            <h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{{system_title}}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #D1ECF1; border-left: 4px solid #17A2B8; padding: 15px 30px;">
                            <p style="margin: 0; color: #0C5460; font-size: 16px; font-weight: bold;">Security Assessment Request</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 30px;">
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                {$greeting}
                            </p>
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                As part of our vendor risk management process, we require <strong>{$safeVendorName}</strong> to complete a security assessment.
                            </p>
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                Please click the button below to access and complete the assessment:
                            </p>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$safeUrl}" style="display: inline-block; padding: 15px 40px; background-color: {{button_color}}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                                            Complete Assessment
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;">
                                This link will expire on {$formattedExpiryFallback}. If you have any questions, please contact us.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: {{footer_color}}; padding: 20px 30px;">
                            <p style="margin: 0; color: #ffffff; font-size: 12px;">This is an automated notification from {{system_title}}. Please do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
            // Apply branding to the fallback HTML
            $mail->Body = $this->renderWithBranding($mail->Body, []);

            $plainGreeting = $contactName ? "Dear {$contactName}," : 'Hello,';
            $mail->AltBody = <<<TEXT
{$plainGreeting}

As part of our vendor risk management process, we require {$vendorName} to complete a security assessment.

Please click the link below to access and complete the assessment:

{$assessmentUrl}

This link will expire on {$formattedExpiryFallback}. If you have any questions, please contact us.

Thank you,
Third Party Risk Management Team
TEXT;

            $this->sendEmail($mail);
            return ['success' => true, 'message' => "Assessment email sent to {$vendorEmail}"];

        } catch (Exception $e) {
            error_log("EmailService: Assessment email failed - " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send email: ' . $e->getMessage()];
        }
    }

    /**
     * Builds the email subject, HTML body, and plain text fallback based on reminder type.
     * This is where we decide how passive-aggressive the email should be.
     * 30 days out? Gentle nudge. Overdue? ALL CAPS SUBJECT LINE ENERGY.
     */
    private function getEmailContent($vendor, $reminderType, $dueDate) {
        $vendorName = htmlspecialchars($vendor['vendor_name'] ?? 'Unknown Vendor');
        $vendorId = intval($vendor['id']);
        $formattedDueDate = date('F j, Y', strtotime($dueDate));

        // Build the review URL so the stakeholder can click straight to the review page.
        $reviewUrl = baseUrl("vendor-annual-review.php?id={$vendorId}");

        // Pick the right tone based on urgency level
        switch ($reminderType) {
            case '30_days_before':
                // Friendly heads-up. "Hey, this is coming up. No rush. But also... rush."
                $subject = "Upcoming Vendor Review: {$vendorName}";
                $heading = "Annual Vendor Review Due Soon";
                $message = "The annual review for <strong>{$vendorName}</strong> is due in 30 days on <strong>{$formattedDueDate}</strong>.";
                $urgency = "due-soon";
                break;

            case 'due_date':
                // Today's the day. The "Action Required" in the subject means business.
                $subject = "Action Required: Vendor Review Due Today - {$vendorName}";
                $heading = "Annual Vendor Review Due Today";
                $message = "The annual review for <strong>{$vendorName}</strong> is due today, <strong>{$formattedDueDate}</strong>.";
                $urgency = "due-now";
                break;

            case 'overdue':
                // The nuclear option. Calculate how many days they've been slacking.
                $daysOverdue = floor((time() - strtotime($dueDate)) / 86400);
                $subject = "OVERDUE: Vendor Review Required - {$vendorName}";
                $heading = "Annual Vendor Review Overdue";
                $message = "The annual review for <strong>{$vendorName}</strong> was due on <strong>{$formattedDueDate}</strong> and is now <strong>{$daysOverdue} days overdue</strong>.";
                $urgency = "overdue";
                break;

            default:
                // Generic fallback -- shouldn't really hit this, but just in case
                $subject = "Vendor Review Reminder: {$vendorName}";
                $heading = "Annual Vendor Review Reminder";
                $message = "This is a reminder about the annual review for <strong>{$vendorName}</strong>.";
                $urgency = "reminder";
        }

        // Try DB template first, fall back to hardcoded
        $daysOverdueStr = isset($daysOverdue) ? (string)$daysOverdue : '0';
        $companyName = '';
        try { $companyName = getAppConfig('company_name', ''); } catch (Exception $e) {}
        $dbTemplate = $this->getTemplateFromDb('stakeholder', $reminderType);
        if ($dbTemplate) {
            $vars = [
                'vendor_name' => $vendorName,
                'review_url' => $reviewUrl,
                'due_date' => $formattedDueDate,
                'days_overdue' => $daysOverdueStr,
                'from_name' => htmlspecialchars($this->config['email_from_name'] ?? 'TPRM System', ENT_QUOTES, 'UTF-8'),
                'company_name' => htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'),
            ];
            return [
                'subject' => $this->renderWithBranding($dbTemplate['email_subject'], $vars),
                'html' => $this->renderWithBranding($dbTemplate['email_body_html'], $vars),
                'text' => $this->renderWithBranding($dbTemplate['email_body_text'], $vars),
            ];
        }

        // Hardcoded fallback (used if email_templates table doesn't exist yet)
        $html = $this->getHtmlTemplate($heading, $message, $vendorName, $reviewUrl, $urgency);
        $text = $this->getTextTemplate($heading, $message, $vendorName, $reviewUrl);

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text
        ];
    }

    /**
     * The fancy HTML email template.
     * Built with inline styles because email clients hate <style> tags more than
     * developers hate building email templates. Every. Single. Style. Is. Inline.
     * Yes, it's ugly in the code. Yes, it's the only way that works. Welcome to email dev.
     *
     * Color-codes the urgency banner so overdue = red and upcoming = yellow.
     * Because nothing motivates a stakeholder like a big red banner of shame.
     */
    private function getHtmlTemplate($heading, $message, $vendorName, $reviewUrl, $urgency) {
        $branding = self::getEmailBrandingVars();
        // Color palette for different urgency levels -- from "meh" blue to "oh crap" red
        $urgencyColors = [
            'due-soon' => ['bg' => '#FFF3CD', 'border' => '#FFC107', 'text' => '#856404'],
            'due-now' => ['bg' => '#FFF3CD', 'border' => '#FF9800', 'text' => '#856404'],
            'overdue' => ['bg' => '#F8D7DA', 'border' => '#DC3545', 'text' => '#721C24'],
            'reminder' => ['bg' => '#D1ECF1', 'border' => '#17A2B8', 'text' => '#0C5460']
        ];

        $colors = $urgencyColors[$urgency] ?? $urgencyColors['reminder'];

        // Here comes the monster HTML template. Nested tables because it's 2024
        // and we're still building emails like it's 1999. Thanks, Outlook.
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: {$branding["header_color"]}; padding: 30px; text-align: center;">{$branding["logo_img"]}
                            <h1 style="margin: 10px 0 0 0; color: #ffffff; font-size: 20px;">{$branding["system_title"]}</h1>
                        </td>
                    </tr>

                    <!-- Alert Banner -->
                    <tr>
                        <td style="background-color: {$colors['bg']}; border-left: 4px solid {$colors['border']}; padding: 15px 30px;">
                            <p style="margin: 0; color: {$colors['text']}; font-size: 16px; font-weight: bold;">
                                {$heading}
                            </p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                {$message}
                            </p>

                            <p style="margin: 0 0 20px 0; color: #333333; font-size: 16px; line-height: 1.6;">
                                As the assigned stakeholder, you are required to complete the annual review, which includes:
                            </p>

                            <ul style="color: #333333; font-size: 16px; line-height: 1.8; margin: 0 0 20px 0;">
                                <li>Confirming your role as the current stakeholder</li>
                                <li>Reviewing and updating the scope of services</li>
                                <li>Verifying vendor contact information</li>
                            </ul>

                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$reviewUrl}" style="display: inline-block; padding: 15px 40px; background-color: {$branding["button_color"]}; color: #ffffff; text-decoration: none; border-radius: 5px; font-size: 16px; font-weight: bold;">
                                            Complete Review Now
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 20px 0 0 0; color: #666666; font-size: 14px; line-height: 1.6;">
                                If you are no longer the stakeholder for this vendor, you can reassign it during the review process.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: {$branding["footer_color"]}; padding: 20px 30px;">
                            <p style="margin: 0; color: #ffffff; font-size: 12px; line-height: 1.6;">
                                This is an automated notification from {$branding["system_title"]}. Please do not reply to this email.
                            </p>
                            <p style="margin: 10px 0 0 0; color: #ffffff; font-size: 12px;">
                                <strong>Vendor:</strong> {$vendorName}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Plain text email template for clients that can't handle our beautiful HTML.
     * Basically the same info but with ASCII art dividers because we're classy like that.
     * This is the email equivalent of a black coffee -- no frills, gets the job done.
     */
    private function getTextTemplate($heading, $message, $vendorName, $reviewUrl) {
        return <<<TEXT
{$heading}
==========================================

{$message}

As the assigned stakeholder, you are required to complete the annual review, which includes:
- Confirming your role as the current stakeholder
- Reviewing and updating the scope of services
- Verifying vendor contact information

Complete your review here:
{$reviewUrl}

If you are no longer the stakeholder for this vendor, you can reassign it during the review process.

==========================================
Vendor: {$vendorName}

This is an automated notification from {{system_title}}. Please do not reply to this email.
TEXT;
    }

    /**
     * Send an assessment expiry reminder email to the vendor contact.
     * Follows the same pattern as sendContractExpiryReminder().
     *
     * @param array  $assessment   Assessment row with vendor_name, vendor_contact_name, uuid, expires_at, etc.
     * @param string $vendorEmail  Recipient email address (vendor_contact_email)
     * @param string $reminderType One of '7_days_before', '3_days_before', 'expiry_day'
     * @return bool True if sent successfully
     */
    public function sendAssessmentReminder(array $assessment, string $vendorEmail, string $reminderType): bool
    {
        if (!$this->enabled || empty($vendorEmail)) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($vendorEmail, $assessment['vendor_contact_name'] ?? '');

            $vendorName = htmlspecialchars($assessment['vendor_name'] ?? 'Unknown Vendor', ENT_QUOTES, 'UTF-8');
            $contactName = $assessment['vendor_contact_name'] ?? '';
            $greeting = $contactName ? 'Dear ' . htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8') . ',' : 'Hello,';
            $expiresAt = date('F j, Y', strtotime($assessment['expires_at']));
            $assessmentUrl = baseUrl('vendor-assessment.php?token=' . $assessment['uuid']);
            $daysLeft = max(0, (int)floor((strtotime($assessment['expires_at']) - time()) / 86400));

            $templateKey = 'assessment_' . $reminderType;
            $dbTemplate = $this->getTemplateFromDb('vendor', $templateKey);

            // Resolve the assessment (template) name so it can appear in the subject/body.
            // Prefer a value already on the row; otherwise look it up from the template id.
            $assessmentName = $assessment['assessment_name'] ?? '';
            if ($assessmentName === '' && !empty($assessment['template_id'])) {
                try {
                    $tplRow = $this->db->fetchOne(
                        "SELECT name FROM assessment_templates WHERE id = ?",
                        [(int)$assessment['template_id']]
                    );
                    if ($tplRow && !empty($tplRow['name'])) {
                        $assessmentName = $tplRow['name'];
                    }
                } catch (Exception $e) {
                    // Non-fatal: subject simply omits the assessment name.
                }
            }

            $vars = [
                'vendor_name' => $vendorName,
                'assessment_url' => htmlspecialchars($assessmentUrl, ENT_QUOTES, 'UTF-8'),
                'greeting' => $greeting,
                'contact_name' => htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8'),
                'expires_at' => htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8'),
                'days_left' => (string)$daysLeft,
                'from_name' => htmlspecialchars($this->config['email_from_name'] ?? 'TPRM System', ENT_QUOTES, 'UTF-8'),
                'assessment_name' => htmlspecialchars($assessmentName, ENT_QUOTES, 'UTF-8'),
            ];

            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                $defaults = self::getDefaultTemplateContent('vendor', $templateKey);
                if ($defaults) {
                    $mail->Subject = $this->renderWithBranding($defaults['subject'], $vars);
                    $mail->Body = $this->renderWithBranding($defaults['html'], $vars);
                    $mail->AltBody = $this->renderWithBranding($defaults['text'], $vars);
                } else {
                    $mail->Subject = "Assessment Reminder: {$vendorName}";
                    $mail->Body = "Your security assessment for {$vendorName} requires attention. Please complete it before {$expiresAt}.";
                    $mail->AltBody = $mail->Body;
                }
            }

            $this->sendEmail($mail);
            return true;
        } catch (Exception $e) {
            error_log("EmailService: Assessment reminder failed - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a contract expiry reminder email (expiring soon or expired).
     * Follows the same pattern as sendAnnualReviewReminder().
     */
    public function sendContractExpiryReminder(array $contract, string $recipientEmail, string $reminderType): bool
    {
        if (!$this->enabled || empty($recipientEmail)) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);

            $contractName = htmlspecialchars($contract['contract_name'] ?? 'Unknown Contract', ENT_QUOTES, 'UTF-8');
            $vendorName = htmlspecialchars($contract['vendor_name'] ?? 'Unknown Vendor', ENT_QUOTES, 'UTF-8');
            $contractType = htmlspecialchars($contract['contract_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $expirationDate = date('F j, Y', strtotime($contract['contract_expiration_date']));
            $daysUntilExpiry = (int)$contract['days_until_expiry'];

            $contractUrl = baseUrl('procurement-contracts.php?filter=expiring');

            $templateKey = ($reminderType === 'expired' || $reminderType === 'expiring_today_expired') ? 'contract_expired' : 'contract_expiring';
            $dbTemplate = $this->getTemplateFromDb('procurement', $templateKey);

            $vars = [
                'contract_name' => $contractName,
                'vendor_name' => $vendorName,
                'expiration_date' => $expirationDate,
                'days_until_expiry' => (string)abs($daysUntilExpiry),
                'days_overdue' => (string)abs($daysUntilExpiry),
                'contract_type' => $contractType,
                'contract_url' => $contractUrl,
            ];

            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                $defaults = self::getDefaultTemplateContent('procurement', $templateKey);
                if ($defaults) {
                    $mail->Subject = $this->renderWithBranding($defaults['subject'], $vars);
                    $mail->Body = $this->renderWithBranding($defaults['html'], $vars);
                    $mail->AltBody = $this->renderWithBranding($defaults['text'], $vars);
                } else {
                    $mail->Subject = "Contract Expiry Notice: {$contractName}";
                    $mail->Body = "Contract {$contractName} for vendor {$vendorName} requires attention. Expiration date: {$expirationDate}.";
                    $mail->AltBody = $mail->Body;
                }
            }

            $this->sendEmail($mail);
            return true;
        } catch (Exception $e) {
            error_log("EmailService: Contract expiry reminder failed - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a case assignment notification email.
     */
    public function sendCaseAssignmentNotification(array $caseData, string $recipientEmail, string $assignerName): bool
    {
        if (!$this->enabled || empty($recipientEmail)) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);

            // Default target is the procurement expiring-contracts list, but a
            // caller (e.g. a fired scheduled action) can override it with a deep
            // link to the relevant page via $caseData['url'].
            $caseUrl = !empty($caseData['url'])
                ? (string)$caseData['url']
                : baseUrl('vendor-onboarding-list.php?view=expiring_contracts');

            $vars = [
                'case_title' => htmlspecialchars($caseData['title'] ?? 'Contract Case', ENT_QUOTES, 'UTF-8'),
                'vendor_name' => htmlspecialchars($caseData['vendor_name'] ?? 'Unknown Vendor', ENT_QUOTES, 'UTF-8'),
                'assigned_by' => htmlspecialchars($assignerName, ENT_QUOTES, 'UTF-8'),
                'case_url' => $caseUrl,
            ];

            $dbTemplate = $this->getTemplateFromDb('procurement', 'case_assigned');
            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                $defaults = self::getDefaultTemplateContent('procurement', 'case_assigned');
                if ($defaults) {
                    $mail->Subject = $this->renderWithBranding($defaults['subject'], $vars);
                    $mail->Body = $this->renderWithBranding($defaults['html'], $vars);
                    $mail->AltBody = $this->renderWithBranding($defaults['text'], $vars);
                } else {
                    $mail->Subject = "Contract Case Assigned: " . ($caseData['title'] ?? 'Contract Case');
                    $mail->Body = "A contract case has been assigned to you by {$assignerName}.";
                    $mail->AltBody = $mail->Body;
                }
            }

            $this->sendEmail($mail);
            return true;
        } catch (Exception $e) {
            error_log("EmailService: Case assignment notification failed - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a GRC task assignment digest email to a user.
     * Groups all open/in-progress tasks assigned to the user into a single email
     * instead of spamming one email per task.
     *
     * @param string $recipientEmail  The assignee's email address
     * @param string $recipientName   The assignee's display name
     * @param array  $tasks           Array of task rows (with assessment_title, task_ref, etc.)
     * @param string $assessmentUrl   Base URL to the GRC assessment page
     * @return bool
     */
    public function sendGrcTaskDigest(string $recipientEmail, string $recipientName, array $tasks, string $assessmentUrl): bool
    {
        if (!$this->enabled || empty($recipientEmail) || empty($tasks)) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail, $recipientName);

            $taskCount = count($tasks);
            $criticalCount = 0;
            $highCount = 0;
            $overdueTasks = 0;
            $today = date('Y-m-d');

            // Build the task list HTML table rows
            $taskRowsHtml = '';
            $taskRowsText = '';
            foreach ($tasks as $task) {
                $priority = $task['priority'] ?? 'medium';
                if ($priority === 'critical') $criticalCount++;
                if ($priority === 'high') $highCount++;

                $isOverdue = (!empty($task['due_date']) && $task['due_date'] < $today && $task['status'] !== 'completed');
                if ($isOverdue) $overdueTasks++;

                $priorityColors = [
                    'critical' => '#dc3545',
                    'high'     => '#fd7e14',
                    'medium'   => '#ffc107',
                    'low'      => '#6c757d',
                ];
                $pColor = $priorityColors[$priority] ?? '#6c757d';
                $pLabel = ucfirst($priority);

                $ref = htmlspecialchars($task['task_ref'] ?? '', ENT_QUOTES, 'UTF-8');
                $title = htmlspecialchars($task['title'] ?? '', ENT_QUOTES, 'UTF-8');
                $assessTitle = htmlspecialchars($task['assessment_title'] ?? '', ENT_QUOTES, 'UTF-8');
                $dueDate = !empty($task['due_date']) ? date('M j, Y', strtotime($task['due_date'])) : 'No due date';
                $status = ucfirst(str_replace('_', ' ', $task['status'] ?? 'open'));
                $overdueFlag = $isOverdue ? ' style="color:#dc3545;font-weight:bold;"' : '';

                $taskRowsHtml .= "<tr>";
                $taskRowsHtml .= "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;\">{$ref}</td>";
                $taskRowsHtml .= "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;\">{$title}</td>";
                $taskRowsHtml .= "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;\">{$assessTitle}</td>";
                $taskRowsHtml .= "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;\"><span style=\"background:{$pColor};color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;\">{$pLabel}</span></td>";
                $taskRowsHtml .= "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;\"{$overdueFlag}>{$dueDate}" . ($isOverdue ? ' (OVERDUE)' : '') . "</td>";
                $taskRowsHtml .= "<td style=\"padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:13px;\">{$status}</td>";
                $taskRowsHtml .= "</tr>\n";

                $taskRowsText .= "- [{$ref}] {$title} | {$assessTitle} | Priority: {$pLabel} | Due: {$dueDate}" . ($isOverdue ? ' (OVERDUE)' : '') . " | Status: {$status}\n";
            }

            $urgentCount = $criticalCount + $highCount;

            $vars = [
                'recipient_name'  => htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8'),
                'task_count'      => (string)$taskCount,
                'urgent_count'    => (string)$urgentCount,
                'critical_count'  => (string)$criticalCount,
                'high_count'      => (string)$highCount,
                'overdue_count'   => (string)$overdueTasks,
                'task_rows_html'  => $taskRowsHtml,
                'task_rows_text'  => $taskRowsText,
                'assessment_url'  => $assessmentUrl,
            ];

            $dbTemplate = $this->getTemplateFromDb('grc', 'task_digest');
            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body    = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                // Hardcoded fallback template
                $subject = "GRC Task Summary: {$taskCount} task(s) assigned to you";
                if ($urgentCount > 0) {
                    $subject = "GRC Task Summary: {$taskCount} task(s) assigned ({$urgentCount} urgent)";
                }

                $html = $this->buildGrcTaskDigestHtml($vars);
                $text = $this->buildGrcTaskDigestText($vars);

                $mail->Subject = $this->renderWithBranding($subject, $vars);
                $mail->Body    = $this->renderWithBranding($html, $vars);
                $mail->AltBody = $this->renderWithBranding($text, $vars);
            }

            $this->sendEmail($mail);
            return true;
        } catch (Exception $e) {
            error_log("EmailService: GRC task digest failed for {$recipientEmail} - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build the default HTML template for GRC task digest emails.
     */
    private function buildGrcTaskDigestHtml(array $vars): string
    {
        $urgentBanner = '';
        if ((int)($vars['overdue_count'] ?? 0) > 0) {
            $urgentBanner = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px 16px;margin-bottom:16px;color:#991b1b;font-size:13px;">'
                . '<strong>Attention:</strong> You have ' . $vars['overdue_count'] . ' overdue task(s) that require immediate action.'
                . '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0;">
<tr><td align="center">
<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
  <!-- Header -->
  <tr><td style="background:{{header_color}};padding:24px 32px;">
    <h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:600;">GRC Task Assignment Summary</h1>
  </td></tr>
  <!-- Body -->
  <tr><td style="padding:24px 32px;">
    <p style="margin:0 0 16px;color:#374151;font-size:14px;">Hello {{recipient_name}},</p>
    <p style="margin:0 0 16px;color:#374151;font-size:14px;">You have <strong>{{task_count}} task(s)</strong> assigned to you in the GRC Assessment module.</p>
    {$urgentBanner}
    <!-- Task Table -->
    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-bottom:20px;">
      <tr style="background:#f9fafb;">
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Ref</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Task</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Assessment</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Priority</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Due</th>
        <th style="padding:10px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;border-bottom:2px solid #e5e7eb;">Status</th>
      </tr>
      {{task_rows_html}}
    </table>
    <p style="text-align:center;margin:20px 0;">
      <a href="{{assessment_url}}" style="display:inline-block;background:{{button_color}};color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-size:14px;font-weight:600;">View My Tasks</a>
    </p>
  </td></tr>
  <!-- Footer -->
  <tr><td style="background:{{footer_color}};padding:16px 32px;text-align:center;">
    <p style="margin:0;color:#ffffff;font-size:12px;opacity:0.8;">{{system_title}}</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
    }

    /**
     * Build the default plain-text template for GRC task digest emails.
     */
    private function buildGrcTaskDigestText(array $vars): string
    {
        $overdue = ((int)($vars['overdue_count'] ?? 0) > 0)
            ? "\nATTENTION: You have {$vars['overdue_count']} overdue task(s) requiring immediate action.\n"
            : '';

        return <<<TEXT
GRC Task Assignment Summary

Hello {$vars['recipient_name']},

You have {$vars['task_count']} task(s) assigned to you in the GRC Assessment module.
{$overdue}
Your Tasks:
{$vars['task_rows_text']}
View your tasks: {$vars['assessment_url']}
TEXT;
    }

    // =========================================================================
    // BREACH / CYBER ALERT EMAIL
    // =========================================================================

    /**
     * Send a breach/cyber alert notification email.
     */
    public function sendBreachAlert(array $alert, string $recipientEmail): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Email is disabled.'];
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);

            $sourceUrls = json_decode($alert['source_urls'] ?? '[]', true) ?: [];
            $sourceLinksHtml = '';
            $sourceLinksText = '';
            foreach ($sourceUrls as $i => $url) {
                $num = $i + 1;
                $sourceLinksHtml .= '<p style="margin:4px 0;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="color:#166534;text-decoration:underline;">Source ' . $num . ': ' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a></p>';
                $sourceLinksText .= "  Source {$num}: {$url}\n";
            }

            $severityColors = [
                'critical' => ['bg' => '#FEE2E2', 'border' => '#DC2626', 'text' => '#991B1B'],
                'high'     => ['bg' => '#FFEDD5', 'border' => '#EA580C', 'text' => '#9A3412'],
                'medium'   => ['bg' => '#FEF3C7', 'border' => '#CA8A04', 'text' => '#854D0E'],
                'low'      => ['bg' => '#DCFCE7', 'border' => '#16A34A', 'text' => '#166534'],
            ];
            $sc = $severityColors[$alert['severity']] ?? $severityColors['medium'];

            $alertUrl = rtrim(baseUrl('breach-alerts.php'), '/');

            $vars = [
                'severity'             => strtoupper($alert['severity'] ?? 'MEDIUM'),
                'title'                => $alert['title'] ?? '',
                'alert_type'           => ucfirst(str_replace('_', ' ', $alert['alert_type'] ?? 'other')),
                'affected_entity'      => $alert['affected_entity'] ?? '',
                'affected_entity_type' => ucfirst($alert['affected_entity_type'] ?? 'vendor'),
                'vendor_count'         => (string)($alert['vendor_count'] ?? 0),
                'detected_date'        => date('M j, Y g:i A', strtotime($alert['created_at'] ?? 'now')),
                'summary'              => $alert['summary'] ?? '',
                'ai_analysis'          => $alert['ai_analysis'] ?? '',
                'source_links_html'    => $sourceLinksHtml,
                'source_links_text'    => $sourceLinksText,
                'alert_url'            => $alertUrl,
                'severity_color'       => $sc['bg'],
                'severity_border'      => $sc['border'],
                'severity_text_color'  => $sc['text'],
            ];

            $dbTemplate = $this->getTemplateFromDb('breach_alert', 'breach_notification');
            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body    = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                $mail->Subject = $vars['severity'] . ' Alert: ' . $vars['title'];
                $mail->Body    = '<h2>' . htmlspecialchars($vars['title']) . '</h2><p>' . htmlspecialchars($vars['summary']) . '</p><p>Sources:</p>' . $sourceLinksHtml;
                $mail->AltBody = $vars['title'] . "\n\n" . $vars['summary'] . "\n\nSources:\n" . $sourceLinksText;
            }

            $this->sendEmail($mail);
            return ['success' => true];
        } catch (Exception $e) {
            error_log('Breach alert email error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a digest email summarizing multiple breach/cyber alerts.
     * One email per recipient instead of one per alert.
     */
    public function sendBreachAlertDigest(array $alerts, string $recipientEmail): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Email is disabled.'];
        }

        if (empty($alerts)) {
            return ['success' => false, 'error' => 'No alerts to send.'];
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);

            $severityColors = [
                'critical' => ['bg' => '#FEE2E2', 'border' => '#DC2626', 'text' => '#991B1B', 'badge' => '#DC2626'],
                'high'     => ['bg' => '#FFEDD5', 'border' => '#EA580C', 'text' => '#9A3412', 'badge' => '#EA580C'],
                'medium'   => ['bg' => '#FEF3C7', 'border' => '#CA8A04', 'text' => '#854D0E', 'badge' => '#CA8A04'],
                'low'      => ['bg' => '#DCFCE7', 'border' => '#16A34A', 'text' => '#166534', 'badge' => '#16A34A'],
            ];

            $alertUrl = rtrim(baseUrl('breach-alerts.php'), '/');

            // Count severities
            $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            foreach ($alerts as $a) {
                $sev = strtolower($a['severity'] ?? 'medium');
                if (isset($severityCounts[$sev])) $severityCounts[$sev]++;
            }

            // Build severity summary text
            $sevParts = [];
            foreach ($severityCounts as $sev => $cnt) {
                if ($cnt > 0) $sevParts[] = $cnt . ' ' . ucfirst($sev);
            }
            $severitySummary = implode(', ', $sevParts);

            // Determine highest severity for banner color
            $highestSeverity = 'low';
            foreach (['critical', 'high', 'medium', 'low'] as $sev) {
                if ($severityCounts[$sev] > 0) { $highestSeverity = $sev; break; }
            }
            $bannerColor = $severityColors[$highestSeverity];

            // Build individual alert rows for HTML
            $alertRowsHtml = '';
            $alertRowsText = '';
            foreach ($alerts as $i => $alert) {
                $sev = strtolower($alert['severity'] ?? 'medium');
                $sc = $severityColors[$sev] ?? $severityColors['medium'];
                $title = htmlspecialchars($alert['title'] ?? '', ENT_QUOTES, 'UTF-8');
                $entity = htmlspecialchars($alert['affected_entity'] ?? '', ENT_QUOTES, 'UTF-8');
                $type = ucfirst(str_replace('_', ' ', $alert['alert_type'] ?? 'other'));
                $summary = htmlspecialchars($alert['summary'] ?? '', ENT_QUOTES, 'UTF-8');
                $date = date('M j, Y g:i A', strtotime($alert['created_at'] ?? 'now'));

                // Shadow SaaS / impacted-users indicator (Grip breach feed). A
                // Shadow SaaS breach has no onboarded vendor; show the tag plus
                // the count of users potentially impacted, e.g. "Shadow SaaS / 17 users".
                $isShadow = (($alert['affected_entity_type'] ?? '') === 'shadow_saas');
                $impacted = (isset($alert['impacted_user_count']) && $alert['impacted_user_count'] !== null && $alert['impacted_user_count'] !== '')
                    ? (int)$alert['impacted_user_count'] : null;
                $shadowTagHtml = $isShadow
                    ? ' <span style="display:inline-block;background:#fef3c7;color:#92400e;font-size:11px;padding:1px 6px;border-radius:3px;font-weight:600;">Shadow SaaS' . ($impacted !== null ? ' / ' . $impacted . ' users' : '') . '</span>'
                    : ($impacted !== null ? ' &middot; ' . $impacted . ' users impacted' : '');
                $shadowTagText = $isShadow
                    ? ' | Shadow SaaS' . ($impacted !== null ? " / {$impacted} users" : '')
                    : ($impacted !== null ? " | {$impacted} users impacted" : '');

                $alertRowsHtml .= '<tr><td style="padding:12px 15px;border-bottom:1px solid #e5e7eb;">'
                    . '<div style="margin-bottom:4px;">'
                    . '<span style="display:inline-block;background:' . $sc['badge'] . ';color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;font-weight:600;text-transform:uppercase;margin-right:8px;">' . strtoupper($sev) . '</span>'
                    . '<strong style="color:#333;font-size:14px;">' . $title . '</strong>'
                    . '</div>'
                    . '<div style="color:#666;font-size:13px;margin-bottom:4px;">' . $entity . ' &middot; ' . $type . ' &middot; ' . $date . $shadowTagHtml . '</div>'
                    . '<div style="color:#4b5563;font-size:13px;line-height:1.5;">' . $summary . '</div>'
                    . '</td></tr>';

                $num = $i + 1;
                $alertRowsText .= "{$num}. [" . strtoupper($sev) . "] " . ($alert['title'] ?? '') . "\n"
                    . "   Entity: " . ($alert['affected_entity'] ?? '') . " | Type: {$type} | Date: {$date}{$shadowTagText}\n"
                    . "   " . ($alert['summary'] ?? '') . "\n\n";
            }

            $alertCount = count($alerts);
            $vars = [
                'alert_count'       => (string)$alertCount,
                'severity_summary'  => $severitySummary,
                'alert_rows_html'   => $alertRowsHtml,
                'alert_rows_text'   => $alertRowsText,
                'alert_url'         => $alertUrl,
                'scan_date'         => date('M j, Y g:i A'),
                'severity_color'    => $bannerColor['bg'],
                'severity_border'   => $bannerColor['border'],
                'severity_text_color' => $bannerColor['text'],
            ];

            $dbTemplate = $this->getTemplateFromDb('breach_alert', 'breach_digest');
            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body    = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                // Fallback if template not in DB
                $mail->Subject = "Breach Alert Digest: {$alertCount} New Alert(s)";
                $mail->Body    = '<h2>Breach Alert Digest</h2><p>' . $alertCount . ' new alert(s) detected: ' . htmlspecialchars($severitySummary) . '</p><table>' . $alertRowsHtml . '</table><p><a href="' . htmlspecialchars($alertUrl) . '">View All Alerts</a></p>';
                $mail->AltBody = "BREACH ALERT DIGEST\n{$alertCount} new alert(s): {$severitySummary}\n\n{$alertRowsText}\nView alerts: {$alertUrl}";
            }

            $this->sendEmail($mail);
            return ['success' => true];
        } catch (Exception $e) {
            error_log('Breach alert digest email error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a digest email summarizing vendors currently in review (in_review +
     * ai_review statuses) along with their latest procurement update.
     * One email per recipient. Modeled on sendBreachAlertDigest().
     *
     * Each $vendors element = ['id','vendor_name','status','update_text','update_date'].
     */
    public function sendProcurementDigest(array $vendors, string $recipientEmail): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Email is disabled.'];
        }

        if (empty($vendors)) {
            return ['success' => false, 'error' => 'No vendor updates to send.'];
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($recipientEmail);

            $appUrl = rtrim(baseUrl('procurement-cyber-status.php'), '/');

            // Status badge styling per onboarding status.
            $statusBadges = [
                'in_review' => ['label' => 'In Review', 'bg' => '#DBEAFE', 'text' => '#1E40AF'],
                'ai_review' => ['label' => 'AI Review', 'bg' => '#EDE9FE', 'text' => '#5B21B6'],
            ];

            // Build individual update rows for HTML and text.
            $updateRowsHtml = '';
            $updateRowsText = '';
            foreach ($vendors as $i => $vendor) {
                $status = $vendor['status'] ?? '';
                $badge = $statusBadges[$status] ?? ['label' => ucwords(str_replace('_', ' ', $status)), 'bg' => '#F3F4F6', 'text' => '#374151'];

                $vendorName = htmlspecialchars($vendor['vendor_name'] ?? '', ENT_QUOTES, 'UTF-8');
                $badgeLabel = htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8');
                $updateText = htmlspecialchars($vendor['update_text'] ?? '', ENT_QUOTES, 'UTF-8');
                $updateDate = $vendor['update_date'] ?? '';
                $dateDisplay = $updateDate ? date('M j, Y g:i A', strtotime($updateDate)) : '';
                $dateDisplayHtml = htmlspecialchars($dateDisplay, ENT_QUOTES, 'UTF-8');

                $updateRowsHtml .= '<tr>'
                    . '<td style="padding:12px 15px;border-bottom:1px solid #e5e7eb;vertical-align:top;">'
                    . '<div style="margin-bottom:4px;">'
                    . '<strong style="color:#333;font-size:14px;">' . $vendorName . '</strong>'
                    . '<span style="display:inline-block;background:' . $badge['bg'] . ';color:' . $badge['text'] . ';font-size:11px;padding:2px 8px;border-radius:3px;font-weight:600;margin-left:8px;">' . $badgeLabel . '</span>'
                    . '</div>'
                    . '<div style="color:#666;font-size:13px;margin-bottom:4px;">' . $dateDisplayHtml . '</div>'
                    . '<div style="color:#4b5563;font-size:13px;line-height:1.5;">' . nl2br($updateText) . '</div>'
                    . '</td></tr>';

                $num = $i + 1;
                $updateRowsText .= "{$num}. " . ($vendor['vendor_name'] ?? '') . " [" . $badge['label'] . "]\n"
                    . "   Updated: {$dateDisplay}\n"
                    . "   " . ($vendor['update_text'] ?? '') . "\n\n";
            }

            $vendorCount = count($vendors);
            $vars = [
                'vendor_count'     => (string)$vendorCount,
                'digest_date'      => date('Y-m-d'),
                'update_rows_html' => $updateRowsHtml,
                'update_rows_text' => $updateRowsText,
                'app_url'          => $appUrl,
            ];

            $dbTemplate = $this->getTemplateFromDb('procurement_digest', 'procurement_update_digest');
            if ($dbTemplate) {
                $mail->Subject = $this->renderWithBranding($dbTemplate['email_subject'], $vars);
                $mail->Body    = $this->renderWithBranding($dbTemplate['email_body_html'], $vars);
                $mail->AltBody = $this->renderWithBranding($dbTemplate['email_body_text'], $vars);
            } else {
                // Fallback if template not in DB
                $mail->Subject = "Procurement Update Digest: {$vendorCount} Vendor(s) in Review";
                $mail->Body    = '<h2>Procurement Update Digest</h2><p>' . $vendorCount . ' vendor(s) in review.</p><table>' . $updateRowsHtml . '</table><p><a href="' . htmlspecialchars($appUrl) . '">View Procurement Cyber Status</a></p>';
                $mail->AltBody = "PROCUREMENT UPDATE DIGEST\n{$vendorCount} vendor(s) in review.\n\n{$updateRowsText}\nView: {$appUrl}";
            }

            $this->sendEmail($mail);
            return ['success' => true];
        } catch (Exception $e) {
            error_log('Procurement digest email error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
