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
    $command = "rrdtool create $rrddb --step 300 "
        . "DS:diskAvail:GAUGE:600:0:U "
        . "DS:diskUsed:GAUGE:600:0:U "
        . "DS:monthExpect:GAUGE:600:0:U "
        . "RRA:LAST:0.5:1:288 "
        . "RRA:AVERAGE:0.5:12:168 "
        . "RRA:AVERAGE:0.5:288:365";
    exec($command, $output, $return_var);
    if ($return_var !== 0) {
        echo "Failed to create RRD database: ", implode("\n", $output), PHP_EOL;
        exit(1);
    }
}

$diskSpaceAvailable = 0;
$diskSpaceUsed = 0;
$currentMonthExpectations = 0;

// Iterate node endpoints
foreach ($nodes as $node) {
    $url = "http://$node/api/sno/";
    $data = curl($url);
    $diskSpaceAvailable += $data['diskSpace']['available'];
    $diskSpaceUsed += $data['diskSpace']['used'];

    $url = "http://$node/api/sno/estimated-payout";
    $data = curl($url);
    $currentMonthExpectations += $data['currentMonthExpectations'];
}

// Formatting
$diskSpaceAvailable = round($diskSpaceAvailable / pow(1000, 4), 3);
$diskSpaceUsed = round($diskSpaceUsed / pow(1000, 4), 3);
$currentMonthExpectations = round($currentMonthExpectations / 100, 2);

// Output
echo "diskSpaceAvailable: $diskSpaceAvailable TB", PHP_EOL;
echo "diskSpaceUsed: $diskSpaceUsed TB", PHP_EOL;
echo "currentMonthExpectations: $$currentMonthExpectations", PHP_EOL;

// Update RRDDb
$updateCommand = "rrdtool update $rrddb N:$diskSpaceAvailable:$diskSpaceUsed:$currentMonthExpectations";
exec($updateCommand, $updateOutput, $updateReturnVar);
if ($updateReturnVar !== 0) {
    echo "Failed to update RRD database: ", implode("\n", $updateOutput), PHP_EOL;
}

// Generate RRDtool graph
$graphFile = 'graph.png';
$graphHistory = getenv('GRAPH_HISTORY');
if ($graphHistory === false || empty($graphHistory)) {
    $graphHistory = '5weeks'; // Default to 5 weeks
}
$graphWidth = getenv('GRAPH_WIDTH');
if ($graphWidth === false || empty($graphWidth)) {
    $graphWidth = '1200'; // Default width
}
$graphHeight = getenv('GRAPH_HEIGHT');
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
    . "--title='Disk Space & Expected Earnings ($graphHistory)' "
    . "--vertical-label='TB / USD' "
    . "DEF:avail=$rrddb:diskAvail:LAST "
    . "DEF:used=$rrddb:diskUsed:LAST "
    . "DEF:expect=$rrddb:monthExpect:LAST "
    . "LINE1:avail#00FF00:'Disk Available (TB)' "
    . "LINE1:used#0000FF:'Disk Used (TB)' "
    . "LINE2:expect#FF9900:'Month Earnings (\$)' "
    . "COMMENT:'\\n' "
    . "GPRINT:avail:LAST:'Avail Now\: %6.2lf TB' "
    . "GPRINT:used:AVERAGE:'Used Now\: %6.2lf TB' "
    . "GPRINT:expect:AVERAGE:'Earn Now\: \$%6.2lf\\n'";
exec($graphCommand, $graphOutput, $graphReturnVar);
if ($graphReturnVar !== 0) {
    echo "Failed to generate RRD graph: ", implode("\n", $graphOutput), PHP_EOL;
} else {
    echo "Graph generated: $graphFile", PHP_EOL;
}


function curl($url)
{
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
