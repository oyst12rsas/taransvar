<?php
if (isset($_POST['formType']) && $_POST['formType'] === 'join') {


    $name    = htmlspecialchars($_POST['name'] ?? '');
    $email   = htmlspecialchars($_POST['email'] ?? '');
    $role    = htmlspecialchars($_POST['role'] ?? '');
    $contact = htmlspecialchars($_POST['contact'] ?? '');

 
    if (!empty($email) && !empty($contact)) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $receiver      = "austinazenga@gmail.com";  
            $email_subject = "New Join Request from $name";
            $body          = "Name: $name\nEmail: $email\nRole: $role\nContact: $contact";
            $sender        = "From: oystein@taransvar.no"; 

            if (mail($receiver, $email_subject, $body, $sender)) {
                echo "Your contacts have been received. We will get in touch with you soon.";
            } else {
                echo "Sorry, failed to send your join request!";
            }
        } else {
            echo "Enter a valid email address!";
        }
    } else {
        echo "Email and contact fields are required!";
    }
} else {
   
    $name    = htmlspecialchars($_POST['name'] ?? '');
    $email   = htmlspecialchars($_POST['email'] ?? '');
    $subject = htmlspecialchars($_POST['subject'] ?? '');
    $message = htmlspecialchars($_POST['message'] ?? '');

    if (!empty($email) && !empty($message)) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $receiver      = "austinazenga@gmail.com";  
            $email_subject = "From: $name <$email> | Subject: $subject";
            $body          = "Name: $name\nEmail: $email\n\nMessage:\n$message\n\nRegards,\n$name";
            $sender        = "From: oystein@taransvar.no";

            if (mail($receiver, $email_subject, $body, $sender)) {
                echo "Your message has been sent";
            } else {
                echo "Sorry, failed to send your message!";
            }
        } else {
            echo "Enter a valid email address!";
        }
    } else {
        echo "Email and message field is required!";
    }
}
?>
