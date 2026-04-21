<?php

// Only allow running from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// Load nodes from environment variable
$nodesEnv = getenv('STORAGE_NODES');
if ($nodesEnv === false || empty($nodesEnv)) {
    echo "Error: STORAGE_NODES environment variable is not set", PHP_EOL;
    exit(1);
}

// Parse comma-separated nodes
$nodes = array_map('trim', explode(',', $nodesEnv));
$nodes = array_filter($nodes); // Remove empty values

if (empty($nodes)) {
    echo "Error: No valid nodes found in STORAGE_NODES environment variable", PHP_EOL;
    exit(1);
}

// RRDtool database file (mounted volume)
$rrddb = '/data/db.rrd';

// Create RRDDb if not exists
if (!file_exists($rrddb)) {
    $command = "rrdtool create $rrddb --step 900 "
        . "DS:diskAlloc:GAUGE:1800:0:U "
        . "DS:diskUsed:GAUGE:1800:0:U "
        . "DS:monthExpect:GAUGE:1800:0:U "
        . "RRA:LAST:0.5:15m:1d "
        . "RRA:AVERAGE:0.5:1h:1w "
        . "RRA:AVERAGE:0.5:1d:1y "
        . "RRA:MAX:0.5:1h:1w "
        . "RRA:MAX:0.5:1d:1y";
    exec($command, $output, $return_var);
    if ($return_var !== 0) {
        echo "Failed to create RRD database: ", implode("\n", $output), PHP_EOL;
        exit(1);
    }
}

$diskSpaceAllocated = 0;
$diskSpaceUsed = 0;
$currentMonthExpectations = 0;

// Iterate node endpoints
foreach ($nodes as $node) {
    $url = "http://$node/api/sno/";
    $data = curl($url);
    if ($data !== false) {
        $diskSpaceAllocated += $data['diskSpace']['allocated'] ?? $data['diskSpace']['available'];
        $diskSpaceUsed += $data['diskSpace']['used'];
    } else {
        echo "Failed to retrieve disk space data from node: $node", PHP_EOL;
    }

    $url = "http://$node/api/sno/estimated-payout";
    $data = curl($url);
    if ($data !== false) {
        $currentMonthExpectations += $data['currentMonthExpectations'];
    } else {
        echo "Failed to retrieve estimated payout data from node: $node", PHP_EOL;
    }
}

// Formatting
$diskSpaceAllocated = round($diskSpaceAllocated / pow(1000, 4), 3);
$diskSpaceUsed = round($diskSpaceUsed / pow(1000, 4), 3);
$currentMonthExpectations = round($currentMonthExpectations / 100, 2);

// Output
echo "diskSpaceAllocated: $diskSpaceAllocated TB", PHP_EOL;
echo "diskSpaceUsed: $diskSpaceUsed TB", PHP_EOL;
echo "currentMonthExpectations: $$currentMonthExpectations", PHP_EOL;

// Send data to Numeric Values Graphing (if enabled)
$nvgUrl = getenv("NVG_URL");
$nvgSecret = getenv("NVG_SECRET");
if ($nvgUrl && $nvgSecret) {
    $nvgData = [
        'secret' => $nvgSecret,
        'disk-allocated' => $diskSpaceAllocated,
        'disk-used' => $diskSpaceUsed,
        'expected-payout' => $currentMonthExpectations
    ];
    $ch = curl_init($nvgUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $nvgData);
    $nvgOutput = curl_exec($ch);
    echo "Sent data to NVG: ", $nvgOutput;
}

// Update RRDDb
$updateCommand = "rrdtool update $rrddb N:$diskSpaceAllocated:$diskSpaceUsed:$currentMonthExpectations";
exec($updateCommand, $updateOutput, $updateReturnVar);
if ($updateReturnVar !== 0) {
    echo "Failed to update RRD database: ", implode("\n", $updateOutput), PHP_EOL;
}

// Generate 2 RRDtool graphs
for ($i = 1; $i <= 2; $i++) {
    $graphFile = "graph$i.png";
    $graphHistory = getenv("GRAPH" . $i . "_HISTORY");
    if ($graphHistory === false || empty($graphHistory)) {
        $graphHistory = '5weeks'; // Default to 5 weeks
    }
    $graphWidth = getenv("GRAPH" . $i . "_WIDTH");
    if ($graphWidth === false || empty($graphWidth)) {
        $graphWidth = '1200'; // Default width
    }
    $graphHeight = getenv("GRAPH" . $i . "_HEIGHT");
    if ($graphHeight === false || empty($graphHeight)) {
        $graphHeight = '600'; // Default height
    }
    $graphCommand = "rrdtool graph $graphFile "
        . "--width $graphWidth --height $graphHeight "
        . "--start -$graphHistory --end now "
        . "--color BACK#000000 "
        . "--color CANVAS#000000 "
        . "--color FONT#FFFFFF "
        . "--color GRID#333333 "
        . "--color MGRID#666666 "
        . "--title='Disk Space & Expected Payout ($graphHistory)' "
        . "--vertical-label='TB / USD' "
        . "DEF:alloc=$rrddb:diskAlloc:MAX "
        . "DEF:used=$rrddb:diskUsed:AVERAGE "
        . "DEF:expect=$rrddb:monthExpect:AVERAGE "
        . "LINE2:alloc#00FF00:'Disk Allocated (TB)' "
        . "LINE2:used#0000FF:'Disk Used (TB)' "
        . "LINE2:expect#FF9900:'Month Payout (\$)' "
        . "COMMENT:'\\n' "
        . "GPRINT:alloc:LAST:'Allocated Now\: %6.2lf TB' "
        . "GPRINT:used:LAST:'Used Now\: %6.2lf TB' "
        . "GPRINT:expect:LAST:'Payout Now\: \$%6.2lf\\n'";
    exec($graphCommand, $graphOutput, $graphReturnVar);
    if ($graphReturnVar !== 0) {
        echo "Failed to generate RRD graph: ", implode("\n", $graphOutput), PHP_EOL;
    } else {
        echo "Graph generated: $graphFile", PHP_EOL;
    }
}

function curl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response !== false && $httpCode === 200) {
        return json_decode($response, true);
    } else {
        echo "Error fetching $url", PHP_EOL;
        return false;
    }
}
