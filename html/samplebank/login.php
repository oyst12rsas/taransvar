<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aequitas Global | Your Trusted Banking Partner</title>
    <link rel="stylesheet" href="style.css">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&family=Playfair+Display:wght@700&display=swap"
        rel="stylesheet">
</head>

<body>

    <nav class="navbar">
        <div class="logo">
            <div class="logo-icon"></div>
            <span>Aequitas Global</span>
        </div>
        <ul class="nav-links">
            <li><a href="#">Personal</a></li>
            <li><a href="#">Business</a></li>
            <li><a href="#">Corporate</a></li>
            <li><a href="#">Wealth</a></li>
        </ul>
        <div class="nav-actions">
            <a href="login.php" class="login-btn">Log In</a>
            <a href="cyber-solution.html" class="cta-btn">Open Account</a>
        </div>
    </nav>

    <section class="director-section">
        <div class="director-container">
            <div class="director-text">
                <h2>NOTE! This is not a real login</h2>
                <h4 class="director-name">This is what we call a honeypot, designed to lure hackers.. So if you try to log in below, you'll not get anywhere, but you'll be tagged as a hacker to nodes in our network.</h4>
                <p>"It's not a dangerous as it sounds. This is for testing. We'll include link here to clear the tag.</p>
<?php
if (isset($_GET["sub"]))
{
    print "<h1>You tried to login...</h1><p>Meaning you're a hacker. You should have been tagged but it's not yet finalized.</p>";
    print '<a href="status.php" class="btn btn-primary btn-large">See you status</a>';

}
else
{
    ?>

                <form action="login.php" type="get">
                <table>
<tr><td>User name</td><td><input></td></tr>
<tr><td>Password</td><td><input type="password"></td></tr>
<tr><td colspan="2"><input type="submit" name="sub"></td></tr>
</table>
</form>
    <?php
}

?>
</div>
    </section>

    <section class="cta-section">
        <h2>Ready to join the illusion?</h2>
        <p>Start your fake banking journey today and experience the peace of mind that comes with absolutely zero
            financial commitment.</p>
        <br>
        <a href="cyber-solution.html" class="btn btn-primary btn-large">Open Account</a>
    </section>

    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>Aequitas Global</h4>
                <p>123 Fictional Boulevard<br>Nonexistent City, NX 00000</p>
            </div>
            <div class="footer-col">
                <h4>Legal</h4>
                <ul>
                    <li><a href="#">Terms of Illusion</a></li>
                    <li><a href="#">Privacy Mirage</a></li>
                    <li><a href="#">Fake Disclosures</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Support</h4>
                <ul>
                    <li><a href="#">Contact No One</a></li>
                    <li><a href="#">Report a Fake Issue</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 Aequitas Global Bank. A 100% fictional entity created for demonstration.</p>
        </div>
    </footer>

</body>

</html>