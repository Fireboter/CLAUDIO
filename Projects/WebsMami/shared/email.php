<?php
require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function create_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    return $mail;
}

function send_order_confirmation(
    string $to,
    string $customerName,
    string $orderNumber,
    float $totalAmount,
    array $items,
    string $shippingMethod
): bool {
    try {
        $mail = create_mailer();
        $mail->addAddress($to, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'Tu pedido #' . $orderNumber . ' ha sido confirmado';

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= '<tr>
                <td>' . htmlspecialchars($item['productName']) . '</td>
                <td>' . (int)$item['quantity'] . '</td>
                <td>' . number_format($item['price'], 2) . ' €</td>
            </tr>';
        }

        $mail->Body = '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:0 auto">
            <h2>Pedido confirmado</h2>
            <p>Hola ' . htmlspecialchars($customerName) . ',</p>
            <p>Tu pedido <strong>#' . htmlspecialchars($orderNumber) . '</strong> ha sido recibido y está siendo procesado.</p>
            <table width="100%" border="1" cellpadding="8" style="border-collapse:collapse">
                <tr style="background:#f5f5f5"><th>Producto</th><th>Cantidad</th><th>Precio</th></tr>
                ' . $itemsHtml . '
            </table>
            <p><strong>Total: ' . number_format($totalAmount, 2) . ' €</strong></p>
            <p>Método de envío: ' . htmlspecialchars($shippingMethod) . '</p>
            <p>Gracias por tu compra.</p>
        </body></html>';
        $mail->AltBody = 'Pedido #' . $orderNumber . ' confirmado. Total: ' . number_format($totalAmount, 2) . ' €';

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email error: ' . $e->getMessage());
        return false;
    }
}

function send_gift_card_email(
    string $to,
    string $customerName,
    string $orderNumber,
    float $amount,
    string $code,
    string $expiryDate
): bool {
    try {
        $mail = create_mailer();
        $mail->addAddress($to, $customerName);
        $mail->isHTML(true);
        $mail->Subject = 'Tu tarjeta regalo - ' . number_format($amount, 2) . ' €';
        $mail->Body = '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:0 auto">
            <h2>Tu tarjeta regalo</h2>
            <p>Hola ' . htmlspecialchars($customerName) . ',</p>
            <p>Gracias por tu pedido <strong>#' . htmlspecialchars($orderNumber) . '</strong>.</p>
            <p>Aquí tienes tu código de tarjeta regalo:</p>
            <div style="background:#f5f5f5;padding:20px;text-align:center;font-size:24px;font-family:monospace;letter-spacing:4px;border-radius:8px">
                <strong>' . htmlspecialchars($code) . '</strong>
            </div>
            <p>Valor: <strong>' . number_format($amount, 2) . ' €</strong></p>
            <p>Válido hasta: <strong>' . htmlspecialchars($expiryDate) . '</strong></p>
            <p>Introduce este código en el checkout para aplicar el descuento.</p>
        </body></html>';
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email error: ' . $e->getMessage());
        return false;
    }
}

function send_contact_notification(
    string $name,
    string $email,
    string $message,
    string $formType
): bool {
    try {
        $mail = create_mailer();
        $mail->addAddress(ADMIN_EMAIL);
        $mail->Subject = '[Contacto] Nuevo mensaje - ' . $formType;
        $mail->Body = "Nombre: $name\nEmail: $email\nTipo: $formType\n\nMensaje:\n$message";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email error: ' . $e->getMessage());
        return false;
    }
}

function send_contact_reply(
    string $to,
    string $recipientName,
    string $subject,
    string $message
): bool {
    try {
        $mail = create_mailer();
        $mail->addAddress($to, $recipientName);
        $mail->Subject = $subject;
        $mail->Body = nl2br(htmlspecialchars($message));
        $mail->AltBody = $message;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Email error: ' . $e->getMessage());
        return false;
    }
}

function send_newsletter(string $to, string $subject, string $htmlContent): bool {
    try {
        $mail = create_mailer();
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Newsletter email error: ' . $e->getMessage());
        return false;
    }
}
