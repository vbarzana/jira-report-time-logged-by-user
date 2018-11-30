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

function buildRowFromData($data)
{
    global $error;

    //echo json_encode($data); exit;

    if (empty($data)) {
        $error = 'Error: Request did not return any results, check login information or project key';
        return false;
    }

    $arr = [];

    foreach ($data as $i => $issue) {
        $field = $issue['fields'];
        $arr[$i]['key'] = $issue['key'];
        $arr[$i]['assignee'] = $field['assignee']['displayName'];
        $arr[$i]['status'] = $field['status']['name'];
        $arr[$i]['priority'] = $field['priority']['name'];
        $arr[$i]['summary'] = $field['summary'];
        $arr[$i]['time_estimate'] = $field['timeestimate'];
        //$arr[$i]['total_time_spent'] = $field['aggregatetimespent'];
        $arr[$i]['total_time_spent'] = $field['timespent'];
    }

    return $arr;
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
    $jql = urlencode("project=" . $jiraKey . " AND worklogAuthor=" . $cfg['jira_username'] . " AND worklogDate >= " . $cfg['from'] . "AND worklogDate <= " . $cfg['to'] . "&maxResults=" . $cfg['max_results']);
    return $cfg['jira_host_address'] . "/rest/api/2/search?jql=" . $jql;
}

if (!empty($_POST)) {
    if ($_POST["submit"] === "fetch") {
        $url = getIssuesUrl();
        $result = getData($url);

        $decodedData = json_decode($result, true);

        $rows = buildRowFromData($decodedData['issues']);

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
    <style>body {
            font-family: Arial;
            padding: 30px
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
        }</style>
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
            <th>Time Estimated</th>
            <th>Total Time Spent</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $index => $row) : ?>
            <tr>
                <td><?php echo $row['key']; ?></td>
                <td><?php echo $row['assignee']; ?></td>
                <td><?php echo $row['status']; ?></td>
                <td><?php echo $row['priority']; ?></td>
                <td><?php echo $row['summary']; ?></td>
                <td><?php echo $row['time_estimate']; ?></td>
                <td><?php echo $row['total_time_spent']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        </table><?php
    endif ?>
</form>
</body>
</html>
