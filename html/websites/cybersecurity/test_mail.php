<?php
$to = "austinazenga@gmail.com"; // email here
$subject = "Test Email from PHP";
$message = "Hello! This is a test email sent from PHP.";
$headers = "From: oystein@taransvar.no\r\n" .
           "Reply-To: sender@gmail.com\r\n" .
           "Content-Type: text/plain; charset=UTF-8";

if (mail($to, $subject, $message, $headers)) {
    echo "Email sent successfully.";
} else {
    echo "Failed to send email.";
}
?>
