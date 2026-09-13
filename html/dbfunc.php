<?php
// dbfunc.php

function getConnection()
{
    $configPath = getenv('TARASEC_DB_CONFIG');
    if ($configPath === false || $configPath === '') {
        $configPath = '/etc/tarasec/db.php';
    }

    $config = [];
    if (is_readable($configPath)) {
        $loaded = require $configPath;
        if (!is_array($loaded)) {
            throw new RuntimeException("TaraSec DB config must return an array: " . $configPath);
        }
        $config = $loaded;
    }

    $servername = getenv('TARASEC_DB_HOST');
    if ($servername === false || $servername === '') {
        $servername = $config['host'] ?? 'localhost';
    }

    $username = getenv('TARASEC_DB_USER');
    if ($username === false || $username === '') {
        $username = $config['user'] ?? '';
    }

    $password = getenv('TARASEC_DB_PASSWORD');
    if ($password === false) {
        $password = $config['password'] ?? '';
    }

    $dbname = getenv('TARASEC_DB_NAME');
    if ($dbname === false || $dbname === '') {
        $dbname = $config['name'] ?? 'taransvar';
    }

    if ($username === '' || $password === '') {
        throw new RuntimeException(
            "TaraSec database credentials are not configured. " .
            "Set TARASEC_DB_USER/TARASEC_DB_PASSWORD or install " . $configPath
        );
    }

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

function addWarningRecord($szWarning)
{
    # NOTE! This function also exists in taralink (C) and func.pm(perl)
    $conn = getConnection();

    # First check if recently inserted.
    $szSQL = "select warningId from warning where handled is null and lastWarned >= DATE_SUB(NOW(), INTERVAL 1 DAY) and warning = ?";
    $stmt = $conn->prepare($szSQL);
    $stmt->bind_param("s", $szWarning);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $szSQL = "update warning set lastWarned = now(), count = count + 1 where warningId = ?";
        $stmt = $conn->prepare($szSQL);
        $stmt->bind_param("i", $row["warningId"]);
        $stmt->execute();
    } else {
        $szSQL = "insert into warning (warning) values (?)";
        $stmt = $conn->prepare($szSQL);
        $stmt->bind_param("s", $szWarning);
        $stmt->execute();
    }
}
?>
