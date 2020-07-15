#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

set_error_handler('catchError');

// Program takes 2 parameters
if ($argc < 3) {
    printUsage($argv);
}

// Parameters to script
$base_path = $argv[1];
$trove_key = $argv[2];
$skipGames = [];
$skipOS = [];
if(!empty($argv[3])) $skipGames = explode(',', $argv[3]);
if(!empty($argv[4])) $skipOS = explode(',', $argv[4]);

// Ensure the provided path is valid
if (!is_dir($base_path)) {
    echo "ERROR: " . $base_path . " is not a valid directory or does not exist! \n";
    echo "       Create the directory and try again.\n\n";

    printUsage($argv);
}

// Trove HTTP Client
$client = getGuzzleHttpClient($trove_key);

// Get list of all trove games from the API
$trove_data = getTroveData($client);

$count = 0;

foreach ($trove_data as $game) {
    
    if(in_array($game->{'human-name'}, $skipGames)){
        echo "Skipping $display \n";
        continue;
    }

    $display   = $game->{'human-name'};
    $downloads = $game->downloads;

    echo "Processing $display ...\n";

    foreach ($downloads as $os => $dl) {

        if(in_array($os, $skipOS)){
            echo "   Skipping OS $os for $display \n";
            continue;
        }

        $file = $dl->url->web;
        $game = $dl->machine_name;
        $md5  = $dl->md5;

        $dl_path = $base_path . DIRECTORY_SEPARATOR . $os . DIRECTORY_SEPARATOR . $file;

        // Ensure full path exists on disk
        if (!is_dir(dirname($dl_path))) {
            mkdir(dirname($dl_path), 0777, true);
        }

        echo "   Checking $os ($file)\n";

        // File already exists- Check md5sum
        if (file_exists($dl_path)) {
            echo "    $file already exists! Checking md5sum ";

            // Cache the md5sum in a file alongside the download
            $cache_path = dirname($dl_path) . DIRECTORY_SEPARATOR
                         . "." . basename($dl_path) . ".md5sum";

            $file_date  = filemtime($dl_path);
            $cache_date = file_exists($cache_path) ? filemtime($cache_path) : 0;

            // If cache is newer than file, use it
            if ($cache_date > $file_date) {
                echo "[Using Cache] ...\n";
                $existing_md5 = file_get_contents($cache_path);

            } else {
                echo "[Creating Cache] ...\n";
                $existing_md5 = md5_file($dl_path);

                // Cache md5sum to file
                file_put_contents($cache_path, $existing_md5);
            }

            if ($existing_md5 === $md5) {
                echo "        Matching md5sum $md5 at $dl_path \n";
                continue;
            } else {
                echo "        Wrong md5sum ($md5 vs $existing_md5) at $dl_path \n\n";
                echo "Delete or move the existing file, then run this script again!\n\n";
                exit(1);
            }
        } else {
            echo "    $file does not exist\n";
        }

        echo "    Downloading to $dl_path... \n";

        $url = getDownloadLink($client, $game, $file);

        // Download file
        $client->request(
            'GET',
            $url,
            [
                'sink'     => $dl_path,
                'progress' => function(
                    $downloadTotal,
                    $downloadedBytes,
                    $uploadTotal,
                    $uploadedBytes
                ) {
                    if ($downloadTotal === 0) {
                        $pct = 0;
                    } else {
                        $pct = number_format(($downloadedBytes / $downloadTotal) * 100, 2);
                    }

                    echo "\r    Progress: " . $pct . '%';
                }
            ]
        );

        echo "\n";

        $count++;
    }
}

echo "Downloaded $count games\n";

/**
 * Prints usage of script
 */
function printUsage($argv) {
    echo "Usage: $argv[0] DOWNLOAD_PATH HUMBLE_API_KEY \n\n";
    echo "    DOWNLOAD_PATH  - Base path to download files\n";
    echo "    HUMBLE_API_KEY - Session from your browser's cookies\n\n";
    echo "    SKIP_GAMES - (optional) comma separated list of games to skip\n\n";
    echo "    SKIP_OS - (optional) comma separated list of OSs to skip\n\n";

    exit(1);
}


/**
 * Creates a Guzzle HTTP Client for interacting with the HB API
 */
function getGuzzleHttpClient($session_key)
{
    $cookies = [
        '_simpleauth_sess' => '"' . $session_key . '"',
    ];

    $cookie_jar = \GuzzleHttp\Cookie\CookieJar::fromArray(
        $cookies, 'humblebundle.com'
    );

    $client = new GuzzleHttp\Client([
        'base_uri' => 'https://www.humblebundle.com/api/v1/',
        'cookies'  => $cookie_jar,
    ]);

    return $client;
}


/**
 * Gets data for Trove from the HB API
 */
function getTroveData($client)
{
    $page_num   = 0;
    $trove_data = [];

    while (true) {
        echo "Fetching game list (page: $page_num)\n";

        // Download each page of trove results
        $page_data = json_decode(
            $client->request('GET', 'trove/chunk?index=' . $page_num)->getBody()
        );

        // If results are empty, return data
        if (empty($page_data)) {
            return $trove_data;
        }

        // Combine results
        $trove_data = array_merge($trove_data, $page_data);

        $page_num++;

        // Prevent possible endless loop if something changes with the API
        if ($page_num > 10) {
            echo "We fetched over 10 pages- Something may be wrong- Exiting\n";
            exit(1);
        }
    }
}


/**
 * Returns download URL for given user, game and file (win, mac, linux, etc)
 */
function getDownloadLink($client, $game, $file) {

    $result = json_decode(
        $client->request('POST', 'user/download/sign',
            [
                'form_params' => [
                    'machine_name' => $game,
                    'filename'     => $file,
                ],
            ]
        )->getBody()
    );

    return $result->signed_url;
}


/**
 * Handle any notices/warnings/errors
 */
function catchError($errNo, $errStr, $errFile, $errLine) {
    echo "$errStr in $errFile on line $errLine\n";

    exit(1);
}
