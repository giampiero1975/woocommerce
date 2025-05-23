<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function sendNotificationEmail($tx) {
    $mail = new PHPMailer(true);
    
    try {
        // Configurazione SMTP
        $mail->isSMTP();
        $mail->Host = 'mail.metmi.it';
        $mail->SMTPAuth = true;
        $mail->Username = 'giampiero.digregorio@metmi.it';
        $mail->Password = '20ero$rio14';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        
        // Destinatario e mittente
        $mail->setFrom('giampiero.digregorio@metmi.it', 'Notifiche Pagamenti Express');
        $mail->addCC('giampiero.digregorio@metmi.it');
        $mail->addAddress('andrea.macario@metmi.it');
        $mail->addAddress('silvia.quaroni@metmi.it');
        $mail->addAddress('marina.grande@metba.es');
        
        // Contenuto email
        $mail->isHTML(true);
        $mail->Subject = "Nuovo Pagamento Ricevuto - " . $tx['transaction_id'];
        $mail->Body = "
            <h2>Nuova Transazione Registrata</h2>
            <p><strong>ID Transazione:</strong> {$tx['transaction_id']}</p>
            <p><strong>Data:</strong> {$tx['transaction_date']}</p>
            <p><strong>Nome Pagante:</strong> {$tx['paying_name']}</p>
            <p><strong>Importo:</strong> €{$tx['amount']}</p>
            <p><strong>Articolo:</strong> {$tx['item_purchased']}</p>
        ";
        
        $mail->send();
        echo "Email inviata correttamente per ID: {$tx['transaction_id']}<br>";
    } catch (Exception $e) {
        echo "Errore invio email: {$mail->ErrorInfo}<br>";
    }
}