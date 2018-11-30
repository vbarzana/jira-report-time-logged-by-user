<?php

/**
 * Local Composer
 */
require 'vendor/autoload.php';

use League\Csv\Writer;

$cfg = "";
$error = "";

session_start();
initConfig();

function initConfig()
{
    global $cfg;
    // Let's read the config and override the default config.json if we find a config.local.json
    $defaultConfig = '';
    $userConfig = '';
    if (file_exists("./config.json")) {
        $defaultConfig = file_get_contents("./config.json");
    }
    if (file_exists("./config.local.json")) {
        $userConfig = file_get_contents("./config.local.json");
    }
    if ($defaultConfig && $userConfig) {
        $cfg = array_merge(json_decode($defaultConfig, true), json_decode($userConfig, true));
    } else {
        $cfg = json_decode(($userConfig ? $userConfig : $defaultConfig), true);
    }
}

function getData($url)
{
    global $cfg;
    global $error;

    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");

    $headers = array();
    $headers[] = "Authorization: Basic " . base64_encode($cfg['jira_user_email'] . ':' . $cfg['jira_user_password']);
    $headers[] = "Content-Type: application/json";
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        $error = 'Error: ' . curl_error($curl);
    }
    curl_close($curl);

    return $result;
}

function buildRowFromData($issues)
{
    global $error;

    //echo json_encode($data); exit;

    if (empty($issues)) {
        $error = 'Error: Request did not return any results, check login information or project key';
        return false;
    }

    $arr = [];
    foreach ($issues as $i => $issue) {
        $field = $issue['fields'];
        $arr[$i]['key'] = $issue['key'];
        $arr[$i]['assignee'] = $field['assignee']['displayName'];
        $arr[$i]['status'] = $field['status']['name'];
        $arr[$i]['priority'] = $field['priority']['name'];
        $arr[$i]['summary'] = getWorklogs($issue['key'], $field['status']['name']);
        $arr[$i]['total_time_spent'] = ($field['timespent'] / 3600);
    }

    return $arr;
}

function debug($data)
{
    echo '<pre>';
    echo var_dump($data);
    echo '</pre>';
    die();
}

function getWorklogs($key, $status)
{
    global $cfg;
    $statusCls = str_replace(" ", "", strtolower($status));
    $url = getBaseUrl() . "issue/$key/worklog?jql=" . urlencode("worklogAuthor=" . $cfg['jira_username'] . " AND worklogDate >= " . $cfg['from'] . "AND worklogDate <= " . $cfg['to']);
    $worklogData = getData($url);
    $worklogs = json_decode($worklogData, true);
    $comments = '';
    foreach ($worklogs['worklogs'] as $i => $worklog) {
        $date = $date = new DateTime($worklog['started']);
        $date = $date->format('Y-m-d H:i:s');
        $comments .= '<div class="entry' . ($i % 2 == 0 ? ' striped' : '') . '"><span class="date ' . $statusCls . '">' . $date . ' (' . $worklog['timeSpent'] . ')</span><span class="description">' . $worklog['comment'] . '</span></div>';
    }
    return $comments;
}

function getBaseUrl()
{
    global $cfg;
    return $cfg['jira_host_address'] . "/rest/api/2/";
}

function getIssuesUrl()
{
    global $cfg;
    $jiraKey = $_POST["jira_key"];
    if (!$jiraKey) {
        $jiraKey = $cfg['project_key'];
    }

    $jiraKey = strtoupper($jiraKey);
    // load url
    $jql = urlencode("project=" . $jiraKey . " AND worklogAuthor=" . $cfg['jira_username'] . " AND worklogDate >= " . $cfg['from'] . "AND worklogDate <= " . $cfg['to']);
    return getBaseUrl() . "search?jql=" . $jql . "&maxResults=" . $cfg['max_results'];
}

if (!empty($_POST)) {
    if ($_POST["submit"] === "fetch") {
        $url = getIssuesUrl();

        $result = getData($url);
        $decodedData = json_decode($result, true);
        $issues = $decodedData['issues'];
        $rows = buildRowFromData($issues);
        $total = 0;
        foreach ($rows as &$row) {
            $total += $row['total_time_spent'];
            $row['total_time_spent'] = $row['total_time_spent'] . ' h';
        }
        $totalRow = array();
        $totalRow['key'] = '<b>TOTAL: </b>';
        $totalRow['total_time_spent'] = '<b>' . $total . ' h</b>';

        array_push($rows, $totalRow);

        $_SESSION['export'] = $rows;
    } else if ($_POST["submit"] === "export") {
        $writer = Writer::createFromFileObject(new SplTempFileObject());

        $csvHeader = array('Key', 'Assignee', 'Status', 'Priority', 'Summary', 'Time Estimated', 'Total Time Spent');

        $writer->insertOne($csvHeader);
        $writer->insertAll($_SESSION['export']);

        $time = date('d-m-Y-H:i:s');

        $writer->output('jira-export-' . $time . '.csv');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JIRA Export</title>
    <link rel="stylesheet" href="https://unpkg.com/purecss@0.6.2/build/pure-min.css"
          integrity="sha384-UQiGfs9ICog+LwheBSRCt1o5cbyKIHbwjWscjemyBMT9YCUMZffs6UqUTd0hObXD" crossorigin="anonymous">
    <style>
        body {
            font-family: Arial;
            padding: 30px
        }

        .entry {
            margin: 0 0 5px 0;
            float: left;
            width: 100%;
            -webkit-border-radius: 3px;
            -moz-border-radius: 3px;
            border-radius: 3px;
        }

        .entry span {
            float: left;
            margin-left: 5px;
        }

        .entry.striped {
            background-color: rgba(240, 248, 255, 0.41);
        }

        span.date {
            color: #594300;
            background-color: #d3efff;
            border: 1px solid #d4d4d4;
            padding: 3px 5px 2px 5px;
            min-width: 76px;
            border-radius: 3px;
            display: inline-block;
            font-size: 11px;
            line-height: 99%;
            margin: 0;
            text-align: center;
            text-transform: uppercase;
        }

        span.date.inprogress {
            background-color: #fbff5d;
        }

        span.description {
            font-style: italic;
            float: none;
            width: auto;
            font-size: 12px;
            clear: left;
        }

        label {
            margin-top: 50px;
            width: 360px;
            font-size: 22px;
            font-weight: 700;
            padding-bottom: 10px;
        }

        input {
            margin-top: 5px;
            height: 40px;
            width: 400px;
            font-size: 20px;
            padding-left: 15px;
            text-transform: uppercase;
            vertical-align: bottom;
        }

        button {
            color: #fff;
            vertical-align: bottom;
            height: 46px;
        }

        .button-success {
            background: #1cb841;
            color: #fff;
        }

        .button-secondary {
            background: #42b8dd
        }

        .button-small {
            font-size: 85%
        }

        .button-xlarge {
            font-size: 125%
        }
    </style>
</head>
<body>
<?php if (!empty($error)) : ?>
    <div>
        <p><?php echo $error ?></p>
    </div>
<?php endif; ?>

<form method="POST">
    <div>
        <label>Enter JIRA Project Key<br><input type="text" name="jira_key" placeholder="Eg. PROJ, ABC"></label>
        <button name="submit" class="button-success button-xlarge pure-button" value="fetch">Run Report</button>
    </div>

    <?php if (!empty($rows)) : ?>
        <hr/>
        <h3>Results
            <button name="submit" class="button-secondary button-small pure-button" value="export">Export CSV</button>
        </h3>
        <table class="pure-table pure-table-bordered">
        <thead>
        <tr>
            <th width="100">Key</th>
            <th>Assignee</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Summary</th>
            <th>Total Time Spent</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $index => $row) : ?>
            <tr>
                <td><?= @$row['key']; ?></td>
                <td><?= @$row['assignee']; ?></td>
                <td><?= @$row['status']; ?></td>
                <td><?= @$row['priority']; ?></td>
                <td><?= @$row['summary']; ?></td>
                <td><?= @$row['total_time_spent']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table><?php
    endif ?>
</form>
</body>
</html>
