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
    $cfg['fromDate'] = parseDate($cfg['from']);
    $cfg['toDate'] = parseDate($cfg['to']);
    $cfg['from'] = str_replace('/', '\u002f', $cfg['from']);
    $cfg['to'] = str_replace('/', '\u002f', $cfg['to']);
}

function parseDate($jiraDate)
{
    $newDate = null;
    $offset = $jiraDate;
    $offset = str_replace('startOfMonth(', '', $offset);
    $offset = str_replace('endOfMonth(', '', $offset);
    $offset = @(int)str_replace(')', '', $offset);
    if (empty($offset)) {
        $offset = 0;
    }
    // If is start of month check the index
    if (strpos($jiraDate, 'startOfMonth') > -1) {
        $newDate = date_create(date("Y/m/01", strtotime($offset . " months")));
    } else if (strpos($jiraDate, 'endOfMonth') > -1) {
        $newDate = date_create(date("Y/m/t", strtotime($offset . " months")));
    } else {
        $newDate = @date_create($jiraDate);
    }
    return $newDate;
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
        $worklogs = getWorklogs($issue['key'], $field['status']['name']);
        $arr[$i]['key'] = '<a href="' . getBrowseUrlForIssue($issue['key']) . '" target="_blank">' . $issue['key'] . '</a>';
        $arr[$i]['assignee'] = $field['assignee']['displayName'];
        $arr[$i]['status'] = $field['status']['name'];
        $arr[$i]['priority'] = $field['priority']['name'];
        $arr[$i]['summary'] = '<strong style="font-size: 12px;">' . $field['summary'] . '</strong><br/>' . $worklogs[1];
        $arr[$i]['total_time_spent'] = $worklogs[0] / 3600; //($field['timespent'] / 3600);
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
    $url = getApiBaseUrl() . "issue/$key/worklog?jql=" . urlencode("worklogAuthor=" . $cfg['jira_username'] . " AND worklogDate >= " . $cfg['from'] . "AND worklogDate <= " . $cfg['to']);
    $worklogData = getData($url);
    $worklogs = json_decode($worklogData, true);
    $comments = '';
    $totalTime = 0;
    foreach ($worklogs['worklogs'] as $i => $worklog) {
        $date = $date = new DateTime($worklog['started']);
        // if(worklogdate is between from and to, take it, otherwise ignore)
        if ($date >= $cfg['fromDate'] && $date <= $cfg['toDate']) {
            $totalTime += ((int)$worklog['timeSpentSeconds']);
            $date = $date->format('Y-m-d H:i:s');
            $comment = str_replace('\n', '<br/>', $worklog['comment']);
            $comments .= '<div class="entry' . ($i % 2 == 0 ? ' striped' : '') . '"><span class="date ' . $statusCls . '">' . $date . ' (' . $worklog['timeSpent'] . ')</span><span class="description">' . $comment . '</span></div>';
        }
    }

    return array($totalTime, $comments);
}

function getApiBaseUrl()
{
    global $cfg;
    return $cfg['jira_host_address'] . "/rest/api/2/";
}

function getBrowseUrlForIssue($issue)
{
    global $cfg;
    return $cfg['jira_host_address'] . '/browse/' . ($issue ? $issue : '');
}

function getApiIssuesUrl()
{
    global $cfg;
    $jiraKey = $_POST["jira_key"];
    if (!$jiraKey) {
        $jiraKey = $cfg['project_key'];
    }

    $jiraKey = strtoupper($jiraKey);
    // load url
    $jql = urlencode("project=" . $jiraKey . " AND worklogAuthor=" . $cfg['jira_username'] . " AND worklogDate >= " . $cfg['from'] . " AND worklogDate <= " . $cfg['to']);
    return getApiBaseUrl() . "search?jql=" . $jql . "&maxResults=" . $cfg['max_results'];
}

if (!empty($_POST)) {
    if ($_POST["submit"] === "fetch") {
        $url = getApiIssuesUrl();

        $result = getData($url);
        $decodedData = json_decode($result, true);
        $issues = $decodedData['issues'];
        $rows = buildRowFromData($issues);
        $total = 0;
        foreach ($rows as $i => $row) {
            $total += $row['total_time_spent'];
        }
        $totalRow = array();
        $totalRow['key'] = '<b>TOTAL: </b>';
        $totalRow['total_row'] = true;
        $totalRow['total_time_spent'] = $total;
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

<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css"
          integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
    <link rel="stylesheet" href="styles.css">
    <title>Worklog report from Jira!</title>
</head>
<body>

<nav class="navbar navbar-expand-md navbar-dark bg-dark fixed-top">
    <a class="navbar-brand" href="?">Jira Worklog Report</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarsExampleDefault"
            aria-controls="navbarsExampleDefault" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarsExampleDefault">

        <form class="form-inline my-2 my-lg-0" method="POST" style="float: right;">
            <input class="form-control mr-sm-2" type="text" name="jira_key"
                   placeholder="<?= $cfg['project_key'] ? $cfg['project_key'] : 'PROJECT' ?>">
            <button name="submit" class="btn btn-outline-success my-2 my-sm-0" value="fetch">Get my worklogs</button>
            <?php if (!empty($rows)): ?>
                <button name="submit" class="btn btn-outline-info my-2 my-sm-0" style="margin-left: 8px;"
                        value="export">Export CSV
                </button>
            <?php endif; ?>
        </form>
    </div>
</nav>

<main role="main" class="container">
    <?php if (!empty($error)) : ?>
        <div>
            <p><?php echo $error ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($rows)) : ?>
        <hr/>
        <table class="table table-responsive">
            <thead class="thead-light">
            <tr>
                <th scope="col">Key</th>
                <th scope="col">Assignee</th>
                <th scope="col">Status</th>
                <th scope="col">Priority</th>
                <th scope="col" class="summary">Summary</th>
                <th scope="col">Time Spent</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $index => $row) : ?>
                <tr class="<? echo @$row['total_row'] ? 'thead-light' : '' ?>">
                    <th scope="row" class="no-whitespace"><?= @$row['key']; ?></th>
                    <td><?= @$row['assignee']; ?></td>
                    <td><?= @$row['status']; ?></td>
                    <td><?= @$row['priority']; ?></td>
                    <td class="summary"><?= @$row['summary']; ?></td>
                    <td><?= @$row['total_time_spent']; ?> h</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="jumbotron">
            <h1 class="display-4">Monthly work report</h1>
            <p class="lead">Make sure you have changed the config.json or the config.local.json files and then just
                click "search".</p>
            <form method="POST">
                <div class="form-group">
                    <input class="form-control mr-sm-2" style="max-width: 100px;float: left;" type="text"
                           name="jira_key"
                           placeholder="<?= $cfg['project_key'] ? $cfg['project_key'] : 'PROJECT' ?>">
                    <button name="submit" class="btn btn-outline-success my-2 my-sm-0" value="fetch">Get my worklogs
                    </button>
                </div>
            </form>
        </div>
    <?php endif ?>

</main><!-- /.container -->


<!-- Optional JavaScript -->
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"
        integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo"
        crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js"
        integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49"
        crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js"
        integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy"
        crossorigin="anonymous"></script>
</body>
</html>