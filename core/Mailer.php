<?php
/**
 * Simple SMTP mailer using PHPMailer conventions.
 * Requires the PHPMailer library — installed via Composer:
 *   composer require phpmailer/phpmailer
 * Or place PHPMailer in /vendor/phpmailer/ manually.
 */
class Mailer {

    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array  $extraHeaders = []
    ): bool {
        // Dynamic require to avoid fatal if Composer isn't run yet
        $autoload = BASE_PATH . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        } else {
            // Fallback: bare PHPMailer
            require_once BASE_PATH . '/vendor/phpmailer/phpmailer/src/Exception.php';
            require_once BASE_PATH . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
            require_once BASE_PATH . '/vendor/phpmailer/phpmailer/src/SMTP.php';
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Database::setting('smtp_host', 'localhost');
            $mail->Port       = (int) Database::setting('smtp_port', '587');
            $mail->SMTPAuth   = !empty(Database::setting('smtp_user'));
            $mail->Username   = Database::setting('smtp_user');
            $mail->Password   = Database::setting('smtp_pass');
            $enc              = Database::setting('smtp_encryption', 'tls');
            $mail->SMTPSecure = $enc === 'ssl'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            $mail->setFrom(
                Database::setting('newsletter_from_email', 'newsletter@your-site.org'),
                Database::setting('newsletter_from_name',  'Your Parish')
            );
            $mail->addAddress($toEmail, $toName);

            foreach ($extraHeaders as $name => $value) {
                $mail->addCustomHeader($name, $value);
            }

            $mail->Subject  = $subject;
            $mail->isHTML(true);
            $mail->Body     = $htmlBody;
            $mail->AltBody  = $textBody ?: strip_tags($htmlBody);
            $mail->CharSet  = 'UTF-8';

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }
}
