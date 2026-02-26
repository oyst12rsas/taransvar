<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gatekeeper Cyber Security Solution</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-red: #d34c3c;
            --light-red: #fcedec;
            --text-dark: #2c2c2c;
            --text-gray: #6b6b6b;
            --bg-light: #ffffff;
            --bg-offwhite: #fafafa;
            --green-accent: #34a853;
            --blue-accent: #4a90e2;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .reveal-banner {
            background: linear-gradient(135deg, #e53935 0%, #b71c1c 100%);
            color: white;
            padding: 4rem 2rem;
            text-align: center;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            z-index: 100;
        }

        .reveal-banner h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .reveal-banner p {
            font-size: 1.25rem;
            max-width: 800px;
            margin: 0 auto 2rem;
            opacity: 0.95;
            font-weight: 400;
        }

        .btn-return {
            display: inline-block;
            background: white;
            color: #b71c1c;
            padding: 1rem 2.5rem;
            border-radius: 50px;
            font-weight: 700;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .btn-return:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .brochure-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 4rem 2rem;
            position: relative;
        }

        .bg-circle {
            position: fixed;
            border-radius: 50%;
            z-index: -1;
            opacity: 0.1;
        }

        .bg-circle.r-1 {
            width: 800px;
            height: 800px;
            background: var(--primary-red);
            top: -200px;
            right: -300px;
        }

        .bg-circle.r-2 {
            width: 500px;
            height: 500px;
            background: var(--primary-red);
            bottom: 100px;
            left: -200px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 6rem;
        }

        .page-header h2 {
            font-size: 4rem;
            color: var(--primary-red);
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 1rem;
        }

        .page-header p {
            font-size: 1.5rem;
            color: var(--text-gray);
            max-width: 600px;
            margin: 0 auto;
        }

        .section-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            margin-bottom: 8rem;
        }

        .section-grid.reverse {
            direction: rtl;
        }

        .section-grid.reverse>* {
            direction: ltr;
        }

        .text-content h3 {
            display: inline-block;
            background: var(--primary-red);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 15px rgba(211, 76, 60, 0.3);
        }

        .text-content p {
            font-size: 1.1rem;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            line-height: 1.8;
            font-weight: 400;
        }

        .visual-content {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .circle-image-wrapper {
            width: 400px;
            height: 400px;
            border-radius: 50%;
            border: 8px solid var(--primary-red);
            padding: 10px;
            position: relative;
            background: white;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
        }

        .circle-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .contact-widget {
            background: white;
            padding: 2rem;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            position: absolute;
            bottom: -30px;
            right: 0;
            border-left: 5px solid var(--primary-red);
        }

        .contact-widget h4 {
            color: var(--primary-red);
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }

        .contact-widget p {
            font-size: 0.95rem;
            color: var(--text-gray);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .diagram-box {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.06);
            width: 100%;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 2rem;
            align-items: center;
        }

        .diagram-box::before {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            background: var(--light-red);
            border-radius: 50%;
            top: -50px;
            right: -50px;
            z-index: 0;
        }

        .diagram-node {
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            z-index: 1;
            text-align: center;
            width: 250px;
            border: 2px solid transparent;
        }

        .node-hacker {
            background: #ffebee;
            color: #d32f2f;
            border-color: #ffcdd2;
        }

        .node-pc {
            background: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
        }

        .node-isp {
            background: #e8f5e9;
            color: #2e7d32;
            border-color: #c8e6c9;
        }

        .diagram-arrow {
            color: #999;
            font-size: 1.5rem;
            font-weight: 800;
            z-index: 1;
        }

        .footer {
            background: var(--text-dark);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
        }

        .footer p {
            margin-bottom: 1rem;
            opacity: 0.8;
        }

        .footer a {
            color: var(--primary-red);
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 900px) {

            .section-grid,
            .section-grid.reverse {
                grid-template-columns: 1fr;
                gap: 3rem;
            }

            .visual-content {
                flex-direction: column;
                width: 100%;
            }

            .reveal-banner h1 {
                font-size: 2.2rem;
            }

            .page-header h2 {
                font-size: 2.8rem;
            }

            .circle-image-wrapper {
                width: 100%;
                max-width: 300px;
                height: auto;
                aspect-ratio: 1;
                /* Maintain circular shape */
                margin: 0 auto;
            }

            .contact-widget {
                position: relative;
                bottom: 0;
                right: 0;
                margin-top: 2rem;
                width: 100%;
                max-width: 350px;
                border-left: none;
                border-top: 5px solid var(--primary-red);
            }

            .diagram-box {
                padding: 1.5rem;
            }

            .diagram-node {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <div class="reveal-banner">
        <h1>THIS IS NOT A REAL BANK</h1>
        <p>You have just experienced a simulated banking environment. The "Aequitas Global" website you just interacted
            with is 100% fictional, created to demonstrate exactly how easily users can be deceived online.</p>
        <p>This simulation is part of the <strong>Gatekeeper Cyber Security Solution</strong>.</p>
        <a href="index.php" class="btn-return">Return to the Fake Bank</a>
    </div>

    <div class="bg-circle r-1"></div>
    <div class="bg-circle r-2"></div>

    <div class="brochure-container">

        <header class="page-header">
            <h2>Tagging status</h2>
            <p
                style="color: var(--primary-red); font-weight: 600; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 2px;">
                Taransvar Cyber Security Solution</p>
            <p>This page is not finalized. Please be patient</p>
            <p>
<?php
    include "guestinfo.php";
?>            </p>
        </header>

    <footer class="footer">
        <p>&copy; 2026 Taransvar CBO. All Rights Reserved.</p>
        <p><a href="index.php">Return to Sample Bank Homepage</a></p>
    </footer>

</body>

</html>