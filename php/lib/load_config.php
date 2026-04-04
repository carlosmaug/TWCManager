<?php

$configCandidates = [];

$envConfig = getenv('TWC_WEB_CONFIG_FILE');
if(is_string($envConfig) && trim($envConfig) !== '') {
    $configCandidates[] = trim($envConfig);
}

$configCandidates[] = __DIR__ . '/config.php';
$configCandidates[] = __DIR__ . '/config.php.example';

$loadedConfigFile = '';
foreach($configCandidates as $candidate) {
    if(is_string($candidate) && $candidate !== '' && is_file($candidate) && is_readable($candidate)) {
        require $candidate;
        $loadedConfigFile = $candidate;
        break;
    }
}

if($loadedConfigFile === '') {
    throw new RuntimeException('No readable PHP web configuration file was found.');
}
